<?php
declare(strict_types=1);

namespace Kingbes\Ui\Layout;

use Kingbes\Ui\App;
use Kingbes\Ui\Control;
use Kingbes\Ui\Window;

/**
 * 盒式布局容器。
 *
 * - vertical=true  → VBox：子控件纵向排列，按 Preferred Height + stretch 分配高度，宽度铺满。
 * - vertical=false → HBox：子控件横向排列，按 Preferred Width + stretch 分配宽度，高度铺满。
 *
 * 分配算法：空间充足时 non-stretch 子控件取 Preferred Size，stretch/Preferred=0
 * 子控件瓜分剩余；空间不足时回退到均分（避免空隙）。详见 layout()。
 * 嵌套容器（Container 子类）会被递归布局。
 *
 * padding：子控件之间的间距像素数（默认 0）。N 个子控件之间有
 * (N-1) 个间距，总间距 = padding * max(0, N-1)，从可用空间中扣除。
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
     * 子控件 stretch 标记列表（与 $children 一一对应）。
     *
     * true 表示该子控件在空间充足时瓜分剩余空间；false 表示按
     * Preferred Size 分配（Preferred=0 时作为 flex 参与剩余瓜分）。
     *
     * @var list<bool>
     */
    protected array $stretchFlags = [];

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
     * 添加子控件（重写父类以支持 stretch 标记）。
     *
     * @param Control $c       子控件。
     * @param bool    $stretch true=空间充足时瓜分剩余空间；false=按 Preferred
     *                         Size 分配（Preferred=0 时仍作为 flex 参与瓜分）。
     */
    public function add(Control $c, bool $stretch = false): static
    {
        $this->children[] = $c;
        $this->stretchFlags[] = $stretch;
        if ($c instanceof self) {
            $c->setToplevel(false);
        }
        return $this;
    }

    /**
     * 移除子控件（同步维护 stretchFlags）。
     */
    public function remove(Control $c): static
    {
        $key = array_search($c, $this->children, true);
        if ($key !== false) {
            array_splice($this->children, (int) $key, 1);
            array_splice($this->stretchFlags, (int) $key, 1);
        }
        return $this;
    }

    /**
     * 执行盒式布局：按 Preferred Size + stretch 分配子控件尺寸。
     *
     * 分配算法（主轴方向，VBox=高度 / HBox=宽度）：
     *   1. 计算 non-stretch 且 Preferred>0 子控件的 Preferred 总和。
     *   2. 若 Preferred 总和 > usable（空间不足）：回退到均分（避免空隙）。
     *   3. 若 Preferred 总和 <= usable（空间充足）：
     *      - non-stretch 且 Preferred>0 的子控件分到 Preferred Size。
     *      - 其余子控件（stretch 或 Preferred=0）瓜分剩余空间。
     *      - 若无 flex 子控件，剩余空间留白在末尾。
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
            // VBox：分配高度
            $usable = $height - $totalGap;
            $sizes = $this->computeSizes($usable);
            $cy = 0;
            $i = 0;
            foreach ($this->children as $child) {
                $h = $sizes[$i];
                $child->setBounds(0, $cy, $width, $h);
                // 嵌套容器递归布局：传本地坐标 (0, 0)
                if ($child instanceof Container) {
                    $child->layout(0, 0, $width, $h);
                }
                $cy += $h + $this->padding;
                $i++;
            }
        } else {
            // HBox：分配宽度
            $usable = $width - $totalGap;
            $sizes = $this->computeSizes($usable);
            $cx = 0;
            $i = 0;
            foreach ($this->children as $child) {
                $w = $sizes[$i];
                $child->setBounds($cx, 0, $w, $height);
                if ($child instanceof Container) {
                    $child->layout(0, 0, $w, $height);
                }
                $cx += $w + $this->padding;
                $i++;
            }
        }
    }

    /**
     * 计算每个子控件在主轴方向上的尺寸。
     *
     * @param int $usable 可用空间（已扣除间距）。
     * @return list<int> 每个子控件的尺寸（与 $children 一一对应）。
     */
    private function computeSizes(int $usable): array
    {
        $count = count($this->children);
        if ($count === 0) {
            return [];
        }
        if ($usable < 0) {
            $usable = 0;
        }

        // 主轴方向的 Preferred Size（VBox=Height，HBox=Width）
        $preferredOf = [];
        foreach ($this->children as $i => $child) {
            $preferredOf[$i] = $this->vertical
                ? $child->getPreferredHeight()
                : $child->getPreferredWidth();
        }

        // 计算 non-stretch 且 Preferred>0 子控件的 Preferred 总和
        $preferredTotal = 0;
        foreach ($preferredOf as $i => $preferred) {
            $stretch = $this->stretchFlags[$i] ?? false;
            if (!$stretch && $preferred > 0) {
                $preferredTotal += $preferred;
            }
        }

        // 空间不足：回退到均分（避免空隙）
        if ($preferredTotal > $usable) {
            $base = intdiv($usable, $count);
            $remainder = $usable - $base * $count;
            $sizes = [];
            for ($i = 0; $i < $count; $i++) {
                $sizes[] = $base + ($i < $remainder ? 1 : 0);
            }
            return $sizes;
        }

        // 空间充足：固定子控件得 Preferred，flex 子控件瓜分剩余
        $remaining = $usable - $preferredTotal;
        $flexIndices = [];
        foreach ($preferredOf as $i => $preferred) {
            $stretch = $this->stretchFlags[$i] ?? false;
            if ($stretch || $preferred <= 0) {
                $flexIndices[] = $i;
            }
        }
        $flexCount = count($flexIndices);

        $sizes = array_fill(0, $count, 0);
        // 固定子控件：non-stretch 且 Preferred>0
        foreach ($preferredOf as $i => $preferred) {
            $stretch = $this->stretchFlags[$i] ?? false;
            if (!$stretch && $preferred > 0) {
                $sizes[$i] = $preferred;
            }
        }
        // flex 子控件瓜分剩余空间
        if ($flexCount > 0) {
            $flexSize = intdiv($remaining, $flexCount);
            $flexRemainder = $remaining - $flexSize * $flexCount;
            foreach ($flexIndices as $idx => $i) {
                $sizes[$i] = $flexSize + ($idx < $flexRemainder ? 1 : 0);
            }
        }
        // 若 flexCount == 0，剩余空间留白在末尾（sizes 已为 Preferred，不分配 remaining）
        return $sizes;
    }
}
