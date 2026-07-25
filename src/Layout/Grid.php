<?php
declare(strict_types=1);

namespace Kingbes\Ui\Layout;

use Kingbes\Ui\App;
use Kingbes\Ui\Control;
use Kingbes\Ui\Window;

/**
 * 网格布局容器。
 *
 * 将区域划分为 rows × cols 的网格，子控件按行优先顺序填入单元格（或按
 * add() 指定的 col/row 显式放置）。每个单元格的尺寸为 width/cols ×
 * height/rows，余数按行/列分配。
 *
 * 对齐：每个子控件可通过 add() 的 $align 参数指定在单元格内的对齐方式。
 * ALIGN_FILL（整格拉伸）为显式拉伸语义；ALIGN_CENTER（默认）按 Preferred
 * Size 居中，避免按钮被压扁、输入框被拉满整格。需要拉伸的控件（TextArea/
 * Slider 等）应显式传 ALIGN_FILL。
 */
class Grid extends Container
{
    /** 对齐：拉伸到整格（原行为）。 */
    public const ALIGN_FILL   = 0;
    /** 对齐：按 Preferred Size 居中（宽度/高度无 Preferred 时回退到整格）。 */
    public const ALIGN_CENTER = 1;
    /** 对齐：靠左，宽度用 Preferred（无则整格），垂直居中。 */
    public const ALIGN_LEFT   = 2;
    /** 对齐：靠右，宽度用 Preferred（无则整格），垂直居中。 */
    public const ALIGN_RIGHT  = 3;
    /** 对齐：靠顶，高度用 Preferred（无则整格），水平填满。 */
    public const ALIGN_TOP    = 4;
    /** 对齐：靠底，高度用 Preferred（无则整格），水平填满。 */
    public const ALIGN_BOTTOM = 5;

    private int $rows;
    private int $cols;

    /**
     * 每个子控件的对齐标记（与 $children 一一对应）。
     *
     * @var list<int>
     */
    protected array $alignFlags = [];

    /**
     * 每个子控件的显式单元格位置（与 $children 一一对应）。
     * col/row 为 -1 表示按索引自动放置（行优先）。
     *
     * @var list<array{col:int,row:int}>
     */
    protected array $cellPositions = [];

    /**
     * @param int            $rows   行数（>0）。
     * @param int            $cols   列数（>0）。
     * @param Control|Window $parent 父容器或窗口。
     */
    public function __construct(int $rows, int $cols, Control|Window $parent)
    {
        $this->rows = max(1, $rows);
        $this->cols = max(1, $cols);
        parent::__construct($parent);
    }

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
     * 行数。
     */
    public function getRows(): int
    {
        return $this->rows;
    }

    /**
     * 列数。
     */
    public function getCols(): int
    {
        return $this->cols;
    }

    /**
     * 添加子控件到网格。
     *
     * @param Control $c     子控件。
     * @param int     $col   列索引（-1=按添加顺序自动放置）。
     * @param int     $row   行索引（-1=按添加顺序自动放置）。
     * @param int     $align 对齐方式（默认 ALIGN_CENTER）。需要整格拉伸的
     *                       控件（TextArea/Slider 等）应显式传 ALIGN_FILL。
     */
    public function add(Control $c, int $col = -1, int $row = -1, int $align = self::ALIGN_CENTER): static
    {
        $this->children[] = $c;
        $this->alignFlags[] = $align;
        $this->cellPositions[] = ['col' => $col, 'row' => $row];
        if ($c instanceof self) {
            $c->setToplevel(false);
        }
        return $this;
    }

    /**
     * 移除子控件（同步维护 alignFlags / cellPositions）。
     */
    public function remove(Control $c): static
    {
        $key = array_search($c, $this->children, true);
        if ($key !== false) {
            array_splice($this->children, (int) $key, 1);
            array_splice($this->alignFlags, (int) $key, 1);
            array_splice($this->cellPositions, (int) $key, 1);
        }
        return $this;
    }

    /**
     * 执行网格布局。
     *
     * 分配单元格尺寸后，根据每个子控件的 $align 标记计算实际位置与尺寸：
     *   - ALIGN_FILL：拉伸到整格（原行为）。
     *   - ALIGN_CENTER：按 Preferred Size 居中（无 Preferred 则整格）。
     *   - ALIGN_LEFT/RIGHT：水平靠左/右，宽度用 Preferred（无则整格），垂直居中。
     *   - ALIGN_TOP/BOTTOM：垂直靠顶/底，高度用 Preferred（无则整格），水平填满。
     *
     * 子控件坐标相对于 Container 自身客户区 (0, 0)，而非传入的 $x/$y
     * （详见 Box::layout 的说明）。
     */
    public function layout(int $x, int $y, int $width, int $height): void
    {
        $count = count($this->children);
        if ($count === 0) {
            return;
        }

        $cellWidth = intdiv($width, $this->cols);
        $cellHeight = intdiv($height, $this->rows);
        $colRemainder = $width - $cellWidth * $this->cols;
        $rowRemainder = $height - $cellHeight * $this->rows;

        foreach ($this->children as $i => $child) {
            // 显式 col/row 优先，否则按索引自动放置（行优先）
            $pos = $this->cellPositions[$i] ?? ['col' => -1, 'row' => -1];
            $col = $pos['col'] >= 0 ? $pos['col'] : ($i % $this->cols);
            $row = $pos['row'] >= 0 ? $pos['row'] : intdiv($i, $this->cols);
            if ($row >= $this->rows || $col >= $this->cols) {
                continue; // 超出网格的子控件不布局
            }

            $cw = $cellWidth + ($col < $colRemainder ? 1 : 0);
            $ch = $cellHeight + ($row < $rowRemainder ? 1 : 0);
            $cx = 0;
            // 累加前 col 列的宽度（含余数）
            for ($c = 0; $c < $col; $c++) {
                $cx += $cellWidth + ($c < $colRemainder ? 1 : 0);
            }
            $cy = 0;
            for ($r = 0; $r < $row; $r++) {
                $cy += $cellHeight + ($r < $rowRemainder ? 1 : 0);
            }

            // 按对齐标记计算实际位置与尺寸
            $align = $this->alignFlags[$i] ?? self::ALIGN_FILL;
            $pw = $child->getPreferredWidth();
            $ph = $child->getPreferredHeight();

            if ($align === self::ALIGN_FILL) {
                // 整格拉伸（原行为）
                $aw = $cw;
                $ah = $ch;
                $ax = $cx;
                $ay = $cy;
            } else {
                // 宽度：ALIGN_TOP/BOTTOM 整格；其余用 Preferred（无则整格）
                if ($align === self::ALIGN_TOP || $align === self::ALIGN_BOTTOM) {
                    $aw = $cw;
                } else {
                    $aw = $pw > 0 ? $pw : $cw;
                }
                // 高度：用 Preferred（无则整格）
                $ah = $ph > 0 ? $ph : $ch;

                // 水平位置
                $ax = match ($align) {
                    self::ALIGN_CENTER => $cx + intdiv($cw - $aw, 2),
                    self::ALIGN_RIGHT  => $cx + ($cw - $aw),
                    default            => $cx, // LEFT/TOP/BOTTOM
                };
                // 垂直位置
                $ay = match ($align) {
                    self::ALIGN_TOP    => $cy,
                    self::ALIGN_BOTTOM => $cy + ($ch - $ah),
                    default            => $cy + intdiv($ch - $ah, 2), // CENTER/LEFT/RIGHT
                };
            }

            // 使用 setBounds 而非 controlSetBounds，以支持 SpinBox 等重写
            $child->setBounds($ax, $ay, $aw, $ah);
            if ($child instanceof Container) {
                $child->layout(0, 0, $aw, $ah);
            }
        }
    }
}
