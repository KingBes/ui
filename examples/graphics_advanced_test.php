<?php
declare(strict_types=1);

/**
 * 绘图与值对象高级测试示例。
 *
 * 覆盖功能点：
 *   - Color 值对象：rgb / rgba / fromColorRef / 直接构造 / toArray / toColorRef
 *     + 静态颜色方法 navy/gray/maroon/olive/teal/orange/transparent
 *   - AttributedString：getSegments / measure / draw（直接调用，区别于 drawTextAttributed）
 *   - MouseEvent：BUTTON_MIDDLE / BUTTON_NONE / $modifiers / MODIFIER_* 常量
 *     + hasModifier / isShiftDown / isCtrlDown / isAltDown
 *   - ResizeEvent：toArray
 *   - Geometry：Point / Size / Rect 全部工厂与访问方法
 *   - 绘图：色块 + 彩色 emoji + 中文 + 修饰键状态显示
 *
 * 运行：php -d ffi.enable=true examples/graphics_advanced_test.php
 * 设环境变量 PHP_UI_AUTO_EXIT=1 时，5 秒后自动退出（CI/无人值守）。
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Kingbes\Ui\App;
use Kingbes\Ui\Window;
use Kingbes\Ui\Layout\VBox;
use Kingbes\Ui\Layout\HBox;
use Kingbes\Ui\Control\Area;
use Kingbes\Ui\Control\Label;
use Kingbes\Ui\Graphics\Color;
use Kingbes\Ui\Graphics\DrawContext;
use Kingbes\Ui\Graphics\AttributedString;
use Kingbes\Ui\Events\MouseEvent;
use Kingbes\Ui\Events\ResizeEvent;
use Kingbes\Ui\Geometry\Point;
use Kingbes\Ui\Geometry\Size;
use Kingbes\Ui\Geometry\Rect;

echo "========================================\n";
echo " PHP UI 绘图与值对象高级测试 🎨\n";
echo "========================================\n";

// ============================================================
// Geometry 值对象演示（启动时 echo 到控制台）
// ============================================================
echo "\n--- Geometry 值对象演示 ---\n";

// Point：of / zero / toArray / $x / $y
$p = Point::of(10, 20);
echo "Point::of(10,20) → x={$p->x} y={$p->y} toArray=[" . implode(',', $p->toArray()) . "]\n";
$pz = Point::zero();
echo "Point::zero()    → x={$pz->x} y={$pz->y} toArray=[" . implode(',', $pz->toArray()) . "]\n";

// Size：of / zero / toArray / $width / $height
$s = Size::of(300, 200);
echo "Size::of(300,200) → width={$s->width} height={$s->height} toArray=[" . implode(',', $s->toArray()) . "]\n";
$sz = Size::zero();
echo "Size::zero()      → width={$sz->width} height={$sz->height} toArray=[" . implode(',', $sz->toArray()) . "]\n";

// Rect：of / fromPointAndSize / zero / right / bottom / origin / size
$r1 = Rect::of(0, 0, 100, 80);
echo "Rect::of(0,0,100,80) → right={$r1->right()} bottom={$r1->bottom()} "
    . "origin=[" . implode(',', $r1->origin()->toArray()) . "] "
    . "size=[" . implode(',', $r1->size()->toArray()) . "]\n";
$r2 = Rect::fromPointAndSize(Point::of(5, 5), Size::of(50, 40));
echo "Rect::fromPointAndSize(Point::of(5,5), Size::of(50,40)) → right={$r2->right()} bottom={$r2->bottom()} "
    . "origin=[" . implode(',', $r2->origin()->toArray()) . "] "
    . "size=[" . implode(',', $r2->size()->toArray()) . "]\n";
$rz = Rect::zero();
echo "Rect::zero() → right={$rz->right()} bottom={$rz->bottom()} "
    . "origin=[" . implode(',', $rz->origin()->toArray()) . "] "
    . "size=[" . implode(',', $rz->size()->toArray()) . "]\n";

// ============================================================
// Color 值对象演示（启动时 echo 到控制台）
// ============================================================
echo "\n--- Color 值对象演示 ---\n";

// 1. Color::rgb 静态工厂构造
$cRgb = Color::rgb(11, 22, 33);
echo "Color::rgb(11,22,33) → toArray=[" . implode(',', $cRgb->toArray()) . "]\n";

// 2. Color::rgba 含 alpha 通道
$cRgba = Color::rgba(1, 2, 3, 128);
echo "Color::rgba(1,2,3,128) → toArray=[" . implode(',', $cRgba->toArray()) . "]\n";

// 3. Color::fromColorRef 从 COLORREF（0x00BBGGRR）构造
$colorRef = 0x00FF8000; // B=0xFF, G=0x80, R=0x00 → r=0, g=128, b=255
$cFromRef = Color::fromColorRef($colorRef);
echo "Color::fromColorRef(0x" . dechex($colorRef) . ") → toArray=[" . implode(',', $cFromRef->toArray()) . "]\n";

// 4. Color::__construct 直接构造（带 alpha）
$cDirect = new Color(50, 100, 150, 200);
echo "new Color(50,100,150,200) → toArray=[" . implode(',', $cDirect->toArray()) . "]\n";

// 5. Color::toArray 已在上面多次使用

// 6. 静态颜色方法（navy/gray/maroon/olive/teal/orange/transparent 全部演示）
echo "navy       toArray=[" . implode(',', Color::navy()->toArray()) . "]\n";
echo "gray       toArray=[" . implode(',', Color::gray()->toArray()) . "]\n";
echo "maroon     toArray=[" . implode(',', Color::maroon()->toArray()) . "]\n";
echo "olive      toArray=[" . implode(',', Color::olive()->toArray()) . "]\n";
echo "teal       toArray=[" . implode(',', Color::teal()->toArray()) . "]\n";
echo "orange     toArray=[" . implode(',', Color::orange()->toArray()) . "]\n";
echo "transparent toArray=[" . implode(',', Color::transparent()->toArray()) . "]\n";

// 7. Color::toColorRef 与 fromColorRef 互逆对比
$roundTrip = $cFromRef->toColorRef();
echo "toColorRef() → 0x" . dechex($roundTrip)
    . "（与 fromColorRef 输入 0x" . dechex($colorRef) . " 一致 ✓ 互逆验证通过）\n";

// ============================================================
// 创建窗口（标题含中文与 emoji）
// ============================================================
$win = new Window("PHP UI 绘图与值对象高级测试 🎨 中文 😀 🚀", 900, 700);
$win->onClose = fn() => App::quit();

// 顶层 VBox
$root = new VBox($win);

// 顶部 HBox：状态 Label（鼠标坐标 + 修饰键 + Area 尺寸）
$topBox = new HBox($root);
$root->add($topBox);
$statusLabel = new Label(
    $topBox,
    "就绪 - 移动鼠标到下方绘图区查看坐标/修饰键/尺寸",
    Label::ALIGN_CENTER
);
$topBox->add($statusLabel);

// 中间 Area 控件（占大部分空间，绘制色块和文本）
$area = new Area($root);
$root->add($area);

// 底部 Label 显示操作日志
$logLabel = new Label($root, "操作日志：等待鼠标事件...", Label::ALIGN_CENTER);
$root->add($logLabel);

// ============================================================
// AttributedString 富文本（多段不同字体/颜色/大小）
// ============================================================
$attrStr = new AttributedString();
$attrStr->append('富文本 ', 'Segoe UI', 22, Color::red())
    ->append('混合 ', 'Segoe UI', 18, Color::blue())
    ->append('emoji 🌈⭐🎉 ', 'Segoe UI Emoji', 24, Color::purple())
    ->append('与中文', 'Segoe UI', 20, Color::green());

// 启动时 echo 富文本段落数据（getSegments）
echo "\n--- AttributedString::getSegments ---\n";
foreach ($attrStr->getSegments() as $i => $seg) {
    $cArr = $seg['color']->toArray();
    echo "段{$i}: text='{$seg['text']}' font='{$seg['font']}' size={$seg['size']} color=[" . implode(',', $cArr) . "]\n";
}

// ============================================================
// 鼠标状态共享变量（引用传递给 onDraw / 事件闭包）
// ============================================================
$mouseX = -1;
$mouseY = -1;
$modShift = false;
$modCtrl = false;
$modAlt = false;
$lastButton = MouseEvent::BUTTON_NONE;
$lastModifiers = MouseEvent::MODIFIER_NONE;
$areaW = 900;
$areaH = 700;

// ============================================================
// Area onDraw：所有绘制集中在此
// ============================================================
$area->onDraw = function (DrawContext $ctx) use (
    &$mouseX, &$mouseY, &$modShift, &$modCtrl, &$modAlt,
    &$lastButton, &$lastModifiers, &$areaW, &$areaH,
    $attrStr
): void {
    // 1. 背景填充（白色）
    $ctx->setBrush(Color::white());
    $ctx->setPen(Color::white(), 1);
    $ctx->drawRect(0, 0, 2000, 2000);

    // === 7 个静态颜色色块（navy/gray/maroon/olive/teal/orange/transparent）===
    $titleColor = Color::rgb(33, 33, 33); // 用 Color::rgb 创建标题色
    $ctx->setFont('Segoe UI', 15);
    $ctx->setColor($titleColor);
    $ctx->drawText(10, 5, '7 个静态颜色色块：navy/gray/maroon/olive/teal/orange/transparent');

    $blocks = [
        [Color::navy(),        'navy'],
        [Color::gray(),        'gray'],
        [Color::maroon(),      'maroon'],
        [Color::olive(),       'olive'],
        [Color::teal(),        'teal'],
        [Color::orange(),      'orange'],
        [Color::transparent(), 'transparent'],
    ];

    $bx = 10;
    $by = 28;
    $bw = 95;
    $bh = 45;
    foreach ($blocks as $i => [$col, $name]) {
        // 色块（用 setBrush + drawRect 填充，setPen 描边）
        $ctx->setPen(Color::black(), 1);
        $ctx->setBrush($col);
        $ctx->drawRect($bx, $by, $bw, $bh);
        // 颜色名标签
        $ctx->setFont('Segoe UI', 11);
        $ctx->setColor(Color::rgb(33, 33, 33));
        $ctx->drawText($bx, $by + $bh + 3, $name);
        // 显示 toArray 值
        $ctx->setFont('Segoe UI', 9);
        $ctx->setColor(Color::rgb(100, 100, 100));
        $ctx->drawText($bx, $by + $bh + 18, '[' . implode(',', $col->toArray()) . ']');
        $bx += $bw + 8;
    }

    // === Color 构造方式对比（直接构造 / rgb / rgba / fromColorRef + toColorRef 互逆）===
    $ctx->setFont('Segoe UI', 14);
    $ctx->setColor(Color::rgb(33, 33, 33));
    $ctx->drawText(10, 115, 'Color 构造方式对比（构造/rgb/rgba/fromColorRef + toColorRef 互逆）：');

    // 直接构造：new Color(200, 50, 50)
    $ctx->setPen(Color::black(), 1);
    $ctx->setBrush(new Color(200, 50, 50));
    $ctx->drawEllipse(10, 138, 50, 40);
    $ctx->setFont('Segoe UI', 10);
    $ctx->setColor(Color::black());
    $ctx->drawText(10, 183, 'new Color(200,50,50)');

    // Color::rgb(50, 200, 50)
    $ctx->setPen(Color::black(), 1);
    $ctx->setBrush(Color::rgb(50, 200, 50));
    $ctx->drawEllipse(170, 138, 50, 40);
    $ctx->setFont('Segoe UI', 10);
    $ctx->setColor(Color::black());
    $ctx->drawText(170, 183, 'rgb(50,200,50)');

    // Color::rgba(50, 50, 200, 0) — alpha=0 表示不透明
    $ctx->setPen(Color::black(), 1);
    $ctx->setBrush(Color::rgba(50, 50, 200, 0));
    $ctx->drawEllipse(330, 138, 50, 40);
    $ctx->setFont('Segoe UI', 10);
    $ctx->setColor(Color::black());
    $ctx->drawText(330, 183, 'rgba(50,50,200,0)');

    // Color::fromColorRef(0x00224488) — 0x00BBGGRR → R=0x88, G=0x44, B=0x22
    $cRef = 0x00224488;
    $cFromRef = Color::fromColorRef($cRef);
    $ctx->setPen(Color::black(), 1);
    $ctx->setBrush($cFromRef);
    $ctx->drawEllipse(490, 138, 50, 40);
    $ctx->setFont('Segoe UI', 10);
    $ctx->setColor(Color::black());
    $ctx->drawText(490, 183, 'fromColorRef(0x' . dechex($cRef) . ')');
    // toColorRef 互逆展示
    $refBack = $cFromRef->toColorRef();
    $ctx->setFont('Segoe UI', 10);
    $ctx->setColor(Color::rgb(150, 0, 150));
    $ctx->drawText(490, 197, 'toColorRef=0x' . dechex($refBack) . ' ✓');

    // === AttributedString 富文本：measure 测量后画框包围，draw 绘制 ===
    $ctx->setFont('Segoe UI', 14);
    $ctx->setColor(Color::rgb(33, 33, 33));
    $ctx->drawText(10, 218, 'AttributedString 富文本（measure 测量后画框包围，draw 直接绘制）：');

    $asX = 10;
    $asY = 242;
    // measure 测量文本尺寸
    [$mw, $mh] = $attrStr->measure($ctx);
    // 画框包围文本（红边 + 白填充模拟空心框）
    $ctx->setPen(Color::red(), 1);
    $ctx->setBrush(Color::white());
    $ctx->drawRect($asX - 2, $asY - 2, $mw + 4, $mh + 4);
    // 直接调用 AttributedString::draw（区别于 DrawContext::drawTextAttributed）
    $attrStr->draw($ctx, $asX, $asY);

    // === 彩色 emoji + 中文文本 ===
    $ctx->setFont('Segoe UI Emoji', 26);
    $ctx->setColor(Color::magenta());
    $ctx->drawText(10, 295, '🎨 彩色 emoji 🚀 😀 中文 ✨ 🐱‍💻');

    $ctx->setFont('Segoe UI', 15);
    $ctx->setColor(Color::blue());
    $ctx->drawText(10, 332, 'GDI+ 文本渲染：彩色 emoji 与中文共存，无乱码。');

    // === 当前鼠标修饰键状态显示（Shift/Ctrl/Alt）===
    $ctx->setFont('Segoe UI', 15);
    $ctx->setColor(Color::rgb(33, 33, 33));
    $ctx->drawText(10, 368, '当前鼠标修饰键状态（绿色=按下，灰色=未按）：');

    $mods = [
        ['Shift', $modShift],
        ['Ctrl',  $modCtrl],
        ['Alt',   $modAlt],
    ];
    $mx = 10;
    $my = 395;
    foreach ($mods as [$name, $down]) {
        $col = $down ? Color::rgb(0, 200, 0) : Color::gray();
        $ctx->setPen(Color::black(), 1);
        $ctx->setBrush($col);
        $ctx->drawRect($mx, $my, 80, 30);
        $ctx->setFont('Segoe UI', 13);
        $ctx->setColor($down ? Color::white() : Color::black());
        $ctx->drawText($mx + 8, $my + 7, $name . ($down ? ' ✓' : ' ✗'));
        $mx += 90;
    }

    // 鼠标坐标 + 最近 button + Area 尺寸 + modifiers 掩码
    $ctx->setFont('Segoe UI', 12);
    $ctx->setColor(Color::rgb(80, 80, 80));
    $ctx->drawText(
        10, 438,
        "鼠标坐标：({$mouseX}, {$mouseY})  最近 button={$lastButton}  "
        . "Area尺寸={$areaW}x{$areaH}  modifiers=0b" . decbin($lastModifiers)
    );

    // 鼠标十字标记（实时跟随）
    if ($mouseX >= 0 && $mouseY >= 0) {
        $ctx->setPen(Color::red(), 1);
        $ctx->drawLine($mouseX - 10, $mouseY, $mouseX + 10, $mouseY);
        $ctx->drawLine($mouseX, $mouseY - 10, $mouseX, $mouseY + 10);
    }
};

// ============================================================
// Area 鼠标事件
// ============================================================

// 辅助闭包：根据 MouseEvent 生成描述字符串
$describeMouse = function (MouseEvent $e): string {
    $mods = [];
    if ($e->isShiftDown()) {
        $mods[] = 'Shift';
    }
    if ($e->isCtrlDown()) {
        $mods[] = 'Ctrl';
    }
    if ($e->isAltDown()) {
        $mods[] = 'Alt';
    }
    $modStr = $mods === [] ? '无' : implode('+', $mods);
    return "({$e->x},{$e->y}) button={$e->button} 修饰键={$modStr} (modifiers=0b" . decbin($e->modifiers) . ")";
};

// onMouseDown：左键/右键/中键 全部处理
$area->onMouseDown = function (MouseEvent $e) use (
    $describeMouse, $logLabel, $area
): void {
    // 演示 BUTTON_MIDDLE / BUTTON_LEFT / BUTTON_RIGHT
    if ($e->button === MouseEvent::BUTTON_MIDDLE) {
        echo "[事件] 中键按下 " . $describeMouse($e) . "\n";
        $logLabel->setText("中键按下 " . $describeMouse($e));
    } elseif ($e->button === MouseEvent::BUTTON_LEFT) {
        echo "[事件] 左键按下 " . $describeMouse($e) . "\n";
        $logLabel->setText("左键按下 " . $describeMouse($e));
    } elseif ($e->button === MouseEvent::BUTTON_RIGHT) {
        echo "[事件] 右键按下 " . $describeMouse($e) . "\n";
        $logLabel->setText("右键按下 " . $describeMouse($e));
    }
    $area->invalidate();
};

// onMouseUp：同上
$area->onMouseUp = function (MouseEvent $e) use (
    $describeMouse, $logLabel, $area
): void {
    if ($e->button === MouseEvent::BUTTON_MIDDLE) {
        echo "[事件] 中键释放 " . $describeMouse($e) . "\n";
        $logLabel->setText("中键释放 " . $describeMouse($e));
    } elseif ($e->button === MouseEvent::BUTTON_LEFT) {
        echo "[事件] 左键释放 " . $describeMouse($e) . "\n";
        $logLabel->setText("左键释放 " . $describeMouse($e));
    } elseif ($e->button === MouseEvent::BUTTON_RIGHT) {
        echo "[事件] 右键释放 " . $describeMouse($e) . "\n";
        $logLabel->setText("右键释放 " . $describeMouse($e));
    }
    $area->invalidate();
};

// onMouseMove：显示坐标 + button(none) + 修饰键状态
$area->onMouseMove = function (MouseEvent $e) use (
    &$mouseX, &$mouseY, &$modShift, &$modCtrl, &$modAlt,
    &$lastButton, &$lastModifiers, &$areaW, &$areaH,
    $statusLabel, $area
): void {
    $mouseX = $e->x;
    $mouseY = $e->y;
    // 演示 BUTTON_NONE：鼠标移动时 button 通常为 'none'
    $lastButton = $e->button;
    // 演示 $modifiers 掩码保存
    $lastModifiers = $e->modifiers;

    // 演示 MODIFIER_SHIFT / MODIFIER_CTRL / MODIFIER_ALT 常量 + hasModifier 方法
    $modShift = $e->hasModifier(MouseEvent::MODIFIER_SHIFT);
    $modCtrl  = $e->hasModifier(MouseEvent::MODIFIER_CTRL);
    $modAlt   = $e->hasModifier(MouseEvent::MODIFIER_ALT);

    // 同时演示 isShiftDown / isCtrlDown / isAltDown 便捷方法
    $mods = [];
    if ($e->isShiftDown()) {
        $mods[] = 'Shift';
    }
    if ($e->isCtrlDown()) {
        $mods[] = 'Ctrl';
    }
    if ($e->isAltDown()) {
        $mods[] = 'Alt';
    }
    $modStr = $mods === [] ? '无' : implode('+', $mods);

    $statusLabel->setText(
        "鼠标移动 ({$e->x},{$e->y}) button={$e->button} 修饰键={$modStr} Area={$areaW}x{$areaH}"
    );
    $area->invalidate();
};

// ============================================================
// 窗口 onResize：演示 ResizeEvent::toArray()
// ============================================================
$win->onResize = function (ResizeEvent $e) use ($statusLabel, &$areaW, &$areaH): void {
    // ResizeEvent::toArray() 转数组 [width, height]
    $arr = $e->toArray();
    $areaW = $arr[0];
    $areaH = $arr[1];
    $statusLabel->setText(
        "窗口尺寸变化 ResizeEvent::toArray()=[" . implode(',', $arr) . "] 宽={$arr[0]} 高={$arr[1]}"
    );
    echo "[事件] 窗口尺寸变化 ResizeEvent::toArray()=[" . implode(',', $arr) . "]\n";
};

// 设置顶层容器并显示
$win->setChild($root);
$win->show();

echo "\n已创建窗口与 Area 控件\n";
echo "覆盖：Color / AttributedString / MouseEvent / ResizeEvent / Geometry 全部功能点\n";
echo "鼠标事件：onMouseDown(左/右/中键) / onMouseUp / onMouseMove(button=none)\n";
echo "窗口事件：onResize（ResizeEvent::toArray）\n";

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
