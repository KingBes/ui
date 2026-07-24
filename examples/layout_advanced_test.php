<?php
declare(strict_types=1);

/**
 * 布局高级特性演示。
 *
 * 运行：php -d ffi.enable=true examples/layout_advanced_test.php
 *
 * 覆盖功能点：
 *   - Container::remove(Control)   动态移除子控件（移除最后一个按钮）
 *   - Container::getChildren()     查询子控件列表，Label 显示数量和类型
 *   - Container::count()           查询子控件数量
 *   - Container::isToplevel()      查询 toplevel 标记（顶层 true vs 嵌套 false）
 *   - Container::setToplevel(bool) 显式设置 toplevel 标记
 *   - Container::destroy()         销毁容器及其所有子控件
 *   - Box::isVertical()            查询 Box 方向（VBox true vs HBox false）
 *   - Box::setPadding / getPadding 设置和查询间距
 *   - Grid::getRows / getCols      查询网格行列数
 *   - Form::getRows                查询表单行数据
 *   - 三层及以上嵌套               VBox > HBox > VBox > HBox 深层嵌套
 *   - 动态添加/移除后触发重布局    triggerRelayout
 *
 * 窗口结构：
 *   ┌─────────────────────────────────────────────────┐
 *   │ VBox(root, toplevel=true)                       │
 *   │ ┌─ HBox 顶部操作按钮区 ──────────────────────┐  │
 *   │ │ [移除] [添加] [查询子控件] [销毁子容器] [查询toplevel] │
 *   │ └────────────────────────────────────────────┘  │
 *   │ ┌─ VBox 中间三层嵌套演示区 (toplevel=true) ──┐  │
 *   │ │ Label "第1层 VBox"                          │  │
 *   │ │ HBox(padding=6)                             │  │
 *   │ │   Label "第2层 HBox"                        │  │
 *   │ │   VBox(padding=4)                           │  │
 *   │ │     Label "第3层 VBox"                      │  │
 *   │ │     HBox(padding=2)  ← 动态增删目标         │  │
 *   │ │       [深层按钮1] [深层按钮2]               │  │
 *   │ │ Grid(3,2)  3行2列网格                       │  │
 *   │ │ VBox  ← 销毁目标                            │  │
 *   │ └────────────────────────────────────────────┘  │
 *   │ ┌─ Form 底部表单 (getRows 查询) ─────────────┐  │
 *   │ │ 姓名: [Entry]  邮箱: [Entry]  城市: [Entry] │  │
 *   │ └────────────────────────────────────────────┘  │
 *   │ Label 操作日志                                  │
 *   └─────────────────────────────────────────────────┘
 *
 * 设环境变量 PHP_UI_AUTO_EXIT=1 时，4 秒后自动退出。
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
echo " PHP UI 布局高级特性演示 📐\n";
echo "========================================\n";

$screen = App::platform()->screenSize();
echo "屏幕尺寸: {$screen->width} x {$screen->height}\n";

// 创建窗口
$win = new Window("PHP UI 布局高级特性 - 动态增删/嵌套/查询 📐 中文", 900, 700);
$win->onClose = fn() => App::quit();

// ============================================================
// 根容器 VBox（由 setChild 自动标记 toplevel=true）
// ============================================================
$root = new VBox($win);

// 日志 Label 先创建（供闭包捕获），最后再加入 root 放在底部
$log = new Label($root, "日志：就绪 ✅", Label::ALIGN_LEFT);

// 日志缓冲与追加辅助：同时输出到控制台与底部 Label
$logLines = [];
$appendLog = function (string $msg) use ($log, &$logLines): void {
    $ts = date('H:i:s');
    $line = "[{$ts}] {$msg}";
    echo $line . "\n";
    $logLines[] = $line;
    // 仅保留最后 5 行，避免 Label 文本过长
    if (count($logLines) > 5) {
        array_shift($logLines);
    }
    $log->setText(implode("\n", $logLines));
};

// 触发窗口重布局的辅助函数（动态增删后调用）
$relayout = function () use ($win): void {
    App::platform()->triggerRelayout($win->getHwnd());
};

// ============================================================
// 顶部 HBox：操作按钮区
// ============================================================
$topBar = new HBox($root);
$root->add($topBar);
$topBar->setPadding(4);

// ============================================================
// 中间：三层嵌套演示区
// VBox(toplevel=false 嵌套, padding=8)
// ============================================================
$midVBox = new VBox($root);
$root->add($midVBox);
$midVBox->setPadding(8);
// 嵌套容器保持 toplevel=false（add() 后默认值），由父容器 layout 递归布局。
// 若设为 true，layoutBoxesInWindow 会用整个窗口客户区重新布局此容器，
// 导致子控件与窗口内其他控件重叠/挤压。
// isToplevel() 查询演示在底部"查询toplevel"按钮回调中展示。

// 第1层 Label
$midVBox->add(new Label($midVBox, "第1层 VBox (padding=8)", Label::ALIGN_LEFT));

// 第2层 HBox（padding=6）
$layer2 = new HBox($midVBox);
$midVBox->add($layer2);
$layer2->setPadding(6);

$layer2->add(new Label($layer2, "第2层 HBox (padding=6)", Label::ALIGN_LEFT));

// 第3层 VBox（padding=4）
$layer3 = new VBox($layer2);
$layer2->add($layer3);
$layer3->setPadding(4);

$layer3->add(new Label($layer3, "第3层 VBox (padding=4)", Label::ALIGN_LEFT));

// 第4层 HBox（padding=2）—— 动态增删目标
$deepBox = new HBox($layer3);
$layer3->add($deepBox);
$deepBox->setPadding(2);

$deepBox->add(new Label($deepBox, "深层→", Label::ALIGN_LEFT));
$deepBox->add(new Button($deepBox, "深层按钮1"));
$deepBox->add(new Button($deepBox, "深层按钮2"));

// Grid(3, 2) —— 3 行 2 列网格，演示 getRows/getCols
$grid = new Grid(3, 2, $midVBox);
$midVBox->add($grid);
$grid->add(new Label($grid, "网格(0,0)", Label::ALIGN_CENTER));
$grid->add(new Label($grid, "网格(0,1)", Label::ALIGN_CENTER));
$grid->add(new Button($grid, "网格(1,0)"));
$grid->add(new Button($grid, "网格(1,1)"));
$grid->add(new Label($grid, "网格(2,0)", Label::ALIGN_CENTER));
$grid->add(new Label($grid, "网格(2,1)", Label::ALIGN_CENTER));

// 销毁目标 VBox —— 演示 Container::destroy()
$destroyTarget = new VBox($midVBox);
$midVBox->add($destroyTarget);
$destroyTarget->add(new Label($destroyTarget, "我是待销毁的子 VBox 💥", Label::ALIGN_LEFT));
$destroyTarget->add(new Button($destroyTarget, "待销毁 VBox 内的按钮"));

// ============================================================
// 底部 Form —— 标签-控件对齐演示，getRows 查询
// ============================================================
$form = new Form($root);
$root->add($form);
$form->setLabelRatio(4); // 标签占 1/4

$form->addRow(new Label($form, "姓名："), new Entry($form, "张三"));
$form->addRow(new Label($form, "邮箱："), new Entry($form, "zhangsan@example.com"));
$form->addRow(new Label($form, "城市："), new Entry($form, "北京市海淀区"));

// ============================================================
// 顶部操作按钮（5 个）
// ============================================================

// 1. 移除最后子控件 —— Container::remove(Control)
$btnRemove = new Button($topBar, "移除最后子控件");
$topBar->add($btnRemove);
$btnRemove->onClick = function () use ($deepBox, $appendLog, $relayout): void {
    $children = $deepBox->getChildren();
    $count = count($children);
    if ($count === 0) {
        $appendLog("remove: deepBox 已无子控件可移除");
        return;
    }
    $last = $children[$count - 1];
    $shortName = (new \ReflectionClass($last))->getShortName();
    $deepBox->remove($last);
    $appendLog("Container::remove() 移除最后一个子控件 ({$shortName})，剩余 " . $deepBox->count() . " 个");
    $relayout();
};

// 2. 添加子控件 —— Container::add(Control)
$addCounter = 0;
$btnAdd = new Button($topBar, "添加子控件");
$topBar->add($btnAdd);
$btnAdd->onClick = function () use ($deepBox, &$addCounter, $appendLog, $relayout): void {
    $addCounter++;
    $b = new Button($deepBox, "新增按钮#{$addCounter}");
    $deepBox->add($b);
    $appendLog("Container::add() 新增按钮#{$addCounter}，当前共 " . $deepBox->count() . " 个子控件");
    $relayout();
};

// 3. 查询子控件 —— Container::getChildren() / count()
$btnQuery = new Button($topBar, "查询子控件");
$topBar->add($btnQuery);
$btnQuery->onClick = function () use ($deepBox, $appendLog): void {
    $children = $deepBox->getChildren();
    $count = $deepBox->count();
    // 收集每个子控件的类型简称
    $types = [];
    foreach ($children as $c) {
        $types[] = (new \ReflectionClass($c))->getShortName();
    }
    $typeStr = $types !== [] ? implode(", ", $types) : "（空）";
    $vert = $deepBox->isVertical() ? "true" : "false";
    $pad = $deepBox->getPadding();
    $appendLog("getChildren() 共 {$count} 个: {$typeStr}");
    $appendLog("count()={$count}; isVertical()={$vert}; getPadding()={$pad}");
};

// 4. 销毁子容器 —— Container::destroy()
$destroyed = false;
$btnDestroy = new Button($topBar, "销毁子容器");
$topBar->add($btnDestroy);
$btnDestroy->onClick = function () use ($destroyTarget, &$destroyed, $btnDestroy, $appendLog, $relayout): void {
    if ($destroyed) {
        $appendLog("destroy: 子容器已销毁，无需重复操作");
        return;
    }
    $destroyTarget->destroy();
    $destroyed = true;
    $btnDestroy->setEnabled(false);
    $appendLog("Container::destroy() 已销毁子 VBox 及其全部子控件");
    $relayout();
};

// 5. 查询 toplevel —— Container::isToplevel() + Grid/Form 查询
$btnTop = new Button($topBar, "查询toplevel");
$topBar->add($btnTop);
$btnTop->onClick = function () use ($root, $midVBox, $layer2, $layer3, $deepBox, $grid, $form, $appendLog): void {
    // isToplevel() 查询：顶层 true vs 嵌套 false
    $appendLog(sprintf(
        "isToplevel: root=%s, midVBox=%s, layer2=%s, layer3=%s, deepBox=%s",
        $root->isToplevel() ? 'T' : 'F',
        $midVBox->isToplevel() ? 'T' : 'F',
        $layer2->isToplevel() ? 'T' : 'F',
        $layer3->isToplevel() ? 'T' : 'F',
        $deepBox->isToplevel() ? 'T' : 'F'
    ));
    // Grid::getRows / getCols
    $appendLog("Grid getRows()={$grid->getRows()}, getCols()={$grid->getCols()}");
    // Form::getRows
    $formRows = $form->getRows();
    $appendLog("Form getRows() 行数=" . count($formRows));
    // Box::isVertical —— VBox true vs HBox false
    $appendLog("isVertical: layer2(HBox)=" . ($layer2->isVertical() ? 'T' : 'F')
        . ", layer3(VBox)=" . ($layer3->isVertical() ? 'T' : 'F'));
};

// ============================================================
// 底部日志 Label（加入 root 末尾，位于底部）
// ============================================================
$root->add($log);

// ============================================================
// 设置顶层容器并显示
// ============================================================
$win->setChild($root);
$win->show();

echo "窗口标题: " . $win->getTitle() . "\n";
echo "布局结构: VBox(root) > [HBox(顶部按钮), VBox(三层嵌套+Grid+销毁目标), Form, Label(日志)]\n";
echo "root isToplevel: " . ($root->isToplevel() ? 'true' : 'false') . "\n";
echo "midVBox isToplevel: " . ($midVBox->isToplevel() ? 'true' : 'false') . " (嵌套容器应为 false)\n";
echo "Box padding: root={$root->getPadding()}, midVBox={$midVBox->getPadding()}, layer2={$layer2->getPadding()}, layer3={$layer3->getPadding()}, deepBox={$deepBox->getPadding()}\n";
echo "Grid: {$grid->getRows()} 行 x {$grid->getCols()} 列\n";
echo "Form: " . count($form->getRows()) . " 行\n";

// 自动退出（CI/无人值守）
if (getenv('PHP_UI_AUTO_EXIT') === '1') {
    echo "PHP_UI_AUTO_EXIT=1，4 秒后自动退出\n";
    App::timer(4000, function (): void {
        echo "自动退出触发\n";
        App::quit();
    });
}

echo "进入事件循环（点击顶部按钮可观察动态增删与重布局）...\n";
App::run();

echo "已退出\n";
