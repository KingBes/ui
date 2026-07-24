<?php
declare(strict_types=1);

/**
 * 窗口高级特性测试示例。
 *
 * 运行：php -d ffi.enable=true examples/window_advanced_test.php
 * 自动退出：PHP_UI_AUTO_EXIT=1 php -d ffi.enable=true examples/window_advanced_test.php
 *   （PowerShell：$env:PHP_UI_AUTO_EXIT='1'; php -d ffi.enable=true examples/window_advanced_test.php）
 *
 * 演示 Window / App / Geometry 高级 API：
 *   - Window: setTitle / setPosition+getPosition / setSize+getSize / setResizeable
 *             minimize / hide+show / isFocused / onFocus / close
 *             getChildContainer / getMenu / 多窗口
 *   - App:    onShouldQuit / clearTimer / queueMain
 *   - Geometry: Point::of/zero/toArray/$x/$y  Size::of/zero/toArray/$width/$height
 *
 * 布局：
 *   - 主窗口 VBox：顶部状态 Label + 中间 HBox(多列按钮) + 底部事件日志 Label
 *   - 第二个窗口：VBox + Label + Button(close)
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Kingbes\Ui\App;
use Kingbes\Ui\Window;
use Kingbes\Ui\Layout\VBox;
use Kingbes\Ui\Layout\HBox;
use Kingbes\Ui\Control\Button;
use Kingbes\Ui\Control\Label;
use Kingbes\Ui\Dialogs;
use Kingbes\Ui\Menu\Menu;
use Kingbes\Ui\Geometry\Point;
use Kingbes\Ui\Geometry\Size;

echo "========================================\n";
echo " PHP UI 窗口高级特性测试 🪟\n";
echo "========================================\n";

$screen = App::platform()->screenSize();
echo "屏幕尺寸: {$screen->width} x {$screen->height}\n";

// ============================================================
// 创建主窗口
// ============================================================
$win = new Window("PHP UI 窗口高级特性 - 🪟 中文 😀", 900, 640);
$win->onClose = function (): void {
    echo "[主窗口] onClose 触发，调用 App::quit 退出主循环\n";
    App::quit();
};

// 顶层 VBox：顶部状态 + 中间按钮区 + 底部日志
$root = new VBox($win);
$root->setPadding(6);

// 顶部状态 Label
$status = new Label(
    $root,
    "就绪 - 点击下方按钮测试窗口高级特性 ✅",
    Label::ALIGN_CENTER
);
$root->add($status);

// 中间按钮区 HBox（多列 VBox）
$btnArea = new HBox($root);
$root->add($btnArea);
$btnArea->setPadding(6);

// 底部事件日志 Label（显示最近若干条事件）
$eventLog = new Label(
    $root,
    "事件日志：等待操作...",
    Label::ALIGN_LEFT
);
$root->add($eventLog);

// ============================================================
// 辅助函数
// ============================================================
/**
 * 在 $btnArea 内追加一列 VBox，含标题 Label。
 */
$mkColumn = function (string $title) use ($btnArea): VBox {
    $col = new VBox($btnArea);
    $btnArea->add($col);
    $col->setPadding(4);
    $titleLab = new Label($col, $title, Label::ALIGN_CENTER);
    $col->add($titleLab);
    return $col;
};

/**
 * 在指定列中创建按钮（已 add 进列）。
 */
$mkBtn = function (VBox $col, string $text): Button {
    $btn = new Button($col, $text);
    $col->add($btn);
    return $btn;
};

/** 事件历史（保留最近 3 条）。 */
$eventHistory = [];

/** 写日志：控制台输出 + 底部 Label 显示最近 3 条。 */
$log = function (string $msg) use ($eventLog, &$eventHistory): void {
    echo "[日志] {$msg}\n";
    $eventHistory[] = $msg;
    if (count($eventHistory) > 3) {
        array_shift($eventHistory);
    }
    $eventLog->setText("日志: " . implode(" | ", $eventHistory));
};

/** 更新顶部状态 Label。 */
$setStatus = function (string $msg) use ($status): void {
    $status->setText($msg);
};

// ============================================================
// 状态变量
// ============================================================
$resizeable = true;          // 当前是否可缩放
$win2 = null;                // 第二个窗口引用
$periodicTimerId = 0;        // 周期定时器 ID
$shouldQuitRegistered = false; // onShouldQuit 是否已注册

// ============================================================
// Window::onFocus 焦点变化事件（注册在主窗口上）
// ============================================================
$win->onFocus = function (bool $focused) use ($log, $setStatus): void {
    $msg = $focused ? "获得焦点 🔍" : "失去焦点 🌫️";
    echo "[事件] Window onFocus: {$msg}\n";
    $log("Window::onFocus → {$msg}");
    $setStatus("焦点状态: {$msg}");
};

// ============================================================
// 列 1：标题 / 位置 / 尺寸
// ============================================================
$col1 = $mkColumn("标题/位置/尺寸");

// 1. Window::setTitle —— 动态修改标题为时间戳
$btnTitle = $mkBtn($col1, "设置标题为时间戳 🕐");
$btnTitle->onClick = function () use ($win, $log, $setStatus): void {
    $ts = date('Y-m-d H:i:s');
    $title = "标题已更新 {$ts} 🕐";
    $win->setTitle($title);
    echo "[操作] Window::setTitle → {$title}\n";
    $log("Window::setTitle → {$ts}");
    $setStatus("标题已修改为时间戳");
};

// 2. Window::setPosition / getPosition —— 移动到 (200,200) 并读取
$btnPos = $mkBtn($col1, "移动到 (200,200) 📍");
$btnPos->onClick = function () use ($win, $log, $setStatus): void {
    $win->setPosition(200, 200);
    $pt = $win->getPosition();
    echo "[操作] Window::setPosition(200,200) → 读取 Point(x={$pt->x}, y={$pt->y})\n";
    $log("Window::getPosition → ({$pt->x}, {$pt->y})");
    $setStatus("窗口位置: ({$pt->x}, {$pt->y})");
};

// 3. Window::setSize / getSize —— 设置 800x600 并读取
$btnSize = $mkBtn($col1, "设置尺寸 800x600 📐");
$btnSize->onClick = function () use ($win, $log, $setStatus): void {
    $win->setSize(800, 600);
    $sz = $win->getSize();
    echo "[操作] Window::setSize(800,600) → 读取 Size(width={$sz->width}, height={$sz->height})\n";
    $log("Window::getSize → {$sz->width}x{$sz->height}");
    $setStatus("窗口尺寸: {$sz->width}x{$sz->height}");
};

// 4. Window::setResizeable —— 切换可缩放
$btnResizeable = $mkBtn($col1, "切换可缩放 🔒");
$btnResizeable->onClick = function () use ($win, &$resizeable, $log, $setStatus): void {
    $resizeable = !$resizeable;
    $win->setResizeable($resizeable);
    $state = $resizeable ? "可缩放" : "不可缩放";
    echo "[操作] Window::setResizeable(" . ($resizeable ? "true" : "false") . ")\n";
    $log("Window::setResizeable → {$state}");
    $setStatus("窗口：{$state}");
};

// ============================================================
// 列 2：窗口状态
// ============================================================
$col2 = $mkColumn("窗口状态");

// 5. Window::minimize —— 最小化
$btnMin = $mkBtn($col2, "最小化 ➖");
$btnMin->onClick = function () use ($win, $log, $setStatus): void {
    $win->minimize();
    echo "[操作] Window::minimize\n";
    $log("Window::minimize 已调用");
    $setStatus("窗口已最小化");
};

// 6. Window::hide / show —— 隐藏 1 秒后再显示（定时器）
$btnHide = $mkBtn($col2, "隐藏1秒后显示 👻");
$btnHide->onClick = function () use ($win, $log, $setStatus): void {
    $win->hide();
    echo "[操作] Window::hide（1 秒后 show）\n";
    $log("Window::hide → 1 秒后 show");
    $setStatus("窗口已隐藏，1 秒后恢复");
    App::timer(1000, function () use ($win, $log, $setStatus): void {
        $win->show();
        echo "[操作] Window::show（定时器触发）\n";
        $log("Window::show（定时器触发）");
        $setStatus("窗口已恢复显示");
    });
};

// 7. Window::isFocused —— 查询焦点状态
$btnFocused = $mkBtn($col2, "查询焦点状态 ❓");
$btnFocused->onClick = function () use ($win, $log, $setStatus): void {
    $f = $win->isFocused();
    $msg = $f ? "已聚焦" : "未聚焦";
    echo "[操作] Window::isFocused → " . ($f ? "true" : "false") . "\n";
    $log("Window::isFocused → {$msg}");
    $setStatus("当前焦点: {$msg}");
};

// 8. onFocus 事件已注册提示
$labFocusTip = new Label($col2, "（onFocus 事件已注册）", Label::ALIGN_CENTER);
$col2->add($labFocusTip);

// ============================================================
// 列 3：访问器 / 多窗口 / 关闭
// ============================================================
$col3 = $mkColumn("访问器/多窗口");

// 10. Window::getChildContainer / getMenu —— 访问器演示
$btnAccessors = $mkBtn($col3, "演示访问器 🔍");
$btnAccessors->onClick = function () use ($win, $log, $setStatus): void {
    $child = $win->getChildContainer();
    $menu = $win->getMenu();
    $childInfo = $child === null ? "null" : ("VBox#" . spl_object_id($child));
    $menuInfo = $menu === null ? "null" : ("Menu#" . spl_object_id($menu));
    echo "[操作] Window::getChildContainer → {$childInfo}\n";
    echo "[操作] Window::getMenu → {$menuInfo}\n";
    $log("getChildContainer={$childInfo}; getMenu={$menuInfo}");
    $setStatus("访问器已调用，详情见日志");
};

// 11. 多窗口 —— 打开新窗口
$btnOpenWin2 = $mkBtn($col3, "打开新窗口 🆕");
$btnOpenWin2->onClick = function () use (&$win2, $log, $setStatus): void {
    if ($win2 !== null) {
        // 已存在则先关闭再重建
        $win2->close();
        $win2 = null;
    }
    $win2 = new Window("第二窗口 🪟 子窗口", 400, 300);
    $v2 = new VBox($win2);
    $v2->setPadding(8);
    $lab2 = new Label(
        $v2,
        "这是第二个窗口\n可独立关闭，不影响主窗口\n(对比 Window::close 与 App::quit)",
        Label::ALIGN_CENTER
    );
    $v2->add($lab2);
    $btnClose2 = new Button($v2, "关闭本窗口 (Window::close)");
    $v2->add($btnClose2);

    // 第二窗口的关闭处理器：仅销毁自身，不调用 App::quit
    $closeWin2 = function () use (&$win2, $log, $setStatus): void {
        if ($win2 === null) {
            return;
        }
        $win2->close(); // 幂等：hwnd===0 时跳过
        $win2 = null;
        echo "[操作] 第二窗口 Window::close（仅销毁窗口，不退出主循环）\n";
        $log("Window::close（第二窗口）");
        $setStatus("第二窗口已关闭（对比 App::quit）");
    };
    $btnClose2->onClick = $closeWin2;
    $win2->onClose = $closeWin2;

    $win2->setChild($v2);
    $win2->show();
    echo "[操作] 创建并显示第二窗口\n";
    $log("多窗口 → 第二窗口已创建");
    $setStatus("第二窗口已打开");
};

// 9. Window::close —— 关闭新窗口（演示与 App::quit 的区别）
$btnCloseWin2 = $mkBtn($col3, "关闭新窗口 ❌");
$btnCloseWin2->onClick = function () use (&$win2, $log, $setStatus): void {
    if ($win2 === null) {
        $log("无第二窗口可关闭");
        $setStatus("第二窗口未打开");
        return;
    }
    $win2->close();
    $win2 = null;
    echo "[操作] 主窗口按钮触发 第二窗口 Window::close\n";
    $log("Window::close（主窗口触发）");
    $setStatus("第二窗口已通过主窗口 close()");
};

// App::quit 对比按钮 —— 退出整个事件循环
$btnQuitCompare = $mkBtn($col3, "App::quit 退出 ⏹️");
$btnQuitCompare->onClick = function () use ($log): void {
    echo "[操作] App::quit（退出整个事件循环，与 Window::close 区别）\n";
    $log("App::quit → 退出主循环");
    App::quit();
};

// ============================================================
// 列 4：App 回调
// ============================================================
$col4 = $mkColumn("App 回调");

// 12. App::onShouldQuit —— 退出确认（Dialogs::msgBoxAsk）
$btnShouldQuit = $mkBtn($col4, "注册退出确认 🤔");
$btnShouldQuit->onClick = function () use (&$shouldQuitRegistered, $win, $log, $setStatus): void {
    if ($shouldQuitRegistered) {
        App::onShouldQuit(null);
        $shouldQuitRegistered = false;
        echo "[操作] App::onShouldQuit(null) 已清除\n";
        $log("App::onShouldQuit → 已清除");
        $setStatus("退出确认已清除");
    } else {
        App::onShouldQuit(function () use ($win): bool {
            echo "[回调] App::onShouldQuit 触发，询问用户\n";
            $ok = Dialogs::msgBoxAsk($win, "确定退出？❓", "退出确认");
            echo "[回调] 用户选择: " . ($ok ? "是 → 允许退出" : "否 → 阻止退出") . "\n";
            return $ok;
        });
        $shouldQuitRegistered = true;
        echo "[操作] App::onShouldQuit 已注册（再次点击清除）\n";
        $log("App::onShouldQuit → 已注册");
        $setStatus("退出确认已注册（关闭主窗口时询问）");
    }
};

// 13. App::clearTimer —— 启动周期定时器（每秒打印）
$btnStartTimer = $mkBtn($col4, "启动周期定时器 ⏱️");
$btnStartTimer->onClick = function () use (&$periodicTimerId, $log, $setStatus): void {
    if ($periodicTimerId !== 0) {
        $log("周期定时器已在运行 id={$periodicTimerId}");
        $setStatus("定时器已在运行 id={$periodicTimerId}");
        return;
    }
    $periodicTimerId = App::timer(1000, function (int $id) use ($log): void {
        $ts = date('H:i:s');
        echo "[定时器 #{$id}] tick {$ts}\n";
        $log("timer #{$id} tick {$ts}");
    });
    echo "[操作] App::timer(1000ms) → id={$periodicTimerId}\n";
    $log("App::timer → id={$periodicTimerId}");
    $setStatus("周期定时器已启动 id={$periodicTimerId}");
};

// 13. App::clearTimer —— 取消周期定时器
$btnClearTimer = $mkBtn($col4, "取消周期定时器 🛑");
$btnClearTimer->onClick = function () use (&$periodicTimerId, $log, $setStatus): void {
    if ($periodicTimerId === 0) {
        $log("无定时器可取消");
        $setStatus("无定时器可取消");
        return;
    }
    $id = $periodicTimerId;
    App::clearTimer($id);
    $periodicTimerId = 0;
    echo "[操作] App::clearTimer({$id})\n";
    $log("App::clearTimer({$id}) 已取消");
    $setStatus("定时器 #{$id} 已取消");
};

// 14. App::queueMain —— 投递 3 个闭包，依次执行
$btnQueueMain = $mkBtn($col4, "投递3个闭包 📨");
$btnQueueMain->onClick = function () use ($log, $setStatus): void {
    echo "[操作] App::queueMain 投递 3 个闭包（依次执行）\n";
    $log("App::queueMain × 3 已投递");
    $setStatus("3 个闭包已投递，等待依次执行");
    for ($i = 1; $i <= 3; $i++) {
        App::queueMain(function () use ($i, $log): void {
            echo "[queueMain] 第 {$i} 个闭包执行\n";
            $log("queueMain #{$i} 执行完成");
        });
    }
};

// ============================================================
// 列 5：Point / Size 值对象
// ============================================================
$col5 = $mkColumn("Point / Size");

// 15. Point::of / zero / toArray / $x / $y
$btnPointDemo = $mkBtn($col5, "Point 值对象 🎯");
$btnPointDemo->onClick = function () use ($win, $log, $setStatus): void {
    // 用 getPosition 获取实际 Point
    $p = $win->getPosition();
    // 演示 Point 静态工厂与原点
    $pOf = Point::of(100, 200);
    $pZero = Point::zero();
    $arr = $p->toArray();
    echo "[操作] Point 演示:\n";
    echo "  getPosition → Point(x={$p->x}, y={$p->y})\n";
    echo "  toArray → [" . implode(", ", $arr) . "]\n";
    echo "  Point::of(100,200) → ({$pOf->x}, {$pOf->y})\n";
    echo "  Point::zero() → ({$pZero->x}, {$pZero->y})\n";
    $log("Point: pos=({$p->x},{$p->y}) of=({$pOf->x},{$pOf->y}) zero=({$pZero->x},{$pZero->y})");
    $setStatus("Point: 实际位置 ({$p->x}, {$p->y})");
};

// 16. Size::of / zero / toArray / $width / $height
$btnSizeDemo = $mkBtn($col5, "Size 值对象 📦");
$btnSizeDemo->onClick = function () use ($win, $log, $setStatus): void {
    // 用 getSize 获取实际 Size
    $s = $win->getSize();
    // 演示 Size 静态工厂与零尺寸
    $sOf = Size::of(640, 480);
    $sZero = Size::zero();
    $arr = $s->toArray();
    echo "[操作] Size 演示:\n";
    echo "  getSize → Size(width={$s->width}, height={$s->height})\n";
    echo "  toArray → [" . implode(", ", $arr) . "]\n";
    echo "  Size::of(640,480) → ({$sOf->width}x{$sOf->height})\n";
    echo "  Size::zero() → ({$sZero->width}x{$sZero->height})\n";
    $log("Size: size={$s->width}x{$s->height} of={$sOf->width}x{$sOf->height} zero={$sZero->width}x{$sZero->height}");
    $setStatus("Size: 实际尺寸 {$s->width}x{$s->height}");
};

// ============================================================
// 菜单栏（用于 getMenu 访问器演示）
// ============================================================
$menuBar = new Menu(true);
$demoMenu = new Menu(false);
$menuBar->addSubmenu("演示", $demoMenu);
$demoItem = $demoMenu->addItem("点击菜单项");
$demoItem->onClick = function () use ($log, $setStatus): void {
    echo "[菜单] 演示 → 点击菜单项\n";
    $log("菜单项被点击");
    $setStatus("菜单项被点击");
};
$win->setMenu($menuBar);

// 挂载顶层容器并显示
$win->setChild($root);
$win->show();

echo "----------------------------------------\n";
echo "已就绪，可测试功能点:\n";
echo "  标题/位置/尺寸: setTitle/setPosition/getPosition/setSize/getSize/setResizeable\n";
echo "  窗口状态: minimize/hide/show/isFocused/onFocus\n";
echo "  访问器/多窗口: getChildContainer/getMenu/close/打开新窗口\n";
echo "  App 回调: onShouldQuit/clearTimer/queueMain\n";
echo "  几何对象: Point::of/zero/toArray Size::of/zero/toArray\n";
echo "----------------------------------------\n";

// 自动退出（CI/无人值守）
if (getenv('PHP_UI_AUTO_EXIT') === '1') {
    echo "PHP_UI_AUTO_EXIT=1，5 秒后自动退出\n";
    App::timer(5000, function (): void {
        echo "自动退出触发\n";
        App::quit();
    });
}

echo "进入事件循环（关闭主窗口或等待自动退出）...\n";
App::run();

echo "已退出\n";
