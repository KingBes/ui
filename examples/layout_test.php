<?php
declare(strict_types=1);

/**
 * 批次 4 布局测试示例（Task 17）。
 *
 * 运行：php -d ffi.enable=true examples/layout_test.php
 *
 * 演示内容：
 *   - 嵌套 HBox + VBox + Grid + Form 布局
 *   - Box padding 间距
 *   - 拉伸窗口时布局自适应（WM_SIZE → toplevel layout → 递归子容器）
 *   - 中文文本
 *
 * 窗口结构：
 *   ┌──────────────────────────────────────────┐
 *   │ HBox (root, padding=6)                    │
 *   │ ┌──────────────┐ ┌─────────────────────┐ │
 *   │ │ VBox (左)    │ │ Grid 2x2 (右)       │ │
 *   │ │  Button 1    │ │  Label  | Label     │ │
 *   │ │  Button 2    │ │  Button | Button    │ │
 *   │ │  Button 3    │ └─────────────────────┘ │
 *   │ └──────────────┘                          │
 *   │ ┌──────────────────────────────────────┐ │
 *   │ │ Form (底部)                          │ │
 *   │ │  姓名:  [Entry]                      │ │
 *   │ │  邮箱:  [Entry]                      │ │
 *   │ └──────────────────────────────────────┘ │
 *   └──────────────────────────────────────────┘
 *
 * 设环境变量 PHP_UI_AUTO_EXIT=1 时，3 秒后自动退出。
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Kingbes\Ui\App;
use Kingbes\Ui\Window;
use Kingbes\Ui\Layout\VBox;
use Kingbes\Ui\Layout\HBox;
use Kingbes\Ui\Layout\Grid;
use Kingbes\Ui\Layout\Form;
use Kingbes\Ui\Control\Button;
use Kingbes\Ui\Control\Label;
use Kingbes\Ui\Control\Entry;

echo "========================================\n";
echo " PHP UI 批次4 布局测试 📐\n";
echo "========================================\n";

$screen = App::platform()->screenSize();
echo "屏幕尺寸: {$screen->width} x {$screen->height}\n";

// 创建窗口
$win = new Window("PHP UI 布局测试 - 嵌套 HBox/VBox/Grid/Form 📐 中文", 800, 600);
$win->onClose = fn() => App::quit();

// 尺寸变化回调：观察自适应布局
$resizeCount = 0;
$win->onResize = function ($ev) use (&$resizeCount): void {
    $resizeCount++;
    if ($resizeCount <= 5) {
        echo "[布局] 窗口尺寸变化 #{$resizeCount}: {$ev->width} x {$ev->height}\n";
    }
};

// ============================================================
// 根容器：VBox（纵向排列：上半区 HBox + 下半区 Form）
// ============================================================
$root = new VBox($win);
$root->setPadding(6); // 子容器间距 6px

// ============================================================
// 上半区：HBox（左侧 VBox 放按钮，右侧 Grid 放标签-控件对）
// ============================================================
$topRow = new HBox($root);
$root->add($topRow);
$topRow->setPadding(6);

// 左侧 VBox：3 个按钮
$leftBox = new VBox($topRow);
$topRow->add($leftBox);
$leftBox->setPadding(4);

$btn1 = new Button($leftBox, "按钮甲 🔴");
$btn2 = new Button($leftBox, "按钮乙 🟢");
$btn3 = new Button($leftBox, "按钮丙 🔵");
$leftBox->add($btn1);
$leftBox->add($btn2);
$leftBox->add($btn3);

$btn1->onClick = function (): void { echo "点击了 [按钮甲]\n"; };
$btn2->onClick = function (): void { echo "点击了 [按钮乙]\n"; };
$btn3->onClick = function (): void { echo "点击了 [按钮丙]\n"; };

// 右侧 Grid：2 行 2 列
$rightGrid = new Grid(2, 2, $topRow);
$topRow->add($rightGrid);

$gridLabel1 = new Label($rightGrid, "网格(0,0) 中文", Label::ALIGN_CENTER);
$gridLabel2 = new Label($rightGrid, "网格(0,1) 📍", Label::ALIGN_CENTER);
$gridBtn1   = new Button($rightGrid, "网格按钮(1,0) ✅");
$gridBtn2   = new Button($rightGrid, "网格按钮(1,1) ⭐");
$rightGrid->add($gridLabel1);
$rightGrid->add($gridLabel2);
$rightGrid->add($gridBtn1);
$rightGrid->add($gridBtn2);

$gridBtn1->onClick = function (): void { echo "点击了 [网格按钮(1,0)]\n"; };
$gridBtn2->onClick = function (): void { echo "点击了 [网格按钮(1,1)]\n"; };

// ============================================================
// 下半区：Form（标签-控件两列对齐）
// ============================================================
$bottomForm = new Form($root);
$root->add($bottomForm);
$bottomForm->setLabelRatio(4); // 标签占 1/4

$nameLabel = new Label($bottomForm, "姓名：");
$nameEntry = new Entry($bottomForm, "张三");
$bottomForm->addRow($nameLabel, $nameEntry);

$mailLabel = new Label($bottomForm, "邮箱：");
$mailEntry = new Entry($bottomForm, "zhangsan@example.com");
$bottomForm->addRow($mailLabel, $mailEntry);

$addrLabel = new Label($bottomForm, "地址：");
$addrEntry = new Entry($bottomForm, "北京市海淀区中关村");
$bottomForm->addRow($addrLabel, $addrEntry);

// ============================================================
// 设置顶层容器并显示
// ============================================================
$win->setChild($root);
$win->show();

echo "窗口标题: " . $win->getTitle() . "\n";
echo "布局结构: VBox(root) > HBox(topRow) > [VBox(leftBox), Grid(rightGrid)]\n";
echo "                            + Form(bottomForm)\n";
echo "Box padding: root={$root->getPadding()}, topRow={$topRow->getPadding()}, leftBox={$leftBox->getPadding()}\n";

// 自动退出（CI/无人值守）
if (getenv('PHP_UI_AUTO_EXIT') === '1') {
    echo "PHP_UI_AUTO_EXIT=1，3 秒后自动退出\n";
    App::timer(3000, function (): void {
        echo "自动退出触发\n";
        App::quit();
    });
}

echo "进入事件循环（拖动窗口边缘可观察布局自适应）...\n";
App::run();

echo "已退出\n";
