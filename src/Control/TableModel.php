<?php
declare(strict_types=1);

namespace Kingbes\Ui\Control;

use Kingbes\Ui\Graphics\Color;
use Kingbes\Ui\Graphics\Image;

/**
 * Table 表格数据源接口（MVC）。
 *
 * 用户通过实现本接口为 Table 控件提供数据，避免一次性加载大数据集
 * 到内存。Table 控件采用 Win32 ListView 虚拟模式（LVS_OWNERDATA），
 * 仅在需要显示某行时回调 {@see getCellValue()} 取数据。
 *
 * 典型用法：
 *   $model = new class implements TableModel {
 *       public function getRowCount(): int { return 10000; }
 *       public function getColumnCount(): int { return 3; }
 *       public function getCellValue(int $row, int $col): string {
 *           return "({$row},{$col})";
 *       }
 *   };
 *   $table = new Table($parent);
 *   $table->setModel($model);
 *
 * 数据变更时调用 $table->refresh() 或 $table->refreshRow($row) 通知视图刷新。
 *
 * 列类型（可选）：
 *   通过 Table::setColumnType($col, $type) 设置列类型，决定该列单元格的
 *   自绘方式。文本/图像列由系统绘制，checkbox/progress/color/button 列由
 *   平台 NM_CUSTOMDRAW CDDS_SUBITEM 阶段自绘。
 *
 *   可选方法按列类型实现：
 *     - TYPE_IMAGE    → getCellImage(int $row, int $col): ?Image
 *     - TYPE_CHECKBOX → getCellCheckbox(int $row, int $col): ?bool
 *     - TYPE_PROGRESS → getCellProgress(int $row, int $col): ?int   (0-100)
 *     - TYPE_COLOR    → getCellColor(int $row, int $col): ?Color
 *     - TYPE_BUTTON   → getCellButton(int $row, int $col): string   (按钮文本)
 *
 *   未实现对应方法的列返回安全默认值（无图像/未勾选/0 进度/灰色/空文本）。
 *
 * 图像列示例：
 *   $model = new class implements TableModel {
 *       public function getRowCount(): int { return 5; }
 *       public function getColumnCount(): int { return 2; }
 *       public function getCellValue(int $row, int $col): string { ... }
 *       public function getCellImage(int $row, int $col): ?Image {
 *           return $col === 0 ? $this->icons[$row] : null;
 *       }
 *   };
 *   $table->setColumnType(0, Table::TYPE_IMAGE);
 *
 * Checkbox 列示例：
 *   $model = new class implements TableModel {
 *       // ... getRowCount / getColumnCount / getCellValue ...
 *       private array $checked = [true, false, true];
 *       public function getCellCheckbox(int $row, int $col): ?bool {
 *           return $col === 1 ? $this->checked[$row] : null;
 *       }
 *       public function setChecked(int $row, bool $v): void {
 *           $this->checked[$row] = $v;
 *       }
 *   };
 *   $table->setColumnType(1, Table::TYPE_CHECKBOX);
 *   $table->onCellCheckboxToggle = function (int $row, int $col, bool $checked) use ($model) {
 *       $model->setChecked($row, $checked);
 *   };
 *
 * Button 列示例：
 *   $table->setColumnType(2, Table::TYPE_BUTTON);
 *   $table->onCellButtonClick = function (int $row, int $col) {
 *       echo "点击了行 {$row} 的按钮\n";
 *   };
 */
interface TableModel
{
    /**
     * 行数。
     */
    public function getRowCount(): int;

    /**
     * 列数（需与 Table::setColumns 设置的列数一致）。
     */
    public function getColumnCount(): int;

    /**
     * 获取指定单元格的显示文本。
     *
     * 对于非文本列（TYPE_IMAGE/CHECKBOX/PROGRESS/COLOR/BUTTON），此方法
     * 仍需实现但返回值会被忽略（系统不会绘制文本，由 NM_CUSTOMDRAW 自绘）。
     *
     * @param int $row 行索引（0-based）。
     * @param int $col 列索引（0-based）。
     * @return string 单元格文本。
     */
    public function getCellValue(int $row, int $col): string;

    /**
     * 获取指定单元格的图像（可选方法，用于 TYPE_IMAGE 列）。
     *
     * 实现本方法后，Table 控件会在单元格左侧显示图标。返回 null 表示
     * 该单元格无图像。同一 Image 对象在多次调用时会被自动去重注册。
     *
     * 本方法为可选扩展，不实现时 Table 通过 method_exists 检测并跳过，
     * 因此接口中不强制声明。建议实现类按需添加。
     *
     * @param int $row 行索引（0-based）。
     * @param int $col 列索引（0-based）。
     * @return Image|null 图像对象，null 表示无图像。
     */
    // public function getCellImage(int $row, int $col): ?Image;

    /**
     * 获取指定单元格的复选框状态（可选方法，用于 TYPE_CHECKBOX 列）。
     *
     * 返回 true 表示勾选，false 表示未勾选，null 表示无状态（默认未勾选）。
     * 用户点击 checkbox 时，Table 会触发 onCellCheckboxToggle 回调，
     * 回调中应更新 model 数据并刷新该行。
     *
     * @param int $row 行索引（0-based）。
     * @param int $col 列索引（0-based）。
     * @return bool|null 勾选状态，null 表示无状态。
     */
    // public function getCellCheckbox(int $row, int $col): ?bool;

    /**
     * 获取指定单元格的进度值（可选方法，用于 TYPE_PROGRESS 列）。
     *
     * 返回 0-100 的整数表示进度百分比。超出范围的值会被自动夹取。
     * 未实现时默认显示 0%。
     *
     * @param int $row 行索引（0-based）。
     * @param int $col 列索引（0-based）。
     * @return int|null 进度值（0-100），null 表示 0。
     */
    // public function getCellProgress(int $row, int $col): ?int;

    /**
     * 获取指定单元格的颜色块（可选方法，用于 TYPE_COLOR 列）。
     *
     * 返回 Color 对象表示该单元格显示的色块颜色。null 显示灰色占位。
     *
     * @param int $row 行索引（0-based）。
     * @param int $col 列索引（0-based）。
     * @return Color|null 颜色对象，null 表示灰色。
     */
    // public function getCellColor(int $row, int $col): ?Color;

    /**
     * 获取指定单元格的按钮文本（可选方法，用于 TYPE_BUTTON 列）。
     *
     * 返回非空字符串作为按钮显示文本。用户点击按钮时，Table 会触发
     * onCellButtonClick 回调。未实现时显示空按钮。
     *
     * @param int $row 行索引（0-based）。
     * @param int $col 列索引（0-based）。
     * @return string 按钮文本。
     */
    // public function getCellButton(int $row, int $col): string;
}
