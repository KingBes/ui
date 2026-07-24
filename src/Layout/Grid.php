<?php
declare(strict_types=1);

namespace Kingbes\Ui\Layout;

use Kingbes\Ui\App;
use Kingbes\Ui\Control;
use Kingbes\Ui\Window;

/**
 * 网格布局容器。
 *
 * 将区域划分为 rows × cols 的网格，子控件按行优先顺序填入单元格。
 * 每个单元格的尺寸为 width/cols × height/rows，余数按行/列分配。
 */
class Grid extends Container
{
    private int $rows;
    private int $cols;

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
     * 执行网格布局。
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

        $i = 0;
        foreach ($this->children as $child) {
            $row = intdiv($i, $this->cols);
            $col = $i % $this->cols;
            if ($row >= $this->rows) {
                break; // 超出网格的子控件不布局
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

            // 使用 setBounds 而非 controlSetBounds，以支持 SpinBox 等重写
            $child->setBounds($cx, $cy, $cw, $ch);
            if ($child instanceof Container) {
                $child->layout(0, 0, $cw, $ch);
            }
            $i++;
        }
    }
}
