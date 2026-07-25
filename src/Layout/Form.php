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
 * 对齐：字段控件可通过 addRow() 的 $align 参数指定在字段列内的对齐方式。
 * ALIGN_FILL（整格拉伸）为显式拉伸语义；ALIGN_CENTER（默认）按 Preferred
 * Size 居中，避免输入框被拉满整格。需要拉伸的控件（TextArea/Slider 等）
 * 应显式传 ALIGN_FILL。标签列始终填满。
 *
 * 使用 addRow() 添加行；add() 仍可用（控件追加到 children），
 * 但不参与 Form 的两列布局。
 */
class Form extends Container
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

    /**
     * 标签列占宽度的比例（1/labelRatio）。
     */
    private int $labelRatio = 3;

    /**
     * @var list<array{label: Control, field: Control, align: int}>
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
     * 供 layout() 使用。$align 仅作用于字段控件；标签列始终填满。
     *
     * @param Control $label 标签控件。
     * @param Control $field 字段控件。
     * @param int     $align 字段控件在字段列内的对齐方式（默认 ALIGN_CENTER）。
     *                       需要整格拉伸的控件（TextArea/Slider 等）应显式传
     *                       ALIGN_FILL。
     */
    public function addRow(Control $label, Control $field, int $align = self::ALIGN_CENTER): static
    {
        $this->children[] = $label;
        $this->children[] = $field;
        $this->rows[] = ['label' => $label, 'field' => $field, 'align' => $align];
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
     * @return list<array{label: Control, field: Control, align: int}>
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
     * 标签列始终填满；字段列根据 addRow() 记录的 $align 计算实际位置与尺寸：
     *   - ALIGN_FILL：拉伸到整列（原行为）。
     *   - ALIGN_CENTER：按 Preferred Size 居中（无 Preferred 则整列）。
     *   - ALIGN_LEFT/RIGHT：水平靠左/右，宽度用 Preferred（无则整列），垂直居中。
     *   - ALIGN_TOP/BOTTOM：垂直靠顶/底，高度用 Preferred（无则整列），水平填满。
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
            $align = $row['align'];
            $field = $row['field'];

            // 标签列：始终填满（用 setBounds 支持 SpinBox 等重写）
            $row['label']->setBounds(0, $cy, $labelWidth, $h);
            if ($row['label'] instanceof Container) {
                $row['label']->layout(0, 0, $labelWidth, $h);
            }

            // 字段列：按 $align 计算实际位置与尺寸
            $fieldX = $labelWidth;
            $pw = $field->getPreferredWidth();
            $ph = $field->getPreferredHeight();

            if ($align === self::ALIGN_FILL) {
                // 整列拉伸（原行为）
                $aw = $fieldWidth;
                $ah = $h;
                $ax = $fieldX;
                $ay = $cy;
            } else {
                // 宽度：ALIGN_TOP/BOTTOM 整列；其余用 Preferred（无则整列）
                if ($align === self::ALIGN_TOP || $align === self::ALIGN_BOTTOM) {
                    $aw = $fieldWidth;
                } else {
                    $aw = $pw > 0 ? $pw : $fieldWidth;
                }
                // 高度：用 Preferred（无则整行高）
                $ah = $ph > 0 ? $ph : $h;

                // 水平位置
                $ax = match ($align) {
                    self::ALIGN_CENTER => $fieldX + intdiv($fieldWidth - $aw, 2),
                    self::ALIGN_RIGHT  => $fieldX + ($fieldWidth - $aw),
                    default            => $fieldX, // LEFT/TOP/BOTTOM
                };
                // 垂直位置
                $ay = match ($align) {
                    self::ALIGN_TOP    => $cy,
                    self::ALIGN_BOTTOM => $cy + ($h - $ah),
                    default            => $cy + intdiv($h - $ah, 2), // CENTER/LEFT/RIGHT
                };
            }

            $field->setBounds($ax, $ay, $aw, $ah);
            if ($field instanceof Container) {
                $field->layout(0, 0, $aw, $ah);
            }
            $cy += $h;
            $i++;
        }
    }
}
