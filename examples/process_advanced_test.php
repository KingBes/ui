<?php
declare(strict_types=1);

/**
 * Process 高级特性与菜单动态化演示。
 *
 * 运行：php -d ffi.enable=true examples/process_advanced_test.php
 *
 * 演示内容：
 *   Process 高级特性：
 *     - Process::start       启动长时间运行的子进程，逐行输出到 TextArea
 *     - Process::stop        主动终止子进程（按钮/菜单触发）
 *     - Process::isRunning   查询进程运行状态
 *     - Process::getExitCode 查询退出码（自然退出返回 0，stop 后返回 -1）
 *     - Process::onExit      进程退出回调（在 start 的第三参数中注册）
 *
 *   菜单动态化：
 *     - Menu::isBar          查询是否为菜单栏
 *     - Menu::getItems       查询菜单项列表
 *     - Menu::destroy        销毁菜单（动态卸载并用新菜单替换）
 *     - MenuItem::getText    查询菜单项文本
 *     - MenuItem::isEnabled  查询启用状态
 *     - MenuItem::isSeparator 判断是否分隔符
 *     - MenuItem::getSubmenu 查询子菜单
 *     - 多级子菜单           工具 > 子菜单 > 孙子菜单（三级嵌套）
 *     - 动态修改菜单项状态   运行时 setEnabled / setChecked 切换
 *
 * 设环境变量 PHP_UI_AUTO_EXIT=1 时，5 秒后自动退出（CI/无人值守）。
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Kingbes\Ui\App;
use Kingbes\Ui\Window;
use Kingbes\Ui\Process;
use Kingbes\Ui\Layout\VBox;
use Kingbes\Ui\Layout\HBox;
use Kingbes\Ui\Control\Button;
use Kingbes\Ui\Control\Label;
use Kingbes\Ui\Control\TextArea;
use Kingbes\Ui\Menu\Menu;

echo "========================================\n";
echo " Process 高级特性 + 菜单动态化 🚀\n";
echo "========================================\n";

$screen = App::platform()->screenSize();
echo "屏幕尺寸: {$screen->width} x {$screen->height}\n";

// ============================================================
// 共享状态（提前定义，供闭包按引用捕获）
// ============================================================
$state = (object)[
    'proc' => null,       // 当前 Process 实例
    'menuBar' => null,    // 当前菜单栏
    'startItem' => null,  // "启动进程"菜单项引用（用于动态切换状态）
    'menuVersion' => 0,   // 菜单版本号（替换菜单时递增）
];

// ============================================================
// 窗口与布局
// ============================================================
$win = new Window("PHP UI 进程高级特性 + 菜单动态化 🚀 中文", 900, 540);
$win->onClose = function () use ($state): void {
    // 关闭窗口前停止子进程，防止句柄泄漏
    if ($state->proc !== null && $state->proc->isRunning()) {
        $state->proc->stop();
    }
    App::quit();
};

// 顶层 VBox：顶部按钮栏 / 中间日志 / 底部状态
$root = new VBox($win);
$root->setPadding(4);

// 顶部 HBox：进程控制按钮 + 菜单操作按钮
$topBar = new HBox($root);
$topBar->setPadding(4);
$root->add($topBar);

// 中间 TextArea：进程输出与菜单操作日志
$ta = new TextArea($root, "进程输出与菜单操作日志将显示在此...\r\n");
$root->add($ta);

// 底部 Label：进程状态
$statusLabel = new Label($root, "进程状态：未启动", Label::ALIGN_LEFT);
$root->add($statusLabel);

$win->setChild($root);

// ============================================================
// 日志辅助：同时输出到控制台与 TextArea
// ============================================================
$logLines = [];
$appendLog = function (string $msg) use ($ta, &$logLines): void {
    $ts = date('H:i:s');
    $line = "[{$ts}] {$msg}";
    echo $line . "\n";
    $logLines[] = $line;
    // 仅保留最后 200 行，避免文本过长
    if (count($logLines) > 200) {
        array_shift($logLines);
    }
    $ta->setText(implode("\r\n", $logLines));
};

// ============================================================
// 菜单结构递归描述（演示 isBar/getItems/getText/isEnabled/
// isSeparator/getSubmenu 全套查询 API）
// ============================================================
$describeMenu = null;
$describeMenu = function (Menu $menu, string $indent = '') use (&$describeMenu, $appendLog): void {
    $isBar = $menu->isBar() ? 'true' : 'false';
    $items = $menu->getItems();
    $appendLog(sprintf('%sMenu(isBar=%s, getItems=%d)', $indent, $isBar, count($items)));
    foreach ($items as $item) {
        if ($item->isSeparator()) {
            $appendLog(sprintf('%s  - [分隔符] isSeparator=true', $indent));
            continue;
        }
        $text = $item->getText();
        $enabled = $item->isEnabled() ? 'true' : 'false';
        $sub = $item->getSubmenu();
        $hasSub = $sub !== null ? 'true' : 'false';
        $appendLog(sprintf(
            '%s  - [项] getText="%s", isEnabled=%s, getSubmenu=%s',
            $indent,
            $text,
            $enabled,
            $hasSub
        ));
        if ($sub !== null) {
            $describeMenu($sub, $indent . '    ');
        }
    }
};

// ============================================================
// 动作闭包（按钮与菜单项共用）
// ============================================================

// --- Process::start ---
// 启动一个长时间运行的子进程，每行 stdout 输出到 TextArea
$startProcess = function () use ($state, $appendLog, $statusLabel): void {
    if ($state->proc !== null && $state->proc->isRunning()) {
        $appendLog('进程已在运行中，请先停止');
        return;
    }
    $cmd = 'php -r "for($i=1;$i<=20;$i++){echo \'line \'.$i.PHP_EOL;sleep(1);}"';
    $appendLog("Process::start 启动子进程: {$cmd}");
    $state->proc = Process::start(
        $cmd,
        // onLine 回调：每读到一行 stdout 触发
        function (string $line) use ($appendLog): void {
            $appendLog("  stdout: {$line}");
        },
        // onExit 回调：进程自然退出时触发（stop() 不触发）
        function (int $code) use ($appendLog, $statusLabel): void {
            $appendLog("Process onExit 回调触发：exitCode={$code}");
            $statusLabel->setText("进程状态：已停止 (onExit exitCode={$code})");
        }
    );
    $running = $state->proc->isRunning();
    $appendLog("进程已启动，isRunning=" . ($running ? 'true' : 'false'));
    $statusLabel->setText("进程状态：运行中");
};

// --- Process::stop ---
// 主动终止子进程（注意：stop 不会触发 onExit 回调）
$stopProcess = function () use ($state, $appendLog, $statusLabel): void {
    if ($state->proc === null || !$state->proc->isRunning()) {
        $appendLog('进程未在运行，无需停止');
        return;
    }
    $state->proc->stop();
    // stop 后查询退出码（应为 -1，因为 stop 不设置退出码）
    $code = $state->proc->getExitCode();
    $appendLog("Process::stop 已调用，isRunning=false, getExitCode={$code}");
    $statusLabel->setText("进程状态：已停止 (手动停止, exitCode={$code})");
};

// --- Process::isRunning + Process::getExitCode ---
// 查询进程当前状态与退出码
$queryStatus = function () use ($state, $appendLog): void {
    if ($state->proc === null) {
        $appendLog('尚未启动任何进程');
        return;
    }
    $running = $state->proc->isRunning();
    $code = $state->proc->getExitCode();
    $appendLog(sprintf(
        '查询状态：isRunning=%s, getExitCode=%d%s',
        $running ? 'true' : 'false',
        $code,
        $code === -1 ? ' (未退出或被 stop)' : ''
    ));
};

// --- 菜单结构查询（演示 isBar/getItems/getText/isEnabled/isSeparator/getSubmenu）---
$queryMenu = function () use ($state, $appendLog, $describeMenu): void {
    if ($state->menuBar === null) {
        $appendLog('无菜单栏');
        return;
    }
    $appendLog('========== 菜单结构查询开始 ==========');
    $describeMenu($state->menuBar);
    $appendLog('========== 菜单结构查询结束 ==========');
};

// --- 动态修改菜单项状态：setEnabled ---
$toggleEnabled = function () use ($state, $appendLog): void {
    if ($state->startItem === null) {
        $appendLog('"启动进程"菜单项不存在');
        return;
    }
    $current = $state->startItem->isEnabled();
    $new = !$current;
    $state->startItem->setEnabled($new);
    $appendLog(sprintf(
        'setEnabled："启动进程" isEnabled %s → %s',
        $current ? 'true' : 'false',
        $new ? 'true' : 'false'
    ));
};

// --- 动态修改菜单项状态：setChecked ---
$toggleChecked = function () use ($state, $appendLog): void {
    if ($state->startItem === null) {
        $appendLog('"启动进程"菜单项不存在');
        return;
    }
    $current = $state->startItem->isChecked();
    $new = !$current;
    $state->startItem->setChecked($new);
    $appendLog(sprintf(
        'setChecked："启动进程" isChecked %s → %s',
        $current ? 'true' : 'false',
        $new ? 'true' : 'false'
    ));
};

// --- 关于 ---
$about = function () use ($appendLog): void {
    $appendLog('关于：PHP UI 进程高级特性与菜单动态化演示 v1.0 ℹ️');
};

// ============================================================
// 菜单构建（支持动态替换）
// ============================================================
$buildMenu = null;

// --- Menu::destroy + 动态替换菜单栏 ---
$replaceMenu = function () use (&$buildMenu, $state, $appendLog): void {
    $appendLog('--- 替换菜单：销毁旧菜单栏 ---');
    if ($state->menuBar !== null) {
        $state->menuBar->destroy(); // Menu::destroy 递归销毁子菜单
        $appendLog('旧菜单栏已 destroy()');
    }
    $buildMenu(true);
    $appendLog('新菜单栏已构建并 setMenu()');
};

// 菜单构建闭包：构建完整菜单栏并挂载到窗口
$buildMenu = function (bool $isReplacement = false) use (
    $state, $win, $appendLog,
    $startProcess, $stopProcess, $queryMenu, $toggleEnabled, $toggleChecked, $about
): void {
    $version = ++$state->menuVersion;
    $procLabel = $isReplacement ? "进程(v{$version})" : '进程';

    $menuBar = new Menu(true); // 菜单栏（isBar=true）

    // ------------------------------------------------------------
    // 进程菜单：启动进程 / 停止进程 / 分隔符 / 退出
    // ------------------------------------------------------------
    $procMenu = new Menu(false);
    $menuBar->addSubmenu($procLabel, $procMenu);

    $startItem = $procMenu->addItem('启动进程');
    $startItem->onClick = $startProcess;

    $stopItem = $procMenu->addItem('停止进程');
    $stopItem->onClick = $stopProcess;

    $procMenu->addSeparator(); // 分隔符（isSeparator=true）

    $exitItem = $procMenu->addItem('退出');
    $exitItem->onClick = function (): void {
        App::quit();
    };

    // ------------------------------------------------------------
    // 工具菜单：查询菜单信息 / 切换启用 / 切换勾选 / 子菜单(三级嵌套)
    // ------------------------------------------------------------
    $toolMenu = new Menu(false);
    $menuBar->addSubmenu('工具', $toolMenu);

    $queryMenuItem = $toolMenu->addItem('查询菜单信息');
    $queryMenuItem->onClick = $queryMenu;

    $toggleEnItem = $toolMenu->addItem('切换"启动进程"启用状态');
    $toggleEnItem->onClick = $toggleEnabled;

    $toggleChkItem = $toolMenu->addItem('切换"启动进程"勾选状态');
    $toggleChkItem->onClick = $toggleChecked;

    // 子菜单（第二级）
    $subMenu = new Menu(false);
    $toolMenu->addSubmenu('子菜单', $subMenu);

    $subItem1 = $subMenu->addItem('子工具1');
    $subItem1->onClick = function () use ($appendLog): void {
        $appendLog('点击：工具 → 子菜单 → 子工具1');
    };

    $subItem2 = $subMenu->addItem('子工具2');
    $subItem2->onClick = function () use ($appendLog): void {
        $appendLog('点击：工具 → 子菜单 → 子工具2');
    };

    // 孙子菜单（第三级，getSubmenu 可递归查询）
    $grandMenu = new Menu(false);
    $subMenu->addSubmenu('孙子菜单', $grandMenu);

    $deepItem1 = $grandMenu->addItem('深层工具1');
    $deepItem1->onClick = function () use ($appendLog): void {
        $appendLog('点击：工具 → 子菜单 → 孙子菜单 → 深层工具1（三级嵌套）');
    };

    $deepItem2 = $grandMenu->addItem('深层工具2');
    $deepItem2->onClick = function () use ($appendLog): void {
        $appendLog('点击：工具 → 子菜单 → 孙子菜单 → 深层工具2（三级嵌套）');
    };

    // ------------------------------------------------------------
    // 帮助菜单：关于
    // ------------------------------------------------------------
    $helpMenu = new Menu(false);
    $menuBar->addSubmenu('帮助', $helpMenu);

    $aboutItem = $helpMenu->addItem('关于');
    $aboutItem->onClick = $about;

    // 保存状态并挂载
    $state->menuBar = $menuBar;
    $state->startItem = $startItem;
    $win->setMenu($menuBar);

    $appendLog($isReplacement
        ? "菜单栏已重建 (v{$version})，isBar=" . ($menuBar->isBar() ? 'true' : 'false')
        : '菜单栏已构建并挂载，isBar=' . ($menuBar->isBar() ? 'true' : 'false'));
};

// 初始化菜单栏
$buildMenu(false);

// ============================================================
// 顶部按钮栏
// ============================================================
$startBtn = new Button($topBar, '启动进程');
$stopBtn = new Button($topBar, '停止进程');
$statusBtn = new Button($topBar, '查询状态');
$queryMenuBtn = new Button($topBar, '查询菜单');
$toggleBtn = new Button($topBar, '切换菜单项状态');
$replaceBtn = new Button($topBar, '替换菜单');

$topBar->add($startBtn);
$topBar->add($stopBtn);
$topBar->add($statusBtn);
$topBar->add($queryMenuBtn);
$topBar->add($toggleBtn);
$topBar->add($replaceBtn);

// 按钮事件
$startBtn->onClick = $startProcess;
$stopBtn->onClick = $stopProcess;
$statusBtn->onClick = $queryStatus;
$queryMenuBtn->onClick = $queryMenu;
$toggleBtn->onClick = $toggleEnabled; // 按钮触发 setEnabled
$replaceBtn->onClick = $replaceMenu;

// ============================================================
// 显示窗口
// ============================================================
$win->show();
echo "窗口标题: " . $win->getTitle() . "\n";
echo "菜单结构:\n";
echo "  进程(启动进程/停止进程/---/退出)\n";
echo "  工具(查询菜单信息/切换启用/切换勾选/子菜单(子工具1/子工具2/孙子菜单(深层工具1/深层工具2)))\n";
echo "  帮助(关于)\n";

// ============================================================
// 自动退出（CI/无人值守）
// ============================================================
if (getenv('PHP_UI_AUTO_EXIT') === '1') {
    echo "PHP_UI_AUTO_EXIT=1，5 秒后自动退出\n";
    App::timer(5000, function () use ($state): void {
        // 退出前停止子进程
        if ($state->proc !== null && $state->proc->isRunning()) {
            $state->proc->stop();
        }
        echo "自动退出触发\n";
        App::quit();
    });
}

echo "进入事件循环（点击按钮或菜单项查看效果）...\n";
App::run();

echo "已退出\n";
