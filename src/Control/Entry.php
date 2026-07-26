<?php
declare(strict_types=1);

namespace Kingbes\Ui\Control;

use Kingbes\Ui\App;
use Kingbes\Ui\Control;
use Kingbes\Ui\Window;

/**
 * 单行文本输入框。
 *
 * 基于 Win32 "Edit" 类（ES_AUTOHSCROLL + WS_EX_CLIENTEDGE 凹陷边框）。
 *
 * 事件：
 *   - onChange：文本变化时触发（EN_CHANGE）。
 *   - onEnter：按下回车键时触发（WM_KEYDOWN VK_RETURN）。
 */
class Entry extends Control
{
    private const ES_AUTOHSCROLL    = 0x0080;
    private const WS_TABSTOP         = 0x00010000;
    private const WS_EX_CLIENTEDGE   = 0x00000200;

    private string $text;

    /** 文本变化回调（无参数）。 */
    public ?\Closure $onChange = null;
    /** 回车键回调（无参数）。 */
    public ?\Closure $onEnter = null;

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
            self::ES_AUTOHSCROLL | self::WS_TABSTOP,
            0,
            $this->parentHwnd(),
            0
        );
    }

    /**
     * 偏好高度：Windows 标准输入框高度 23px。
     *
     * 让布局容器在空间充足时给输入框分配 23px 高度，避免被拉满整格；
     * 空间不足时回退到均分。
     */
    public function getPreferredHeight(): int
    {
        return 23;
    }

    /**
     * 偏好宽度：0（由容器决定，输入框宽度通常随布局拉伸）。
     */
    public function getPreferredWidth(): int
    {
        return 0;
    }
}
