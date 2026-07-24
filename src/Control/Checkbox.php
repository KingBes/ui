<?php
declare(strict_types=1);

namespace Kingbes\Ui\Control;

use Kingbes\Ui\App;
use Kingbes\Ui\Control;
use Kingbes\Ui\Window;

/**
 * 复选框控件。
 *
 * 基于 Win32 "Button" 类（BS_AUTOCHECKBOX）。点击时自动切换勾选状态
 * 并触发 onClick 回调（WM_COMMAND BN_CLICKED）。
 */
class Checkbox extends Control
{
    private const BS_AUTOCHECKBOX = 0x00000003;
    private const WS_TABSTOP       = 0x00010000;

    private string $text;

    /**
     * @param Control|Window $parent 父容器或窗口。
     * @param string         $text   复选框标签文本。
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
            self::BS_AUTOCHECKBOX | self::WS_TABSTOP,
            0,
            $this->parentHwnd(),
            0
        );
    }

    /**
     * 是否已勾选。
     */
    public function isChecked(): bool
    {
        return App::platform()->controlIsChecked($this->hwnd);
    }

    /**
     * 设置勾选状态。
     */
    public function setChecked(bool $checked): void
    {
        App::platform()->controlSetChecked($this->hwnd, $checked);
    }
}
