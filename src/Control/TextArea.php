<?php
declare(strict_types=1);

namespace Kingbes\Ui\Control;

use Kingbes\Ui\App;
use Kingbes\Ui\Control;
use Kingbes\Ui\Window;

/**
 * 多行文本输入框。
 *
 * 基于 Win32 "Edit" 类（ES_MULTILINE | ES_AUTOVSCROLL | ES_WANTRETURN |
 * WS_VSCROLL + WS_EX_CLIENTEDGE 凹陷边框）。回车键换行。
 *
 * 事件：
 *   - onChange：文本变化时触发（EN_CHANGE）。
 */
class TextArea extends Control
{
    private const ES_MULTILINE      = 0x0004;
    private const ES_AUTOVSCROLL    = 0x0040;
    private const ES_WANTRETURN     = 0x1000;
    private const WS_VSCROLL         = 0x00200000;
    private const WS_TABSTOP         = 0x00010000;
    private const WS_EX_CLIENTEDGE   = 0x00000200;

    private string $text;

    /** 文本变化回调（无参数）。 */
    public ?\Closure $onChange = null;

    /**
     * @param Control|Window $parent 父容器或窗口。
     * @param string         $text   初始文本。
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
            self::ES_MULTILINE | self::ES_AUTOVSCROLL
                | self::ES_WANTRETURN | self::WS_VSCROLL | self::WS_TABSTOP,
            self::WS_EX_CLIENTEDGE,
            $this->parentHwnd(),
            0
        );
    }
}
