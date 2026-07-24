<?php
declare(strict_types=1);

namespace Kingbes\Ui\Menu;

use Kingbes\Ui\App;

/**
 * 菜单项。
 *
 * 表示菜单中的一个条目（文本项、分隔符或子菜单入口）。
 *
 * 状态控制：
 *   - setEnabled(bool)   启用/禁用（灰显），委托平台 EnableMenuItem
 *   - setChecked(bool)   勾选/取消勾选，委托平台 CheckMenuItem
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
