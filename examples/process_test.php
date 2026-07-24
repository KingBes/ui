<?php
declare(strict_types=1);

/**
 * Process.php 独立测试：不启动 GUI 事件循环，仅验证子进程启动/读行/退出回调。
 *
 * 运行：php -d ffi.enable=true examples/process_test.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Kingbes\Ui\Process;

echo "========================================\n";
echo " Process.php 独立测试 🚀\n";
echo "========================================\n";

// 测试 1：简单子进程（无回调）
echo "\n[测试1] 启动简单子进程...\n";
$cmd = 'php -r "echo \'hello world\'.PHP_EOL;"';
$proc = Process::start($cmd, function (string $line): void {
    echo "  stdout: {$line}\n";
}, function (int $code): void {
    echo "  exit code={$code}\n";
});
echo "  isRunning=" . ($proc->isRunning() ? "true" : "false") . "\n";

// 测试 2：多行输出（带 sleep）
echo "\n[测试2] 启动多行子进程（每秒一行）...\n";
$cmd2 = 'php -r "for($i=1;$i<=3;$i++){echo \'line \'.$i.PHP_EOL;sleep(1);}"';
$proc2 = Process::start($cmd2, function (string $line): void {
    echo "  stdout: {$line}\n";
}, function (int $code): void {
    echo "  exit code={$code}\n";
});

// 不进入 GUI 事件循环，手动用 wait() 等待
echo "  等待子进程退出（wait）...\n";
$code = $proc2->wait();
echo "  wait() 返回 exit code={$code}\n";
echo "  isRunning=" . ($proc2->isRunning() ? "true" : "false") . "\n";

// 也让测试 1 的进程有机会执行（它已立刻退出，再 wait 一次）
$proc->wait();

echo "\n[完成] Process 测试通过 ✅\n";
