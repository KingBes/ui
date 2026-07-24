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
 */
class Slider extends Control
{
    private const TBS_AUTOTICKS = 0x0001;
    private const WS_TABSTOP     = 0x00010000;

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
