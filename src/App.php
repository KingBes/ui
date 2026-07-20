<?php
declare(strict_types=1);

namespace Kingbes\Ui;

use Kingbes\Ui\Platform\Platform;

/**
 * 应用入口。
 *
 * 封装平台后端的生命周期管理，使用者只需在闭包中构建界面并启动主循环。
 *
 * 用法示例：
 *   App::run(function () {
 *       $win = Platform::current()->windowCreate('Demo', 400, 300);
 *       Platform::current()->windowShow($win);
 *   });
 */
class App
{
    /**
     * 已创建的顶层窗口引用表。
     *
     * Window 构造时注册到这里，close/destroy 时注销。
     * 目的：防止用户在 App::run 闭包内创建的 Window 因闭包结束
     * 被 PHP GC 回收（Control::__destruct 会调 destroy 销毁窗口），
     * 导致 main() 主循环尚未运行窗口就消失了。
     *
     * @var array<int, Window>
     */
    private static array $windows = [];

    /**
     * 注册顶层窗口，保持引用防 GC。
     *
     * Window 构造时自动调用，用户无需手动调用。
     */
    public static function registerWindow(Window $window): void
    {
        self::$windows[spl_object_id($window)] = $window;
    }

    /**
     * 注销顶层窗口。
     *
     * Window::close/destroy 时自动调用。
     */
    public static function unregisterWindow(Window $window): void
    {
        unset(self::$windows[spl_object_id($window)]);
    }

    /**
     * 启动应用：初始化平台、执行用户主函数、进入主循环，
     * 无论是否抛异常都会在 finally 中调用 uninit 释放资源。
     *
     * @param \Closure $main 用户主函数，在其中创建窗口与控件
     */
    public static function run(\Closure $main): void
    {
        $platform = Platform::current();
        $platform->init();
        try {
            $main();
            $platform->main();
        } finally {
            self::$windows = [];
            $platform->uninit();
        }
    }

    /**
     * 退出主事件循环。
     */
    public static function quit(): void
    {
        Platform::current()->quit();
    }

    /**
     * 注册定时器。
     *
     * @param int      $ms  间隔毫秒数
     * @param \Closure $cb  回调函数
     */
    public static function timer(int $ms, \Closure $cb): void
    {
        Platform::current()->timer($ms, $cb);
    }

    /**
     * 执行一次事件循环迭代。
     *
     * @param bool $wait 是否阻塞等待事件
     * @return bool 是否仍有事件需要处理
     */
    public static function mainStep(bool $wait = false): bool
    {
        return Platform::current()->mainStep($wait);
    }
}
