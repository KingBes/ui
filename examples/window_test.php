<?php
declare(strict_types=1);

/**
 * 批次 2 窗口测试示例。
 *
 * 运行：php -d ffi.enable=true examples/window_test.php
 *
 * 交互：
 *   - 窗口正常显示后可拖动、缩放、最小化/最大化、关闭。
 *   - 关闭窗口即退出。
 *   - 设环境变量 PHP_UI_AUTO_EXIT=1 时，3 秒后自动退出（CI/无人值守）。
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Kingbes\Ui\App;
use Kingbes\Ui\Window;

echo "========================================\n";
echo " PHP UI 批次2 窗口测试\n";
echo "========================================\n";

// 屏幕尺寸
$screen = App::platform()->screenSize();
echo "屏幕尺寸: {$screen->width} x {$screen->height}\n";

// 创建窗口
$win = new Window("PHP UI 测试 - 批次2", 640, 480);

// 关闭回调：退出主循环
$win->onClose = fn() => App::quit();

// 尺寸变化回调
$win->onResize = function ($ev) {
    static $count = 0;
    $count++;
    if ($count <= 3) {
        echo "窗口尺寸变化 #{$count}: {$ev->width} x {$ev->height}\n";
    }
};

// 显示窗口
$win->show();

// 验证标题读写
echo "窗口标题: " . $win->getTitle() . "\n";

// 验证尺寸/位置
$size = $win->getSize();
echo "窗口尺寸: {$size->width} x {$size->height}\n";
$client = $win->getClientSize();
echo "客户区尺寸: {$client->width} x {$client->height}\n";

// 置顶 1 秒后取消（演示 windowSetTopmost）
App::queueMain(function () use ($win) {
    $win->setTopmost(true);
    echo "已置顶\n";
});
App::timer(1000, function () use ($win) {
    static $done = false;
    if (!$done) {
        $done = true;
        $win->setTopmost(false);
        echo "已取消置顶\n";
    }
});

// 自动退出（CI/无人值守）
if (getenv('PHP_UI_AUTO_EXIT') === '1') {
    echo "PHP_UI_AUTO_EXIT=1，3 秒后自动退出\n";
    App::timer(3000, function () {
        echo "自动退出触发\n";
        App::quit();
    });
}

echo "进入事件循环（关闭窗口退出）...\n";
App::run();

echo "已退出\n";
