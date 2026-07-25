<?php
declare(strict_types=1);

namespace Kingbes\Ui\Menu;

use Kingbes\Ui\App;
use Kingbes\Ui\Graphics\Image;

/**
 * 菜单项。
 *
 * 表示菜单中的一个条目（文本项、分隔符或子菜单入口）。
 *
 * 状态控制：
 *   - setEnabled(bool)   启用/禁用（灰显），委托平台 EnableMenuItem
 *   - setChecked(bool)   勾选/取消勾选，委托平台 CheckMenuItem
 *   - setImage(Image)    设置位图图标，委托平台 SetMenuItemInfoW
 *
 * 事件：
 *   - onClick  用户点击该菜单项时触发的闭包（分隔符与子菜单入口无 onClick）。
 *
 * 菜单项 ID 从 9000 起（Menu::$nextId），避免与控件 ID（1000+）冲突。
 */
class MenuItem
{
    /**
     * 菜单项 ID（用于 WM_COMMAND 分发）。
     */
    private int $id;

    /**
     * 菜单项文本。
     */
    private string $text;

    /**
     * 是否启用。
     */
    private bool $enabled = true;

    /**
     * 是否勾选。
     */
    private bool $checked = false;

    /**
     * 是否为分隔符。
     */
    private bool $separator = false;

    /**
     * 子菜单（若此项为子菜单入口）。
     */
    private ?Menu $submenu = null;

    /**
     * 当前图像的 HBITMAP int 句柄（0 表示无图像）。
     */
    private int $hbmInt = 0;

    /**
     * 持有的 Image 引用（防止 GC 释放 GpImage）。
     */
    private ?Image $image = null;

    /**
     * 点击回调。签名：fn(): void。
     */
    public ?\Closure $onClick = null;

    /**
     * 所属菜单的 HMENU int 值（用于 setEnabled/setChecked 委托平台调用）。
     */
    private int $menuHwnd;

    /**
     * @param string $text      菜单项文本。
     * @param int    $id        菜单项 ID。
     * @param int    $menuHwnd  所属菜单 HMENU int。
     */
    public function __construct(string $text, int $id, int $menuHwnd)
    {
        $this->text = $text;
        $this->id = $id;
        $this->menuHwnd = $menuHwnd;
    }

    /**
     * 创建分隔符菜单项（内部使用）。
     *
     * @param int $menuHwnd 所属菜单 HMENU int。
     */
    public static function forSeparator(int $menuHwnd): self
    {
        $item = new self('', 0, $menuHwnd);
        $item->separator = true;
        return $item;
    }

    /**
     * 设置启用状态。
     */
    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;
        if ($this->id !== 0) {
            App::platform()->menuSetEnabled($this->menuHwnd, $this->id, $enabled);
        }
        return $this;
    }

    /**
     * 设置勾选状态。
     */
    public function setChecked(bool $checked): self
    {
        $this->checked = $checked;
        if ($this->id !== 0) {
            App::platform()->menuSetChecked($this->menuHwnd, $this->id, $checked);
        }
        return $this;
    }

    /**
     * 设置菜单项图标。
     *
     * 内部流程：
     *   1. GpImage → HBITMAP（int 句柄）
     *   2. SetMenuItemInfoW（MIIM_BITMAP）设置 hbmpItem
     *   3. 持有 Image 引用防止 GC 释放 GpImage
     *
     * @param Image $image 图像对象（建议 16x16 或更小图标）。
     */
    public function setImage(Image $image): self
    {
        if ($this->id === 0) {
            return $this; // 分隔符无图标
        }

        $platform = App::platform();

        // 释放旧图像
        if ($this->hbmInt !== 0) {
            $platform->deleteGdiObjectInt($this->hbmInt);
            $this->hbmInt = 0;
        }

        // GpImage → HBITMAP
        $this->hbmInt = $platform->gdipImageToHbitmapInt($image->getGpImage());
        if ($this->hbmInt === 0) {
            trigger_error('MenuItem::setImage: image conversion failed', \E_USER_WARNING);
            return $this;
        }

        // 持有 Image 引用防 GC
        $this->image = $image;

        // SetMenuItemInfoW 设置 hbmpItem
        $platform->menuSetItemBitmap($this->menuHwnd, $this->id, $this->hbmInt);
        return $this;
    }

    /**
     * 设置子菜单。
     */
    public function setSubmenu(Menu $m): self
    {
        $this->submenu = $m;
        return $this;
    }

    /**
     * 获取菜单项 ID。
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * 获取菜单项文本。
     */
    public function getText(): string
    {
        return $this->text;
    }

    /**
     * 是否启用。
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * 是否勾选。
     */
    public function isChecked(): bool
    {
        return $this->checked;
    }

    /**
     * 是否为分隔符。
     */
    public function isSeparator(): bool
    {
        return $this->separator;
    }

    /**
     * 获取子菜单。
     */
    public function getSubmenu(): ?Menu
    {
        return $this->submenu;
    }
}
