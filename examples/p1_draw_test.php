<?php
declare(strict_types=1);

/**
 * P1 绘图增强测试示例。
 *
 * 运行：php -d ffi.enable=true examples/p1_draw_test.php
 *
 * 覆盖：
 *   #8  路径系统（fillPath / strokePath / moveTo / lineTo / arcTo / bezierTo / quadTo / closeFigure）
 *   #9  fill/stroke 分离（fillRect / strokeRect / fillEllipse / strokeEllipse）
 *   #10 渐变画笔（两色线性渐变 + 多色停止点）
 *   #11 贝塞尔曲线（drawBezier + 路径 bezierTo / quadTo）
 *   #12 圆弧（drawArc + 路径 arcTo）
 *   #13 变换矩阵（translate / scale / rotate / save / restore）
 *   #14 裁剪（setClipRect / setClipPath / resetClip）
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Kingbes\Ui\App;
use Kingbes\Ui\Window;
use Kingbes\Ui\Layout\VBox;
use Kingbes\Ui\Control\Area;
use Kingbes\Ui\Control\Button;
use Kingbes\Ui\Control\Label;
use Kingbes\Ui\Graphics\Color;
use Kingbes\Ui\Graphics\DrawContext;
use Kingbes\Ui\Graphics\DrawPath;
use Kingbes\Ui\Graphics\GradientBrush;

echo "========================================\n";
echo " PHP UI P1 绘图增强测试 🎨\n";
echo "========================================\n";

$win = new Window("PHP UI P1 绘图增强测试 🎨 中文", 820, 580);
$win->onClose = fn() => App::quit();

$root = new VBox($win);
$root->setPadding(6);

// 状态栏
$status = new Label($root, "就绪 - 点击下方按钮触发对应测试", Label::ALIGN_CENTER);
$root->add($status);

// 当前测试索引（点击按钮切换）
$curIdx = 0;
$titles = [
    0 => '#8 路径系统（moveTo/lineTo/bezierTo/arcTo/closeFigure + fillPath/strokePath）',
    1 => '#9 fill / stroke 分离（fillRect / strokeRect / fillEllipse / strokeEllipse）',
    2 => '#10 渐变画笔（线性渐变 + 多色停止点）',
    3 => '#11 贝塞尔曲线（drawBezier + 路径 bezierTo / quadTo）',
    4 => '#12 圆弧（drawArc + 路径 arcTo）',
    5 => '#13 变换矩阵（translate / scale / rotate + save/restore）',
    6 => '#14 裁剪（setClipRect / setClipPath / resetClip）',
    7 => '综合演示（变换 + 渐变 + 路径 + 裁剪）',
];

// ============================================================
// 主绘图区
// ============================================================
$area = new Area($root);
$root->add($area);

// ============================================================
// 按钮行
// ============================================================
$btnRow = new \Kingbes\Ui\Layout\HBox($root);
$root->add($btnRow);

$mkBtn = function (string $text, int $idx) use (&$curIdx, $area, $status, $titles, $btnRow): Button {
    $btn = new Button($btnRow, $text);
    $btnRow->add($btn);
    $btn->onClick = function () use ($idx, &$curIdx, $area, $status, $titles): void {
        $curIdx = $idx;
        $status->setText($titles[$idx]);
        echo "[按钮] 切换到测试 #{$idx}\n";
        $area->invalidate();
    };
    return $btn;
};

$mkBtn("#8 路径", 0);
$mkBtn("#9 fill/stroke", 1);
$mkBtn("#10 渐变", 2);
$mkBtn("#11 贝塞尔", 3);
$mkBtn("#12 圆弧", 4);
$mkBtn("#13 变换", 5);
$mkBtn("#14 裁剪", 6);
$mkBtn("综合", 7);

// ============================================================
// onDraw：根据 curIdx 绘制对应测试
// ============================================================
$area->onDraw = function (DrawContext $ctx) use (&$curIdx, $titles): void {
    $w = 800;
    $h = 460;

    // 白色背景
    $ctx->setColor(Color::white());
    $ctx->fillRect(0, 0, $w, $h);

    // 顶部标题
    $ctx->setColor(Color::black());
    $ctx->setFont('Microsoft YaHei', 14);
    $ctx->drawText(10, 6, $titles[$curIdx] ?? '(空)');

    switch ($curIdx) {
        case 0:
            drawTestPath($ctx);
            break;
        case 1:
            drawTestFillStroke($ctx);
            break;
        case 2:
            drawTestGradient($ctx);
            break;
        case 3:
            drawTestBezier($ctx);
            break;
        case 4:
            drawTestArc($ctx);
            break;
        case 5:
            drawTestTransform($ctx);
            break;
        case 6:
            drawTestClip($ctx);
            break;
        case 7:
            drawTestComposite($ctx);
            break;
    }
};

$win->setChild($root);
$win->show();

echo "窗口已创建。点击下方按钮切换不同绘图测试。\n";

if (getenv('PHP_UI_AUTO_EXIT') === '1') {
    echo "PHP_UI_AUTO_EXIT=1，每秒切换一个测试，8 秒后退出\n";
    $step = 0;
    App::timer(1000, function () use (&$step, &$curIdx, $area, $status, $titles): void {
        if ($step >= count($titles)) {
            App::quit();
            return;
        }
        $curIdx = $step;
        $status->setText($titles[$step]);
        echo ">>> 自动切换到测试 #{$step}\n";
        $area->invalidate();
        $step++;
    });
}

echo "进入事件循环（关闭窗口退出）...\n";
App::run();

echo "已退出\n";

// ============================================================
// 测试 #8：路径系统
// ============================================================
function drawTestPath(DrawContext $ctx): void
{
    // 三角形（lineTo + closeFigure + fillPath）
    $path = $ctx->createPath(DrawPath::FILL_WINDING);
    $path->moveTo(60, 50);
    $path->lineTo(160, 50);
    $path->lineTo(110, 150);
    $path->closeFigure();

    $ctx->setColor(Color::rgb(255, 200, 100));
    $ctx->fillPath($path);

    $ctx->setPen(Color::red(), 2);
    $ctx->strokePath($path);
    $path->free();

    // 五角星（路径 + 描边 + 填充）
    $star = $ctx->createPath(DrawPath::FILL_WINDING);
    $cx = 320;
    $cy = 100;
    $R = 60;
    $r = 25;
    $star->moveTo($cx + $R * cos(deg2rad(-90)), $cy + $R * sin(deg2rad(-90)));
    for ($i = 1; $i < 10; $i++) {
        $angle = -90 + $i * 36;
        $radius = ($i % 2 === 1) ? $r : $R;
        $star->lineTo($cx + $radius * cos(deg2rad($angle)), $cy + $radius * sin(deg2rad($angle)));
    }
    $star->closeFigure();

    $ctx->setColor(Color::yellow());
    $ctx->fillPath($star);
    $ctx->setPen(Color::rgb(200, 100, 0), 1);
    $ctx->strokePath($star);
    $star->free();

    // 说明文本
    $ctx->setColor(Color::black());
    $ctx->setFont('Microsoft YaHei', 12);
    $ctx->drawText(60, 200, '▲ 三角形：moveTo + lineTo + closeFigure');
    $ctx->drawText(60, 220, '★ 五角星：10 条 lineTo + closeFigure + winding 填充');

    // 右侧：圆角矩形（arcTo）
    $round = $ctx->createPath(DrawPath::FILL_WINDING);
    $x = 480; $y = 50; $rw = 200; $rh = 100; $radius = 20;
    // 用 4 段 arcTo 拼圆角矩形
    $round->moveTo($x + $radius, $y);
    $round->arcTo($x + $rw - $radius * 2, $y, $radius * 2, $radius * 2, 270, 90);
    $round->arcTo($x + $rw - $radius * 2, $y + $rh - $radius * 2, $radius * 2, $radius * 2, 0, 90);
    $round->arcTo($x, $y + $rh - $radius * 2, $radius * 2, $radius * 2, 90, 90);
    $round->arcTo($x, $y, $radius * 2, $radius * 2, 180, 90);
    $round->closeFigure();

    $ctx->setColor(Color::rgb(173, 216, 230));
    $ctx->fillPath($round);
    $ctx->setPen(Color::blue(), 2);
    $ctx->strokePath($round);
    $round->free();

    $ctx->setColor(Color::black());
    $ctx->drawText(490, 90, '圆角矩形：4 段 arcTo');
}

// ============================================================
// 测试 #9：fill / stroke 分离
// ============================================================
function drawTestFillStroke(DrawContext $ctx): void
{
    // 纯填充矩形（无边框）
    $ctx->setColor(Color::rgb(255, 200, 200));
    $ctx->fillRect(20, 50, 120, 60);

    // 纯描边矩形（无填充）
    $ctx->setPen(Color::red(), 3);
    $ctx->strokeRect(160, 50, 120, 60);

    // 填充+描边（需调用两次）
    $ctx->setColor(Color::rgb(200, 255, 200));
    $ctx->fillRect(300, 50, 120, 60);
    $ctx->setPen(Color::green(), 2);
    $ctx->strokeRect(300, 50, 120, 60);

    // 椭圆：纯填充
    $ctx->setColor(Color::rgb(200, 200, 255));
    $ctx->fillEllipse(440, 50, 120, 80);

    // 椭圆：纯描边
    $ctx->setPen(Color::blue(), 3);
    $ctx->strokeEllipse(580, 50, 120, 80);

    // 椭圆：填充 + 描边
    $ctx->setColor(Color::rgb(255, 220, 150));
    $ctx->fillEllipse(20, 160, 120, 80);
    $ctx->setPen(Color::orange(), 2);
    $ctx->strokeEllipse(20, 160, 120, 80);

    // 说明
    $ctx->setColor(Color::black());
    $ctx->setFont('Microsoft YaHei', 12);
    $ctx->drawText(20, 260, '从左到右：fillRect / strokeRect / fillRect+strokeRect');
    $ctx->drawText(20, 280, '         fillEllipse / strokeEllipse / fillEllipse+strokeEllipse');
    $ctx->drawText(20, 300, 'fill/stroke 分离后可灵活组合：仅填充、仅描边、或两者叠加。');
}

// ============================================================
// 测试 #10：渐变画笔
// ============================================================
function drawTestGradient(DrawContext $ctx): void
{
    // 两色线性渐变（左→右）
    $grad1 = $ctx->createGradientBrush(20, 50, 220, 50, Color::red(), Color::blue());
    $ctx->setGradientBrush($grad1);
    $ctx->fillRect(20, 50, 200, 60);
    $ctx->setGradientBrush(null);
    $grad1->free();

    // 两色垂直渐变（上→下）
    $grad2 = $ctx->createGradientBrush(240, 50, 240, 110, Color::green(), Color::yellow());
    $ctx->setGradientBrush($grad2);
    $ctx->fillRect(240, 50, 200, 60);
    $ctx->setGradientBrush(null);
    $grad2->free();

    // 两色对角渐变
    $grad3 = $ctx->createGradientBrush(460, 50, 660, 110, Color::magenta(), Color::cyan());
    $ctx->setGradientBrush($grad3);
    $ctx->fillRect(460, 50, 200, 60);
    $ctx->setGradientBrush(null);
    $grad3->free();

    // 多色停止点：红→黄→绿→蓝
    $grad4 = $ctx->createGradientBrush(20, 150, 660, 150, Color::red(), Color::blue());
    $grad4->setStops([
        [0.0, Color::red()],
        [0.33, Color::yellow()],
        [0.66, Color::green()],
        [1.0, Color::blue()],
    ]);
    $ctx->setGradientBrush($grad4);
    $ctx->fillRect(20, 150, 640, 80);
    $ctx->setGradientBrush(null);
    $grad4->free();

    // 渐变填充椭圆
    $grad5 = $ctx->createGradientBrush(20, 260, 320, 360, Color::orange(), Color::purple());
    $ctx->setGradientBrush($grad5);
    $ctx->fillEllipse(20, 260, 300, 100);
    $ctx->setGradientBrush(null);
    $grad5->free();

    $ctx->setPen(Color::black(), 1);
    $ctx->strokeEllipse(20, 260, 300, 100);

    // 说明
    $ctx->setColor(Color::black());
    $ctx->setFont('Microsoft YaHei', 12);
    $ctx->drawText(20, 390, '上排：水平 / 垂直 / 对角两色线性渐变');
    $ctx->drawText(20, 410, '中排：多色停止点（红→黄→绿→蓝）');
    $ctx->drawText(20, 430, '下排：渐变填充椭圆 + 描边');
}

// ============================================================
// 测试 #11：贝塞尔曲线
// ============================================================
function drawTestBezier(DrawContext $ctx): void
{
    // 控制点可视化
    $drawControlPoints = function (int $x1, int $y1, int $x2, int $y2, int $x3, int $y3, int $x4, int $y4) use ($ctx): void {
        // 控制点连线（虚线效果用细线近似）
        $ctx->setPen(Color::gray(), 1);
        $ctx->drawLine($x1, $y1, $x2, $y2);
        $ctx->drawLine($x3, $y3, $x4, $y4);
        // 控制点小圆
        $ctx->setColor(Color::gray());
        $ctx->fillEllipse($x2 - 3, $y2 - 3, 6, 6);
        $ctx->fillEllipse($x3 - 3, $y3 - 3, 6, 6);
        // 端点
        $ctx->setColor(Color::red());
        $ctx->fillEllipse($x1 - 3, $y1 - 3, 6, 6);
        $ctx->fillEllipse($x4 - 3, $y4 - 3, 6, 6);
    };

    // 三次贝塞尔（直接绘制）
    $ctx->setPen(Color::blue(), 2);
    $ctx->drawBezier(20, 80, 80, 20, 160, 140, 220, 80);
    $drawControlPoints(20, 80, 80, 20, 160, 140, 220, 80);

    // 路径中的 bezierTo
    $path = $ctx->createPath(DrawPath::FILL_WINDING);
    $path->moveTo(280, 80);
    $path->bezierTo(340, 20, 420, 140, 480, 80);
    $path->bezierTo(540, 20, 620, 140, 680, 80);
    $ctx->setPen(Color::red(), 2);
    $ctx->strokePath($path);
    $path->free();

    // 路径中的 quadTo（二次贝塞尔）
    $path2 = $ctx->createPath(DrawPath::FILL_WINDING);
    $path2->moveTo(20, 220);
    $path2->quadTo(120, 160, 220, 220);
    $path2->quadTo(320, 280, 420, 220);
    $ctx->setPen(Color::green(), 2);
    $ctx->strokePath($path2);
    $path2->free();

    // 闭合贝塞尔形成心形
    $heart = $ctx->createPath(DrawPath::FILL_WINDING);
    $hx = 560; $hy = 220; $hr = 50;
    $heart->moveTo($hx, $hy + $hr);
    $heart->bezierTo($hx - $hr * 2, $hy - $hr / 2, $hx - $hr / 2, $hy - $hr * 1.5, $hx, $hy - $hr / 4);
    $heart->bezierTo($hx + $hr / 2, $hy - $hr * 1.5, $hx + $hr * 2, $hy - $hr / 2, $hx, $hy + $hr);
    $heart->closeFigure();
    $ctx->setColor(Color::red());
    $ctx->fillPath($heart);
    $ctx->setPen(Color::maroon(), 2);
    $ctx->strokePath($heart);
    $heart->free();

    // 说明
    $ctx->setColor(Color::black());
    $ctx->setFont('Microsoft YaHei', 12);
    $ctx->drawText(20, 310, '上：drawBezier（蓝色）+ 路径 bezierTo 连续曲线（红色）');
    $ctx->drawText(20, 330, '中：路径 quadTo 二次贝塞尔（绿色）');
    $ctx->drawText(20, 350, '右：闭合 bezierTo + closeFigure 形成心形（填充 + 描边）');
}

// ============================================================
// 测试 #12：圆弧
// ============================================================
function drawTestArc(DrawContext $ctx): void
{
    // drawArc：四分之一圆弧
    $ctx->setPen(Color::blue(), 2);
    $ctx->drawArc(20, 50, 100, 100, 0, 90);

    // drawArc：半圆
    $ctx->setPen(Color::red(), 2);
    $ctx->drawArc(140, 50, 100, 100, 180, 180);

    // drawArc：3/4 圆
    $ctx->setPen(Color::green(), 2);
    $ctx->drawArc(260, 50, 100, 100, 0, 270);

    // drawArc：完整圆
    $ctx->setPen(Color::purple(), 2);
    $ctx->drawArc(380, 50, 100, 100, 0, 360);

    // drawArc：椭圆弧
    $ctx->setPen(Color::orange(), 2);
    $ctx->drawArc(500, 50, 140, 80, 30, 120);

    // 路径 arcTo：拼成花瓣
    $petal = $ctx->createPath(DrawPath::FILL_WINDING);
    $cx = 200; $cy = 230;
    for ($i = 0; $i < 6; $i++) {
        $a1 = $i * 60;
        $a2 = $a1 + 60;
        $petal->moveTo($cx, $cy);
        $petal->arcTo($cx - 50, $cy - 50, 100, 100, $a1, $a2);
        $petal->closeFigure();
    }
    $ctx->setColor(Color::rgb(255, 200, 220));
    $ctx->fillPath($petal);
    $ctx->setPen(Color::rgb(200, 0, 100), 1);
    $ctx->strokePath($petal);
    $petal->free();

    // 路径 arcTo：拼成饼图
    $pie = $ctx->createPath(DrawPath::FILL_WINDING);
    $px = 500; $py = 230; $pr = 80;
    $pie->moveTo($px, $py);
    $pie->arcTo($px - $pr, $py - $pr, $pr * 2, $pr * 2, 0, 120);
    $pie->closeFigure();
    $ctx->setColor(Color::rgb(255, 180, 180));
    $ctx->fillPath($pie);
    $ctx->setPen(Color::red(), 1);
    $ctx->strokePath($pie);
    $pie->free();

    $pie2 = $ctx->createPath(DrawPath::FILL_WINDING);
    $pie2->moveTo($px, $py);
    $pie2->arcTo($px - $pr, $py - $pr, $pr * 2, $pr * 2, 120, 120);
    $pie2->closeFigure();
    $ctx->setColor(Color::rgb(180, 255, 180));
    $ctx->fillPath($pie2);
    $ctx->setPen(Color::green(), 1);
    $ctx->strokePath($pie2);
    $pie2->free();

    $pie3 = $ctx->createPath(DrawPath::FILL_WINDING);
    $pie3->moveTo($px, $py);
    $pie3->arcTo($px - $pr, $py - $pr, $pr * 2, $pr * 2, 240, 120);
    $pie3->closeFigure();
    $ctx->setColor(Color::rgb(180, 180, 255));
    $ctx->fillPath($pie3);
    $ctx->setPen(Color::blue(), 1);
    $ctx->strokePath($pie3);
    $pie3->free();

    // 说明
    $ctx->setColor(Color::black());
    $ctx->setFont('Microsoft YaHei', 12);
    $ctx->drawText(20, 360, '上排：drawArc 四分之一/半圆/3/4/完整圆/椭圆弧');
    $ctx->drawText(20, 380, '左下：6 段 arcTo 拼成花瓣');
    $ctx->drawText(20, 400, '右下：3 段 arcTo + closeFigure 拼成饼图');
}

// ============================================================
// 测试 #13：变换矩阵
// ============================================================
function drawTestTransform(DrawContext $ctx): void
{
    // 基准矩形：原始位置（无变换）
    $ctx->setColor(Color::rgb(220, 220, 220));
    $ctx->fillRect(20, 50, 80, 50);
    $ctx->setPen(Color::gray(), 1);
    $ctx->strokeRect(20, 50, 80, 50);
    $ctx->setColor(Color::black());
    $ctx->setFont('Microsoft YaHei', 11);
    $ctx->drawText(30, 65, '原始');

    // 平移
    $state1 = $ctx->save();
    $ctx->translate(120, 0);
    $ctx->setColor(Color::rgb(255, 200, 200));
    $ctx->fillRect(20, 50, 80, 50);
    $ctx->setPen(Color::red(), 1);
    $ctx->strokeRect(20, 50, 80, 50);
    $ctx->setColor(Color::black());
    $ctx->drawText(30, 65, 'translate(120,0)');
    $ctx->restore($state1);

    // 缩放
    $state2 = $ctx->save();
    $ctx->translate(280, 30);
    $ctx->scale(1.5, 1.5);
    $ctx->setColor(Color::rgb(200, 255, 200));
    $ctx->fillRect(0, 0, 80, 50);
    $ctx->setPen(Color::green(), 1);
    $ctx->strokeRect(0, 0, 80, 50);
    $ctx->setColor(Color::black());
    $ctx->drawText(5, 15, 'scale(1.5)');
    $ctx->restore($state2);

    // 旋转
    $state3 = $ctx->save();
    $ctx->translate(520, 90);
    $ctx->rotate(30);
    $ctx->setColor(Color::rgb(200, 200, 255));
    $ctx->fillRect(-40, -25, 80, 50);
    $ctx->setPen(Color::blue(), 1);
    $ctx->strokeRect(-40, -25, 80, 50);
    $ctx->setColor(Color::black());
    $ctx->drawText(-30, -10, 'rotate(30°)');
    $ctx->restore($state3);

    // 嵌套变换：旋转 + 平移绘制风车
    $windmill = function (float $angle, int $color) use ($ctx): void {
        $s = $ctx->save();
        $ctx->translate(150, 280);
        $ctx->rotate($angle);
        $ctx->setColor(Color::rgb($color >> 16 & 0xFF, $color >> 8 & 0xFF, $color & 0xFF));
        $ctx->fillRect(0, -8, 80, 16);
        $ctx->setPen(Color::black(), 1);
        $ctx->strokeRect(0, -8, 80, 16);
        $ctx->restore($s);
    };
    $windmill(0,   0xFF8888);
    $windmill(90,  0x88FF88);
    $windmill(180, 0x8888FF);
    $windmill(270, 0xFFFF88);

    // 中心圆
    $ctx->setColor(Color::gray());
    $ctx->fillEllipse(135, 265, 30, 30);

    // 说明
    $ctx->setColor(Color::black());
    $ctx->setFont('Microsoft YaHei', 12);
    $ctx->drawText(20, 360, '上排：原始 / translate / scale / rotate（每个用 save/restore 隔离）');
    $ctx->drawText(20, 380, '左下：4 次 rotate 绘制风车叶片，中心为 translate 原点');
    $ctx->drawText(20, 400, '右下：连续 translate + rotate 实现坐标系嵌套。');
}

// ============================================================
// 测试 #14：裁剪
// ============================================================
function drawTestClip(DrawContext $ctx): void
{
    // 左：矩形裁剪 - 在裁剪区域内绘制椭圆
    $s1 = $ctx->save();
    $ctx->setClipRect(20, 50, 200, 150);
    // 绘制大椭圆（超出裁剪区域的部分会被裁掉）
    $ctx->setColor(Color::rgb(200, 200, 255));
    $ctx->fillEllipse(0, 30, 240, 190);
    $ctx->setPen(Color::blue(), 2);
    $ctx->strokeEllipse(0, 30, 240, 190);
    $ctx->restore($s1);

    // 裁剪框边框（用于可视化裁剪区域）
    $ctx->setPen(Color::red(), 1);
    $ctx->strokeRect(20, 50, 200, 150);

    $ctx->setColor(Color::black());
    $ctx->setFont('Microsoft YaHei', 12);
    $ctx->drawText(25, 215, '矩形裁剪：椭圆超出红框部分被裁掉');

    // 右：路径裁剪 - 用星形路径裁剪
    $star = $ctx->createPath(DrawPath::FILL_WINDING);
    $cx = 480; $cy = 130; $R = 90; $r = 36;
    $star->moveTo($cx + $R * cos(deg2rad(-90)), $cy + $R * sin(deg2rad(-90)));
    for ($i = 1; $i < 10; $i++) {
        $angle = -90 + $i * 36;
        $radius = ($i % 2 === 1) ? $r : $R;
        $star->lineTo($cx + $radius * cos(deg2rad($angle)), $cy + $radius * sin(deg2rad($angle)));
    }
    $star->closeFigure();

    $s2 = $ctx->save();
    $ctx->setClipPath($star);
    // 绘制水平条纹（仅星形内部可见）
    for ($y = 30; $y < 230; $y += 12) {
        $color = (intdiv($y, 12) % 2 === 0) ? Color::red() : Color::white();
        $ctx->setColor($color);
        $ctx->fillRect(360, $y, 240, 12);
    }
    $ctx->restore($s2);

    // 星形边框
    $ctx->setPen(Color::maroon(), 2);
    $ctx->strokePath($star);
    $star->free();

    $ctx->setColor(Color::black());
    $ctx->drawText(360, 250, '路径裁剪：红白条纹仅星形内可见');

    // 下：resetClip 后可正常绘制
    $ctx->setPen(Color::green(), 2);
    $ctx->strokeRect(20, 290, 760, 130);
    $ctx->setColor(Color::black());
    $ctx->drawText(25, 305, 'resetClip 后此区域不受裁剪限制：');
    $ctx->drawText(25, 325, '  - setClipRect / setClipPath 设置的裁剪在 restore 后自动失效');
    $ctx->drawText(25, 345, '  - 也可显式调用 resetClip() 清除当前裁剪区域');
}

// ============================================================
// 综合演示
// ============================================================
function drawTestComposite(DrawContext $ctx): void
{
    // 背景：渐变天空
    $sky = $ctx->createGradientBrush(0, 0, 0, 300, Color::rgb(135, 206, 250), Color::rgb(255, 200, 150));
    $ctx->setGradientBrush($sky);
    $ctx->fillRect(0, 40, 800, 300);
    $ctx->setGradientBrush(null);
    $sky->free();

    // 太阳：渐变 + 旋转光线
    $sunGrad = $ctx->createGradientBrush(120, 130, 220, 230, Color::yellow(), Color::orange());
    $ctx->setGradientBrush($sunGrad);
    $ctx->fillEllipse(120, 130, 100, 100);
    $ctx->setGradientBrush(null);
    $sunGrad->free();

    $ctx->setPen(Color::orange(), 2);
    $ctx->strokeEllipse(120, 130, 100, 100);

    // 太阳光线（旋转）
    for ($i = 0; $i < 12; $i++) {
        $s = $ctx->save();
        $ctx->translate(170, 180);
        $ctx->rotate($i * 30);
        $ctx->setPen(Color::yellow(), 2);
        $ctx->drawLine(60, 0, 90, 0);
        $ctx->restore($s);
    }

    // 地面：渐变草地
    $grass = $ctx->createGradientBrush(0, 340, 0, 460, Color::rgb(34, 139, 34), Color::rgb(0, 100, 0));
    $ctx->setGradientBrush($grass);
    $ctx->fillRect(0, 340, 800, 120);
    $ctx->setGradientBrush(null);
    $grass->free();

    // 房子：用路径绘制
    $house = $ctx->createPath(DrawPath::FILL_WINDING);
    $house->moveTo(400, 280);
    $house->lineTo(500, 280);
    $house->lineTo(500, 380);
    $house->lineTo(400, 380);
    $house->closeFigure();
    $ctx->setColor(Color::rgb(210, 180, 140));
    $ctx->fillPath($house);
    $ctx->setPen(Color::rgb(139, 90, 43), 2);
    $ctx->strokePath($house);
    $house->free();

    // 屋顶：三角形路径
    $roof = $ctx->createPath(DrawPath::FILL_WINDING);
    $roof->moveTo(380, 280);
    $roof->lineTo(450, 220);
    $roof->lineTo(520, 280);
    $roof->closeFigure();
    $ctx->setColor(Color::rgb(178, 34, 34));
    $ctx->fillPath($roof);
    $ctx->setPen(Color::maroon(), 2);
    $ctx->strokePath($roof);
    $roof->free();

    // 门：矩形
    $ctx->setColor(Color::rgb(101, 67, 33));
    $ctx->fillRect(440, 320, 30, 60);
    $ctx->setPen(Color::black(), 1);
    $ctx->strokeRect(440, 320, 30, 60);

    // 窗户：圆弧 + 矩形
    $ctx->setColor(Color::rgb(173, 216, 230));
    $ctx->fillRect(410, 295, 20, 20);
    $ctx->setPen(Color::black(), 1);
    $ctx->strokeRect(410, 295, 20, 20);

    // 云朵：多个椭圆
    $ctx->setColor(Color::white());
    $ctx->fillEllipse(500, 80, 80, 40);
    $ctx->fillEllipse(540, 70, 80, 40);
    $ctx->fillEllipse(580, 80, 80, 40);
    $ctx->fillEllipse(620, 90, 80, 40);

    // 鸟（贝塞尔曲线）
    $ctx->setPen(Color::black(), 2);
    $ctx->drawBezier(300, 100, 320, 80, 340, 80, 360, 100);
    $ctx->drawBezier(360, 100, 380, 80, 400, 80, 420, 100);

    // 文字
    $ctx->setColor(Color::black());
    $ctx->setFont('Microsoft YaHei', 12);
    $ctx->drawText(10, 60, '综合：渐变天空 + 渐变太阳 + 旋转光线 + 路径房屋 + 贝塞尔鸟');
}
