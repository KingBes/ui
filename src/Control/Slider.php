<?php
declare(strict_types=1);

namespace Kingbes\Ui\Control;

use Kingbes\Ui\App;
use Kingbes\Ui\Control;
use Kingbes\Ui\Window;

/**
 * 滑块控件。
 *
 * 基于 Win32 "msctls_trackbar32" 类（TBS_AUTOTICKS 自动刻度）。
 * 拖动滑块或点击轨道时触发 onChanged 回调（WM_HSCROLL）。
 * 拖动结束/点击释放后触发 onReleased 回调（SB_ENDSCROLL）。
 */
class Slider extends Control
{
    private const TBS_AUTOTICKS = 0x0001;
    private const WS_TABSTOP     = 0x00010000;

    /** 值变化回调（无参数）。拖动过程或键盘方向键触发。 */
    public ?\Closure $onChanged = null;

    /** 拖动/操作结束回调（无参数）。WM_HSCROLL 的 SB_ENDSCROLL 通知触发。 */
    public ?\Closure $onReleased = null;

    /**
     * @param Control|Window $parent 父容器或窗口。
     */
    public function __construct(Control|Window $parent)
    {
        parent::__construct($parent);
    }

    protected function create(): void
    {
        $this->hwnd = App::platform()->controlCreate(
            'msctls_trackbar32',
            '',
            self::TBS_AUTOTICKS | self::WS_TABSTOP,
            0,
            $this->parentHwnd(),
            0
        );
    }

    /**
     * 设置范围。
     */
    public function setRange(int $min, int $max): void
    {
        App::platform()->controlSetRange($this->hwnd, $min, $max);
    }

    /**
     * 设置当前值。
     */
    public function setValue(int $value): void
    {
        App::platform()->controlSetValue($this->hwnd, $value);
    }

    /**
     * 获取当前值。
     */
    public function getValue(): int
    {
        return App::platform()->controlGetValue($this->hwnd);
    }
}
