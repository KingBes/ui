<?php
declare(strict_types=1);

/**
 * 系统托盘 + 窗口图标测试示例。
 *
 * 运行：php -d ffi.enable=true examples/tray_test.php
 *
 * 覆盖功能：
 *   - 系统托盘图标（Shell_NotifyIconW）
 *     * 从预定义系统图标加载（IDI_APPLICATION）
 *     * 从 Image 对象加载（PNG，GDI+ GdipCreateHICONFromBitmap）
 *     * 左键点击：显示/隐藏窗口
 *     * 双击：显示气球通知（点击气球触发 onBalloonClick）
 *     * 右键菜单：显示窗口/发送通知/切换图标/退出
 *     * 气球通知（Balloon Tip，4 种类型 NONE/INFO/WARNING/ERROR）
 *   - 窗口图标（WM_SETICON）
 *     * 从预定义系统图标设置（IDI_ASTERISK）
 *     * 从 Image 对象设置（PNG，支持 alpha 透明通道）
 *
 * 测试 PNG 图标由 PHP GD 扩展运行时生成到临时目录。
 *
 * 交互：
 *   - 点击「切换托盘图标」按钮循环切换托盘图标（系统图标 ↔ 自定义 PNG）
 *   - 点击「发送气球通知」按钮显示 4 种类型的气球
 *   - 点击「修改提示文本」按钮修改托盘 tooltip
 *   - 关闭窗口时窗口隐藏到托盘（不退出），通过托盘菜单退出
 *   - 设环境变量 PHP_UI_AUTO_EXIT=1 时，7 秒后自动退出（CI/无人值守）
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Kingbes\Ui\App;
use Kingbes\Ui\Window;
use Kingbes\Ui\TrayIcon;
use Kingbes\Ui\Layout\VBox;
use Kingbes\Ui\Layout\HBox;
use Kingbes\Ui\Control\Button;
use Kingbes\Ui\Control\Label;
use Kingbes\Ui\Menu\Menu;
use Kingbes\Ui\Graphics\Image;

echo "========================================\n";
echo " PHP UI 系统托盘 + 窗口图标测试 🔔\n";
echo "========================================\n";

// ============================================================
// 0. 用 GD 生成自定义 PNG 图标（32x32 彩色，带 alpha 透明）
// ============================================================
$tmpDir = sys_get_temp_dir() . '/php_ui_tray';
if (!is_dir($tmpDir)) {
    @mkdir($tmpDir, 0777, true);
}

/**
 * 生成一个 32x32 彩色 PNG 图标（圆形 + 字母）。
 */
function genPngIcon(string $file, int $bg, int $fg, string $letter): void
{
    $img = imagecreatetruecolor(32, 32);
    // 启用 alpha 合成
    imagealphablending($img, false);
    imagesavealpha($img, true);
    // 全透明背景
    $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
    imagefilledrectangle($img, 0, 0, 31, 31, $transparent);
    // 圆形背景
    imagealphablending($img, true);
    $bgC = imagecolorallocate($img, ($bg >> 16) & 0xFF, ($bg >> 8) & 0xFF, $bg & 0xFF);
    imagefilledellipse($img, 16, 16, 28, 28, $bgC);
    // 字母（白色）
    $fgC = imagecolorallocate($img, ($fg >> 16) & 0xFF, ($fg >> 8) & 0xFF, $fg & 0xFF);
    imagestring($img, 5, 11, 8, $letter, $fgC);

    imagepng($img, $file);
}

$pngIcons = [
    'red_P'    => $tmpDir . '/icon_red_P.png',
    'green_U'  => $tmpDir . '/icon_green_U.png',
    'blue_X'   => $tmpDir . '/icon_blue_X.png',
];
genPngIcon($pngIcons['red_P'],   0xC0392B, 0xFFFFFF, 'P');
genPngIcon($pngIcons['green_U'], 0x27AE60, 0xFFFFFF, 'U');
genPngIcon($pngIcons['blue_X'],  0x2980B9, 0xFFFFFF, 'X');
echo "自定义 PNG 图标已生成到: {$tmpDir}\n";

// 加载 Image 对象
$pngImages = [];
foreach ($pngIcons as $name => $path) {
    $pngImages[$name] = Image::fromFile($path);
    echo "  已加载: {$name}\n";
}

// ============================================================
// 1. 创建主窗口（设置 Image 自定义图标）
// ============================================================
$win = new Window("PHP UI 托盘测试 🔔", 640, 480);
$win->onClose = function () use (&$win): void {
    // 关闭按钮不退出，而是隐藏到托盘
    $win->hide();
    echo "[事件] 窗口隐藏到托盘（通过托盘菜单退出）\n";
};

// 从 Image 对象设置窗口图标（PNG，支持 alpha 透明通道）
$win->setIconFromImage($pngImages['red_P']);
echo "[构建] 窗口图标已设置（自定义 PNG 红色 P 图标）\n";

$win->setMargined(10);

$root = new VBox($win);
$root->setPadding(8);

$status = new Label($root, "就绪 - 关闭窗口将隐藏到托盘", Label::ALIGN_CENTER);
$root->add($status);

// ============================================================
// 2. 创建系统托盘（从 Image 加载自定义图标）
// ============================================================
echo "\n[构建] 系统托盘...\n";
$tray = new TrayIcon($win, 'PHP UI 托盘测试');
$tray->setIconFromImage($pngImages['green_U']);
echo "  托盘图标已添加（自定义 PNG 绿色 U 图标）\n";

// 托盘左键点击：切换窗口显示/隐藏
$tray->onClick = function () use ($win, $status): void {
    if ($win->isFocused()) {
        $win->hide();
        $status->setText("窗口已隐藏（托盘左键点击）");
        echo "[托盘] 左键点击 -> 隐藏窗口\n";
    } else {
        $win->show();
        $status->setText("窗口已显示（托盘左键点击）");
        echo "[托盘] 左键点击 -> 显示窗口\n";
    }
};

// 托盘双击：显示气球通知
$tray->onDoubleClick = function () use ($tray, $status): void {
    $tray->showBalloon('双击事件', '您双击了托盘图标 - 点击此气球有回调', TrayIcon::BALLOON_INFO, 3000);
    $status->setText("托盘双击 -> 显示气球通知（点击气球有回调）");
    echo "[托盘] 双击 -> 显示气球\n";
};

// 气球被点击回调（用户点击气球通知时触发）
$tray->onBalloonClick = function () use ($status): void {
    $status->setText("气球通知被点击！");
    echo "[托盘] 气球通知被用户点击 -> onBalloonClick 触发\n";
};

// 气球超时消失回调
$tray->onBalloonTimeout = function () use ($status): void {
    $status->setText("气球通知超时消失");
    echo "[托盘] 气球通知超时消失 -> onBalloonTimeout 触发\n";
};

// ============================================================
// 3. 托盘右键菜单
// ============================================================
$trayMenu = new Menu(false);

$showItem = $trayMenu->addItem('显示窗口');
$showItem->onClick = function () use ($win, $status): void {
    $win->show();
    $status->setText("通过托盘菜单显示窗口");
    echo "[菜单] 显示窗口\n";
};

$hideItem = $trayMenu->addItem('隐藏窗口');
$hideItem->onClick = function () use ($win, $status): void {
    $win->hide();
    $status->setText("通过托盘菜单隐藏窗口");
    echo "[菜单] 隐藏窗口\n";
};

$trayMenu->addSeparator();

$balloonInfo = $trayMenu->addItem('发送信息通知');
$balloonInfo->onClick = function () use ($tray, $status): void {
    $tray->showBalloon('信息', '这是一条信息通知', TrayIcon::BALLOON_INFO, 5000);
    $status->setText("发送信息通知");
    echo "[菜单] 气球通知: 信息\n";
};

$balloonWarn = $trayMenu->addItem('发送警告通知');
$balloonWarn->onClick = function () use ($tray, $status): void {
    $tray->showBalloon('警告', '这是一条警告通知', TrayIcon::BALLOON_WARNING, 5000);
    $status->setText("发送警告通知");
    echo "[菜单] 气球通知: 警告\n";
};

$balloonError = $trayMenu->addItem('发送错误通知');
$balloonError->onClick = function () use ($tray, $status): void {
    $tray->showBalloon('错误', '这是一条错误通知', TrayIcon::BALLOON_ERROR, 5000);
    $status->setText("发送错误通知");
    echo "[菜单] 气球通知: 错误\n";
};

$trayMenu->addSeparator();

// 切换托盘图标：系统图标 ↔ 自定义 PNG 循环
$iconIdx = 0;
$iconCycle = [
    ['sys', TrayIcon::IDI_APPLICATION, 'APPLICATION', null],
    ['sys', TrayIcon::IDI_HAND,        'HAND',        null],
    ['sys', TrayIcon::IDI_QUESTION,    'QUESTION',    null],
    ['sys', TrayIcon::IDI_EXCLAMATION, 'EXCLAMATION', null],
    ['sys', TrayIcon::IDI_ASTERISK,    'ASTERISK',    null],
    ['img', 0,                          'PNG red_P',   $pngImages['red_P']],
    ['img', 0,                          'PNG green_U', $pngImages['green_U']],
    ['img', 0,                          'PNG blue_X',  $pngImages['blue_X']],
];
$toggleIconItem = $trayMenu->addItem('切换托盘图标');
$toggleIconItem->onClick = function () use ($tray, $iconCycle, &$iconIdx, $status): void {
    $iconIdx = ($iconIdx + 1) % count($iconCycle);
    [$kind, $sysId, $name, $img] = $iconCycle[$iconIdx];
    if ($kind === 'sys') {
        $tray->setIconFromIconId($sysId);
    } else {
        $tray->setIconFromImage($img);
    }
    $status->setText("托盘图标切换为 {$name}");
    echo "[菜单] 切换托盘图标 -> {$name}\n";
};

$trayMenu->addSeparator();

$quitItem = $trayMenu->addItem('退出');
$quitItem->onClick = function (): void {
    echo "[菜单] 退出\n";
    App::quit();
};

$tray->setContextMenu($trayMenu);
echo "  托盘右键菜单已设置（显示/隐藏/通知/切换图标/退出）\n";

// ============================================================
// 4. 控制按钮
// ============================================================
echo "\n[构建] 控制按钮...\n";

$btnRow1 = new HBox($root);
$root->add($btnRow1);
$btnRow1->add(new Label($btnRow1, "托盘操作:"));

// 切换托盘图标（与托盘菜单共用同一组循环）
$toggleBtn = new Button($btnRow1, "切换托盘图标");
$btnRow1->add($toggleBtn);
$toggleBtn->onClick = function () use ($tray, $iconCycle, &$iconIdx, $status): void {
    $iconIdx = ($iconIdx + 1) % count($iconCycle);
    [$kind, $sysId, $name, $img] = $iconCycle[$iconIdx];
    if ($kind === 'sys') {
        $tray->setIconFromIconId($sysId);
    } else {
        $tray->setIconFromImage($img);
    }
    $status->setText("托盘图标切换为 {$name}");
    echo "[按钮] 切换托盘图标 -> {$name}\n";
};

// 发送气球通知
$balloonBtn = new Button($btnRow1, "发送气球通知");
$btnRow1->add($balloonBtn);
$balloonTypeIdx = 0;
$balloonTypes = [
    [TrayIcon::BALLOON_INFO, '信息', '这是一条信息通知'],
    [TrayIcon::BALLOON_WARNING, '警告', '这是一条警告通知'],
    [TrayIcon::BALLOON_ERROR, '错误', '这是一条错误通知'],
    [TrayIcon::BALLOON_NONE, '无图标', '这是一条无图标通知'],
];
$balloonBtn->onClick = function () use ($tray, $balloonTypes, &$balloonTypeIdx, $status): void {
    [$type, $title, $msg] = $balloonTypes[$balloonTypeIdx % count($balloonTypes)];
    $tray->showBalloon($title, $msg, $type, 5000);
    $status->setText("气球通知: {$title}");
    echo "[按钮] 气球通知 -> {$title}\n";
    $balloonTypeIdx++;
};

// 修改提示文本
$tooltipBtn = new Button($btnRow1, "修改提示文本");
$btnRow1->add($tooltipBtn);
$tooltipIdx = 0;
$tooltips = ['PHP UI 托盘测试', '运行中...', '点击我有惊喜', 'PHP UI v1.0'];
$tooltipBtn->onClick = function () use ($tray, $tooltips, &$tooltipIdx, $status): void {
    $text = $tooltips[$tooltipIdx % count($tooltips)];
    $tray->setTooltip($text);
    $status->setText("提示文本修改为: {$text}");
    echo "[按钮] 修改提示文本 -> {$text}\n";
    $tooltipIdx++;
};

// 第二行按钮
$btnRow2 = new HBox($root);
$root->add($btnRow2);
$btnRow2->add(new Label($btnRow2, "窗口操作:"));

// 隐藏窗口
$hideBtn = new Button($btnRow2, "隐藏窗口到托盘");
$btnRow2->add($hideBtn);
$hideBtn->onClick = function () use ($win, $status): void {
    $win->hide();
    $status->setText("窗口已隐藏");
    echo "[按钮] 隐藏窗口\n";
};

// 切换窗口图标（系统图标 ↔ 自定义 PNG）
$winIconBtn = new Button($btnRow2, "切换窗口图标");
$btnRow2->add($winIconBtn);
$winIconIdx = 0;
$winIconCycle = [
    ['sys', TrayIcon::IDI_APPLICATION, 'APPLICATION', null],
    ['sys', TrayIcon::IDI_HAND,        'HAND',        null],
    ['sys', TrayIcon::IDI_QUESTION,    'QUESTION',    null],
    ['sys', TrayIcon::IDI_EXCLAMATION, 'EXCLAMATION', null],
    ['sys', TrayIcon::IDI_ASTERISK,    'ASTERISK',    null],
    ['img', 0,                          'PNG red_P',   $pngImages['red_P']],
    ['img', 0,                          'PNG green_U', $pngImages['green_U']],
    ['img', 0,                          'PNG blue_X',  $pngImages['blue_X']],
];
$winIconBtn->onClick = function () use ($win, $winIconCycle, &$winIconIdx, $status): void {
    $winIconIdx = ($winIconIdx + 1) % count($winIconCycle);
    [$kind, $sysId, $name, $img] = $winIconCycle[$winIconIdx];
    if ($kind === 'sys') {
        $win->setIconFromId($sysId);
    } else {
        $win->setIconFromImage($img);
    }
    $status->setText("窗口图标切换为 {$name}");
    echo "[按钮] 切换窗口图标 -> {$name}\n";
};

// 退出
$quitBtn = new Button($btnRow2, "退出应用");
$btnRow2->add($quitBtn);
$quitBtn->onClick = function (): void {
    echo "[按钮] 退出应用\n";
    App::quit();
};

$root->add($status);

$win->setChild($root);
$win->show();

echo "\n窗口已创建。测试要点：\n";
echo "  - 系统托盘区域可见 PHP UI 图标（鼠标悬停显示提示）\n";
echo "  - 左键点击托盘：切换窗口显示/隐藏\n";
echo "  - 双击托盘：显示气球通知\n";
echo "  - 右键托盘：弹出菜单（显示/隐藏/通知/切换图标/退出）\n";
echo "  - 关闭窗口：隐藏到托盘而非退出\n";
echo "  - 窗口标题栏 + 任务栏显示蓝色 i 图标\n";
echo "  - 控制按钮：切换托盘图标/发送气球/修改提示/隐藏窗口/切换窗口图标/退出\n";

// ============================================================
// 5. 自动测试序列
// ============================================================
if (getenv('PHP_UI_AUTO_EXIT') === '1') {
    echo "\nPHP_UI_AUTO_EXIT=1，运行自动测试序列\n";
    // 1秒后发送信息气球
    App::timer(1000, function () use ($tray): void {
        echo ">>> 自动测试: 发送信息气球\n";
        $tray->showBalloon('自动测试', '信息类型气球 - 点击有回调', TrayIcon::BALLOON_INFO, 2000);
    });
    // 2秒后切换托盘图标为系统图标
    App::timer(2000, function () use ($tray): void {
        echo ">>> 自动测试: 切换托盘图标为 IDI_HAND\n";
        $tray->setIconFromIconId(TrayIcon::IDI_HAND);
    });
    // 3秒后切换托盘图标为 PNG 自定义
    App::timer(3000, function () use ($tray, $pngImages): void {
        echo ">>> 自动测试: 切换托盘图标为 PNG blue_X\n";
        $tray->setIconFromImage($pngImages['blue_X']);
    });
    // 4秒后修改提示文本
    App::timer(4000, function () use ($tray): void {
        echo ">>> 自动测试: 修改提示文本\n";
        $tray->setTooltip('自动测试 - 运行中');
    });
    // 5秒后发送警告气球
    App::timer(5000, function () use ($tray): void {
        echo ">>> 自动测试: 发送警告气球\n";
        $tray->showBalloon('警告', '警告类型气球', TrayIcon::BALLOON_WARNING, 2000);
    });
    // 6秒后切换窗口图标为 PNG 自定义
    App::timer(6000, function () use ($win, $pngImages): void {
        echo ">>> 自动测试: 切换窗口图标为 PNG green_U\n";
        $win->setIconFromImage($pngImages['green_U']);
    });
    // 7秒后退出
    App::timer(7000, function (): void {
        echo ">>> 自动退出触发\n";
        App::quit();
    });
}

echo "\n进入事件循环（通过托盘菜单退出）...\n";
App::run();

echo "已退出\n";
