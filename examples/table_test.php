<?php
declare(strict_types=1);

/**
 * Table 表格控件测试示例。
 *
 * 运行：php -d ffi.enable=true examples/table_test.php
 *
 * 覆盖：
 *   - MVC 虚拟模式：TableModel 按需取数据（万级行不占内存）
 *   - 列定义：setColumns
 *   - 行选择：onSelectionChanged
 *   - 行双击：onRowDoubleClicked
 *   - 程序化选择：select($row) / getSelectedRow()
 *   - 行级着色：setRowBackgroundColor / setRowTextColor
 *   - 单行刷新：refreshRow（数据变化时）
 *   - 全量刷新：refresh（行数变化时）
 *
 * 交互：
 *   - 点击行：状态栏显示选中行内容
 *   - 双击行：控制台打印双击事件
 *   - 「选中第 5 行」按钮：程序化选中
 *   - 「取消选中」按钮：清除选中
 *   - 「刷新首行」按钮：修改 model 数据后通知视图
 *   - 「奇数行着色」按钮：演示 NM_CUSTOMDRAW 行级着色
 *   - 「清除着色」按钮：恢复默认
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Kingbes\Ui\App;
use Kingbes\Ui\Window;
use Kingbes\Ui\Layout\VBox;
use Kingbes\Ui\Layout\HBox;
use Kingbes\Ui\Control\Table;
use Kingbes\Ui\Control\TableModel;
use Kingbes\Ui\Control\Button;
use Kingbes\Ui\Control\Label;

echo "========================================\n";
echo " PHP UI Table 表格测试 📊\n";
echo "========================================\n";

// ============================================================
// 1. 数据源：实现 TableModel 接口
// ============================================================
$sampleData = [
    ['1001', '张三', '工程师', '北京', '18000'],
    ['1002', '李四', '设计师', '上海', '15000'],
    ['1003', '王五', '产品经理', '深圳', '22000'],
    ['1004', '赵六', '测试工程师', '杭州', '13000'],
    ['1005', '钱七', '架构师', '北京', '35000'],
    ['1006', '孙八', '运维工程师', '成都', '12000'],
    ['1007', '周九', '前端工程师', '广州', '16000'],
    ['1008', '吴十', '后端工程师', '深圳', '19000'],
    ['1009', '郑十一', '数据分析师', '上海', '17000'],
    ['1010', '王十二', '安全工程师', '北京', '21000'],
    ['1011', '冯十三', 'DBA', '杭州', '23000'],
    ['1012', '陈十四', '项目经理', '深圳', '28000'],
    ['1013', '褚十五', '技术总监', '北京', '45000'],
    ['1014', '卫十六', 'CTO', '上海', '80000'],
    ['1015', '蒋十七', '运维总监', '深圳', '38000'],
];

$model = new class($sampleData) implements TableModel {
    /** @var list<list<string>> */
    private array $data;

    /**
     * @param list<list<string>> $data
     */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function getRowCount(): int
    {
        return count($this->data);
    }

    public function getColumnCount(): int
    {
        return 5;
    }

    public function getCellValue(int $row, int $col): string
    {
        return $this->data[$row][$col] ?? '';
    }

    /**
     * 内部方法：修改单元格数据（演示单行刷新）。
     */
    public function setCellValue(int $row, int $col, string $value): void
    {
        if (isset($this->data[$row])) {
            $this->data[$row][$col] = $value;
        }
    }

    /**
     * 内部方法：获取整行数据。
     *
     * @return list<string>
     */
    public function getRow(int $row): array
    {
        return $this->data[$row] ?? [];
    }
};

// ============================================================
// 2. 创建主窗口
// ============================================================
$win = new Window("PHP UI Table 测试 - MVC 虚拟模式 📊 中文", 820, 560);
$win->onClose = fn() => App::quit();

$win->setMargined(12);

$root = new VBox($win);
$root->setPadding(6);

$status = new Label($root, "就绪 - 点击/双击表格行测试", Label::ALIGN_CENTER);
$root->add($status);

// ============================================================
// 3. 创建 Table
// ============================================================
$tableRow = new HBox($root);
$root->add($tableRow);
$tableRow->add(new Label($tableRow, "员工表:"));

$table = new Table($tableRow);
$tableRow->add($table);

$table->setColumns(['工号', '姓名', '职位', '城市', '薪资(元)'], 110);
$table->setModel($model);

$selectionLabel = new Label($tableRow, "选中: (无)");
$tableRow->add($selectionLabel);

$table->onSelectionChanged = function (int $row) use ($model, $selectionLabel, $status): void {
    if ($row < 0) {
        $selectionLabel->setText("选中: (无)");
        $status->setText("取消选中");
        echo "[事件] onSelectionChanged row=-1\n";
        return;
    }
    $rowData = $model->getRow($row);
    $text = implode(' | ', $rowData);
    $selectionLabel->setText("选中: #{$row} {$rowData[1]}");
    $status->setText("选中行: {$text}");
    echo "[事件] onSelectionChanged row={$row} ({$rowData[1]} {$rowData[2]})\n";
};

$table->onRowDoubleClicked = function (int $row) use ($model, $status): void {
    $rowData = $model->getRow($row);
    $status->setText("双击行 #{$row}: {$rowData[1]} - {$rowData[2]}");
    echo "[事件] onRowDoubleClicked row={$row} ({$rowData[1]})\n";
};

// ============================================================
// 4. 操作按钮
// ============================================================
$btnRow = new HBox($root);
$root->add($btnRow);
$btnRow->add(new Label($btnRow, "操作:"));

// 选中第 5 行
$selectBtn = new Button($btnRow, "选中第 5 行");
$btnRow->add($selectBtn);
$selectBtn->onClick = function () use ($table, $status): void {
    $table->select(4); // 0-based
    $status->setText("程序化选中第 5 行");
    echo "[按钮] 选中第 5 行（index=4）\n";
};

// 取消选中
$clearSelBtn = new Button($btnRow, "取消选中");
$btnRow->add($clearSelBtn);
$clearSelBtn->onClick = function () use ($table, $status): void {
    $table->select(-1);
    $status->setText("取消选中");
    echo "[按钮] 取消选中\n";
};

// 刷新首行
$refreshBtn = new Button($btnRow, "修改首行");
$btnRow->add($refreshBtn);
$refreshBtn->onClick = function () use ($model, $table, $status): void {
    $model->setCellValue(0, 1, '张三（已修改）');
    $model->setCellValue(0, 4, '99999');
    $table->refreshRow(0);
    $status->setText("首行数据已修改并刷新");
    echo "[按钮] 修改首行 → refreshRow(0)\n";
};

// 奇数行着色
$colorBtn = new Button($btnRow, "奇数行着色");
$btnRow->add($colorBtn);
$colored = false;
$colorBtn->onClick = function () use ($table, &$colored, $status): void {
    $colored = !$colored;
    if ($colored) {
        // 奇数行浅黄背景，偶数行保留默认
        for ($i = 0; $i < 15; $i++) {
            if ($i % 2 === 1) {
                // COLORREF: 0x00BBGGRR = 浅黄 0x00C8FFFF
                $table->setRowBackgroundColor($i, 0x00C8FFFF);
            }
        }
        // 第 5 行（架构师）特殊：红色背景白字
        $table->setRowBackgroundColor(4, 0x000000C8); // 浅红
        $table->setRowTextColor(4, 0x00FFFFFF); // 白
        $status->setText("已应用行级着色（奇数行浅黄、第 5 行红底白字）");
        echo "[按钮] 行级着色 ON\n";
    } else {
        for ($i = 0; $i < 15; $i++) {
            $table->setRowBackgroundColor($i, null);
            $table->setRowTextColor($i, null);
        }
        $status->setText("已清除所有行级着色");
        echo "[按钮] 行级着色 OFF\n";
    }
};

// 获取选中行
$getBtn = new Button($btnRow, "获取选中");
$btnRow->add($getBtn);
$getBtn->onClick = function () use ($table, $status): void {
    $row = $table->getSelectedRow();
    $status->setText("当前选中行: " . ($row >= 0 ? "#{$row}" : "(无)"));
    echo "[按钮] getSelectedRow() = {$row}\n";
};

// 状态栏
$root->add($status);

$win->setChild($root);
$win->show();

echo "窗口已创建。交互提示：\n";
echo "  - 点击行：触发 onSelectionChanged\n";
echo "  - 双击行：触发 onRowDoubleClicked\n";
echo "  - 「选中第 5 行」/「取消选中」测试程序化选择\n";
echo "  - 「修改首行」测试单行刷新\n";
echo "  - 「奇数行着色」测试 NM_CUSTOMDRAW 行级着色\n";

if (getenv('PHP_UI_AUTO_EXIT') === '1') {
    echo "PHP_UI_AUTO_EXIT=1，运行自动测试序列\n";
    // 1秒后程序化选中第5行
    App::timer(1000, function () use ($table): void {
        echo ">>> 自动测试: 选中第 5 行\n";
        $table->select(4);
    });
    // 2秒后读取选中
    App::timer(2000, function () use ($table): void {
        $row = $table->getSelectedRow();
        echo ">>> 自动测试: getSelectedRow() = {$row}\n";
        if ($row !== 4) {
            echo "[失败] 预期 4，实际 {$row}\n";
        } else {
            echo "[通过] 选中行读取正确\n";
        }
    });
    // 3秒后修改首行
    App::timer(3000, function () use ($model, $table): void {
        echo ">>> 自动测试: 修改首行\n";
        $model->setCellValue(0, 1, '张三（自动修改）');
        $table->refreshRow(0);
    });
    // 4秒后着色
    App::timer(4000, function () use ($table): void {
        echo ">>> 自动测试: 奇数行着色\n";
        for ($i = 0; $i < 15; $i++) {
            if ($i % 2 === 1) {
                $table->setRowBackgroundColor($i, 0x00C8FFFF);
            }
        }
    });
    App::timer(6000, function (): void {
        echo "自动退出触发\n";
        App::quit();
    });
}

echo "进入事件循环（关闭窗口退出）...\n";
App::run();

echo "已退出\n";
