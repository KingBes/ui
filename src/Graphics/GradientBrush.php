<?php
declare(strict_types=1);

namespace Kingbes\Ui\Graphics;

use Kingbes\Ui\Platform\Windows\WindowsPlatform;

/**
 * 线性渐变画笔（GDI+ GpLineGradient 封装）。
 *
 * 支持两色渐变和多色停止点。创建后传给 DrawContext::setGradientBrush。
 *
 * 用法（两色）：
 *   $brush = new GradientBrush($platform, 0, 0, 200, 0, Color::red(), Color::blue());
 *   $ctx->setGradientBrush($brush);
 *   $ctx->fillRect(0, 0, 200, 100);
 *   $brush->free();
 *
 * 用法（多色停止点）：
 *   $brush = new GradientBrush($platform, 0, 0, 200, 0, Color::red(), Color::blue());
 *   $brush->setStops([
 *       [0.0, Color::red()],
 *       [0.5, Color::yellow()],
 *       [1.0, Color::green()],
 *   ]);
 *   $ctx->setGradientBrush($brush);
 *   $ctx->fillRect(0, 0, 200, 100);
 *   $brush->free();
 */
final class GradientBrush
{
    private WindowsPlatform $platform;
    private \FFI\CData $brush;
    private bool $freed = false;

    /**
     * 创建线性渐变画笔。
     *
     * @param WindowsPlatform $platform 平台实例。
     * @param float           $x1       起点 x。
     * @param float           $y1       起点 y。
     * @param float           $x2       终点 x。
     * @param float           $y2       终点 y。
     * @param Color           $color1   起点颜色。
     * @param Color           $color2   终点颜色。
     */
    public function __construct(
        WindowsPlatform $platform,
        float $x1, float $y1,
        float $x2, float $y2,
        Color $color1, Color $color2
    ) {
        $this->platform = $platform;
        $gp = $platform->getGdiplus();

        $p1 = $gp->new('PointF');
        $p1->X = $x1;
        $p1->Y = $y1;
        $p2 = $gp->new('PointF');
        $p2->X = $x2;
        $p2->Y = $y2;

        $c1 = self::colorToArgb($color1);
        $c2 = self::colorToArgb($color2);

        $brush = $gp->new('GpLineGradient');
        $status = (int) $gp->GdipCreateLineBrush(
            \FFI::addr($p1),
            \FFI::addr($p2),
            $c1, $c2,
            0, // WrapModeTile
            \FFI::addr($brush)
        );
        if ($status !== 0) {
            throw new \RuntimeException('GdipCreateLineBrush failed: ' . $status);
        }
        $this->brush = $brush;
    }

    /**
     * 设置多色停止点。
     *
     * @param array<int, array{0:float,1:Color}> $stops
     *   停止点数组，每个元素为 [position, color]。
     *   position 范围 0.0 ~ 1.0。
     */
    public function setStops(array $stops): void
    {
        if (count($stops) < 2) {
            return;
        }

        $gp = $this->platform->getGdiplus();
        $count = count($stops);

        // 创建颜色和位置数组
        $colors = $gp->new('int[' . $count . ']');
        $positions = $gp->new('float[' . $count . ']');

        for ($i = 0; $i < $count; $i++) {
            $positions[$i] = (float) $stops[$i][0];
            $colors[$i] = self::colorToArgb($stops[$i][1]);
        }

        $gp->GdipSetLinePresetBlend(
            $this->brush,
            \FFI::addr($colors[0]),
            \FFI::addr($positions[0]),
            $count
        );
    }

    /**
     * 获取底层 GpLineGradient 指针（供 DrawContext 使用）。
     *
     * @internal
     */
    public function getBrush(): \FFI\CData
    {
        return $this->brush;
    }

    /**
     * 释放底层资源。幂等。
     */
    public function free(): void
    {
        if ($this->freed) {
            return;
        }
        $this->freed = true;
        $gp = $this->platform->getGdiplus();
        // GpLineGradient 是 GpBrush 的子类，用 GdipDeleteBrush 释放
        $gp->GdipDeleteBrush($this->brush);
    }

    public function __destruct()
    {
        $this->free();
    }

    /**
     * Color 转 GDI+ ARGB（int32 有符号）。
     */
    private static function colorToArgb(Color $c): int
    {
        $argb = (0xFF << 24)
            | (($c->r & 0xFF) << 16)
            | (($c->g & 0xFF) << 8)
            | ($c->b & 0xFF);
        if ($argb > 0x7FFFFFFF) {
            $argb -= 0x100000000;
        }
        return $argb;
    }
}
