<?php
declare(strict_types=1);

namespace Kingbes\Ui\Control;

use Kingbes\Ui\App;
use Kingbes\Ui\Control;
use Kingbes\Ui\Window;

/**
 * 数值微调控件（SpinBox）。
 *
 * 复合控件：Edit（显示数值）+ UpDown（上下箭头按钮）。
 * UpDown 通过 UDS_AUTOBUDDY 自动关联前一个兄弟控件（Edit）作为伙伴，
 * UDS_SETBUDDYINT 使 UpDown 自动更新 Edit 的文本。
 *
 * 主 hwnd 为 Edit（由布局系统定位），UpDown 在 setBounds 中手动定位到右侧。
 * 值/范围操作通过 UpDown 的 hwnd 进行（UDM_SETPOS/UDM_GETPOS/UDM_SETRANGE）。
 */
class SpinBox extends Control
{
    private const ES_AUTOHSCROLL    = 0x0080;
    private const ES_NUMBER         = 0x2000;
    private const WS_TABSTOP        = 0x00010000;
    private const WS_EX_CLIENTEDGE  = 0x00000200;

    // UpDown 样式
    private const UDS_AUTOBUDDY    = 0x0010;
    private const UDS_SETBUDDYINT  = 0x0008;
    private const UDS_ARROWKEYS    = 0x0020;

    /** UpDown 副控件句柄。 */
    private int $updownHwnd = 0;

    /** 值变化回调（无参数）。 */
    public ?\Closure $onChanged = null;

    /**
     * @param Control|Window $parent 父容器或窗口。
     */
    public function __construct(Control|Window $parent)
    {
        parent::__construct($parent);
    }

    protected function create(): void
    {
        // Edit（主 hwnd，由布局定位，显示数值）
        // ES_NUMBER 限制只能输入数字，避免非数字内容
        $this->hwnd = App::platform()->controlCreate(
            'Edit',
            '0',
            self::ES_AUTOHSCROLL | self::ES_NUMBER | self::WS_TABSTOP,
            self::WS_EX_CLIENTEDGE,
            $this->parentHwnd(),
            0
        );

        // UpDown（自动关联 Edit，箭头键支持，自动更新 Edit 文本）
        // UDS_ALIGNRIGHT 让 UpDown 定位在伙伴控件右侧（布局仍由 setBounds 手动控制）
        $this->updownHwnd = App::platform()->controlCreate(
            'msctls_updown32',
            '',
            self::UDS_AUTOBUDDY | self::UDS_SETBUDDYINT | self::UDS_ARROWKEYS,
            0,
            $this->parentHwnd(),
            0
        );
        // 注册 UpDown 以便 WM_VSCROLL 事件分发
        if ($this->updownHwnd !== 0) {
            App::platform()->registerControl($this->updownHwnd, $this);
        }
    }

    /**
     * 重写 setBounds：Edit 占左侧大部分宽度，UpDown 占右侧固定宽度。
     */
    public function setBounds(int $x, int $y, int $width, int $height): void
    {
        $updownWidth = 18;
        $editWidth = max(0, $width - $updownWidth);
        App::platform()->controlSetBounds(
            $this->hwnd, $x, $y, $editWidth, $height
        );
        if ($this->updownHwnd !== 0) {
            App::platform()->controlSetBounds(
                $this->updownHwnd,
                $x + $editWidth,
                $y,
                $updownWidth,
                $height
            );
        }
    }

    /**
     * 设置范围。
     */
    public function setRange(int $min, int $max): void
    {
        App::platform()->controlSetRange($this->updownHwnd, $min, $max);
    }

    /**
     * 设置当前值。
     */
    public function setValue(int $value): void
    {
        App::platform()->controlSetValue($this->updownHwnd, $value);
    }

    /**
     * 获取当前值。
     */
    public function getValue(): int
    {
        return App::platform()->controlGetValue($this->updownHwnd);
    }

    public function destroy(): void
    {
        if ($this->updownHwnd !== 0) {
            App::platform()->controlDestroy($this->updownHwnd);
            App::platform()->unregisterControl($this->updownHwnd);
            $this->updownHwnd = 0;
        }
        parent::destroy();
    }

    /**
     * 偏好高度：Windows 标准输入框高度 23px（SpinBox 主控件为 Edit）。
     *
     * 让布局容器在空间充足时给 SpinBox 分配 23px 高度，避免被拉满整格；
     * 空间不足时回退到均分。
     */
    public function getPreferredHeight(): int
    {
        return 23;
    }

    /**
     * 偏好宽度：0（由容器决定，SpinBox 宽度通常随布局拉伸）。
     */
    public function getPreferredWidth(): int
    {
        return 0;
    }
}
