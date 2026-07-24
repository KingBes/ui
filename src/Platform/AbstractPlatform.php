<?php
declare(strict_types=1);

namespace Kingbes\Ui\Platform;

use Kingbes\Ui\Graphics\AttributedString;

/**
 * 平台后端共享基类。
 *
 * 提供跨平台共享的状态与逻辑：
 *   - 窗口/控件注册表（hwnd(int) => 实例）防 GC
 *   - 定时器表（id => [nextNs, intervalNs, callback]）基于 hrtime(true)
 *   - queueMain 待执行闭包队列
 *   - 退出确认回调
 *   - runTimers()/runQueueMain() 共享逻辑
 *
 * 子类必须实现所有平台相关的 abstract 方法（窗口/控件/菜单/对话框/
 * 绘图/事件循环/系统服务）。
 *
 * 注意：Window/Control 类在批次 1 Task 4 创建，本类在解析阶段不
 * 触发其自动加载（use 别名与方法签名只在运行时解析），因此可在
 * 它们之前定义。
 */
abstract class AbstractPlatform implements PlatformInterface
{
    /**
     * 窗口注册表：hwnd(int) => Window 实例。
     *
     * 用于：
     *   - 防止 Window 实例被 GC 回收（GWLP_USERDATA 仅存引用计数）
     *   - WM_SIZE/WM_CLOSE 等事件分发时由 hwnd 反查 Window 实例
     *
     * @var array<int, object>
     */
    protected array $windows = [];

    /**
     * 控件注册表：hwnd(int) => Control 实例。
     *
     * @var array<int, object>
     */
    protected array $controls = [];

    /**
     * 定时器表：id => [nextNs, intervalNs, callback]。
     *
     * nextNs/intervalNs 单位为纳秒（hrtime(true)）。
     *
     * @var array<int, array{0:int,1:int,2:\Closure}>
     */
    protected array $timers = [];

    /**
     * 待执行的 queueMain 闭包队列（FIFO）。
     *
     * @var list<\Closure>
     */
    protected array $queueMain = [];

    /**
     * 退出确认回调。返回 false 阻止退出。
     */
    protected ?\Closure $shouldQuitCallback = null;

    /**
     * 事件循环运行标志。
     */
    protected bool $running = false;

    /**
     * 定时器 ID 自增计数器。
     */
    private int $timerIdSeq = 0;

    /**
     * AttributedString 注册表：id => AttributedString 实例。
     *
     * 供 drawTextAttributed(id) 反查富文本对象。
     *
     * @var array<int, AttributedString>
     */
    protected array $attrStrings = [];

    /**
     * AttributedString ID 自增计数器。
     */
    private int $attrStringIdSeq = 0;

    // ============================================================
    // AttributedString 注册表（共享逻辑）
    // ============================================================

    /**
     * 分配一个新的 AttributedString ID。
     */
    public function allocAttrStringId(): int
    {
        return ++$this->attrStringIdSeq;
    }

    /**
     * 注册 AttributedString 实例（防 GC + drawTextAttributed 反查）。
     */
    public function registerAttrString(int $id, AttributedString $s): void
    {
        $this->attrStrings[$id] = $s;
    }

    /**
     * 按 ID 查找 AttributedString。
     */
    public function getAttrString(int $id): ?AttributedString
    {
        return $this->attrStrings[$id] ?? null;
    }

    /**
     * 注销 AttributedString。
     */
    public function unregisterAttrString(int $id): void
    {
        unset($this->attrStrings[$id]);
    }

    // ============================================================
    // 生命周期共享逻辑
    // ============================================================

    /**
     * 事件循环是否在运行。
     */
    public function isRunning(): bool
    {
        return $this->running;
    }

    /**
     * 注册窗口实例（防 GC + 事件反查）。
     *
     * @param object $window Window 实例。
     */
    public function registerWindow(int $hwnd, object $window): void
    {
        $this->windows[$hwnd] = $window;
    }

    /**
     * 注销窗口实例。
     */
    public function unregisterWindow(int $hwnd): void
    {
        unset($this->windows[$hwnd]);
    }

    /**
     * 注册控件实例（防 GC + 事件反查）。
     *
     * @param object $control Control 实例。
     */
    public function registerControl(int $hwnd, object $control): void
    {
        $this->controls[$hwnd] = $control;
    }

    /**
     * 注销控件实例。
     */
    public function unregisterControl(int $hwnd): void
    {
        unset($this->controls[$hwnd]);
    }

    /**
     * 根据 hwnd 查询窗口实例。
     *
     * @return object|null Window 实例，不存在返回 null。
     */
    public function findWindow(int $hwnd): ?object
    {
        return $this->windows[$hwnd] ?? null;
    }

    /**
     * 根据 hwnd 查询控件实例。
     *
     * @return object|null Control 实例，不存在返回 null。
     */
    public function findControl(int $hwnd): ?object
    {
        return $this->controls[$hwnd] ?? null;
    }

    // ============================================================
    // 定时器共享逻辑（基于 hrtime(true)）
    // ============================================================

    /**
     * 注册周期性定时器。
     *
     * 实现：将 [nextNs, intervalNs, callback] 写入 $timers 表。
     * 平台 run() 每轮调用 runTimers() 检查到期回调。
     *
     * @param int $intervalMs 间隔（毫秒），>0。
     * @return int 定时器 ID（>=1）。
     */
    public function timer(int $intervalMs, \Closure $cb): int
    {
        if ($intervalMs <= 0) {
            $intervalMs = 1;
        }
        $id = ++$this->timerIdSeq;
        $intervalNs = $intervalMs * 1_000_000;
        $now = hrtime(true);
        $this->timers[$id] = [$now + $intervalNs, $intervalNs, $cb];
        return $id;
    }

    /**
     * 取消定时器。
     */
    public function clearTimer(int $id): void
    {
        unset($this->timers[$id]);
    }

    /**
     * 检查所有定时器，触发到期回调并更新下次触发时间。
     *
     * 由平台 run() 在每轮事件循环中调用。
     *
     * 实现说明：先对 keys 取快照迭代，避免回调内 timer()/clearTimer()
     * 修改 $timers 表导致迭代行为不确定。回调内新增的定时器下一轮
     * 才会被检查。
     */
    protected function runTimers(): void
    {
        if ($this->timers === []) {
            return;
        }
        $now = hrtime(true);
        foreach (array_keys($this->timers) as $id) {
            // 回调可能已 clearTimer 当前 id，跳过
            if (!isset($this->timers[$id])) {
                continue;
            }
            $entry = $this->timers[$id];
            if ($now < $entry[0]) {
                continue;
            }
            // 触发前先更新 nextNs（即使回调内 clearTimer 也无副作用）
            $entry[0] = $now + $entry[1];
            $this->timers[$id] = $entry;
            try {
                ($entry[2])($id);
            } catch (\Throwable $e) {
                // 回调异常不中断主循环
                trigger_error(
                    sprintf('Timer #%d callback error: %s', $id, $e->getMessage()),
                    \E_USER_WARNING
                );
            }
        }
    }

    // ============================================================
    // queueMain 共享逻辑
    // ============================================================

    /**
     * 投递闭包到主线程队列，下一轮事件循环执行。
     *
     * 共享实现：push 到 $queueMain 后调用 wakeUpMainLoop() 唤醒
     * 可能阻塞的平台事件循环（如 Windows GetMessageA 阻塞）。
     * WindowsPlatform 重写 wakeUpMainLoop() 用 PostMessageA(WM_NULL)。
     */
    public function queueMain(\Closure $fn): void
    {
        $this->queueMain[] = $fn;
        $this->wakeUpMainLoop();
    }

    /**
     * 唤醒主线程事件循环的钩子。
     *
     * 默认空实现；WindowsPlatform 重写为 PostMessageA(WM_NULL)。
     * PeekMessageA 轮询模式下不需要唤醒，保持空即可。
     */
    protected function wakeUpMainLoop(): void
    {
        // 默认空实现；非阻塞轮询模式无需唤醒
    }

    /**
     * 执行所有待处理的 queueMain 闭包。
     *
     * 由平台 run() 在每轮事件循环中调用。回调异常不中断主循环。
     */
    protected function runQueueMain(): void
    {
        if ($this->queueMain === []) {
            return;
        }
        // 取出当前队列快照后清空，避免回调内 queueMain 死循环
        $pending = $this->queueMain;
        $this->queueMain = [];
        foreach ($pending as $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                trigger_error(
                    sprintf('queueMain callback error: %s', $e->getMessage()),
                    \E_USER_WARNING
                );
            }
        }
    }

    // ============================================================
    // 退出确认共享逻辑
    // ============================================================

    /**
     * 注册退出确认回调。
     *
     * 回调签名：fn(): bool；返回 false 阻止退出。
     */
    public function onShouldQuit(?\Closure $cb): void
    {
        $this->shouldQuitCallback = $cb;
    }

    /**
     * 询问 shouldQuitCallback 是否允许退出。
     *
     * 平台 quit() 实现应先调用本方法，返回 false 则不退出。
     */
    protected function shouldQuit(): bool
    {
        if ($this->shouldQuitCallback === null) {
            return true;
        }
        try {
            return (bool) ($this->shouldQuitCallback)();
        } catch (\Throwable $e) {
            trigger_error(
                sprintf('shouldQuit callback error: %s', $e->getMessage()),
                \E_USER_WARNING
            );
            return true;
        }
    }

    // ============================================================
    // triggerRelayout 共享逻辑（异步重布局）
    // ============================================================

    /**
     * 触发窗口顶层容器的异步重布局。
     *
     * 共享实现：通过 queueMain 投递一个布局任务，在下一轮事件循环中
     * 查找窗口的 toplevel 容器并调用其 layout() 方法。
     *
     * 平台 run() 的 WM_SIZE 处理可调用本方法触发自适应布局。
     */
    public function triggerRelayout(int $hwnd): void
    {
        $this->queueMain(function () use ($hwnd): void {
            $this->relayoutWindowNow($hwnd);
        });
    }

    /**
     * 立即对窗口的顶层容器执行重布局（同步）。
     *
     * 内部辅助：查找 hwnd 对应的 Window 实例，取其 toplevel 子容器
     * （getChildContainer 方法，Task 4 定义），获取客户区尺寸后调用
     * container->layout(0, 0, w, h)。
     */
    protected function relayoutWindowNow(int $hwnd): void
    {
        $window = $this->windows[$hwnd] ?? null;
        if ($window === null) {
            return;
        }
        // Window::getChildContainer() 在 Task 4 定义
        if (!method_exists($window, 'getChildContainer')) {
            return;
        }
        /** @var object|null $container */
        $container = $window->getChildContainer();
        if ($container === null) {
            return;
        }
        // Container::layout(int,int,int,int): void 在 Task 4 定义
        if (!method_exists($container, 'layout')) {
            return;
        }
        $size = $this->windowGetClientSize($hwnd);
        // 先设置顶层 Container 自身的位置和尺寸到 Window 客户区。
        // controlCreate 创建 Container 时初始尺寸为 0x0，若不显式设置，
        // 子控件会被 0x0 客户区裁剪导致窗口空白。
        $containerHwnd = $container->getHwnd();
        if ($containerHwnd !== 0) {
            $this->controlSetBounds($containerHwnd, 0, 0, $size->width, $size->height);
        }
        $container->layout(0, 0, $size->width, $size->height);
    }
}
