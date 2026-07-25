<?php
declare(strict_types=1);

namespace Kingbes\Ui\Control;

use Kingbes\Ui\App;
use Kingbes\Ui\Control;
use Kingbes\Ui\Window;

/**
 * 分隔线控件。
 *
 * 基于 Win32 "Static" 类（SS_ETCHEDHORZ 水平蚀刻线 / SS_ETCHEDVERT 垂直蚀刻线）。
 * 用于在布局中视觉分隔控件组。
 */
class Separator extends Control
{
    /** SS_ETCHEDHORZ：水平蚀刻线 */
    private const SS_ETCHEDHORZ = 0x0010;
    /** SS_ETCHEDVERT：垂直蚀刻线 */
    private const SS_ETCHEDVERT = 0x0011;
    /** SS_SUNKEN：凹陷边框（备选样式） */
    private const SS_SUNKEN     = 0x1000;

    /**
     * 方向枚举值。
     *
     * @param Control|Window $parent     父容器或窗口。
     * @param bool           $horizontal true=水平线，false=垂直线。
     */
    public function __construct(Control|Window $parent, bool $horizontal = true)
    {
        $this->horizontal = $horizontal;
        parent::__construct($parent);
    }

    private bool $horizontal;

    protected function create(): void
    {
        $style = $this->horizontal ? self::SS_ETCHEDHORZ : self::SS_ETCHEDVERT;
        $this->hwnd = App::platform()->controlCreate(
            'Static',
            '',
            $style,
            0,
            $this->parentHwnd(),
            0
        );
    }

    /**
     * 是否为水平方向。
     */
    public function isHorizontal(): bool
    {
        return $this->horizontal;
    }
}
