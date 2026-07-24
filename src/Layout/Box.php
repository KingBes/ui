<?php
declare(strict_types=1);

namespace Kingbes\Ui\Layout;

use Kingbes\Ui\App;
use Kingbes\Ui\Control;
use Kingbes\Ui\Window;

/**
 * 盒式布局容器。
 *
 * - vertical=true  → VBox：子控件纵向均分高度，宽度铺满。
 * - vertical=false → HBox：子控件横向均分宽度，高度铺满。
 *
 * 整除余数按像素分配给前若干个子控件，避免子控件间出现空隙。
 * 嵌套容器（Container 子类）会被递归布局。
 *
 * padding：子控件之间的间距像素数（默认 0）。N 个子控件之间有
 * (N-1) 个间距，总间距 = padding * max(0, N-1)，从可用空间中扣除
 * 后再均分给各子控件。
 */
class Box extends Container
{
    /**
     * 是否为垂直布局。
     */
    private bool $vertical;

    /**
     * 子控件间距（像素）。
     */
    protected int $padding = 0;

    /**
     * @param bool             $vertical true=VBox，false=HBox。
     * @param Control|Window   $parent   父容器或窗口。
     */
    public function __construct(bool $vertical, Control|Window $parent)
    {
        $this->vertical = $vertical;
        parent::__construct($parent);
    }

    /**
     * 创建原生容器控件。
     */
    protected function create(): void
    {
        $this->hwnd = App::platform()->controlCreate(
            'Container',
            '',
            0,
            0,
            $this->parentHwnd(),
            0
        );
    }

    /**
     * 是否垂直布局。
     */
    public function isVertical(): bool
    {
        return $this->vertical;
    }

    /**
     * 设置子控件间距（像素）。
     *
     * @param int $padding 间距，<0 视为 0。
     */
    public function setPadding(int $padding): static
    {
        $this->padding = max(0, $padding);
        return $this;
    }

    /**
     * 获取子控件间距。
     */
    public function getPadding(): int
    {
        return $this->padding;
    }

    /**
     * 执行盒式布局：均分子控件尺寸（扣除间距后均分）。
     *
     * 子控件坐标相对于 Container 自身客户区 (0, 0)，而非传入的 $x/$y
     * （$x/$y 描述的是 Container 在父级中的位置，由父级 setBounds 设置
     * Container 自身时使用，layout 内部不应再叠加该偏移，否则嵌套容器
     * 的子控件坐标会超出 Container 客户区被裁剪）。
     */
    public function layout(int $x, int $y, int $width, int $height): void
    {
        $count = count($this->children);
        if ($count === 0) {
            return;
        }

        // N 个子控件间有 (N-1) 个间距
        $totalGap = $this->padding * max(0, $count - 1);

        if ($this->vertical) {
            // VBox：均分高度
            $usable = $height - $totalGap;
            $childSize = intdiv($usable, $count);
            $remainder = $usable - $childSize * $count;
            $cy = 0;
            $i = 0;
            foreach ($this->children as $child) {
                $h = $childSize + ($i < $remainder ? 1 : 0);
                $child->setBounds(0, $cy, $width, $h);
                // 嵌套容器递归布局：传本地坐标 (0, 0)
                if ($child instanceof Container) {
                    $child->layout(0, 0, $width, $h);
                }
                $cy += $h + $this->padding;
                $i++;
            }
        } else {
            // HBox：均分宽度
            $usable = $width - $totalGap;
            $childSize = intdiv($usable, $count);
            $remainder = $usable - $childSize * $count;
            $cx = 0;
            $i = 0;
            foreach ($this->children as $child) {
                $w = $childSize + ($i < $remainder ? 1 : 0);
                $child->setBounds($cx, 0, $w, $height);
                if ($child instanceof Container) {
                    $child->layout(0, 0, $w, $height);
                }
                $cx += $w + $this->padding;
                $i++;
            }
        }
    }
}
