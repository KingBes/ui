<?php
declare(strict_types=1);

/**
 * 批次 5 对话框与系统服务测试示例（Task 19/20/21）。
 *
 * 运行：php -d ffi.enable=true examples/dialogs_test.php
 *
 * 演示内容：
 *   - 消息框：msgBox / msgBoxError / msgBoxWarn / msgBoxAsk
 *     覆盖中文与 emoji 文本，验证 Unicode W 系列 MessageBoxW。
 *   - 文件对话框：openFile / saveFile（含过滤器）
 *     验证 GetOpenFileNameW / GetSaveFileNameW。
 *   - 文件夹对话框：openFolder（SHBrowseForFolderW + SHGetPathFromIDListW）
 *   - 颜色对话框：chooseColor（ChooseColorW，返回 Color）
 *   - 字体对话框：chooseFont（ChooseFontW，返回 name/size/color）
 *   - 系统服务：Screen::size() / Clipboard::set/get 文本
 *
 * 模态对话框期间 inModalDialog=true，WindowProc 调 DefWindowProcW
 * 允许底层窗口重绘，不卡死。
 *
 * 设环境变量 PHP_UI_AUTO_EXIT=1 时，5 秒后自动退出（CI/无人值守）。
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Kingbes\Ui\App;
use Kingbes\Ui\Window;
use Kingbes\Ui\Layout\VBox;
use Kingbes\Ui\Layout\HBox;
use Kingbes\Ui\Control\Button;
use Kingbes\Ui\Control\Label;
use Kingbes\Ui\Dialogs;
use Kingbes\Ui\Screen;
use Kingbes\Ui\Clipboard;

echo "========================================\n";
echo " PHP UI 批次5 对话框与系统服务测试 💬\n";
echo "========================================\n";

// ============================================================
// 系统服务：Screen
// ============================================================
$screen = Screen::size();
echo "屏幕尺寸: {$screen->width} x {$screen->height}\n";
echo "Screen::width()=" . Screen::width() . " Screen::height()=" . Screen::height() . "\n";

// ============================================================
// 系统服务：Clipboard（启动时先写入一条）
// ============================================================
Clipboard::setText("PHP UI 启动剪贴板初始内容 📋 中文");
echo "剪贴板初始写入完成\n";

// 创建窗口（标题含中文与 emoji）
$win = new Window("PHP UI 对话框测试 - 💬 中文 🎨", 640, 560);
$win->onClose = fn() => App::quit();

// 顶层 VBox：所有按钮分行排列
$root = new VBox($win);

// 状态栏 Label（先创建以便闭包捕获）
$status = new Label(
    $root,
    "就绪 - 点击下方按钮测试对话框 ✅ 中文测试",
    Label::ALIGN_CENTER
);

/**
 * 辅助：在 root 下创建一行 [按钮 + 简短说明] 的 HBox。
 * 返回该 HBox，调用方把按钮 add 进去。
 */
$mkRow = function (string $desc) use ($root): HBox {
    $row = new HBox($root);
    $root->add($row);
    $lab = new Label($row, $desc);
    $row->add($lab);
    return $row;
};

// ============================================================
// 消息框：msgBox / msgBoxError / msgBoxWarn / msgBoxAsk
// ============================================================
$row = $mkRow("消息框:");
$btnInfo = new Button($row, "信息 ℹ️");
$row->add($btnInfo);
$btnInfo->onClick = function () use ($win, $status): void {
    echo "[对话框] msgBox（信息）\n";
    Dialogs::msgBox($win, "这是一条信息消息框 ✅\n中文与 emoji 测试 😀", "信息");
    $status->setText("msgBox 信息已显示 ✅");
};

$btnError = new Button($row, "错误 ❌");
$row->add($btnError);
$btnError->onClick = function () use ($win, $status): void {
    echo "[对话框] msgBoxError\n";
    Dialogs::msgBoxError($win, "文件不存在 ❌\n路径: C:\\test\\missing.txt", "错误");
    $status->setText("msgBoxError 已显示 ❌");
};

$btnWarn = new Button($row, "警告 ⚠️");
$row->add($btnWarn);
$btnWarn->onClick = function () use ($win, $status): void {
    echo "[对话框] msgBoxWarn\n";
    Dialogs::msgBoxWarn($win, "磁盘空间不足 ⚠️\n建议清理临时文件", "警告");
    $status->setText("msgBoxWarn 已显示 ⚠️");
};

$btnAsk = new Button($row, "询问 ❓");
$row->add($btnAsk);
$btnAsk->onClick = function () use ($win, $status): void {
    echo "[对话框] msgBoxAsk\n";
    $yes = Dialogs::msgBoxAsk($win, "确定要删除该文件吗？❓\n此操作不可撤销", "询问");
    echo "  用户选择: " . ($yes ? "是(IDYES)" : "否(IDNO)") . "\n";
    $status->setText("msgBoxAsk 返回: " . ($yes ? "是 ✅" : "否 ❌"));
};

// ============================================================
// 文件对话框：openFile / saveFile
// ============================================================
$row = $mkRow("文件对话框:");
$btnOpen = new Button($row, "打开文件 📂");
$row->add($btnOpen);
$btnOpen->onClick = function () use ($win, $status): void {
    echo "[对话框] openFile\n";
    $path = Dialogs::openFile($win, [
        "文本文件|*.txt",
        "PHP 文件|*.php",
        "所有文件|*.*",
    ]);
    if ($path === null) {
        echo "  用户取消\n";
        $status->setText("openFile: 已取消");
    } else {
        echo "  选中: {$path}\n";
        $status->setText("openFile: " . basename($path));
    }
};

$btnSave = new Button($row, "保存文件 💾");
$row->add($btnSave);
$btnSave->onClick = function () use ($win, $status): void {
    echo "[对话框] saveFile\n";
    $path = Dialogs::saveFile($win, [
        "文本文件|*.txt",
        "PHP 文件|*.php",
        "所有文件|*.*",
    ]);
    if ($path === null) {
        echo "  用户取消\n";
        $status->setText("saveFile: 已取消");
    } else {
        echo "  选中: {$path}\n";
        $status->setText("saveFile: " . basename($path));
    }
};

// ============================================================
// 文件夹对话框：openFolder
// ============================================================
$row = $mkRow("文件夹:");
$btnFolder = new Button($row, "打开文件夹 🗂️");
$row->add($btnFolder);
$btnFolder->onClick = function () use ($win, $status): void {
    echo "[对话框] openFolder\n";
    $path = Dialogs::openFolder($win);
    if ($path === null) {
        echo "  用户取消\n";
        $status->setText("openFolder: 已取消");
    } else {
        echo "  选中: {$path}\n";
        $status->setText("openFolder: " . basename($path));
    }
};

// ============================================================
// 颜色对话框：chooseColor
// ============================================================
$row = $mkRow("颜色对话框:");
$btnColor = new Button($row, "选择颜色 🎨");
$row->add($btnColor);
$btnColor->onClick = function () use ($win, $status): void {
    echo "[对话框] chooseColor\n";
    $color = Dialogs::chooseColor($win);
    if ($color === null) {
        echo "  用户取消\n";
        $status->setText("chooseColor: 已取消");
    } else {
        $ref = $color->toColorRef();
        echo "  选中: R={$color->r} G={$color->g} B={$color->b} (COLORREF=0x"
            . strtoupper(dechex($ref)) . ")\n";
        $status->setText(
            "chooseColor: RGB({$color->r},{$color->g},{$color->b}) 🎨"
        );
    }
};

// ============================================================
// 字体对话框：chooseFont
// ============================================================
$row = $mkRow("字体对话框:");
$btnFont = new Button($row, "选择字体 🔤");
$row->add($btnFont);
$btnFont->onClick = function () use ($win, $status): void {
    echo "[对话框] chooseFont\n";
    $font = Dialogs::chooseFont($win);
    if ($font === null) {
        echo "  用户取消\n";
        $status->setText("chooseFont: 已取消");
    } else {
        $name = $font['name'] ?? '?';
        $size = $font['size'] ?? 0;
        $color = $font['color'] ?? null;
        $colorStr = $color !== null
            ? "RGB({$color->r},{$color->g},{$color->b})"
            : "无颜色";
        echo "  选中: name={$name} size={$size} color={$colorStr}\n";
        $status->setText("chooseFont: {$name} {$size}pt 🔤");
    }
};

// ============================================================
// 系统服务：Clipboard 读写
// ============================================================
$row = $mkRow("剪贴板:");
$btnCbSet = new Button($row, "写入剪贴板 📋");
$row->add($btnCbSet);
$btnCbSet->onClick = function () use ($status): void {
    $ts = date('H:i:s');
    $text = "PHP UI 剪贴板测试 {$ts} 📝 中文";
    Clipboard::setText($text);
    echo "[剪贴板] 写入: {$text}\n";
    $status->setText("Clipboard 已写入: {$text}");
};

$btnCbGet = new Button($row, "读取剪贴板 📖");
$row->add($btnCbGet);
$btnCbGet->onClick = function () use ($status): void {
    $text = Clipboard::getText();
    echo "[剪贴板] 读取: {$text}\n";
    $status->setText("Clipboard 读取: " . mb_substr($text, 0, 30));
};

// ============================================================
// 系统服务：Screen 信息
// ============================================================
$row = $mkRow("屏幕信息:");
$btnScreen = new Button($row, "显示屏幕尺寸 🖥️");
$row->add($btnScreen);
$btnScreen->onClick = function () use ($status): void {
    $w = Screen::width();
    $h = Screen::height();
    echo "[屏幕] {$w} x {$h}\n";
    $status->setText("屏幕: {$w} x {$h} 🖥️");
};

// 挂载根容器
$win->setChild($root);

// 显示窗口
$win->show();

echo "----------------------------------------\n";
echo "测试项目:\n";
echo "  消息框: 信息/错误/警告/询问\n";
echo "  文件对话框: 打开/保存\n";
echo "  文件夹对话框: openFolder\n";
echo "  颜色对话框: chooseColor\n";
echo "  字体对话框: chooseFont\n";
echo "  剪贴板: 写入/读取\n";
echo "  屏幕: size/width/height\n";
echo "----------------------------------------\n";

// 自动退出（CI/无人值守）
if (getenv('PHP_UI_AUTO_EXIT') === '1') {
    echo "PHP_UI_AUTO_EXIT=1，5 秒后自动退出\n";
    App::timer(5000, function (): void {
        echo "自动退出触发\n";
        App::quit();
    });
}

echo "进入事件循环（点击按钮测试对话框）...\n";
App::run();

echo "已退出\n";
