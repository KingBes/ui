<?php
declare(strict_types=1);

namespace Kingbes\Ui;

use Kingbes\Ui\Exception\UiRuntimeException;

/**
 * 子进程封装（单线程模拟）。
 *
 * 由于 PHP 单线程无法真正后台执行，本类用 proc_open + 非阻塞管道 +
 * App::queueMain 轮询的方式在主事件循环中"时分复用"地读取子进程 stdout：
 *
 *   1. start() 调用 proc_open 创建子进程，stdin/stdout/stderr 设为非阻塞。
 *   2. 通过 App::queueMain 投递一个轮询闭包，每轮事件循环执行一次：
 *      - 非阻塞读取 stdout（fgets 返回 false 时表示暂无数据，break 退出本轮）；
 *      - 对每行（已去 \r\n）调用 $onLine 回调（在主线程同步执行）；
 *      - proc_get_status 检查进程是否退出；若退出则关闭管道/进程并触发 $onExit。
 *      - 仍在运行则继续 queueMain 下一轮。
 *   3. stop() 调用 proc_terminate(SIGTERM) 并关闭管道/进程。
 *   4. wait() 用于事件循环未运行的场景：手动轮询 stdout 并调用回调。
 *
 * 注意：
 *   - 进程不会真正后台执行，子进程与主循环共享同一 PHP 线程的 CPU 时间。
 *   - onLine/onExit 都在主线程同步执行（无需线程切换）。
 *   - 在事件循环运行中调用 wait() 会阻塞主循环，导致 queueMain 回调无法
 *     执行而死锁。仅在脚本未启动事件循环时使用 wait()。
 *
 * Windows 提示：
 *   - `dir`、`type` 等 cmd 内建命令需通过 `cmd /c dir` 启动。
 *   - `php -r "..."` 可直接运行，是测试本类的首选命令。
 *
 * 用法：
 *   $proc = Process::start(
 *       'php -r "for($i=0;$i<3;$i++){echo \'line \'.$i.\"\\n\";sleep(1);}"',
 *       function (string $line) use ($ta) { $ta->append($line . "\n"); },
 *       function (int $code) { echo "exit code=$code\n"; }
 *   );
 *   // 在事件循环中，每轮 queueMain 自动轮询 stdout
 */
class Process
{
    /**
     * proc_open 资源。
     *
     * @var resource|null
     */
    private $proc = null;

    /**
     * 管道数组：[stdin, stdout, stderr]。
     *
     * @var array<int, resource>
     */
    private array $pipes = [];

    /**
     * 是否仍在运行。
     */
    private bool $running = false;

    /**
     * 启动命令（原始字符串）。
     */
    private string $cmd;

    /**
     * 退出码（进程结束后填充；停止前为 -1）。
     */
    private int $exitCode = -1;

    /**
     * stdout 不完整行缓冲区。
     *
     * stream_select + fread 读取的数据追加到此缓冲区，按 \n 分割：
     * 完整行触发 onLine 回调，剩余不完整行留待下次读取或 EOF 时 flush。
     */
    private string $stdoutBuffer = '';

    /**
     * 每行 stdout 回调（在主线程同步执行）。
     */
    private ?\Closure $onLine = null;

    /**
     * 进程退出回调（在主线程同步执行）。
     */
    private ?\Closure $onExit = null;

    /**
     * 私有构造，强制通过 start() 创建。
     */
    private function __construct(string $cmd)
    {
        $this->cmd = $cmd;
    }

    /**
     * 启动子进程。
     *
     * @param string        $cmd    命令字符串（直接传给 proc_open）。
     * @param \Closure|null $onLine fn(string $line): void，每读到一行 stdout
     *                              在主线程同步调用（已去尾部 \r\n）。
     * @param \Closure|null $onExit fn(int $code): void，进程退出时在主线程
     *                              同步调用，$code 为退出码。
     * @return self
     * @throws UiRuntimeException proc_open 失败时抛出。
     */
    public static function start(string $cmd, ?\Closure $onLine = null, ?\Closure $onExit = null): self
    {
        $proc = new self($cmd);
        $proc->onLine = $onLine;
        $proc->onExit = $onExit;
        $proc->startProcess();
        return $proc;
    }

    /**
     * 实际启动子进程并注册轮询。
     */
    private function startProcess(): void
    {
        $descriptors = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];
        $this->proc = proc_open($this->cmd, $descriptors, $this->pipes);
        if (!is_resource($this->proc)) {
            throw new UiRuntimeException("Failed to start process: {$this->cmd}");
        }
        // stdout/stderr 非阻塞：fgets 无数据时立即返回 false
        stream_set_blocking($this->pipes[1], false);
        stream_set_blocking($this->pipes[2], false);
        $this->running = true;

        // 轮询闭包（自引用以递归投递下一轮）
        $readCallback = function () use (&$readCallback): void {
            if (!$this->running) {
                return;
            }
            if ($this->pollOnce()) {
                // 仍在运行：继续下一轮轮询
                App::queueMain($readCallback);
            }
            // pollOnce 返回 false 表示进程已退出，不再投递
        };

        App::queueMain($readCallback);
    }

    /**
     * 单次轮询：读取 stdout 行 + 检查进程状态。
     *
     * @return bool true=仍在运行（需继续轮询）；false=已退出（停止轮询）。
     */
    private function pollOnce(): bool
    {
        if (!is_resource($this->proc)) {
            $this->handleEof();
            return false;
        }

        // 读 stdout：非阻塞 stream_select + fread，可能检测到 EOF 并触发 handleEof
        $this->drainStdout();

        // drainStdout 已检测到 EOF 并处理退出，停止轮询
        if (!$this->running) {
            return false;
        }

        // 检查进程状态：proc_get_status 首次返回 running=false 时携带 exitcode
        $status = proc_get_status($this->proc);
        if (!$status['running']) {
            $this->exitCode = $status['exitcode'];
            $this->handleEof();
            return false;
        }
        return true;
    }

    /**
     * 非阻塞读取 stdout 所有可用数据，按 \n 分割行后调用 onLine 回调。
     *
     * 使用 stream_select($read, $write, $except, 0) + fread($pipe, 4096) 组合
     * 读取，避免 Windows 下 fgets 在无完整行时阻塞主事件循环。读取的数据追加
     * 到 $stdoutBuffer，按 \n 分割：完整行触发 onLine，剩余不完整行留在缓冲区。
     *
     * EOF 处理：stream_select 返回可读但 fread 返回空字符串时判定子进程结束，
     * 先 flush 缓冲区剩余数据为最后一行，再触发 onExit（经 handleEof）。
     *
     * 非阻塞：stream_select 返回 0 可读时立即退出，不等待数据。
     */
    private function drainStdout(): void
    {
        if (!isset($this->pipes[1]) || !is_resource($this->pipes[1])) {
            return;
        }

        $pipe = $this->pipes[1];

        while (true) {
            $read = [$pipe];
            $write = null;
            $except = null;

            // 非阻塞 poll：超时 0，立即返回可读性
            $selected = stream_select($read, $write, $except, 0);

            if ($selected === false) {
                // stream_select 出错，退出本轮
                return;
            }

            if ($selected === 0) {
                // 无数据可读，立即退出避免阻塞主事件循环
                return;
            }

            // 管道可读，读取最多 4096 字节
            $data = fread($pipe, 4096);

            if ($data === false) {
                // 读取出错，退出本轮
                return;
            }

            if ($data === '') {
                // stream_select 报告可读但 fread 返回空字符串 = EOF，子进程已结束
                // 先 flush 缓冲区剩余数据为最后一行，再触发退出流程
                $this->flushStdoutBuffer();
                $this->handleEof();
                return;
            }

            // 追加到缓冲区并按 \n 分割完整行
            $this->stdoutBuffer .= $data;
            while (($pos = strpos($this->stdoutBuffer, "\n")) !== false) {
                $line = substr($this->stdoutBuffer, 0, $pos);
                $this->stdoutBuffer = substr($this->stdoutBuffer, $pos + 1);
                // 去除行尾 \r（CRLF 换行）
                $line = rtrim($line, "\r");
                $this->dispatchLine($line);
            }
        }
    }

    /**
     * 将 stdoutBuffer 中剩余的不完整行作为最后一行触发 onLine 回调。
     */
    private function flushStdoutBuffer(): void
    {
        if ($this->stdoutBuffer === '') {
            return;
        }
        $line = rtrim($this->stdoutBuffer, "\r");
        $this->stdoutBuffer = '';
        $this->dispatchLine($line);
    }

    /**
     * 分发一行 stdout 给 onLine 回调（异常不中断主循环）。
     */
    private function dispatchLine(string $line): void
    {
        if ($this->onLine === null) {
            return;
        }
        try {
            ($this->onLine)($line);
        } catch (\Throwable $e) {
            trigger_error(
                'Process onLine callback error: ' . $e->getMessage(),
                \E_USER_WARNING
            );
        }
    }

    /**
     * 统一的进程退出处理（幂等，可由 drainStdout EOF 或 pollOnce proc_get_status 触发）。
     *
     * 流程：尝试读取管道剩余数据 → flush 缓冲区 → 关闭资源 → 触发 onExit。
     * 通过 running 标志保证只触发一次。
     */
    private function handleEof(): void
    {
        // 幂等：已处理过则直接返回
        if (!$this->running) {
            return;
        }

        // 先标记为已停止，防止 drainStdout 递归调用 handleEof 时重复触发
        $this->running = false;

        // 尝试读取管道剩余数据（defensive：proc_get_status 路径下可能仍有未读数据）
        $this->drainStdout();
        // flush 缓冲区中剩余的不完整行
        $this->flushStdoutBuffer();

        $this->closeResources();
        $this->callExit($this->exitCode);
    }

    /**
     * 安全调用 onExit 回调（异常不中断主循环）。
     */
    private function callExit(int $code): void
    {
        if ($this->onExit === null) {
            return;
        }
        try {
            ($this->onExit)($code);
        } catch (\Throwable $e) {
            trigger_error(
                'Process onExit callback error: ' . $e->getMessage(),
                \E_USER_WARNING
            );
        }
    }

    /**
     * 关闭管道与进程资源（幂等）。
     */
    private function closeResources(): void
    {
        foreach ($this->pipes as $i => $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
            unset($this->pipes[$i]);
        }
        if (is_resource($this->proc)) {
            proc_close($this->proc);
        }
        $this->proc = null;
    }

    /**
     * 进程是否仍在运行。
     */
    public function isRunning(): bool
    {
        return $this->running;
    }

    /**
     * 获取退出码（进程未退出时返回 -1）。
     */
    public function getExitCode(): int
    {
        return $this->exitCode;
    }

    /**
     * 终止子进程。
     *
     * 调用 proc_terminate(SIGTERM) 并立即关闭管道/进程资源；
     * 下一轮轮询闭包检测到 running=false 后会自然停止递归投递。
     * 不会触发 onExit 回调（避免与正常退出冲突）。
     */
    public function stop(): void
    {
        if (!$this->running) {
            return;
        }
        $this->running = false;
        if (is_resource($this->proc)) {
            // SIGTERM=15；Windows 上等价于 TerminateProcess
            proc_terminate($this->proc, 15);
        }
        $this->closeResources();
    }

    /**
     * 阻塞等待进程退出并返回退出码。
     *
     * 警告：在事件循环运行中调用本方法会导致死锁——主循环被阻塞后
     * queueMain 回调无法执行，轮询闭包不再触发。仅在脚本未启动事件循环
     * 时使用。本方法会同步轮询 stdout 并调用 onLine/onExit 回调。
     *
     * @return int 退出码；无法确定时返回 -1。
     */
    public function wait(): int
    {
        while ($this->running) {
            if (!is_resource($this->proc)) {
                $this->running = false;
                break;
            }
            $this->pollOnce();
            usleep(10000); // 10ms，避免 CPU 占用 100%
        }
        return $this->exitCode;
    }

    /**
     * 析构：兜底关闭资源，防止句柄泄漏。
     */
    public function __destruct()
    {
        $this->stop();
    }
}
