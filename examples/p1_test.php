<?php
declare(strict_types=1);

/**
 * P1 第一批控件测试示例：Area 事件增强 + DateTimePicker。
 *
 * 运行：php -d ffi.enable=true examples/p1_test.php
 *
 * 覆盖：
 *   - Area onMouseEnter / onMouseLeave（鼠标进入/离开）
 *   - Area onKeyDown / onKeyUp（键盘事件，需先点击 Area 获得焦点）
 *   - DateTimePicker DATE / TIME / DATETIME 三种模式
 *
 * 交互：
 *   - 进入/离开 Area 时标题栏更新 + 控制台打印
 *   - 点击 Area 获得焦点后，按方向键/Esc/Enter 触发键盘事件
 *   - 操作 DateTimePicker 时 onChanged 触发，底部状态栏显示当前值
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Kingbes\Ui\App;
use Kingbes\Ui\Window;
use Kingbes\Ui\Layout\VBox;
use Kingbes\Ui\Layout\HBox;
use Kingbes\Ui\Control\Area;
use Kingbes\Ui\Control\Button;
use Kingbes\Ui\Control\Label;
use Kingbes\Ui\Control\DateTimePicker;
use Kingbes\Ui\Graphics\Color;
use Kingbes\Ui\Graphics\DrawContext;
use Kingbes\Ui\Events\KeyEvent;
use Kingbes\Ui\Events\MouseEvent;

echo "========================================\n";
echo " PHP UI P1 第一批测试 🎹\n";
echo "========================================\n";

$screen = App::platform()->screenSize();
echo "屏幕尺寸: {$screen->width} x {$screen->height}\n";

$win = new Window("PHP UI P1 测试 - Area 事件 & DateTimePicker 📅 中文", 720, 640);
$win->onClose = fn() => App::quit();

$root = new VBox($win);
$root->setPadding(6);

// 状态栏
$status = new Label($root, "就绪 - 进入/离开 Area、点击 Area 后按键、操作日期选择器", Label::ALIGN_CENTER);

/**
 * 行辅助：[描述 Label + 控件容器 HBox]
 */
$mkRow = function (string $desc) use ($root): HBox {
    $row = new HBox($root);
    $row->setPadding(4);
    $root->add($row);
    $row->add(new Label($row, $desc));
    return $row;
};

// ============================================================
// Area：演示鼠标进入/离开 + 键盘事件
// ============================================================
$areaRow = $mkRow("绘图区 Area:");
$area = new Area($areaRow);
$areaRow->add($area);

// 内部状态：是否在区域内、上次按键
$inArea = false;
$lastKey = '(none)';
$bgColor = Color::rgb(240, 240, 240); // 默认浅灰
$hoverColor = Color::rgb(255, 255, 200); // 进入时浅黄

$area->onDraw = function (DrawContext $ctx) use (&$inArea, $bgColor, $hoverColor): void {
    // 背景填充：进入时浅黄，否则浅灰
    $ctx->setBrush($inArea ? $hoverColor : $bgColor);
    $ctx->drawRect(0, 0, 400, 200);
    // 提示文本
    $ctx->setColor(Color::black());
    $ctx->setFont('Microsoft YaHei', 14);
    $ctx->drawText(10, 10, '进入区域变黄 🟨');
    $ctx->drawText(10, 40, '点击获得焦点后按键 ⌨️');
};

$area->onMouseEnter = function () use (&$inArea, $area, $status): void {
    $inArea = true;
    echo "[事件] Area onMouseEnter\n";
    $status->setText("Area 鼠标进入 🟨");
    $area->invalidate();
};

$area->onMouseLeave = function () use (&$inArea, $area, $status): void {
    $inArea = false;
    echo "[事件] Area onMouseLeave\n";
    $status->setText("Area 鼠标离开 ⬜");
    $area->invalidate();
};

$area->onMouseMove = function (MouseEvent $e) use ($status): void {
    $status->setText("Area 鼠标移动 ({$e->x}, {$e->y})");
};

$area->onMouseDown = function (MouseEvent $e) use ($status): void {
    echo "[事件] Area onMouseDown button={$e->button} at ({$e->x}, {$e->y})\n";
    $status->setText("Area 鼠标按下 {$e->button}（已获焦点）");
};

$area->onKeyDown = function (KeyEvent $e) use (&$lastKey, $status): void {
    $lastKey = sprintf('0x%02X', $e->keyCode);
    echo "[事件] Area onKeyDown keyCode={$lastKey}\n";
    // 常见键码提示
    $hint = match ($e->keyCode) {
        0x0D => 'Enter ↵',
        0x1B => 'Esc ⎋',
        0x25 => '←',
        0x26 => '↑',
        0x27 => '→',
        0x28 => '↓',
        0x20 => 'Space ␣',
        default => "key={$lastKey}",
    };
    $status->setText("Area 键盘按下: {$hint}");
};

$area->onKeyUp = function (KeyEvent $e) use ($status): void {
    echo "[事件] Area onKeyUp keyCode=0x" . sprintf('%02X', $e->keyCode) . "\n";
};

// ============================================================
// DateTimePicker DATE 模式
// ============================================================
$dateRow = $mkRow("仅日期 DATE:");
$dtpDate = new DateTimePicker($dateRow, DateTimePicker::MODE_DATE);
$dateRow->add($dtpDate);
// setTime 延迟到事件循环中调用（控件需要进入消息循环后才能正确响应）
$dtpDate->onChanged = function (DateTimePicker $dtp) use ($status): void {
    $t = $dtp->getTime();
    if ($t === null) {
        echo "[事件] DTP DATE onChanged: (未选择)\n";
        $status->setText("DTP DATE: 未选择");
    } else {
        echo "[事件] DTP DATE onChanged: {$t['year']}-{$t['month']}-{$t['day']}\n";
        $status->setText("DTP DATE: {$t['year']}-{$t['month']}-{$t['day']}");
    }
};

// ============================================================
// DateTimePicker TIME 模式
// ============================================================
$timeRow = $mkRow("仅时间 TIME:");
$dtpTime = new DateTimePicker($timeRow, DateTimePicker::MODE_TIME);
$timeRow->add($dtpTime);
// setTime 延迟到事件循环中调用
$dtpTime->onChanged = function (DateTimePicker $dtp) use ($status): void {
    $t = $dtp->getTime();
    if ($t === null) {
        echo "[事件] DTP TIME onChanged: (未选择)\n";
        $status->setText("DTP TIME: 未选择");
    } else {
        $h = str_pad((string) $t['hour'], 2, '0', STR_PAD_LEFT);
        $m = str_pad((string) $t['minute'], 2, '0', STR_PAD_LEFT);
        $s = str_pad((string) $t['second'], 2, '0', STR_PAD_LEFT);
        echo "[事件] DTP TIME onChanged: {$h}:{$m}:{$s}\n";
        $status->setText("DTP TIME: {$h}:{$m}:{$s}");
    }
};

// ============================================================
// DateTimePicker DATETIME 模式（自定义格式 yyyy-MM-dd HH:mm:ss）
// ============================================================
$dtRow = $mkRow("日期+时间 DATETIME:");
$dtpDt = new DateTimePicker($dtRow, DateTimePicker::MODE_DATETIME);
$dtRow->add($dtpDt);
// setTime 延迟到事件循环中调用
$dtpDt->onChanged = function (DateTimePicker $dtp) use ($status): void {
    $t = $dtp->getTime();
    if ($t === null) {
        echo "[事件] DTP DATETIME onChanged: (未选择)\n";
        $status->setText("DTP DATETIME: 未选择");
    } else {
        $h = str_pad((string) $t['hour'], 2, '0', STR_PAD_LEFT);
        $m = str_pad((string) $t['minute'], 2, '0', STR_PAD_LEFT);
        $s = str_pad((string) $t['second'], 2, '0', STR_PAD_LEFT);
        $text = "{$t['year']}-{$t['month']}-{$t['day']} {$h}:{$m}:{$s}";
        echo "[事件] DTP DATETIME onChanged: {$text}\n";
        $status->setText("DTP DATETIME: {$text}");
    }
};

// ============================================================
// 按钮：读取所有 DateTimePicker 当前值
// ============================================================
$btnRow = new HBox($root);
$root->add($btnRow);
$readBtn = new Button($btnRow, "读取所有日期时间 📋");
$btnRow->add($readBtn);
$readBtn->onClick = function () use ($dtpDate, $dtpTime, $dtpDt, $status): void {
    $d = $dtpDate->getTime();
    $t = $dtpTime->getTime();
    $dt = $dtpDt->getTime();
    echo "==== 当前值 ====\n";
    echo "DATE: " . ($d ? "{$d['year']}-{$d['month']}-{$d['day']}" : '(null)') . "\n";
    echo "TIME: " . ($t ? "{$t['hour']}:{$t['minute']}:{$t['second']}" : '(null)') . "\n";
    echo "DATETIME: " . ($dt ? "{$dt['year']}-{$dt['month']}-{$dt['day']} {$dt['hour']}:{$dt['minute']}:{$dt['second']}" : '(null)') . "\n";
    echo "================\n";
    $status->setText("已读取所有日期时间，详见控制台");
};

// 状态栏加入底部
$root->add($status);

$win->setChild($root);
$win->show();

echo "窗口标题: " . $win->getTitle() . "\n";
echo "已创建: Area(事件增强) / DateTimePicker(DATE/TIME/DATETIME)\n";

if (getenv('PHP_UI_AUTO_EXIT') === '1') {
    echo "PHP_UI_AUTO_EXIT=1，1 秒后设置时间，3 秒后读取值，5 秒后自动退出\n";
    // 延迟设置时间：控件需要进入消息循环后才能正确响应 DTM_SETSYSTEMTIME
    App::timer(1000, function () use ($dtpDate, $dtpTime, $dtpDt): void {
        echo ">>> 延迟设置时间\n";
        $dtpDate->setTime(2025, 12, 31);  // 使用非系统日期验证
        $dtpTime->setTime(2000, 1, 1, 14, 30, 0);
        $dtpDt->setTime(2025, 12, 31, 14, 30, 0);
    });
    App::timer(3000, function () use ($dtpDate, $dtpTime, $dtpDt, $status): void {
        $d = $dtpDate->getTime();
        $t = $dtpTime->getTime();
        $dt = $dtpDt->getTime();
        echo "==== 自动读取当前值 ====\n";
        echo "DATE: " . ($d ? "{$d['year']}-{$d['month']}-{$d['day']}" : '(null)') . "\n";
        echo "TIME: " . ($t ? "{$t['hour']}:{$t['minute']}:{$t['second']}" : '(null)') . "\n";
        echo "DATETIME: " . ($dt ? "{$dt['year']}-{$dt['month']}-{$dt['day']} {$dt['hour']}:{$dt['minute']}:{$dt['second']}" : '(null)') . "\n";
        echo "========================\n";
    });
    App::timer(5000, function (): void {
        echo "自动退出触发\n";
        App::quit();
    });
}

echo "进入事件循环（关闭窗口退出）...\n";
App::run();

echo "已退出\n";
