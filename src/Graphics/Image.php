<?php
declare(strict_types=1);

namespace Kingbes\Ui\Graphics;

use Kingbes\Ui\Platform\Windows\WindowsPlatform;

/**
 * GDI+ 图像（GpImage）封装。
 *
 * 支持从文件加载 BMP/PNG/JPEG/GIF/TIFF，由 DrawContext::drawImage /
 * drawImageScaled 绘制到 Area 画布。析构时调用 GdipDisposeImage 释放。
 *
 * 注意：
 *   - GpImage CData 由 gdiplus 作用域创建，不可跨 FFI 作用域使用。
 *   - 路径用 wchar_t[]（UTF-16LE），与 gdiplus 作用域一致。
 *   - 同一 Image 可在多个 DrawContext 中重复绘制（GDI+ 允许）。
 *
 * 典型用法：
 *   $img = Image::fromFile('C:/path/to/img.png');
 *   $area->onDraw = fn(DrawContext $ctx) => $ctx->drawImage($img, 10, 10);
 *   // 用完调用 free() 释放（或依赖 __destruct）
 *   $img->free();
 */
final class Image
{
    /** 平台实例（提供 gdiplus FFI 作用域）。 */
    private WindowsPlatform $platform;

    /** GpImage CData（gdiplus 作用域）。 */
    private \FFI\CData $image;

    /** 图像宽度（像素，加载时缓存）。 */
    private int $width;

    /** 图像高度（像素，加载时缓存）。 */
    private int $height;

    /** 释放标志。 */
    private bool $freed = false;

    /**
     * 从文件加载图像。
     *
     * @param WindowsPlatform $platform 平台实例。
     * @param string          $path     图像文件路径（UTF-8）。
     *
     * @throws \RuntimeException 加载失败时抛出。
     */
    private function __construct(WindowsPlatform $platform, string $path)
    {
        $this->platform = $platform;
        $gp = $platform->getGdiplus();

        // UTF-8 → UTF-16LE → gdiplus 作用域 wchar_t[]
        $wide = mb_convert_encoding($path, 'UTF-16LE', 'UTF-8');
        $len = intdiv(strlen($wide), 2);
        $arr = $gp->new('wchar_t[' . ($len + 1) . ']');
        for ($i = 0; $i < $len; $i++) {
            $arr[$i] = ord($wide[$i * 2]) | (ord($wide[$i * 2 + 1]) << 8);
        }
        $arr[$len] = 0;

        $img = $gp->new('GpImage');
        $status = (int) $gp->GdipLoadImageFromFile(\FFI::addr($arr[0]), \FFI::addr($img));
        if ($status !== 0) {
            throw new \RuntimeException(
                'GdipLoadImageFromFile failed (status ' . $status . ') for: ' . $path
            );
        }
        $this->image = $img;

        // 读取尺寸并缓存
        $w = $gp->new('unsigned int');
        $h = $gp->new('unsigned int');
        $gp->GdipGetImageWidth($img, \FFI::addr($w));
        $gp->GdipGetImageHeight($img, \FFI::addr($h));
        $this->width = (int) $w->cdata;
        $this->height = (int) $h->cdata;
    }

    /**
     * 工厂方法：从文件加载图像。
     *
     * @param string $path 图像文件路径（UTF-8）。
     */
    public static function fromFile(string $path): self
    {
        return new self(\Kingbes\Ui\App::platform(), $path);
    }

    /**
     * 内部构造（已注入 platform）。
     */
    public static function fromFileWithPlatform(WindowsPlatform $platform, string $path): self
    {
        return new self($platform, $path);
    }

    /**
     * 获取图像宽度（像素）。
     */
    public function getWidth(): int
    {
        return $this->width;
    }

    /**
     * 获取图像高度（像素）。
     */
    public function getHeight(): int
    {
        return $this->height;
    }

    /**
     * 获取 GpImage CData（gdiplus 作用域）。仅供 DrawContext 内部使用。
     */
    public function getGpImage(): \FFI\CData
    {
        return $this->image;
    }

    /**
     * 主动释放 GpImage。
     *
     * 重复调用安全（freed 标志守护）。
     */
    public function free(): void
    {
        if ($this->freed) {
            return;
        }
        $this->freed = true;
        $this->platform->getGdiplus()->GdipDisposeImage($this->image);
    }

    public function __destruct()
    {
        $this->free();
    }
}
