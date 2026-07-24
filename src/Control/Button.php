<?php
declare(strict_types=1);

namespace Kingbes\Ui\Control;

use Kingbes\Ui\App;
use Kingbes\Ui\Control;
use Kingbes\Ui\Window;

/**
 * 按钮控件。
 *
 * 基于 Win32 "Button" 类（BS_PUSHBUTTON）。点击时触发 onClick 回调
 * （由 WM_COMMAND BN_CLICKED 通知分发）。
 */
class Button extends Control
{
    /** BS_PUSHBUTTON */
    private const STYLE_PUSHBUTTON = 0x00010000;

    private string $text;

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
}
