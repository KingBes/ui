<?php
declare(strict_types=1);

/**
 * 图片/图标支持综合测试示例。
 *
 * 运行：php -d ffi.enable=true examples/image_test.php
 *
 * 覆盖控件（5 个）：
 *   - Table：图像列（getCellImage 返回 Image，LVN_GETDISPINFO LVIF_IMAGE）
 *     + 多类型列（checkbox/progress/color/button，NM_CUSTOMDRAW 自绘）
 *     + 完整功能：选择/双击/程序化选择/单行刷新/行级着色/获取选中
 *   - Button：图标按钮（setImage，BS_BITMAP + BM_SETIMAGE）
 *   - Label：图像标签（构造器 Image 参数，SS_BITMAP + STM_SETIMAGE）
 *   - Tab：页签图标（setPageImage，TCM_SETIMAGELIST + TCM_SETITEMW）
 *   - MenuItem：菜单项图标（setImage，SetMenuItemInfoW MIIM_BITMAP）
 *
 * 测试图标由 PHP GD 扩展在运行时生成到临时目录（无需外部图片文件）。
 *
 * 交互：
 *   - 点击表格行 / 双击行 / 切换 Tab / 点击按钮 / 操作菜单 均有事件回调输出。
 *   - Table 操作按钮：选中第3行 / 取消选中 / 修改首行 / 获取选中 / 奇数行着色
 *   - 设环境变量 PHP_UI_AUTO_EXIT=1 时，9 秒后自动退出（CI/无人值守）。
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Kingbes\Ui\App;
use Kingbes\Ui\Window;
use Kingbes\Ui\Layout\VBox;
use Kingbes\Ui\Layout\HBox;
use Kingbes\Ui\Layout\Tab;
use Kingbes\Ui\Control\Button;
use Kingbes\Ui\Control\Label;
use Kingbes\Ui\Control\Table;
use Kingbes\Ui\Control\TableModel;
use Kingbes\Ui\Graphics\Image;
use Kingbes\Ui\Graphics\Color;
use Kingbes\Ui\Menu\Menu;

echo "========================================\n";
echo " PHP UI 图片/图标支持测试 🖼️\n";
echo "========================================\n";

// ============================================================
// 1. 用 GD 生成测试图标（16x16 彩色 PNG）
// ============================================================
$tmpDir = sys_get_temp_dir() . '/php_ui_icons';
if (!is_dir($tmpDir)) {
    @mkdir($tmpDir, 0777, true);
}

/**
 * 生成一个纯色填充 + 中心几何图形的 16x16 PNG 图标。
 *
 * @param string $file  输出文件路径。
 * @param int    $bg    背景色（0xRRGGBB）。
 * @param int    $fg    前景色（0xRRGGBB）。
 * @param string $shape 形状：circle/square/triangle/diamond。
 */
function genIcon(string $file, int $bg, int $fg, string $shape): void
{
    $img = imagecreatetruecolor(16, 16);
    // 关闭 alpha 混合，保持纯色背景
    imagealphablending($img, false);
    $bgC = imagecolorallocate($img, ($bg >> 16) & 0xFF, ($bg >> 8) & 0xFF, $bg & 0xFF);
    $fgC = imagecolorallocate($img, ($fg >> 16) & 0xFF, ($fg >> 8) & 0xFF, $fg & 0xFF);
    imagefilledrectangle($img, 0, 0, 15, 15, $bgC);

    switch ($shape) {
        case 'circle':
            imagefilledellipse($img, 8, 8, 10, 10, $fgC);
            break;
        case 'square':
            imagefilledrectangle($img, 4, 4, 11, 11, $fgC);
            break;
        case 'triangle':
            // PHP 8.5+ 不再接受 $num_points 参数，points 用扁平数组 [x1,y1,x2,y2,...]
            imagefilledpolygon($img, [8, 3, 13, 12, 3, 12], $fgC);
            break;
        case 'diamond':
            imagefilledpolygon($img, [8, 2, 14, 8, 8, 14, 2, 8], $fgC);
            break;
        case 'star':
            // 简化五角星：中心圆 + 四角小方块
            imagefilledellipse($img, 8, 8, 6, 6, $fgC);
            imagefilledrectangle($img, 7, 1, 9, 3, $fgC);
            imagefilledrectangle($img, 7, 13, 9, 15, $fgC);
            imagefilledrectangle($img, 1, 7, 3, 9, $fgC);
            imagefilledrectangle($img, 13, 7, 15, 9, $fgC);
            break;
    }

    imagepng($img, $file);
    // PHP 8.0+ imagedestroy 已无效，8.5+ 标记为 deprecated，无需调用
}

// 生成 5 种图标（不同颜色 + 形状）
$icons = [
    'red_circle'    => $tmpDir . '/red_circle.png',
    'blue_square'   => $tmpDir . '/blue_square.png',
    'green_triangle' => $tmpDir . '/green_triangle.png',
    'orange_diamond' => $tmpDir . '/orange_diamond.png',
    'purple_star'   => $tmpDir . '/purple_star.png',
];
genIcon($icons['red_circle'], 0xF0F0F0, 0xC0392B, 'circle');
genIcon($icons['blue_square'], 0xF0F0F0, 0x2980B9, 'square');
genIcon($icons['green_triangle'], 0xF0F0F0, 0x27AE60, 'triangle');
genIcon($icons['orange_diamond'], 0xF0F0F0, 0xE67E22, 'diamond');
genIcon($icons['purple_star'], 0xF0F0F0, 0x8E44AD, 'star');

echo "测试图标已生成到: {$tmpDir}\n";

// 加载 Image 对象
$iconImages = [];
foreach ($icons as $name => $path) {
    $iconImages[$name] = Image::fromFile($path);
    echo "  已加载: {$name} ({$path})\n";
}

// ============================================================
// 2. 创建主窗口
// ============================================================
$win = new Window("PHP UI 图片/图标支持测试 🖼️", 860, 620);
$win->onClose = fn() => App::quit();
$win->setMargined(10);

$root = new VBox($win);
$root->setPadding(6);

$status = new Label($root, "就绪 - 测试 5 个控件的图片/图标支持", Label::ALIGN_CENTER);
$root->add($status);

// ============================================================
// 3. 菜单栏（MenuItem 图标）
// ============================================================
echo "\n[构建] 菜单栏（MenuItem 图标）...\n";
$menuBar = new Menu(true);
$iconMenu = new Menu(false);

$itemRed = $iconMenu->addItem("红色圆形");
$itemRed->setImage($iconImages['red_circle']);
$itemRed->onClick = function () use ($status): void {
    $status->setText("菜单项：红色圆形");
    echo "[菜单] 红色圆形\n";
};

$itemBlue = $iconMenu->addItem("蓝色方块");
$itemBlue->setImage($iconImages['blue_square']);
$itemBlue->onClick = function () use ($status): void {
    $status->setText("菜单项：蓝色方块");
    echo "[菜单] 蓝色方块\n";
};

$itemGreen = $iconMenu->addItem("绿色三角");
$itemGreen->setImage($iconImages['green_triangle']);
$itemGreen->onClick = function () use ($status): void {
    $status->setText("菜单项：绿色三角");
    echo "[菜单] 绿色三角\n";
};

$iconMenu->addSeparator();

$itemClear = $iconMenu->addItem("清除图标");
$itemClear->onClick = function () use ($status): void {
    $status->setText("菜单项：清除图标（演示）");
    echo "[菜单] 清除图标\n";
};

$menuBar->addSubmenu("图标菜单", $iconMenu);
$win->setMenu($menuBar);
echo "  菜单项图标已设置（SetMenuItemInfoW MIIM_BITMAP）\n";

// ============================================================
// 4. Table 多类型列（图像 + 文本 + 复选框 + 进度 + 颜色 + 按钮）
// ============================================================
echo "\n[构建] Table 多类型列...\n";

/**
 * 表格行数据格式：
 *   [icon_name, name, checked, progress, color_hex, btn_text]
 */
$tableData = [
    ['red_circle',     '红色圆形',   true,  85,  0xC0392B, '查看'],
    ['blue_square',    '蓝色方块',   false, 60,  0x2980B9, '编辑'],
    ['green_triangle', '绿色三角',   true,  40,  0x27AE60, '删除'],
    ['orange_diamond', '橙色菱形',   false, 95,  0xE67E22, '查看'],
    ['purple_star',    '紫色星形',   true,  20,  0x8E44AD, '编辑'],
    [null,             '无图标项',   false, 0,   0x808080, '删除'],
    ['red_circle',     '红色圆形2',  true,  75,  0xC0392B, '查看'],
    ['blue_square',    '蓝色方块2',  false, 50,  0x2980B9, '编辑'],
];

$tableModel = new class($tableData, $iconImages) implements TableModel {
    /** @var list<array{0:?string,1:string,2:bool,3:int,4:int,5:string}> */
    private array $data;
    /** @var array<string, Image> */
    private array $icons;

    /**
     * @param list<array{0:?string,1:string,2:bool,3:int,4:int,5:string}> $data
     * @param array<string, Image> $icons
     */
    public function __construct(array $data, array $icons)
    {
        $this->data = $data;
        $this->icons = $icons;
    }

    public function getRowCount(): int
    {
        return count($this->data);
    }

    public function getColumnCount(): int
    {
        return 6;
    }

    public function getCellValue(int $row, int $col): string
    {
        // 非文本列返回空字符串（NM_CUSTOMDRAW 自绘会覆盖）
        if ($col === 0 || $col === 2 || $col === 3 || $col === 4 || $col === 5) {
            return '';
        }
        return $this->data[$row][$col] ?? '';
    }

    /** 图像列（第 0 列） */
    public function getCellImage(int $row, int $col): ?Image
    {
        if ($col !== 0) {
            return null;
        }
        $iconName = $this->data[$row][0] ?? null;
        if ($iconName === null) {
            return null;
        }
        return $this->icons[$iconName] ?? null;
    }

    /** 复选框列（第 2 列） */
    public function getCellCheckbox(int $row, int $col): ?bool
    {
        if ($col !== 2) {
            return null;
        }
        return $this->data[$row][2] ?? false;
    }

    /** 进度列（第 3 列） */
    public function getCellProgress(int $row, int $col): ?int
    {
        if ($col !== 3) {
            return null;
        }
        return $this->data[$row][3] ?? 0;
    }

    /** 颜色块列（第 4 列） */
    public function getCellColor(int $row, int $col): ?Color
    {
        if ($col !== 4) {
            return null;
        }
        $hex = $this->data[$row][4] ?? 0x808080;
        return Color::rgb(($hex >> 16) & 0xFF, ($hex >> 8) & 0xFF, $hex & 0xFF);
    }

    /** 按钮列（第 5 列） */
    public function getCellButton(int $row, int $col): string
    {
        if ($col !== 5) {
            return '';
        }
        return $this->data[$row][5] ?? '';
    }

    /** 内部：切换 checkbox 状态（演示用，由回调调用） */
    public function setCellCheckbox(int $row, bool $checked): void
    {
        $this->data[$row][2] = $checked;
    }

    /** 内部：修改名称（演示单行刷新） */
    public function setCellName(int $row, string $name): void
    {
        $this->data[$row][1] = $name;
    }

    /** 内部：修改进度（演示单行刷新） */
    public function setCellProgress(int $row, int $value): void
    {
        $this->data[$row][3] = max(0, min(100, $value));
    }

    /** 内部：获取整行名称（供回调显示） */
    public function getName(int $row): string
    {
        return $this->data[$row][1] ?? '';
    }
};

$table = new Table($root);
$root->add($table);
$table->setColumns(['图标', '名称', '启用', '进度', '颜色', '操作'], 90);
// 设置各列类型
$table->setColumnType(0, Table::TYPE_IMAGE);
$table->setColumnType(1, Table::TYPE_TEXT);
$table->setColumnType(2, Table::TYPE_CHECKBOX);
$table->setColumnType(3, Table::TYPE_PROGRESS);
$table->setColumnType(4, Table::TYPE_COLOR);
$table->setColumnType(5, Table::TYPE_BUTTON);
$table->setModel($tableModel);

$table->onSelectionChanged = function (int $row) use ($tableModel, $status): void {
    if ($row < 0) {
        $status->setText("表格：取消选中");
        echo "[表格] onSelectionChanged row=-1\n";
        return;
    }
    $name = $tableModel->getCellValue($row, 1);
    $status->setText("表格选中：#{$row} {$name}");
    echo "[表格] onSelectionChanged row={$row} ({$name})\n";
};

$table->onRowDoubleClicked = function (int $row) use ($tableModel, $status): void {
    $name = $tableModel->getCellValue($row, 1);
    $status->setText("双击行 #{$row}: {$name}");
    echo "[表格] onRowDoubleClicked row={$row} ({$name})\n";
};

$table->onCellCheckboxToggle = function (int $row, int $col, bool $checked) use ($tableModel, $status): void {
    $name = $tableModel->getCellValue($row, 1);
    $tableModel->setCellCheckbox($row, $checked);
    $status->setText("行 {$name} 复选框：" . ($checked ? "勾选" : "取消"));
    echo "[表格] checkbox toggle row={$row} name={$name} checked=" . ($checked ? "true" : "false") . "\n";
};

$table->onCellButtonClick = function (int $row, int $col) use ($tableModel, $status): void {
    $name = $tableModel->getCellValue($row, 1);
    $action = $tableModel->getCellButton($row, $col);
    $status->setText("行 {$name} 按钮点击：{$action}");
    echo "[表格] button click row={$row} name={$name} action={$action}\n";
};

echo "  Table 多类型列已设置（image/text/checkbox/progress/color/button）\n";

// ============================================================
// 4.1 Table 操作按钮组（程序化选择 / 数据修改 / 行级着色）
// ============================================================
echo "[构建] Table 操作按钮组...\n";
$tableBtnRow = new HBox($root);
$root->add($tableBtnRow);
$tableBtnRow->add(new Label($tableBtnRow, "表格操作:"));

// 选中第 3 行
$selBtn = new Button($tableBtnRow, "选中第3行");
$tableBtnRow->add($selBtn);
$selBtn->onClick = function () use ($table, $status): void {
    $table->select(2);
    $status->setText("程序化选中第 3 行");
    echo "[按钮] 选中第 3 行（index=2）\n";
};

// 取消选中
$clearBtn = new Button($tableBtnRow, "取消选中");
$tableBtnRow->add($clearBtn);
$clearBtn->onClick = function () use ($table, $status): void {
    $table->select(-1);
    $status->setText("取消选中");
    echo "[按钮] 取消选中\n";
};

// 修改首行数据（演示 refreshRow）
$modBtn = new Button($tableBtnRow, "修改首行");
$tableBtnRow->add($modBtn);
$modBtn->onClick = function () use ($tableModel, $table, $status): void {
    $tableModel->setCellName(0, '红色圆形（已修改）');
    $tableModel->setCellProgress(0, 100);
    $table->refreshRow(0);
    $status->setText("首行数据已修改并刷新（refreshRow）");
    echo "[按钮] 修改首行名称+进度 → refreshRow(0)\n";
};

// 获取选中行
$getBtn = new Button($tableBtnRow, "获取选中");
$tableBtnRow->add($getBtn);
$getBtn->onClick = function () use ($table, $status): void {
    $row = $table->getSelectedRow();
    $status->setText("当前选中行: " . ($row >= 0 ? "#{$row}" : "(无)"));
    echo "[按钮] getSelectedRow() = {$row}\n";
};

// 奇数行着色
$colorBtn = new Button($tableBtnRow, "奇数行着色");
$tableBtnRow->add($colorBtn);
$colored = false;
$colorBtn->onClick = function () use ($table, &$colored, $status): void {
    $colored = !$colored;
    if ($colored) {
        // 奇数行浅黄背景 0x00BBGGRR = 0x00C8FFFF
        for ($i = 0; $i < 8; $i++) {
            if ($i % 2 === 1) {
                $table->setRowBackgroundColor($i, 0x00C8FFFF);
            }
        }
        // 第 3 行特殊：红底白字
        $table->setRowBackgroundColor(2, 0x000000C8);
        $table->setRowTextColor(2, 0x00FFFFFF);
        $status->setText("已应用行级着色（奇数行浅黄、第 3 行红底白字）");
        echo "[按钮] 行级着色 ON\n";
    } else {
        for ($i = 0; $i < 8; $i++) {
            $table->setRowBackgroundColor($i, null);
            $table->setRowTextColor($i, null);
        }
        $status->setText("已清除所有行级着色");
        echo "[按钮] 行级着色 OFF\n";
    }
};

echo "  Table 操作按钮组已创建（select/refreshRow/getSelectedRow/着色）\n";

// ============================================================
// 5. Button 图标 + Label 图像
// ============================================================
echo "\n[构建] Button 图标 + Label 图像...\n";

$btnRow = new HBox($root);
$root->add($btnRow);
$btnRow->add(new Label($btnRow, "按钮/图像:"));

// 图标按钮
$iconBtn = new Button($btnRow, "图标按钮");
$btnRow->add($iconBtn);
$iconBtn->setImage($iconImages['purple_star']);
$iconBtn->onClick = function () use ($status): void {
    $status->setText("图标按钮被点击");
    echo "[按钮] 图标按钮 onClick\n";
};

// 切换图标按钮（点击实际切换按钮自身图标）
$toggleBtn = new Button($btnRow, "切换图标");
$btnRow->add($toggleBtn);
$toggleBtn->setImage($iconImages['red_circle']);
$toggleIdx = 0;
$toggleBtn->onClick = function () use ($iconImages, $toggleBtn, $status, &$toggleIdx): void {
    $names = ['red_circle', 'blue_square', 'green_triangle', 'orange_diamond', 'purple_star'];
    $name = $names[$toggleIdx % count($names)];
    // 实际切换按钮自身图标
    $toggleBtn->setImage($iconImages[$name]);
    $status->setText("切换图标：{$name}");
    echo "[按钮] 切换图标 -> {$name}\n";
    $toggleIdx++;
};

// Label 图像（构造器传入 Image）
$imageLabel = new Label($btnRow, '', Label::ALIGN_LEFT, $iconImages['orange_diamond']);
$btnRow->add($imageLabel);

echo "  Button 图标已设置（BS_BITMAP + BM_SETIMAGE）\n";
echo "  Label 图像已设置（SS_BITMAP + STM_SETIMAGE）\n";

// ============================================================
// 6. Tab 页签图标
// ============================================================
echo "\n[构建] Tab 页签图标...\n";

$tab = new Tab($root);
$root->add($tab);

// 三个页面
$page1 = new VBox($tab);
$page2 = new VBox($tab);
$page3 = new VBox($tab);

$tab->addPage("红色", $page1);
$tab->addPage("蓝色", $page2);
$tab->addPage("绿色", $page3);

// 为页签设置图标
$tab->setPageImage(0, $iconImages['red_circle']);
$tab->setPageImage(1, $iconImages['blue_square']);
$tab->setPageImage(2, $iconImages['green_triangle']);

// 页面内容
$page1->add(new Label($page1, "🔴 这是红色页面（图标：red_circle）"));
$page2->add(new Label($page2, "🔵 这是蓝色页面（图标：blue_square）"));
$page3->add(new Label($page3, "🟢 这是绿色页面（图标：green_triangle）"));

$tab->onPageChanged = function () use ($tab, $status): void {
    $idx = $tab->getSelectedIndex();
    $status->setText("Tab 切换到第 " . ($idx + 1) . " 页");
    echo "[Tab] onPageChanged -> index={$idx}\n";
};

echo "  Tab 页签图标已设置（TCM_SETIMAGELIST + TCM_SETITEMW）\n";

// ============================================================
// 7. 启动
// ============================================================
$win->setChild($root);
$win->show();

echo "\n窗口已创建。测试要点：\n";
echo "  - 菜单栏「图标菜单」展开查看菜单项图标\n";
echo "  - Table 6 列演示：图标/名称/复选框/进度/颜色/按钮\n";
echo "    * 点击 checkbox 列单元格切换勾选\n";
echo "    * 点击操作列按钮触发回调\n";
echo "    * 双击行触发 onRowDoubleClicked\n";
echo "  - Table 操作按钮：选中/取消/修改首行/获取选中/奇数行着色\n";
echo "  - 「图标按钮」显示星形图标\n";
echo "  - 「切换图标」按钮点击后自身图标会循环切换\n";
echo "  - Label 显示橙色菱形图像\n";
echo "  - Tab 三个页签各带不同图标\n";

if (getenv('PHP_UI_AUTO_EXIT') === '1') {
    echo "\nPHP_UI_AUTO_EXIT=1，运行自动测试序列\n";
    // 1秒后选中表格第 3 行
    App::timer(1000, function () use ($table): void {
        echo ">>> 自动测试: 选中表格第 3 行\n";
        $table->select(2);
    });
    // 2秒后读取选中行
    App::timer(2000, function () use ($table): void {
        $row = $table->getSelectedRow();
        echo ">>> 自动测试: getSelectedRow() = {$row}\n";
        if ($row !== 2) {
            echo "[失败] 预期 2，实际 {$row}\n";
        } else {
            echo "[通过] 选中行读取正确\n";
        }
    });
    // 3秒后修改首行数据 + refreshRow
    App::timer(3000, function () use ($tableModel, $table): void {
        echo ">>> 自动测试: 修改首行数据 + refreshRow\n";
        $tableModel->setCellName(0, '红色圆形（自动修改）');
        $tableModel->setCellProgress(0, 100);
        $table->refreshRow(0);
    });
    // 4秒后应用行级着色
    App::timer(4000, function () use ($table): void {
        echo ">>> 自动测试: 应用奇数行着色\n";
        for ($i = 0; $i < 8; $i++) {
            if ($i % 2 === 1) {
                $table->setRowBackgroundColor($i, 0x00C8FFFF);
            }
        }
        $table->setRowBackgroundColor(2, 0x000000C8);
        $table->setRowTextColor(2, 0x00FFFFFF);
    });
    // 5秒后取消选中
    App::timer(5000, function () use ($table): void {
        echo ">>> 自动测试: 取消选中\n";
        $table->select(-1);
    });
    // 6秒后切换 Tab
    App::timer(6000, function () use ($tab): void {
        echo ">>> 自动测试: 切换到 Tab 第 2 页\n";
        $tab->selectPage(1);
    });
    // 7秒后程序化切换按钮图标（验证 setImage 切换可见）
    App::timer(7000, function () use ($toggleBtn, $iconImages): void {
        echo ">>> 自动测试: 程序化切换 toggleBtn 图标\n";
        $names = ['blue_square', 'green_triangle', 'orange_diamond'];
        static $idx = 0;
        $name = $names[$idx % count($names)];
        $toggleBtn->setImage($iconImages[$name]);
        $idx++;
        echo "    图标已切换为：{$name}\n";
    });
    // 8秒后全量刷新表格（验证自绘刷新）
    App::timer(8000, function () use ($table): void {
        echo ">>> 自动测试: 全量刷新表格\n";
        $table->refresh();
    });
    // 9秒后退出
    App::timer(9000, function (): void {
        echo ">>> 自动退出触发\n";
        App::quit();
    });
}

echo "\n进入事件循环（关闭窗口退出）...\n";
App::run();

// 清理临时图标文件（可选）
foreach ($icons as $path) {
    @unlink($path);
}
@rmdir($tmpDir);

echo "已退出\n";
