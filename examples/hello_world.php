<?php
declare(strict_types=1);

/**
 * 最小化 Hello World 示例。
 *
 * 演示 App::run + Window + Label + onClosing 的最基础用法。
 *
 * 运行（需开启 FFI）：
 *   php -d ffi.enable=true -f examples/hello_world.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Kingbes\Ui\App;
use Kingbes\Ui\Window;
use Kingbes\Ui\Label;

App::run(function () {
    // 创建窗口并装入 Label
    $window = new Window("Hello World", 300, 200);
    $window->setChild(new Label("Hello, World!"));

    // 注册关闭回调：退出主循环
    $window->onClosing(function () {
        App::quit();
    });

    // 显示窗口
    $window->show();
});
