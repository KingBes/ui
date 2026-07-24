<?php
declare(strict_types=1);

/**
 * 批次 6 绘图与 Area 测试示例。
 *
 * 运行：php -d ffi.enable=true examples/area_test.php
 *
 * 交互：
 *   - Area 内绘制：背景、网格线、矩形、椭圆、彩色 emoji 文本、富文本。
 *   - 鼠标移动时实时更新十字标记并在底部状态栏显示坐标。
 *   - 鼠标左键点击留下绿色圆点标记，右键点击清空所有标记。
 *   - 设环境变量 PHP_UI_AUTO_EXIT=1 时，5 秒后自动退出（CI/无人值守）。
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Kingbes\Ui\App;
use Kingbes\Ui\Window;
use Kingbes\Ui\Layout\VBox;
use Kingbes\Ui\Control\Area;
use Kingbes\Ui\Control\Label;
use Kingbes\Ui\Graphics\Color;
use Kingbes\Ui\Graphics\DrawContext;
use Kingbes\Ui\Graphics\AttributedString;
use Kingbes\Ui\Events\MouseEvent;

echo "========================================\n";
echo " PHP UI 批次6 绘图与 Area 测试 🎨\n";
echo "========================================\n";

$screen = App::platform()->screenSize();
echo "屏幕尺寸: {$screen->width} x {$screen->height}\n";

// 创建窗口（标题含中文与 emoji，验证 Unicode W 系列 + GDI+ 彩色字体）
$win = new Window("PHP UI 绘图测试 - 批次6 🎨 中文 😀 🚀", 720, 600);
$win->onClose = fn() => App::quit();

// 顶层 VBox：顶部说明 + 中间 Area + 底部状态栏
$root = new VBox($win);

// 顶部说明 Label
$hint = new Label(
    $root,
    "移动鼠标看十字标记 · 左键留点 · 右键清空 · 彩色 emoji 与富文本渲染",
    Label::ALIGN_CENTER
);
$root->add($hint);

// 中间 Area（自定义绘图区）
$area = new Area($root);
$root->add($area);

// 底部状态栏 Label（先创建以便闭包捕获）
$status = new Label($root, "就绪 - 移动鼠标到绘图区", Label::ALIGN_CENTER);
$root->add($status);

// ============================================================
// 富文本（AttributedString）：多段不同字体/字号/颜色
// ============================================================
$attrStr = new AttributedString();
$attrStr->append('富文本 ', 'Segoe UI', 20, Color::red())
    ->append('混合 ', 'Segoe UI', 16, Color::blue())
    ->append('emoji 🌈⭐🎉 ', 'Segoe UI Emoji', 22, Color::purple())
    ->append('与中文', 'Segoe UI', 18, Color::green());

// ============================================================
// 鼠标状态（引用传递给 onDraw 闭包）
// ============================================================
$mouseX = -1;
$mouseY = -1;
$clickX = -1;
$clickY = -1;

// ============================================================
// onDraw：所有绘制集中在此
// ============================================================
$area->onDraw = function (DrawContext $ctx) use (
    &$mouseX, &$mouseY, &$clickX, &$clickY,
    $attrStr
): void {
    // 1. 背景填充（白色）
    $ctx->setBrush(Color::white());
    $ctx->setPen(Color::white(), 1);
    $ctx->drawRect(0, 0, 700, 460);

    // 2. 网格线（银色）
    $ctx->setPen(Color::silver(), 1);
    for ($x = 0; $x <= 700; $x += 40) {
        $ctx->drawLine($x, 0, $x, 460);
    }
    for ($y = 0; $y <= 460; $y += 40) {
        $ctx->drawLine(0, $y, 700, $y);
    }

    // 3. 填充矩形（红边 + 黄填充）
    $ctx->setPen(Color::red(), 2);
    $ctx->setBrush(Color::yellow());
    $ctx->drawRect(20, 20, 180, 70);

    // 4. 填充椭圆（蓝边 + 青填充）
    $ctx->setPen(Color::blue(), 3);
    $ctx->setBrush(Color::cyan());
    $ctx->drawEllipse(230, 20, 140, 70);

    // 5. 描边矩形（仅画笔，用背景色画刷模拟空心）
    $ctx->setPen(Color::purple(), 2);
    $ctx->setBrush(Color::white());
    $ctx->drawRect(400, 20, 180, 70);

    // 6. 彩色 emoji 文本（GDI+ GdipDrawString 支持 Segoe UI Emoji 彩色字体）
    $ctx->setFont('Segoe UI Emoji', 26);
    $ctx->setColor(Color::magenta());
    $ctx->drawText(20, 120, '🎨 彩色 emoji 🚀 😀 中文测试 ✨');

    // 7. 普通中文文本
    $ctx->setFont('Segoe UI', 16);
    $ctx->setColor(Color::black());
    $ctx->drawText(20, 165, 'GDI+ 文本渲染：中文与 emoji 共存，无乱码。');

    // 8. 富文本（AttributedString 多段不同字体/字号/颜色）
    $ctx->drawTextAttributed(20, 200, $attrStr->getId());

    // 9. 鼠标十字标记（实时跟随鼠标）
    if ($mouseX >= 0 && $mouseY >= 0) {
        $ctx->setPen(Color::red(), 1);
        $ctx->drawLine($mouseX - 12, $mouseY, $mouseX + 12, $mouseY);
        $ctx->drawLine($mouseX, $mouseY - 12, $mouseX, $mouseY + 12);
    }

    // 10. 左键点击标记（绿色圆点）
    if ($clickX >= 0 && $clickY >= 0) {
        $ctx->setPen(Color::green(), 2);
        $ctx->setBrush(Color::lime());
        $ctx->drawEllipse($clickX - 7, $clickY - 7, 14, 14);
    }
};

// ============================================================
// 鼠标事件
// ============================================================
$area->onMouseMove = function (MouseEvent $e) use (
    &$mouseX, &$mouseY, $status, $area
): void {
    $mouseX = $e->x;
    $mouseY = $e->y;
    $status->setText("鼠标移动 → ({$e->x}, {$e->y})");
    $area->invalidate();
};

$area->onMouseDown = function (MouseEvent $e) use (
    &$clickX, &$clickY, $status, $area
): void {
    if ($e->button === MouseEvent::BUTTON_LEFT) {
        $clickX = $e->x;
        $clickY = $e->y;
        echo "[事件] 左键点击 ({$e->x}, {$e->y})\n";
        $status->setText("左键点击 ({$e->x}, {$e->y}) ✅");
        $area->invalidate();
    } elseif ($e->button === MouseEvent::BUTTON_RIGHT) {
        $clickX = -1;
        $clickY = -1;
        $mouseX = -1;
        $mouseY = -1;
        echo "[事件] 右键点击 - 清空标记\n";
        $status->setText("已清空所有标记 🧹");
        $area->invalidate();
    }
};

$area->onMouseUp = function (MouseEvent $e): void {
    echo "[事件] 鼠标释放 button={$e->button} ({$e->x}, {$e->y})\n";
};

// 设置顶层容器并显示
$win->setChild($root);
$win->show();

echo "已创建 Area 控件，onDraw 绘制：背景/网格/矩形/椭圆/emoji/富文本\n";
echo "鼠标事件：onMouseMove/onMouseDown(左键留点·右键清空)/onMouseUp\n";

// 定时器：每 2 秒触发一次重绘（演示定时器 + invalidate 联动）
App::timer(2000, function () use ($area): void {
    static $tick = 0;
    $tick++;
    echo "[定时器] 第 {$tick} 次触发重绘\n";
    $area->invalidate();
});

echo "进入事件循环（关闭窗口退出）...\n";
App::run();

echo "已退出\n";
