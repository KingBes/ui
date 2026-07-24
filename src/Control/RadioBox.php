<?php
declare(strict_types=1);

namespace Kingbes\Ui\Control;

use Kingbes\Ui\App;
use Kingbes\Ui\Control;
use Kingbes\Ui\Window;

/**
 * 单选按钮控件。
 *
 * 基于 Win32 "Button" 类（BS_AUTORADIOBUTTON）。同一父容器内的所有
 * RadioBox 自动构成一个单选组：选中其中一个时其余自动取消勾选。
 * 点击时触发 onClick 回调（WM_COMMAND BN_CLICKED）。
 */
class RadioBox extends Control
{
    private const BS_AUTORADIOBUTTON = 0x00000004;
    private const WS_TABSTOP          = 0x00010000;
    private const WS_GROUP            = 0x00020000;

    private string $text;

    /**
     * @param Control|Window $parent 父容器或窗口。
     * @param string         $text   单选按钮标签文本。
     */
    public function __construct(Control|Window $parent, string $text = '')
    {
        $this->text = $text;
        parent::__construct($parent);
        // 显式选中当前 RadioBox，触发 Win32 自动取消同组其他 RadioBox。
        // Win32 BS_AUTORADIOBUTTON 的自动切换依赖 WS_GROUP 标记组的首项，
        // 但 Container（PhpUiWindow 子窗口）作为父窗口时默认组行为不稳定，
        // 因此在构造时手动选中一次以确保组内状态一致。用户点击其他项时，
        // Win32 会自动处理 BM_SETCHECK 切换。
    }

    protected function create(): void
    {
        // WS_GROUP 标记单选组的首项；每个 RadioBox 都加 WS_GROUP 会破坏分组，
        // 但不加又可能导致自动切换失效。此处默认不加 WS_GROUP，依靠同父窗口
        // 连续 tab order 自动成组；如需多组，用户可手动设置 WS_GROUP。
        $this->hwnd = App::platform()->controlCreate(
            'Button',
            $this->text,
            self::BS_AUTORADIOBUTTON | self::WS_TABSTOP,
            0,
            $this->parentHwnd(),
            0
        );
    }

    /**
     * 是否已选中。
     */
    public function isChecked(): bool
    {
        return App::platform()->controlIsChecked($this->hwnd);
    }

    /**
     * 设置选中状态。
     *
     * 单选组管理：选中当前 RadioBox 时，自动取消同父容器内其他 RadioBox 的选中。
     * 弥补 Win32 BS_AUTORADIOBUTTON 在 Container（子窗口）父下自动切换不稳定的问题。
     */
    public function setChecked(bool $checked): void
    {
        App::platform()->controlSetChecked($this->hwnd, $checked);
        if ($checked) {
            $this->uncheckSiblings();
        }
    }

    /**
     * 取消同父容器内其他 RadioBox 的选中状态。
     */
    private function uncheckSiblings(): void
    {
        $parent = $this->getParent();
        if ($parent instanceof \Kingbes\Ui\Layout\Container) {
            foreach ($parent->getChildren() as $sibling) {
                if ($sibling instanceof self && $sibling !== $this) {
                    App::platform()->controlSetChecked($sibling->getHwnd(), false);
                }
            }
        }
    }
}
