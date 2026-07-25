<?php
declare(strict_types=1);

namespace Kingbes\Ui\Control;

use Kingbes\Ui\App;
use Kingbes\Ui\Control;
use Kingbes\Ui\Graphics\Image;
use Kingbes\Ui\Window;

/**
 * 按钮控件。
 *
 * 基于 Win32 "Button" 类（BS_PUSHBUTTON）。点击时触发 onClick 回调
 * （由 WM_COMMAND BN_CLICKED 通知分发）。
 *
 * 图标支持：通过 setImage() 设置位图图像，BS_BITMAP 样式启用后按钮显示
 * 位图而非文本。setImage(null) 清除图像恢复文本模式。
 */
class Button extends Control
{
    /** BS_PUSHBUTTON */
    private const STYLE_PUSHBUTTON = 0x00010000;
    /** BS_BITMAP：按钮显示位图 */
    private const BS_BITMAP = 0x00000080;

    private string $text;

    /** 当前图像的 HBITMAP int 句柄（0 表示无图像）。 */
    private int $hbmInt = 0;

    /**
     * @param Control|Window $parent 父容器或窗口。
     * @param string         $text   按钮文本（支持中文/emoji）。
     */
    public function __construct(Control|Window $parent, string $text = '')
    {
        $this->text = $text;
        parent::__construct($parent);
    }

    protected function create(): void
    {
        $this->hwnd = App::platform()->controlCreate(
            'Button',
            $this->text,
            self::STYLE_PUSHBUTTON,
            0,
            $this->parentHwnd(),
            0
        );
    }

    /**
     * 设置按钮图像。
     *
     * 内部流程：
     *   1. GpImage → HBITMAP（int 句柄）
     *   2. 通过 GWL_STYLE 给按钮追加 BS_BITMAP 样式
     *   3. BM_SETIMAGE 关联位图
     *
     * 传 null 清除图像并移除 BS_BITMAP 样式，恢复文本模式。
     * 旧图像的 HBITMAP 会被自动 DeleteObject 释放。
     *
     * @param Image|null $image 图像对象，null 清除。
     */
    public function setImage(?Image $image): void
    {
        $platform = App::platform();

        // 释放旧图像
        if ($this->hbmInt !== 0) {
            $platform->deleteGdiObjectInt($this->hbmInt);
            $this->hbmInt = 0;
        }

        if ($image === null) {
            // 清除图像：移除 BS_BITMAP 样式
            $style = $platform->controlGetStyle($this->hwnd);
            $platform->controlSetStyle($this->hwnd, $style & ~self::BS_BITMAP);
            $platform->buttonSetImage($this->hwnd, 0);
            return;
        }

        // GpImage → HBITMAP
        $this->hbmInt = $platform->gdipImageToHbitmapInt($image->getGpImage());
        if ($this->hbmInt === 0) {
            trigger_error('Button::setImage: image conversion failed', \E_USER_WARNING);
            return;
        }

        // 追加 BS_BITMAP 样式
        $style = $platform->controlGetStyle($this->hwnd);
        $platform->controlSetStyle($this->hwnd, $style | self::BS_BITMAP);
        // BM_SETIMAGE 关联位图
        $platform->buttonSetImage($this->hwnd, $this->hbmInt);
    }
}
