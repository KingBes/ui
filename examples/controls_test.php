<?php
declare(strict_types=1);

/**
 * 批次 3 控件测试示例。
 *
 * 运行：php -d ffi.enable=true examples/controls_test.php
 *
 * 交互：
 *   - 窗口展示所有基础控件（Button/Label/Entry/TextArea/Checkbox/RadioBox/
 *     ComboBox/ListBox/Slider/ProgressBar/SpinBox），含中文与 emoji。
 *   - 操作各控件时控制台打印事件回调，底部状态栏同步更新。
 *   - 进度条由定时器驱动自动递增，演示 timer + ProgressBar 联动。
 *   - 设环境变量 PHP_UI_AUTO_EXIT=1 时，5 秒后自动退出（CI/无人值守）。
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
use Kingbes\Ui\Control\Checkbox;
use Kingbes\Ui\Control\RadioBox;
use Kingbes\Ui\Control\ComboBox;
use Kingbes\Ui\Control\ListBox;
use Kingbes\Ui\Control\Slider;
use Kingbes\Ui\Control\ProgressBar;
use Kingbes\Ui\Control\SpinBox;

echo "========================================\n";
echo " PHP UI 批次3 控件测试 🎨\n";
echo "========================================\n";

$screen = App::platform()->screenSize();
echo "屏幕尺寸: {$screen->width} x {$screen->height}\n";

// 创建窗口（标题含中文与 emoji，验证 Unicode W 系列 API）
$win = new Window("PHP UI 控件测试 - 批次3 🎨 中文 😀", 700, 720);
$win->onClose = fn() => App::quit();

// 顶层 VBox：所有控件行纵向排列
$root = new VBox($win);

// 状态栏 Label（先创建以便闭包捕获，最后再加入布局放在底部）
$status = new Label($root, "就绪 - 操作控件查看事件输出", Label::ALIGN_CENTER);

/**
 * 辅助：在 root 下创建一行 [描述 Label + 控件] 的 HBox。
 * 返回该 HBox，调用方把控件 add 进去。
 */
$mkRow = function (string $desc) use ($root): HBox {
    $row = new HBox($root);
    $root->add($row);
    $lab = new Label($row, $desc);
    $row->add($lab);
    return $row;
};

// ============================================================
// Button
// ============================================================
$row = $mkRow("按钮 Button:");
$btn = new Button($row, "点击我 😀 中文");
$row->add($btn);
$btn->onClick = function () use ($status): void {
    echo "[事件] Button onClick 被触发\n";
    $status->setText("Button 被点击 ✅");
};

// ============================================================
// Label（居中对齐，含中文与 emoji）
// ============================================================
$row = $mkRow("标签 Label:");
$demoLabel = new Label($row, "居中文本 🚀 中文测试", Label::ALIGN_CENTER);
$row->add($demoLabel);

// ============================================================
// Entry（单行输入）
// ============================================================
$row = $mkRow("单行输入 Entry:");
$entry = new Entry($row, "初始文本 ✏️");
$row->add($entry);
$entry->onChange = function () use ($entry, $status): void {
    echo "[事件] Entry onChange: " . $entry->getText() . "\n";
    $status->setText("Entry 内容变化");
};
$entry->onEnter = function () use ($entry, $status): void {
    echo "[事件] Entry onEnter: " . $entry->getText() . "\n";
    $status->setText("Entry 回车按下 ↵");
};

// ============================================================
// TextArea（多行输入）
// ============================================================
$row = $mkRow("多行输入 TextArea:");
$ta = new TextArea($row, "第一行 📝\n第二行 中文\n第三行 emoji 😀");
$row->add($ta);
$ta->onChange = function () use ($status): void {
    echo "[事件] TextArea onChange\n";
    $status->setText("TextArea 内容变化");
};

// ============================================================
// Checkbox
// ============================================================
$row = $mkRow("复选框 Checkbox:");
$cb = new Checkbox($row, "启用某功能 ☑️ 中文");
$row->add($cb);
$cb->setChecked(true);
$cb->onClick = function () use ($cb, $status): void {
    $checked = $cb->isChecked();
    echo "[事件] Checkbox 点击, checked=" . ($checked ? "true" : "false") . "\n";
    $status->setText("Checkbox 状态: " . ($checked ? "勾选" : "未勾选"));
};

// ============================================================
// RadioBox（一组单选，嵌套 HBox）
// ============================================================
$row = $mkRow("单选按钮 RadioBox:");
$radioRow = new HBox($row);
$row->add($radioRow);
$r1 = new RadioBox($radioRow, "选项甲 🔴");
$r2 = new RadioBox($radioRow, "选项乙 🟢");
$r3 = new RadioBox($radioRow, "选项丙 🔵");
$radioRow->add($r1);
$radioRow->add($r2);
$radioRow->add($r3);
$r1->setChecked(true);
$mkRadioCb = function (RadioBox $r, string $name) use ($status): void {
    $r->onClick = function () use ($r, $name, $status): void {
        $checked = $r->isChecked();
        echo "[事件] RadioBox 点击: {$name}, checked=" . ($checked ? "true" : "false") . "\n";
        if ($checked) {
            $status->setText("RadioBox 选中: {$name}");
        }
    };
};
$mkRadioCb($r1, "选项甲");
$mkRadioCb($r2, "选项乙");
$mkRadioCb($r3, "选项丙");

// ============================================================
// ComboBox
// ============================================================
$row = $mkRow("下拉选择 ComboBox:");
$combo = new ComboBox($row);
$row->add($combo);
$combo->addItem("苹果 🍎");
$combo->addItem("香蕉 🍌");
$combo->addItem("中文选项 馒头");
$combo->addItem("emoji 🍇");
$combo->select(0);
$combo->onSelect = function () use ($combo, $status): void {
    $idx = $combo->getSelectedIndex();
    echo "[事件] ComboBox onSelect index={$idx}\n";
    $status->setText("ComboBox 选择 index={$idx}");
};

// ============================================================
// ListBox
// ============================================================
$row = $mkRow("列表 ListBox:");
$list = new ListBox($row);
$row->add($list);
$list->addItem("列表项 1 中文");
$list->addItem("列表项 2 🐱");
$list->addItem("列表项 3 🐶");
$list->addItem("列表项 4 中文测试");
$list->select(0);
$list->onSelect = function () use ($list, $status): void {
    $idx = $list->getSelectedIndex();
    echo "[事件] ListBox onSelect index={$idx}\n";
    $status->setText("ListBox 选择 index={$idx}");
};

// ============================================================
// Slider
// ============================================================
$row = $mkRow("滑块 Slider:");
$slider = new Slider($row);
$row->add($slider);
$slider->setRange(0, 100);
$slider->setValue(50);
$slider->onChanged = function () use ($slider, $status): void {
    $v = $slider->getValue();
    echo "[事件] Slider onChanged value={$v}\n";
    $status->setText("Slider 值={$v}");
};

// ============================================================
// ProgressBar
// ============================================================
$row = $mkRow("进度条 ProgressBar:");
$pb = new ProgressBar($row);
$row->add($pb);
$pb->setRange(0, 100);
$pb->setValue(30);

// ============================================================
// SpinBox（复合控件：Edit + UpDown）
// ============================================================
$row = $mkRow("数值微调 SpinBox:");
$spin = new SpinBox($row);
$row->add($spin);
$spin->setRange(0, 999);
$spin->setValue(10);
$spin->onChanged = function () use ($spin, $status): void {
    $v = $spin->getValue();
    echo "[事件] SpinBox onChanged value={$v}\n";
    $status->setText("SpinBox 值={$v}");
};

// ============================================================
// 状态栏（加入 root 末尾，位于底部）
// ============================================================
$root->add($status);

// 设置顶层容器并显示
$win->setChild($root);
$win->show();

echo "窗口标题: " . $win->getTitle() . "\n";
echo "已创建控件: Button/Label/Entry/TextArea/Checkbox/RadioBox"
    . "/ComboBox/ListBox/Slider/ProgressBar/SpinBox\n";

// 定时器：进度条自动递增，演示 timer + ProgressBar 联动
App::timer(100, function () use ($pb): void {
    static $v = 30;
    $v = ($v >= 100) ? 0 : $v + 5;
    $pb->setValue($v);
});

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
