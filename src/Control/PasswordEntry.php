<?php
declare(strict_types=1);

namespace Kingbes\Ui\Control;

use Kingbes\Ui\App;
use Kingbes\Ui\Control;
use Kingbes\Ui\Window;

/**
 * 密码输入框。
 *
 * 基于 Win32 "Edit" 类（ES_PASSWORD 样式，默认掩码字符 '*'）。
 * 文本内容以掩码字符显示，但 getText 仍返回真实文本。
 *
 * 事件：
 *   - onChange：文本变化时触发（EN_CHANGE）。
 *   - onEnter：按下回车键时触发。
 */
class PasswordEntry extends Control
{
    private const ES_AUTOHSCROLL    = 0x0080;
    private const ES_PASSWORD       = 0x0020;
    private const WS_TABSTOP        = 0x00010000;
    private const WS_EX_CLIENTEDGE  = 0x00000200;

    private string $text;

    /** 文本变化回调（无参数）。 */
    public ?\Closure $onChange = null;
    /** 回车键回调（无参数）。 */
    public ?\Closure $onEnter = null;

    /**
     * @param Control|Window $parent 父容器或窗口。
     * @param string         $text   初始文本（已掩码显示）。
     */
    public function __construct(Control|Window $parent, string $text = '')
    {
        $this->text = $text;
        parent::__construct($parent);
    }

    protected function create(): void
    {
        $this->hwnd = App::platform()->controlCreate(
            'Edit',
            $this->text,
            self::ES_AUTOHSCROLL | self::ES_PASSWORD | self::WS_TABSTOP,
            self::WS_EX_CLIENTEDGE,
            $this->parentHwnd(),
            0
        );
    }
}
