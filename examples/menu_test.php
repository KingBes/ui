<?php
declare(strict_types=1);

/**
 * 批次 4 菜单系统测试示例（Task 18）。
 *
 * 运行：php -d ffi.enable=true examples/menu_test.php
 *
 * 演示内容：
 *   - 菜单栏：文件(新建/打开/保存/分隔/退出)、编辑(撤销/重做/分隔/查找)、
 *             视图(状态栏[勾选]/工具栏[勾选]/全屏)、帮助(关于)
 *   - "退出"调 App::quit()
 *   - "新建"显示消息（Dialogs 批次5未实现，用 echo 替代）
 *   - "状态栏"菜单项可勾选切换
 *   - "撤销"初始禁用，点"重做"后启用"撤销"
 *   - 中文菜单文本
 *
 * 设环境变量 PHP_UI_AUTO_EXIT=1 时，5 秒后自动退出。
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Kingbes\Ui\App;
use Kingbes\Ui\Window;
use Kingbes\Ui\Layout\VBox;
use Kingbes\Ui\Control\Label;
use Kingbes\Ui\Menu\Menu;

echo "========================================\n";
echo " PHP UI 批次4 菜单系统测试 📋\n";
echo "========================================\n";

$screen = App::platform()->screenSize();
echo "屏幕尺寸: {$screen->width} x {$screen->height}\n";

// 创建窗口
$win = new Window("PHP UI 菜单测试 - 📋 中文菜单系统", 640, 480);
$win->onClose = fn() => App::quit();

// ============================================================
// 内容区：简单的状态标签
// ============================================================
$root = new VBox($win);
$status = new Label($root, "就绪 - 请使用菜单栏操作 ✅ 中文测试", Label::ALIGN_CENTER);
$root->add($status);
$win->setChild($root);

// ============================================================
// 菜单栏构建
// ============================================================
$menuBar = new Menu(true); // 菜单栏

// ------------------------------------------------------------
// 文件菜单：新建/打开/保存/分隔/退出
// ------------------------------------------------------------
$fileMenu = new Menu(false);
$menuBar->addSubmenu("文件", $fileMenu);

$newItem = $fileMenu->addItem("新建");
$openItem = $fileMenu->addItem("打开");
$saveItem = $fileMenu->addItem("保存");
$fileMenu->addSeparator();
$exitItem = $fileMenu->addItem("退出");

$newItem->onClick = function () use ($status): void {
    echo "[菜单] 文件 → 新建\n";
    $status->setText("操作: 新建文件 📄");
};
$openItem->onClick = function () use ($status): void {
    echo "[菜单] 文件 → 打开\n";
    $status->setText("操作: 打开文件 📂");
};
$saveItem->onClick = function () use ($status): void {
    echo "[菜单] 文件 → 保存\n";
    $status->setText("操作: 保存文件 💾");
};
$exitItem->onClick = function (): void {
    echo "[菜单] 文件 → 退出，调用 App::quit()\n";
    App::quit();
};

// ------------------------------------------------------------
// 编辑菜单：撤销/重做/分隔/查找
// 演示：撤销初始禁用，点重做后启用撤销
// ------------------------------------------------------------
$editMenu = new Menu(false);
$menuBar->addSubmenu("编辑", $editMenu);

$undoItem = $editMenu->addItem("撤销");
$redoItem = $editMenu->addItem("重做");
$editMenu->addSeparator();
$findItem = $editMenu->addItem("查找");

// 撤销初始禁用
$undoItem->setEnabled(false);

$undoItem->onClick = function () use ($status, $undoItem): void {
    echo "[菜单] 编辑 → 撤销\n";
    $status->setText("操作: 撤销 ↩️");
    // 撤销后再次禁用（模拟无操作可撤销）
    $undoItem->setEnabled(false);
};
$redoItem->onClick = function () use ($status, $undoItem): void {
    echo "[菜单] 编辑 → 重做（启用撤销）\n";
    $status->setText("操作: 重做 ↪️（撤销已启用）");
    // 点重做后启用撤销
    $undoItem->setEnabled(true);
};
$findItem->onClick = function () use ($status): void {
    echo "[菜单] 编辑 → 查找\n";
    $status->setText("操作: 查找 🔍");
};

// ------------------------------------------------------------
// 视图菜单：状态栏[勾选]/工具栏[勾选]/全屏
// 演示：勾选切换
// ------------------------------------------------------------
$viewMenu = new Menu(false);
$menuBar->addSubmenu("视图", $viewMenu);

$statusBarItem = $viewMenu->addItem("状态栏");
$toolbarItem = $viewMenu->addItem("工具栏");
$viewMenu->addSeparator();
$fullscreenItem = $viewMenu->addItem("全屏");

// 初始勾选
$statusBarItem->setChecked(true);
$toolbarItem->setChecked(true);

$statusBarItem->onClick = function () use ($statusBarItem, $status): void {
    $checked = !$statusBarItem->isChecked();
    $statusBarItem->setChecked($checked);
    echo "[菜单] 视图 → 状态栏 " . ($checked ? "勾选" : "取消勾选") . "\n";
    $status->setText("状态栏: " . ($checked ? "显示 ✅" : "隐藏 ❌"));
};
$toolbarItem->onClick = function () use ($toolbarItem, $status): void {
    $checked = !$toolbarItem->isChecked();
    $toolbarItem->setChecked($checked);
    echo "[菜单] 视图 → 工具栏 " . ($checked ? "勾选" : "取消勾选") . "\n";
    $status->setText("工具栏: " . ($checked ? "显示 ✅" : "隐藏 ❌"));
};
$fullscreenItem->onClick = function () use ($win, $status): void {
    static $fs = false;
    $fs = !$fs;
    $win->setFullscreen($fs);
    echo "[菜单] 视图 → 全屏 " . ($fs ? "开启" : "关闭") . "\n";
    $status->setText("全屏: " . ($fs ? "开启 🖥️" : "关闭"));
};

// ------------------------------------------------------------
// 帮助菜单：关于
// ------------------------------------------------------------
$helpMenu = new Menu(false);
$menuBar->addSubmenu("帮助", $helpMenu);

$aboutItem = $helpMenu->addItem("关于");
$aboutItem->onClick = function () use ($status): void {
    echo "[菜单] 帮助 → 关于\n";
    $status->setText("关于: PHP UI 菜单系统测试 v1.0 ℹ️ 中文");
};

// ============================================================
// 挂载菜单栏到窗口
// ============================================================
$win->setMenu($menuBar);

echo "菜单结构:\n";
echo "  文件(新建/打开/保存/---/退出)\n";
echo "  编辑(撤销[禁用]/重做/---/查找)\n";
echo "  视图(状态栏[勾]/工具栏[勾]/---/全屏)\n";
echo "  帮助(关于)\n";
echo "菜单项 ID 从 9000 起自增\n";

// 显示窗口
$win->show();

echo "窗口标题: " . $win->getTitle() . "\n";

// 自动退出（CI/无人值守）
if (getenv('PHP_UI_AUTO_EXIT') === '1') {
    echo "PHP_UI_AUTO_EXIT=1，5 秒后自动退出\n";
    App::timer(5000, function (): void {
        echo "自动退出触发\n";
        App::quit();
    });
}

echo "进入事件循环（点击菜单项查看效果）...\n";
App::run();

echo "已退出\n";
