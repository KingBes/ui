<?php
declare(strict_types=1);

/**
 * P2 测试示例：Slider onReleased / ProgressBar 不确定状态 /
 *           窗口 onPositionChanged / 窗口 Margined。
 *
 * 运行：php -d ffi.enable=true examples/p2_test.php
 *
 * 交互：
 *   - 拖动 Slider：拖动中触发 onChanged，释放后触发 onReleased
 *   - 切换 ProgressBar 不确定状态：按钮控制 marquee 动画开关
 *   - 拖动窗口：onPositionChanged 实时显示窗口屏幕坐标
 *   - 切换 Margined：按钮在 0 / 20 / 40 像素边距间循环
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Kingbes\Ui\App;
use Kingbes\Ui\Window;
use Kingbes\Ui\Layout\VBox;
use Kingbes\Ui\Layout\HBox;
use Kingbes\Ui\Control\Slider;
use Kingbes\Ui\Control\ProgressBar;
use Kingbes\Ui\Control\Button;
use Kingbes\Ui\Control\Label;
use Kingbes\Ui\Geometry\Point;

echo "========================================\n";
echo " PHP UI P2 测试 🎚️\n";
echo "========================================\n";

$win = new Window("PHP UI P2 测试 - Slider/ProgressBar/Position/Margined 📐 中文", 760, 560);
$win->onClose = fn() => App::quit();

// 启用 Margined：20 像素内边距
$win->setMargined(20);

$root = new VBox($win);
$root->setPadding(8);

// 状态栏
$status = new Label($root, "就绪 - 拖动滑块/切换按钮/移动窗口观察行为", Label::ALIGN_CENTER);
$root->add($status);

/**
 * 行辅助
 */
$mkRow = function (string $desc) use ($root): HBox {
    $row = new HBox($root);
    $row->setPadding(4);
    $root->add($row);
    $row->add(new Label($row, $desc));
    return $row;
};

// ============================================================
// #18 Slider onReleased
// ============================================================
$sliderRow = $mkRow("Slider (拖动):");
$slider = new Slider($sliderRow);
$sliderRow->add($slider);
$slider->setRange(0, 100);
$slider->setValue(50);

$sliderValue = new Label($sliderRow, "值: 50", Label::ALIGN_LEFT);
$sliderRow->add($sliderValue);

$slider->onChanged = function () use ($slider, $sliderValue, $status): void {
    $v = $slider->getValue();
    $sliderValue->setText("值: {$v}");
    $status->setText("Slider onChanged: {$v}（拖动中）");
};

$slider->onReleased = function () use ($slider, $sliderValue, $status): void {
    $v = $slider->getValue();
    $sliderValue->setText("值: {$v}");
    $status->setText("Slider onReleased: {$v}（拖动结束）✓");
    echo "[事件] Slider onReleased value={$v}\n";
};

// ============================================================
// #19 ProgressBar 不确定状态
// ============================================================
$pbRow = $mkRow("ProgressBar:");
$pb = new ProgressBar($pbRow);
$pbRow->add($pb);
$pb->setRange(0, 100);
$pb->setValue(30);

$pbRow2 = new HBox($root);
$root->add($pbRow2);
$pbState = new Label($pbRow2, "状态: 确定 (30%)", Label::ALIGN_LEFT);
$pbRow2->add($pbState);

$pbBtn = new Button($pbRow2, "切换不确定状态");
$pbRow2->add($pbBtn);

$indeterminate = false;
$pbValue = 30;
$pbBtn->onClick = function () use ($pb, &$indeterminate, $pbState, $status): void {
    $indeterminate = !$indeterminate;
    $pb->setIndeterminate($indeterminate);
    if ($indeterminate) {
        $pbState->setText("状态: 不确定 (marquee)");
        $status->setText("ProgressBar 进入不确定状态（滚动动画）");
        echo "[按钮] ProgressBar marquee=ON\n";
    } else {
        $pbState->setText("状态: 确定");
        $status->setText("ProgressBar 恢复确定状态");
        echo "[按钮] ProgressBar marquee=OFF\n";
    }
};

// 模拟进度增长（确定状态下每 100ms +1，到 100 后回到 0）
App::timer(100, function () use ($pb, &$indeterminate, &$pbValue, $pbState): void {
    if ($indeterminate) {
        return; // 不确定状态下不更新值
    }
    $pbValue = ($pbValue + 1) % 101;
    $pb->setValue($pbValue);
    $pbState->setText("状态: 确定 ({$pbValue}%)");
});

// ============================================================
// #20 窗口 onPositionChanged
// ============================================================
$posRow = $mkRow("窗口位置:");
$posLabel = new Label($posRow, "(未触发)", Label::ALIGN_LEFT);
$posRow->add($posLabel);

$win->onPositionChanged = function (Point $p) use ($posLabel, $status): void {
    $posLabel->setText("({$p->x}, {$p->y})");
    $status->setText("窗口移动到 ({$p->x}, {$p->y})");
};

// 初始位置显示
$initPos = $win->getPosition();
$posLabel->setText("({$initPos->x}, {$initPos->y})");

// ============================================================
// #21 Margined 切换
// ============================================================
$mgRow = $mkRow("Margined:");
$mgLabel = new Label($mgRow, "边距: 20px", Label::ALIGN_LEFT);
$mgRow->add($mgLabel);

$mgBtn = new Button($mgRow, "循环边距 0/20/40");
$mgRow->add($mgBtn);

$marginStep = 1; // 0 → 20 → 40 → 0 ...
$mgBtn->onClick = function () use ($win, $mgLabel, $status, &$marginStep): void {
    $marginStep = ($marginStep + 1) % 3;
    $margin = [0, 20, 40][$marginStep];
    $win->setMargined($margin);
    $mgLabel->setText("边距: {$margin}px");
    $status->setText("窗口边距设为 {$margin}px（观察内边距变化）");
    echo "[按钮] Margined={$margin}px\n";
};

// ============================================================
// 窗口尺寸显示（额外）
// ============================================================
$sizeRow = $mkRow("窗口尺寸:");
$sizeLabel = new Label($sizeRow, "(未触发)", Label::ALIGN_LEFT);
$sizeRow->add($sizeLabel);

$updateSize = function () use ($win, $sizeLabel): void {
    $s = $win->getSize();
    $cs = $win->getClientSize();
    $sizeLabel->setText("外: {$s->width}x{$s->height} 内: {$cs->width}x{$cs->height}");
};
$updateSize();

$win->onResize = function () use ($updateSize, $status): void {
    $updateSize();
    $status->setText("窗口尺寸变化");
};

// 状态栏加入底部
$root->add($status);

$win->setChild($root);
$win->show();

echo "窗口已创建。交互提示：\n";
echo "  - 拖动 Slider 释放后看 onReleased\n";
echo "  - 点击按钮切换 ProgressBar marquee\n";
echo "  - 移动窗口看 onPositionChanged\n";
echo "  - 循环 Margined 按钮观察边距变化\n";

if (getenv('PHP_UI_AUTO_EXIT') === '1') {
    echo "PHP_UI_AUTO_EXIT=1，4 秒后自动退出\n";
    App::timer(4000, function (): void {
        echo "自动退出触发\n";
        App::quit();
    });
}

echo "进入事件循环（关闭窗口退出）...\n";
App::run();

echo "已退出\n";
