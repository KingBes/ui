<?php
declare(strict_types=1);

namespace Kingbes\Ui\Control;

use Kingbes\Ui\App;
use Kingbes\Ui\Control;
use Kingbes\Ui\Graphics\Image;
use Kingbes\Ui\Window;

/**
 * 标签控件（静态文本/图像）。
 *
 * 基于 Win32 "Static" 类。文本模式支持左/居中/右对齐；
 * 图像模式通过构造器传入 Image 或调用 setImage 设置位图。
 *
 * 注意：SS_BITMAP 样式必须在创建时设置，因此图像模式需在构造器指定
 * Image（或显式传 imageMode=true 创建空图像标签后用 setImage 填充）。
 */
class Label extends Control
{
    public const ALIGN_LEFT   = 0;
    public const ALIGN_CENTER = 1;
    public const ALIGN_RIGHT  = 2;

    /** SS_LEFT / SS_CENTER / SS_RIGHT */
    private const SS_LEFT   = 0x00000000;
    private const SS_CENTER = 0x00000001;
    private const SS_RIGHT  = 0x00000002;
    /** SS_BITMAP：静态控件显示位图 */
    private const SS_BITMAP = 0x0000000E;

    private string $text;
    private int $alignment;
    private bool $imageMode;

    /** 当前图像的 HBITMAP int 句柄（0 表示无图像）。 */
    private int $hbmInt = 0;

    /**
     * @param Control|Window  $parent    父容器或窗口。
     * @param string          $text      标签文本（支持中文/emoji）。
     * @param int             $alignment 对齐方式（ALIGN_LEFT/CENTER/RIGHT）。
     * @param Image|null      $image     图像对象，传入非 null 时创建图像标签。
     */
    public function __construct(
        Control|Window $parent,
        string $text = '',
        int $alignment = self::ALIGN_LEFT,
        ?Image $image = null
    ) {
        $this->text = $text;
        $this->alignment = $alignment;
        $this->imageMode = $image !== null;
        parent::__construct($parent);

        if ($image !== null) {
            $this->setImage($image);
        }
    }

    protected function create(): void
    {
        if ($this->imageMode) {
            // 图像模式：SS_BITMAP 样式
            $this->hwnd = App::platform()->controlCreate(
                'Static',
                '',
                self::SS_BITMAP,
                0,
                $this->parentHwnd(),
                0
            );
            return;
        }

        $style = match ($this->alignment) {
            self::ALIGN_CENTER => self::SS_CENTER,
            self::ALIGN_RIGHT  => self::SS_RIGHT,
            default            => self::SS_LEFT,
        };
        $this->hwnd = App::platform()->controlCreate(
            'Static',
            $this->text,
            $style,
            0,
            $this->parentHwnd(),
            0
        );
    }

    /**
     * 设置标签图像（仅图像模式标签有效）。
     *
     * 标签必须以 SS_BITMAP 样式创建（构造器传入 Image 或显式 imageMode）。
     * 旧图像的 HBITMAP 会被自动 DeleteObject 释放。
     *
     * @param Image $image 图像对象。
     */
    public function setImage(Image $image): void
    {
        $platform = App::platform();

        // GpImage → HBITMAP
        $newHbm = $platform->gdipImageToHbitmapInt($image->getGpImage());
        if ($newHbm === 0) {
            trigger_error('Label::setImage: image conversion failed', \E_USER_WARNING);
            return;
        }

        // STM_SETIMAGE 关联位图，返回旧图像句柄
        $oldHbm = $platform->labelSetImage($this->hwnd, $newHbm);
        $this->hbmInt = $newHbm;

        // 释放旧图像
        if ($oldHbm !== 0) {
            $platform->deleteGdiObjectInt($oldHbm);
        }
    }
}
