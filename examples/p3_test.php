<?php
declare(strict_types=1);

/**
 * P3 测试示例：图像加载与绘制 (#23) + Area 滚动 (#24)。
 *
 * 运行：php -d ffi.enable=true examples/p3_test.php
 *
 * 覆盖：
 *   #23 图像加载：drawImage / drawImageScaled / drawImageCropped
 *   #24 Area 滚动：setSize + 程序化 scrollTo + 滚动条交互 + 鼠标坐标转换
 *
 * 测试图片在启动时生成到系统临时目录（24-bit BMP，渐变 + 网格），
 * 不依赖外部资源。
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Kingbes\Ui\App;
use Kingbes\Ui\Window;
use Kingbes\Ui\Layout\VBox;
use Kingbes\Ui\Layout\HBox;
use Kingbes\Ui\Control\Area;
use Kingbes\Ui\Control\Button;
use Kingbes\Ui\Control\Label;
use Kingbes\Ui\Graphics\Color;
use Kingbes\Ui\Graphics\DrawContext;
use Kingbes\Ui\Graphics\Image;
use Kingbes\Ui\Events\MouseEvent;

echo "========================================\n";
echo " PHP UI P3 测试 🖼️\n";
echo "========================================\n";

// ============================================================
// 1. 生成测试 BMP 文件（24-bit，渐变 + 网格，160x120）
// ============================================================
$tmpBmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpui_p3_test.bmp';
generateGradientBmp($tmpBmp, 160, 120);
echo "已生成测试图片: {$tmpBmp}\n";

// 加载图像
try {
    $img = Image::fromFile($tmpBmp);
} catch (\Throwable $e) {
    echo "[错误] 图像加载失败: " . $e->getMessage() . "\n";
    exit(1);
}
echo "图像尺寸: {$img->getWidth()} x {$img->getHeight()}\n";

// ============================================================
// 2. 创建主窗口
// ============================================================
$win = new Window("PHP UI P3 测试 - 图像加载 & Area 滚动 🖼️ 中文", 820, 620);
$win->onClose = function () use ($img, $tmpBmp): void {
    $img->free();
    @unlink($tmpBmp);
    App::quit();
};

$root = new VBox($win);
$root->setPadding(6);

$status = new Label($root, "就绪 - 拖动右侧 Area 滚动条 / 点击按钮切换图像绘制模式", Label::ALIGN_CENTER);
$root->add($status);

// ============================================================
// 3. 左侧：图像绘制测试 (#23)
// ============================================================
$imgRow = new HBox($root);
$root->add($imgRow);

$imgRow->add(new Label($imgRow, "#23 图像:"));

$imgArea = new Area($imgRow);
$imgRow->add($imgArea);

$imgMode = 0; // 0=原始 / 1=缩放 / 2=裁剪
$imgModeLabel = new Label($imgRow, "模式: 原始尺寸");
$imgRow->add($imgModeLabel);

$imgArea->onDraw = function (DrawContext $ctx) use ($img, &$imgMode): void {
    // 白底
    $ctx->setColor(Color::white());
    $ctx->fillRect(0, 0, 400, 280);

    $ctx->setColor(Color::black());
    $ctx->setFont('Microsoft YaHei', 12);

    switch ($imgMode) {
        case 0:
            // 原始尺寸（160x120）
            $ctx->drawText(10, 8, 'drawImage 原始尺寸 (160x120)');
            $ctx->drawImage($img, 10, 30);
            break;
        case 1:
            // 缩放到 320x240
            $ctx->drawText(10, 8, 'drawImageScaled 缩放到 320x240');
            $ctx->drawImageScaled($img, 10, 30, 320, 240);
            break;
        case 2:
            // 裁剪：取图像中心 80x60 区域，绘制到 200x150
            $ctx->drawText(10, 8, 'drawImageCropped 取中心 80x60 → 200x150');
            $sx = (int)(($img->getWidth() - 80) / 2);
            $sy = (int)(($img->getHeight() - 60) / 2);
            $ctx->drawImageCropped($img, 10, 30, 200, 150, $sx, $sy, 80, 60);
            // 同时显示原图缩略图作为参照
            $ctx->setColor(Color::gray());
            $ctx->drawText(230, 30, '原图缩略:');
            $ctx->drawImageScaled($img, 230, 50, 80, 60);
            // 在缩略图上标注裁剪区域
            $ctx->setPen(Color::red(), 2);
            $thumbSx = 230 + (int)($sx * 80 / $img->getWidth());
            $thumbSy = 50 + (int)($sy * 60 / $img->getHeight());
            $thumbSw = (int)(80 * 80 / $img->getWidth());
            $thumbSh = (int)(60 * 60 / $img->getHeight());
            $ctx->strokeRect($thumbSx, $thumbSy, $thumbSw, $thumbSh);
            break;
    }

    // 边框
    $ctx->setPen(Color::black(), 1);
    $ctx->strokeRect(0, 0, 400, 280);
};

// 切换模式按钮
$btnRow = new HBox($root);
$root->add($btnRow);
$btnRow->add(new Label($btnRow, "切换:"));
$modeBtn = new Button($btnRow, "切换图像模式");
$btnRow->add($modeBtn);
$modeBtn->onClick = function () use (&$imgMode, $imgModeLabel, $imgArea, $status): void {
    $imgMode = ($imgMode + 1) % 3;
    $names = ['原始尺寸', '缩放', '裁剪'];
    $imgModeLabel->setText("模式: {$names[$imgMode]}");
    $status->setText("图像模式切换为: {$names[$imgMode]}");
    echo "[按钮] 图像模式 = {$imgMode} ({$names[$imgMode]})\n";
    $imgArea->invalidate();
};

// ============================================================
// 4. 右侧：Area 滚动测试 (#24)
// ============================================================
$scrollRow = new HBox($root);
$root->add($scrollRow);

$scrollRow->add(new Label($scrollRow, "#24 滚动:"));

$scrollArea = new Area($scrollRow);
$scrollRow->add($scrollArea);

// 内容尺寸 1200x900（远大于可视区域，触发滚动条）
$scrollArea->setSize(1200, 900);

$scrollPosLabel = new Label($scrollRow, "滚动: (0, 0)");
$scrollRow->add($scrollPosLabel);

$scrollArea->onDraw = function (DrawContext $ctx): void {
    // 内容坐标系：0,0 ~ 1200,900
    // 平台已应用 GdipTranslateWorldTransform(-scrollX, -scrollY)
    // 用户按内容坐标绘制即可

    // 浅黄背景
    $ctx->setColor(Color::rgb(255, 252, 230));
    $ctx->fillRect(0, 0, 1200, 900);

    // 网格线（每 50 像素）
    $ctx->setPen(Color::rgb(220, 220, 200), 1);
    for ($x = 0; $x <= 1200; $x += 50) {
        $ctx->drawLine($x, 0, $x, 900);
    }
    for ($y = 0; $y <= 900; $y += 50) {
        $ctx->drawLine(0, $y, 1200, $y);
    }
    // 主网格（每 200 像素加粗）
    $ctx->setPen(Color::rgb(180, 180, 150), 2);
    for ($x = 0; $x <= 1200; $x += 200) {
        $ctx->drawLine($x, 0, $x, 900);
    }
    for ($y = 0; $y <= 900; $y += 200) {
        $ctx->drawLine(0, $y, 1200, $y);
    }

    // 坐标标签
    $ctx->setColor(Color::black());
    $ctx->setFont('Microsoft YaHei', 12);
    for ($x = 0; $x <= 1200; $x += 200) {
        for ($y = 0; $y <= 900; $y += 200) {
            $ctx->drawText($x + 4, $y + 4, "({$x},{$y})");
        }
    }

    // 中心标记
    $ctx->setColor(Color::red());
    $ctx->fillEllipse(580, 420, 40, 40);
    $ctx->setPen(Color::maroon(), 2);
    $ctx->strokeEllipse(580, 420, 40, 40);
    $ctx->setColor(Color::black());
    $ctx->drawText(585, 435, '中心');

    // 四角标记
    $corners = [[0, 0], [1160, 0], [0, 860], [1160, 860]];
    foreach ($corners as [$cx, $cy]) {
        $ctx->setColor(Color::blue());
        $ctx->fillEllipse($cx, $cy, 40, 40);
        $ctx->setPen(Color::black(), 2);
        $ctx->strokeEllipse($cx, $cy, 40, 40);
    }

    // 边框（内容坐标系）
    $ctx->setPen(Color::black(), 3);
    $ctx->strokeRect(0, 0, 1200, 900);
};

// 鼠标移动时显示内容坐标（验证坐标转换）
$scrollArea->onMouseMove = function (MouseEvent $e) use ($scrollPosLabel, $status): void {
    $scrollPosLabel->setText("鼠标: ({$e->x}, {$e->y})");
    $status->setText("Area 鼠标（内容坐标）: ({$e->x}, {$e->y})");
};

// 程序化滚动按钮
$scrollBtnRow = new HBox($root);
$root->add($scrollBtnRow);
$scrollBtnRow->add(new Label($scrollBtnRow, "跳转:"));

$jumpBtns = [
    '左上(0,0)' => [0, 0],
    '中心(600,450)' => [600, 450],
    '右下(1200,900)' => [1200, 900],
];
foreach ($jumpBtns as $text => $pos) {
    $btn = new Button($scrollBtnRow, $text);
    $scrollBtnRow->add($btn);
    $btn->onClick = function () use ($scrollArea, $pos, $status): void {
        $scrollArea->scrollTo($pos[0], $pos[1]);
        $sp = $scrollArea->getScrollPos();
        $status->setText("程序化滚动到 ({$pos[0]}, {$pos[1]})，实际 = ({$sp['x']}, {$sp['y']})");
        echo "[按钮] scrollTo({$pos[0]}, {$pos[1]}) → ({$sp['x']}, {$sp['y']})\n";
    };
}

// 底部状态栏
$root->add($status);

$win->setChild($root);
$win->show();

echo "窗口已创建。交互提示：\n";
echo "  - 点击「切换图像模式」测试 drawImage/drawImageScaled/drawImageCropped\n";
echo "  - 拖动 Area 滚动条 / 点击跳转按钮测试滚动\n";
echo "  - 在 Area 内移动鼠标，状态栏显示内容坐标（已应用滚动偏移）\n";

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

/**
 * 生成 24-bit BMP 渐变图（带网格）。
 *
 * @param string $path 输出文件路径。
 * @param int    $w    宽度。
 * @param int    $h    高度。
 */
function generateGradientBmp(string $path, int $w, int $h): void
{
    // 每行像素需 4 字节对齐
    $rowBytes = (($w * 3 + 3) & ~3);
    $pixelSize = $rowBytes * $h;
    $fileSize = 54 + $pixelSize;

    // BITMAPFILEHEADER (14 bytes)
    $fileHeader = pack(
        'A2VvvV',
        'BM',          // bfType
        $fileSize,     // bfSize
        0,             // bfReserved1
        0,             // bfReserved2
        54             // bfOffBits
    );

    // BITMAPINFOHEADER (40 bytes)
    $infoHeader = pack(
        'VVVvvVVVVVV',
        40,           // biSize
        $w,           // biWidth
        $h,           // biHeight (正数=bottom-up)
        1,            // biPlanes
        24,           // biBitCount
        0,            // biCompression (BI_RGB)
        $pixelSize,   // biSizeImage
        2835,         // biXPelsPerMeter (72 DPI)
        2835,         // biYPelsPerMeter
        0,            // biClrUsed
        0             // biClrImportant
    );

    // 像素数据（BGR，bottom-up）
    $pixels = str_repeat("\x00", $pixelSize);
    for ($y = 0; $y < $h; $y++) {
        // BMP bottom-up：第 0 行是图像底部
        $imgY = $h - 1 - $y;
        for ($x = 0; $x < $w; $x++) {
            // 渐变：左上红 → 右下蓝，叠加绿色斜线
            $r = (int)(255 * $x / max(1, $w - 1));
            $b = (int)(255 * $imgY / max(1, $h - 1));
            $g = (int)(255 * (($x + $imgY) / max(1, $w + $h - 2)));
            // 每 20 像素加白色网格
            if ($x % 20 === 0 || $imgY % 20 === 0) {
                $r = $g = $b = 255;
            }
            $offset = $y * $rowBytes + $x * 3;
            $pixels[$offset]     = chr($b); // B
            $pixels[$offset + 1] = chr($g); // G
            $pixels[$offset + 2] = chr($r); // R
        }
    }

    file_put_contents($path, $fileHeader . $infoHeader . $pixels);
}
