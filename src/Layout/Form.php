<?php
declare(strict_types=1);

namespace Kingbes\Ui\Layout;

use Kingbes\Ui\App;
use Kingbes\Ui\Control;
use Kingbes\Ui\Window;

/**
 * 表单布局容器（标签-控件两列）。
 *
 * 每行包含一个标签控件与一个字段控件，标签列占宽度的 1/3，
 * 字段列占 2/3。行高均分，余数按行分配。
 *
 * 使用 addRow() 添加行；add() 仍可用（控件追加到 children），
 * 但不参与 Form 的两列布局。
 */
class Form extends Container
{
    /**
     * 标签列占宽度的比例（1/labelRatio）。
     */
    private int $labelRatio = 3;

    /**
     * @var list<array{label: Control, field: Control}>
     */
    private array $rows = [];

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
            'Container',
            '',
            0,
            0,
            $this->parentHwnd(),
            0
        );
    }

    /**
     * 添加一行（标签 + 字段）。
     *
     * 两个控件同时加入 $children（用于 destroy/GC），并记录到 $rows
     * 供 layout() 使用。
     */
    public function addRow(Control $label, Control $field): static
    {
        $this->children[] = $label;
        $this->children[] = $field;
        $this->rows[] = ['label' => $label, 'field' => $field];
        if ($label instanceof self) {
            $label->setToplevel(false);
        }
        if ($field instanceof self) {
            $field->setToplevel(false);
        }
        return $this;
    }

    /**
     * 获取行列表。
     *
     * @return list<array{label: Control, field: Control}>
     */
    public function getRows(): array
    {
        return $this->rows;
    }

    /**
     * 设置标签列占比（如 3 表示占 1/3）。
     */
    public function setLabelRatio(int $ratio): static
    {
        if ($ratio < 2) {
            $ratio = 2;
        }
        $this->labelRatio = $ratio;
        return $this;
    }

    /**
     * 执行表单布局：标签-字段两列。
     *
     * 子控件坐标相对于 Container 自身客户区 (0, 0)，而非传入的 $x/$y
     * （详见 Box::layout 的说明）。
     */
    public function layout(int $x, int $y, int $width, int $height): void
    {
        $count = count($this->rows);
        if ($count === 0) {
            return;
        }

        $rowHeight = intdiv($height, $count);
        $remainder = $height - $rowHeight * $count;
        $labelWidth = intdiv($width, $this->labelRatio);
        $fieldWidth = $width - $labelWidth;

        $cy = 0;
        $i = 0;
        foreach ($this->rows as $row) {
            $h = $rowHeight + ($i < $remainder ? 1 : 0);
            // 标签列（用 setBounds 支持 SpinBox 等重写）
            $row['label']->setBounds(0, $cy, $labelWidth, $h);
            // 字段列
            $fieldX = $labelWidth;
            $row['field']->setBounds($fieldX, $cy, $fieldWidth, $h);
            // 递归布局嵌套容器：传本地坐标 (0, 0)
            if ($row['label'] instanceof Container) {
                $row['label']->layout(0, 0, $labelWidth, $h);
            }
            if ($row['field'] instanceof Container) {
                $row['field']->layout(0, 0, $fieldWidth, $h);
            }
            $cy += $h;
            $i++;
        }
    }
}
