<?php
declare(strict_types=1);

/**
 * 控件高级特性与键盘事件演示。
 *
 * 运行：php -d ffi.enable=true examples/controls_advanced_test.php
 *
 * 覆盖功能点：
 *   - Control::show / hide          切换控件显隐
 *   - Control::setEnabled           切换控件启用/禁用
 *   - Control::setBounds            手动定位控件（脱离布局）
 *   - Control::destroy              动态销毁控件
 *   - Control::getParent/getWindow  父链访问
 *   - Label::ALIGN_LEFT/CENTER/RIGHT 三种对齐对比
 *   - ComboBox::removeItem/clear     动态增删选项
 *   - ListBox::removeItem/clear      动态增删选项
 *   - onKeyDown/onKeyUp              Entry/TextArea/Button 键盘事件
 *   - KeyEvent API                   keyCode/modifiers/hasModifier/isShiftDown
 *                                    /isCtrlDown/isAltDown/MODIFIER_*
 *
 * 设环境变量 PHP_UI_AUTO_EXIT=1 时，5 秒后自动退出（CI/无人值守）。
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Kingbes\Ui\App;
use Kingbes\Ui\Window;
use Kingbes\Ui\Layout\VBox;
use Kingbes\Ui\Layout\HBox;
use Kingbes\Ui\Control\Button;
use Kingbes\Ui\Control\Label;
use Kingbes\Ui\Control\Entry;
use Kingbes\Ui\Control\TextArea;
use Kingbes\Ui\Control\ComboBox;
use Kingbes\Ui\Control\ListBox;
use Kingbes\Ui\Events\KeyEvent;

echo "========================================\n";
echo " PHP UI 控件高级特性 + 键盘事件 ⌨️\n";
echo "========================================\n";

$screen = App::platform()->screenSize();
echo "屏幕尺寸: {$screen->width} x {$screen->height}\n";

// 创建窗口（标题含中文与 emoji，验证 Unicode W 系列 API）
$win = new Window("PHP UI 控件高级特性 + 键盘事件 ⌨️ 中文 😀", 880, 820);
$win->onClose = fn() => App::quit();

// 顶层 VBox：顶部对齐行 / 中间三列 / 底部日志
$root = new VBox($win);

// 底部日志 Label（先创建以便闭包捕获，最后再加入布局放在底部）
$log = new Label($root, "日志：就绪 ✅", Label::ALIGN_LEFT);

// 日志缓冲与追加辅助：同时输出到控制台与底部 Label
$logLines = [];
$appendLog = function (string $msg) use ($log, &$logLines): void {
    $ts = date('H:i:s');
    $line = "[{$ts}] {$msg}";
    echo $line . "\n";
    $logLines[] = $line;
    // 仅保留最后 4 行，避免 Label 文本过长
    if (count($logLines) > 4) {
        array_shift($logLines);
    }
    $log->setText(implode("\n", $logLines));
};

// KeyEvent 描述辅助：演示全部 API 与 MODIFIER_* 常量
$describeModifiers = function (KeyEvent $e): string {
    $flags = [];
    // 三个 is*Down() 便捷方法
    if ($e->isShiftDown()) {
        $flags[] = "SHIFT";
    }
    if ($e->isCtrlDown()) {
        $flags[] = "CTRL";
    }
    if ($e->isAltDown()) {
        $flags[] = "ALT";
    }
    $modStr = $flags !== [] ? implode("+", $flags) : "无修饰键";
    // hasModifier() + MODIFIER_* 常量演示
    $hasShift = $e->hasModifier(KeyEvent::MODIFIER_SHIFT) ? "T" : "F";
    $hasCtrl  = $e->hasModifier(KeyEvent::MODIFIER_CTRL)  ? "T" : "F";
    $hasAlt   = $e->hasModifier(KeyEvent::MODIFIER_ALT)   ? "T" : "F";
    return "mods={$e->modifiers} [{$modStr}] hasModifier(S/C/A)={$hasShift}/{$hasCtrl}/{$hasAlt}";
};

// ============================================================
// 顶部 HBox：三个 Label 对比 ALIGN_LEFT / ALIGN_CENTER / ALIGN_RIGHT
// ============================================================
$topHBox = new HBox($root);
$root->add($topHBox);
$topHBox->add(new Label($topHBox, "左对齐 LEFT ←", Label::ALIGN_LEFT));
$topHBox->add(new Label($topHBox, "居中对齐 CENTER ↔", Label::ALIGN_CENTER));
$topHBox->add(new Label($topHBox, "右对齐 RIGHT →", Label::ALIGN_RIGHT));

// ============================================================
// 中间 HBox：三列布局
// ============================================================
$midHBox = new HBox($root);
$root->add($midHBox);

// ----------------------------------------------------------------
// 左列 VBox：显隐 / 启用禁用 / 销毁 / 父链访问 / setBounds 演示
// ----------------------------------------------------------------
$leftVBox = new VBox($midHBox);
$midHBox->add($leftVBox);

// --- show / hide ---
$leftVBox->add(new Label($leftVBox, "【show / hide 演示】", Label::ALIGN_LEFT));
$toggleVisibleTarget = new Label($leftVBox, "我是可被隐藏/显示的 Label 👀", Label::ALIGN_CENTER);
$leftVBox->add($toggleVisibleTarget);
$visibleBtn = new Button($leftVBox, "切换显隐");
$leftVBox->add($visibleBtn);
$visible = true;
$visibleBtn->onClick = function () use ($toggleVisibleTarget, &$visible, $appendLog): void {
    $visible = !$visible;
    if ($visible) {
        $toggleVisibleTarget->show();
        $appendLog("Control::show() — Label 已显示");
    } else {
        $toggleVisibleTarget->hide();
        $appendLog("Control::hide() — Label 已隐藏");
    }
};

// --- setEnabled ---
$leftVBox->add(new Label($leftVBox, "【setEnabled 演示】", Label::ALIGN_LEFT));
$toggleEnabledTarget = new Button($leftVBox, "我是可被禁用的按钮");
$leftVBox->add($toggleEnabledTarget);
$toggleEnabledTarget->onClick = fn() => $appendLog("⚠ 被禁用按钮被点击了（不应出现）");
$enabledBtn = new Button($leftVBox, "切换启用/禁用");
$leftVBox->add($enabledBtn);
$enabled = true;
$enabledBtn->onClick = function () use ($toggleEnabledTarget, &$enabled, $appendLog): void {
    $enabled = !$enabled;
    $toggleEnabledTarget->setEnabled($enabled);
    $appendLog("Control::setEnabled(" . ($enabled ? "true" : "false") . ")");
};

// --- destroy ---
$leftVBox->add(new Label($leftVBox, "【destroy 演示】", Label::ALIGN_LEFT));
$destroyTarget = new Label($leftVBox, "我将被 destroy 销毁 💥", Label::ALIGN_CENTER);
$leftVBox->add($destroyTarget);
$destroyBtn = new Button($leftVBox, "销毁上面的 Label");
$leftVBox->add($destroyBtn);
$destroyed = false;
$destroyBtn->onClick = function () use ($destroyTarget, &$destroyed, $destroyBtn, $appendLog): void {
    if ($destroyed) {
        $appendLog("Label 已销毁，无需重复操作");
        return;
    }
    $destroyTarget->destroy();
    $destroyed = true;
    $destroyBtn->setEnabled(false);
    $appendLog("Control::destroy() — Label 已销毁，该位置空白");
};

// --- getParent / getWindow ---
$leftVBox->add(new Label($leftVBox, "【getParent / getWindow 演示】", Label::ALIGN_LEFT));
$parentInfoLabel = new Label($leftVBox, "(点击下方按钮查询父链)", Label::ALIGN_LEFT);
$leftVBox->add($parentInfoLabel);
$parentBtn = new Button($leftVBox, "查询父链信息");
$leftVBox->add($parentBtn);
$parentBtn->onClick = function () use ($parentInfoLabel, $appendLog): void {
    $parent = $parentInfoLabel->getParent();      // Control 或 Window
    $window = $parentInfoLabel->getWindow();      // 顶层 Window
    $parentClass = $parent !== null ? $parent::class : 'null';
    $grandClass = 'null';
    if ($parent !== null) {
        $grand = $parent->getParent();            // 继续向上追溯父链
        $grandClass = $grand !== null ? $grand::class : 'null';
    }
    $hasWindow = $window !== null ? 'yes' : 'no';
    $info = "parent={$parentClass}; grandparent={$grandClass}; window={$hasWindow}";
    $parentInfoLabel->setText($info);
    $appendLog("getParent/getWindow: {$info}");
};

// --- setBounds（脱离布局，手动定位）---
$leftVBox->add(new Label($leftVBox, "【setBounds 手动定位演示】", Label::ALIGN_LEFT));
// canvas 作为画布加入布局获得区域，但浮动按钮不调用 add()，脱离布局管理
$canvas = new HBox($leftVBox);
$leftVBox->add($canvas);
$floatBtn = new Button($canvas, "浮动按钮");
// 手动设置 x/y/w/h（相对于 canvas 客户区）
$floatBtn->setBounds(10, 5, 120, 28);
$floatPos = 0;
$moveBtn = new Button($leftVBox, "移动浮动按钮 (setBounds)");
$leftVBox->add($moveBtn);
$moveBtn->onClick = function () use ($floatBtn, &$floatPos, $appendLog): void {
    // 在画布内循环移动到不同坐标，演示手动设置 x/y/w/h
    $positions = [[10, 5], [120, 5], [10, 40], [120, 40]];
    [$x, $y] = $positions[$floatPos % count($positions)];
    $floatPos++;
    $floatBtn->setBounds($x, $y, 120, 28);
    $appendLog("Control::setBounds({$x}, {$y}, 120, 28)");
};

// ----------------------------------------------------------------
// 中列 VBox：ComboBox + ListBox 增删演示
// ----------------------------------------------------------------
$midColVBox = new VBox($midHBox);
$midHBox->add($midColVBox);

// --- ComboBox ---
$midColVBox->add(new Label($midColVBox, "【ComboBox 增删选项】", Label::ALIGN_LEFT));
$combo = new ComboBox($midColVBox);
$midColVBox->add($combo);
$combo->addItem("苹果 🍎");
$combo->addItem("香蕉 🍌");
$combo->addItem("中文 馒头");
$combo->select(0);
$combo->onSelect = function () use ($combo, $appendLog): void {
    $appendLog("ComboBox onSelect index=" . $combo->getSelectedIndex());
};
$comboCounter = 0;
$comboRow = new HBox($midColVBox);
$midColVBox->add($comboRow);
$comboAddBtn = new Button($comboRow, "添加项");
$comboRow->add($comboAddBtn);
$comboAddBtn->onClick = function () use ($combo, &$comboCounter, $appendLog): void {
    $comboCounter++;
    $combo->addItem("新增项 #{$comboCounter}");
    $appendLog("ComboBox addItem → 新增项 #{$comboCounter}");
};
$comboRmBtn = new Button($comboRow, "删除选中项");
$comboRow->add($comboRmBtn);
$comboRmBtn->onClick = function () use ($combo, $appendLog): void {
    $idx = $combo->getSelectedIndex();
    if ($idx < 0) {
        $appendLog("ComboBox removeItem: 无选中项");
        return;
    }
    $combo->removeItem($idx);
    $appendLog("ComboBox removeItem({$idx})");
};
$comboClearBtn = new Button($comboRow, "清空全部");
$comboRow->add($comboClearBtn);
$comboClearBtn->onClick = function () use ($combo, $appendLog): void {
    $combo->clear();
    $appendLog("ComboBox clear() 已清空全部选项");
};

// --- ListBox ---
$midColVBox->add(new Label($midColVBox, "【ListBox 增删选项】", Label::ALIGN_LEFT));
$list = new ListBox($midColVBox);
$midColVBox->add($list);
$list->addItem("列表项 1 中文");
$list->addItem("列表项 2 🐱");
$list->addItem("列表项 3 🐶");
$list->select(0);
$list->onSelect = function () use ($list, $appendLog): void {
    $appendLog("ListBox onSelect index=" . $list->getSelectedIndex());
};
$listCounter = 0;
$listRow = new HBox($midColVBox);
$midColVBox->add($listRow);
$listAddBtn = new Button($listRow, "添加项");
$listRow->add($listAddBtn);
$listAddBtn->onClick = function () use ($list, &$listCounter, $appendLog): void {
    $listCounter++;
    $list->addItem("新增项 #{$listCounter}");
    $appendLog("ListBox addItem → 新增项 #{$listCounter}");
};
$listRmBtn = new Button($listRow, "删除选中项");
$listRow->add($listRmBtn);
$listRmBtn->onClick = function () use ($list, $appendLog): void {
    $idx = $list->getSelectedIndex();
    if ($idx < 0) {
        $appendLog("ListBox removeItem: 无选中项");
        return;
    }
    $list->removeItem($idx);
    $appendLog("ListBox removeItem({$idx})");
};
$listClearBtn = new Button($listRow, "清空全部");
$listRow->add($listClearBtn);
$listClearBtn->onClick = function () use ($list, $appendLog): void {
    $list->clear();
    $appendLog("ListBox clear() 已清空全部选项");
};

// ----------------------------------------------------------------
// 右列 VBox：Entry / TextArea / Button 键盘事件测试
// ----------------------------------------------------------------
$rightVBox = new VBox($midHBox);
$midHBox->add($rightVBox);

// --- Entry 键盘事件 ---
$rightVBox->add(new Label($rightVBox, "【Entry 键盘事件测试】", Label::ALIGN_LEFT));
$rightVBox->add(new Label($rightVBox, "在下面输入框按键，观察 keyCode 与修饰键：", Label::ALIGN_LEFT));
$entry = new Entry($rightVBox, "");
$rightVBox->add($entry);
$entry->onKeyDown = function (KeyEvent $e) use ($appendLog, $describeModifiers): void {
    $appendLog("Entry DOWN keyCode=" . $e->keyCode . " " . $describeModifiers($e));
};
$entry->onKeyUp = function (KeyEvent $e) use ($appendLog, $describeModifiers): void {
    $appendLog("Entry UP   keyCode=" . $e->keyCode . " " . $describeModifiers($e));
};

// --- TextArea 键盘事件 ---
$rightVBox->add(new Label($rightVBox, "【TextArea 键盘事件日志】", Label::ALIGN_LEFT));
$ta = new TextArea($rightVBox, "在此输入，按键事件打印到下方日志与控制台\n");
$rightVBox->add($ta);
$ta->onKeyDown = function (KeyEvent $e) use ($appendLog): void {
    // 单独演示 isShiftDown/isCtrlDown/isAltDown 三个便捷方法
    $flags = [];
    if ($e->isShiftDown()) {
        $flags[] = "SHIFT";
    }
    if ($e->isCtrlDown()) {
        $flags[] = "CTRL";
    }
    if ($e->isAltDown()) {
        $flags[] = "ALT";
    }
    $modStr = $flags !== [] ? implode("+", $flags) : "无";
    $appendLog("TextArea DOWN keyCode=" . $e->keyCode . " mods=[" . $modStr . "]");
};

// --- Button 键盘事件（演示控件都能收键盘事件）---
$rightVBox->add(new Label($rightVBox, "【Button 键盘事件】（聚焦按钮后按键）", Label::ALIGN_LEFT));
$kbdBtn = new Button($rightVBox, "点我聚焦后按键 ⌨️");
$rightVBox->add($kbdBtn);
$kbdBtn->onKeyDown = function (KeyEvent $e) use ($appendLog, $describeModifiers): void {
    $appendLog("Button DOWN keyCode=" . $e->keyCode . " " . $describeModifiers($e));
};
$kbdBtn->onKeyUp = function (KeyEvent $e) use ($appendLog, $describeModifiers): void {
    $appendLog("Button UP   keyCode=" . $e->keyCode . " " . $describeModifiers($e));
};

// ============================================================
// 底部日志 Label（加入 root 末尾，位于底部）
// ============================================================
$root->add($log);

// 设置顶层容器并显示
$win->setChild($root);
$win->show();

echo "窗口标题: " . $win->getTitle() . "\n";
echo "已创建：对齐对比行 / 显隐·启用·销毁·父链·setBounds 演示 / "
    . "ComboBox·ListBox 增删 / Entry·TextArea·Button 键盘事件\n";

// 自动退出（CI/无人值守）
if (getenv('PHP_UI_AUTO_EXIT') === '1') {
    echo "PHP_UI_AUTO_EXIT=1，5 秒后自动退出\n";
    App::timer(5000, function (): void {
        echo "自动退出触发\n";
        App::quit();
    });
}

echo "进入事件循环（关闭窗口退出）...\n";
App::run();

echo "已退出\n";
