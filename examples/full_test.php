<?php
declare(strict_types=1);

/**
 * 批次 7 综合示例（Task 29）。
 *
 * 运行：php -d ffi.enable=true examples/full_test.php
 * 自动退出：php -d ffi.enable=true -d "PHP_UI_AUTO_EXIT=1" examples/full_test.php
 *   （或先 set PHP_UI_AUTO_EXIT=1 再运行，PowerShell: $env:PHP_UI_AUTO_EXIT='1'）
 *
 * 覆盖全部 API：
 *   - 窗口：setFullscreen/setBorderless/setResizeable/maximize/restore/setTopmost
 *           （通过菜单触发）
 *   - 菜单：文件(退出) / 视图(全屏/无边框/置顶/最大化/还原) / 帮助(关于)
 *   - 布局：VBox 顶层 + HBox（左控件区 + 右 Area 绘图区）+ 底部状态栏
 *   - 控件：Button/Label/Entry/TextArea/Checkbox/RadioBox/ComboBox/ListBox/
 *           Slider/ProgressBar/SpinBox 全展示
 *   - 对话框：msgBox/openFile/chooseColor/chooseFont（按钮触发）
 *   - 绘图：Area 画线/矩形/椭圆/中文文本/彩色 emoji/富文本
 *   - 定时器：ProgressBar 自动递增（每 100ms）
 *   - 进程：按钮启动 php -r 子进程，stdout 每行追加到 TextArea
 *   - 剪贴板：读写按钮
 *   - 滚动：windowSetScrollable(contentHeight=1300)，超出客户区可垂直滚动
 *   - 中文+emoji：窗口标题、控件文本、绘图文本均含
 *   - onClose：App::quit()
 *   - PHP_UI_AUTO_EXIT=1：5 秒后自动退出（CI）
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Kingbes\Ui\App;
use Kingbes\Ui\Window;
use Kingbes\Ui\Layout\VBox;
use Kingbes\Ui\Layout\HBox;
use Kingbes\Ui\Control\Button;
use Kingbes\Ui\Control\Label;
use Kingbes\Ui\Control\Entry;
use Kingbes\Ui\Control\TextArea;
use Kingbes\Ui\Control\Checkbox;
use Kingbes\Ui\Control\RadioBox;
use Kingbes\Ui\Control\ComboBox;
use Kingbes\Ui\Control\ListBox;
use Kingbes\Ui\Control\Slider;
use Kingbes\Ui\Control\ProgressBar;
use Kingbes\Ui\Control\SpinBox;
use Kingbes\Ui\Control\Area;
use Kingbes\Ui\Dialogs;
use Kingbes\Ui\Clipboard;
use Kingbes\Ui\Process;
use Kingbes\Ui\Menu\Menu;
use Kingbes\Ui\Graphics\Color;
use Kingbes\Ui\Graphics\DrawContext;
use Kingbes\Ui\Graphics\AttributedString;
use Kingbes\Ui\Events\MouseEvent;

echo "========================================\n";
echo " PHP UI 批次7 综合示例 🎨 全功能展示\n";
echo "========================================\n";

$screen = App::platform()->screenSize();
echo "屏幕尺寸: {$screen->width} x {$screen->height}\n";

// ============================================================
// 创建窗口（标题含中文与 emoji）
// ============================================================
$win = new Window("PHP UI 综合示例 - 全功能展示 🎨 中文 😀 🚀", 900, 700);
$win->onClose = fn() => App::quit();

// ============================================================
// 顶层布局：VBox（root）> HBox(topRow: 左控件 + 右Area) + 底部状态栏
// ============================================================
$root = new VBox($win);
$root->setPadding(4);

// 顶部 HBox：左侧控件区 + 右侧绘图区
$topRow = new HBox($root);
$root->add($topRow);
$topRow->setPadding(4);

// 左侧控件区 VBox
$leftBox = new VBox($topRow);
$topRow->add($leftBox);
$leftBox->setPadding(3);

// 右侧绘图区 Area
$area = new Area($topRow);
$topRow->add($area);

// 底部状态栏
$status = new Label($root, "就绪 - 操作控件查看事件输出 ✅ 中文测试", Label::ALIGN_CENTER);
$root->add($status);

// ============================================================
// 富文本（AttributedString）：多段不同字体/字号/颜色
// ============================================================
$attrStr = new AttributedString();
$attrStr->append('富文本 ', 'Segoe UI', 18, Color::red())
    ->append('混合 ', 'Segoe UI', 14, Color::blue())
    ->append('emoji 🌈⭐🎉 ', 'Segoe UI Emoji', 20, Color::purple())
    ->append('与中文 🐼', 'Segoe UI', 16, Color::green());

// ============================================================
// 控件区：按行 HBox 组织（描述 Label + 控件）
// ============================================================
$mkRow = function (string $desc) use ($leftBox): HBox {
    $row = new HBox($leftBox);
    $leftBox->add($row);
    $lab = new Label($row, $desc);
    $row->add($lab);
    return $row;
};

// ----- Button -----
$row = $mkRow("按钮 Button:");
$btn = new Button($row, "点击我 😀 中文");
$row->add($btn);
$btn->onClick = function () use ($status): void {
    echo "[事件] Button onClick\n";
    $status->setText("Button 被点击 ✅");
};

// ----- Label -----
$row = $mkRow("标签 Label:");
$demoLabel = new Label($row, "居中文本 🚀 中文测试", Label::ALIGN_CENTER);
$row->add($demoLabel);

// ----- Entry -----
$row = $mkRow("单行输入 Entry:");
$entry = new Entry($row, "初始文本 ✏️");
$row->add($entry);
$entry->onChange = function () use ($entry, $status): void {
    echo "[事件] Entry onChange: " . $entry->getText() . "\n";
    $status->setText("Entry 内容变化");
};
$entry->onEnter = function () use ($entry, $status): void {
    echo "[事件] Entry onEnter: " . $entry->getText() . "\n";
    $status->setText("Entry 回车 ↵");
};

// ----- TextArea -----
$row = $mkRow("多行输入 TextArea:");
$ta = new TextArea($row, "第一行 📝\n第二行 中文\n第三行 emoji 😀\n等待子进程输出...");
$row->add($ta);

// ----- Checkbox -----
$row = $mkRow("复选框 Checkbox:");
$cb = new Checkbox($row, "启用某功能 ☑️ 中文");
$row->add($cb);
$cb->setChecked(true);
$cb->onClick = function () use ($cb, $status): void {
    $checked = $cb->isChecked();
    echo "[事件] Checkbox checked=" . ($checked ? "true" : "false") . "\n";
    $status->setText("Checkbox: " . ($checked ? "勾选" : "未勾选"));
};

// ----- RadioBox -----
$row = $mkRow("单选按钮 RadioBox:");
$radioRow = new HBox($row);
$row->add($radioRow);
$r1 = new RadioBox($radioRow, "选项甲 🔴");
$r2 = new RadioBox($radioRow, "选项乙 🟢");
$r3 = new RadioBox($radioRow, "选项丙 🔵");
$radioRow->add($r1);
$radioRow->add($r2);
$radioRow->add($r3);
$r1->setChecked(true);
$mkRadioCb = function (RadioBox $r, string $name) use ($status): void {
    $r->onClick = function () use ($r, $name, $status): void {
        if ($r->isChecked()) {
            echo "[事件] RadioBox: {$name}\n";
            $status->setText("RadioBox 选中: {$name}");
        }
    };
};
$mkRadioCb($r1, "选项甲");
$mkRadioCb($r2, "选项乙");
$mkRadioCb($r3, "选项丙");

// ----- ComboBox -----
$row = $mkRow("下拉选择 ComboBox:");
$combo = new ComboBox($row);
$row->add($combo);
$combo->addItem("苹果 🍎");
$combo->addItem("香蕉 🍌");
$combo->addItem("中文 馒头");
$combo->addItem("emoji 🍇");
$combo->select(0);
$combo->onSelect = function () use ($combo, $status): void {
    $idx = $combo->getSelectedIndex();
    echo "[事件] ComboBox index={$idx}\n";
    $status->setText("ComboBox 选择 index={$idx}");
};

// ----- ListBox -----
$row = $mkRow("列表 ListBox:");
$list = new ListBox($row);
$row->add($list);
$list->addItem("列表项 1 中文");
$list->addItem("列表项 2 🐱");
$list->addItem("列表项 3 🐶");
$list->select(0);
$list->onSelect = function () use ($list, $status): void {
    $idx = $list->getSelectedIndex();
    echo "[事件] ListBox index={$idx}\n";
    $status->setText("ListBox 选择 index={$idx}");
};

// ----- Slider -----
$row = $mkRow("滑块 Slider:");
$slider = new Slider($row);
$row->add($slider);
$slider->setRange(0, 100);
$slider->setValue(50);
$slider->onChanged = function () use ($slider, $status): void {
    $v = $slider->getValue();
    echo "[事件] Slider value={$v}\n";
    $status->setText("Slider 值={$v}");
};

// ----- ProgressBar -----
$row = $mkRow("进度条 ProgressBar:");
$pb = new ProgressBar($row);
$row->add($pb);
$pb->setRange(0, 100);
$pb->setValue(30);

// ----- SpinBox -----
$row = $mkRow("数值微调 SpinBox:");
$spin = new SpinBox($row);
$row->add($spin);
$spin->setRange(0, 999);
$spin->setValue(42);
$spin->onChanged = function () use ($spin, $status): void {
    $v = $spin->getValue();
    echo "[事件] SpinBox value={$v}\n";
    $status->setText("SpinBox 值={$v}");
};

// ----- 对话框按钮组 -----
$row = $mkRow("对话框:");
$btnMsg = new Button($row, "消息框 💬");
$btnOpen = new Button($row, "打开文件 📂");
$btnColor = new Button($row, "颜色 🎨");
$btnFont = new Button($row, "字体 🔤");
$row->add($btnMsg);
$row->add($btnOpen);
$row->add($btnColor);
$row->add($btnFont);

$btnMsg->onClick = function () use ($win, $status): void {
    echo "[对话框] msgBox\n";
    Dialogs::msgBox($win, "综合示例运行中 ✅\n中文与 emoji 测试 😀", "信息");
    $status->setText("msgBox 已显示 ✅");
};
$btnOpen->onClick = function () use ($win, $status): void {
    echo "[对话框] openFile\n";
    $path = Dialogs::openFile($win, ["文本文件|*.txt", "PHP 文件|*.php", "所有文件|*.*"]);
    if ($path === null) {
        $status->setText("openFile: 已取消");
    } else {
        echo "  选中: {$path}\n";
        $status->setText("openFile: " . basename($path));
    }
};
$btnColor->onClick = function () use ($win, $status): void {
    echo "[对话框] chooseColor\n";
    $color = Dialogs::chooseColor($win);
    if ($color === null) {
        $status->setText("chooseColor: 已取消");
    } else {
        echo "  RGB({$color->r},{$color->g},{$color->b})\n";
        $status->setText("chooseColor: RGB({$color->r},{$color->g},{$color->b}) 🎨");
    }
};
$btnFont->onClick = function () use ($win, $status): void {
    echo "[对话框] chooseFont\n";
    $font = Dialogs::chooseFont($win);
    if ($font === null) {
        $status->setText("chooseFont: 已取消");
    } else {
        $name = $font['name'] ?? '?';
        $size = $font['size'] ?? 0;
        echo "  name={$name} size={$size}\n";
        $status->setText("chooseFont: {$name} {$size}pt 🔤");
    }
};

// ----- 进程按钮 -----
$row = $mkRow("子进程:");
$btnProc = new Button($row, "启动子进程 🚀");
$row->add($btnProc);
$btnProc->onClick = function () use ($ta, $status): void {
    echo "[进程] 启动 php -r 子进程\n";
    $ta->setText("--- 子进程输出开始 ---\r\n");
    $status->setText("子进程启动 🚀");
    // 子进程：每秒输出一行，共 3 行
    // 转义说明：PHP 单引号字符串中 \' → '，外层 "..." 由 cmd 解析为单个参数传给 php -r
    $cmd = 'php -r "for($i=1;$i<=3;$i++){echo \'line \'.$i.PHP_EOL;sleep(1);}"';
    Process::start(
        $cmd,
        function (string $line) use ($ta): void {
            echo "[进程 stdout] {$line}\n";
            $text = $ta->getText();
            $ta->setText($text . $line . "\r\n");
        },
        function (int $code) use ($status, $ta): void {
            echo "[进程 exit] code={$code}\n";
            $text = $ta->getText();
            $ta->setText($text . "--- 子进程退出 code={$code} ---\r\n");
            $status->setText("子进程退出 code={$code} ✅");
        }
    );
};

// ----- 剪贴板按钮 -----
$row = $mkRow("剪贴板:");
$btnCbSet = new Button($row, "写入剪贴板 📋");
$btnCbGet = new Button($row, "读取剪贴板 📖");
$row->add($btnCbSet);
$row->add($btnCbGet);
$btnCbSet->onClick = function () use ($status): void {
    $ts = date('H:i:s');
    $text = "PHP UI 综合示例 {$ts} 📝 中文";
    Clipboard::setText($text);
    echo "[剪贴板] 写入: {$text}\n";
    $status->setText("Clipboard 已写入: {$text}");
};
$btnCbGet->onClick = function () use ($status): void {
    $text = Clipboard::getText();
    echo "[剪贴板] 读取: {$text}\n";
    $status->setText("Clipboard: " . mb_substr($text, 0, 30));
};

// ============================================================
// 菜单栏：文件 / 视图 / 帮助
// ============================================================
$menuBar = new Menu(true);

// 文件菜单
$fileMenu = new Menu(false);
$menuBar->addSubmenu("文件", $fileMenu);
$fileMenu->addItem("新建");
$fileMenu->addSeparator();
$exitItem = $fileMenu->addItem("退出");
$exitItem->onClick = function (): void {
    echo "[菜单] 文件 → 退出\n";
    App::quit();
};

// 视图菜单
$viewMenu = new Menu(false);
$menuBar->addSubmenu("视图", $viewMenu);
$fullscreenItem = $viewMenu->addItem("全屏切换 🖥️");
$borderlessItem = $viewMenu->addItem("无边框切换 ⬜");
$topmostItem = $viewMenu->addItem("置顶切换 📌");
$viewMenu->addSeparator();
$maximizeItem = $viewMenu->addItem("最大化");
$restoreItem = $viewMenu->addItem("还原");

$fullscreenItem->onClick = function () use ($win, $status): void {
    static $fs = false;
    $fs = !$fs;
    $win->setFullscreen($fs);
    echo "[视图] 全屏 " . ($fs ? "开启" : "关闭") . "\n";
    $status->setText("全屏: " . ($fs ? "开启 🖥️" : "关闭"));
};
$borderlessItem->onClick = function () use ($win, $status): void {
    static $bl = false;
    $bl = !$bl;
    $win->setBorderless($bl);
    echo "[视图] 无边框 " . ($bl ? "开启" : "关闭") . "\n";
    $status->setText("无边框: " . ($bl ? "开启 ⬜" : "关闭"));
};
$topmostItem->onClick = function () use ($win, $status): void {
    static $tm = false;
    $tm = !$tm;
    $win->setTopmost($tm);
    echo "[视图] 置顶 " . ($tm ? "开启" : "关闭") . "\n";
    $status->setText("置顶: " . ($tm ? "开启 📌" : "关闭"));
};
$maximizeItem->onClick = function () use ($win, $status): void {
    $win->maximize();
    echo "[视图] 最大化\n";
    $status->setText("窗口最大化");
};
$restoreItem->onClick = function () use ($win, $status): void {
    $win->restore();
    echo "[视图] 还原\n";
    $status->setText("窗口还原");
};

// 帮助菜单
$helpMenu = new Menu(false);
$menuBar->addSubmenu("帮助", $helpMenu);
$aboutItem = $helpMenu->addItem("关于");
$aboutItem->onClick = function () use ($win, $status): void {
    echo "[菜单] 帮助 → 关于\n";
    Dialogs::msgBox(
        $win,
        "PHP UI 综合示例 v1.0 ℹ️\n\n基于 FFI + Win32 API 的跨平台 GUI 库\n中文与 emoji 测试 😀🚀🎨",
        "关于"
    );
    $status->setText("关于对话框已显示 ℹ️");
};

$win->setMenu($menuBar);

// ============================================================
// 绘图：Area onDraw - 画线/矩形/椭圆/中文/emoji/富文本
// ============================================================
$mouseX = -1;
$mouseY = -1;
$area->onDraw = function (DrawContext $ctx) use (
    &$mouseX, &$mouseY, $attrStr
): void {
    // 1. 白色背景
    $ctx->setBrush(Color::white());
    $ctx->setPen(Color::white(), 1);
    $ctx->drawRect(0, 0, 600, 600);

    // 2. 网格线
    $ctx->setPen(Color::silver(), 1);
    for ($x = 0; $x <= 600; $x += 50) {
        $ctx->drawLine($x, 0, $x, 600);
    }
    for ($y = 0; $y <= 600; $y += 50) {
        $ctx->drawLine(0, $y, 600, $y);
    }

    // 3. 填充矩形
    $ctx->setPen(Color::red(), 2);
    $ctx->setBrush(Color::yellow());
    $ctx->drawRect(20, 20, 160, 60);

    // 4. 填充椭圆
    $ctx->setPen(Color::blue(), 3);
    $ctx->setBrush(Color::cyan());
    $ctx->drawEllipse(220, 20, 120, 60);

    // 5. 描边矩形
    $ctx->setPen(Color::purple(), 2);
    $ctx->setBrush(Color::white());
    $ctx->drawRect(380, 20, 180, 60);

    // 6. 彩色 emoji 文本
    $ctx->setFont('Segoe UI Emoji', 24);
    $ctx->setColor(Color::magenta());
    $ctx->drawText(20, 110, '🎨 彩色 emoji 🚀 😀 中文 ✨');

    // 7. 普通中文
    $ctx->setFont('Segoe UI', 14);
    $ctx->setColor(Color::black());
    $ctx->drawText(20, 150, 'GDI+ 文本渲染：中文与 emoji 共存');

    // 8. 富文本
    $ctx->drawTextAttributed(20, 180, $attrStr->getId());

    // 9. 鼠标十字标记
    if ($mouseX >= 0 && $mouseY >= 0) {
        $ctx->setPen(Color::red(), 1);
        $ctx->drawLine($mouseX - 10, $mouseY, $mouseX + 10, $mouseY);
        $ctx->drawLine($mouseX, $mouseY - 10, $mouseX, $mouseY + 10);
    }
};

$area->onMouseMove = function (MouseEvent $e) use (&$mouseX, &$mouseY, $status, $area): void {
    $mouseX = $e->x;
    $mouseY = $e->y;
    $status->setText("鼠标 ({$e->x}, {$e->y})");
    $area->invalidate();
};

// ============================================================
// 定时器：ProgressBar 自动递增（100ms）
// ============================================================
App::timer(100, function () use ($pb): void {
    static $v = 30;
    $v = ($v >= 100) ? 0 : $v + 5;
    $pb->setValue($v);
});

// ============================================================
// 设置顶层容器 + 启用滚动 + 显示窗口
// ============================================================
$win->setChild($root);
// 启用窗口垂直滚动条：内容高度 1300，超出客户区时可滚动查看底部控件
$win->setScrollable(1300);
$win->show();

echo "----------------------------------------\n";
echo "已创建:\n";
echo "  窗口: setFullscreen/setBorderless/setTopmost/maximize/restore\n";
echo "  菜单: 文件(退出)/视图(全屏/无边框/置顶/最大化/还原)/帮助(关于)\n";
echo "  布局: VBox(root) > HBox(topRow: 左控件VBox + 右Area) + 状态栏\n";
echo "  控件: Button/Label/Entry/TextArea/Checkbox/RadioBox/ComboBox/ListBox/Slider/ProgressBar/SpinBox\n";
echo "  对话框: msgBox/openFile/chooseColor/chooseFont\n";
echo "  绘图: 线/矩形/椭圆/中文/emoji/富文本\n";
echo "  定时器: ProgressBar 100ms 递增\n";
echo "  进程: php -r 子进程输出到 TextArea\n";
echo "  剪贴板: 读写\n";
echo "  滚动: contentHeight=1300 可垂直滚动\n";
echo "  中文+emoji: 标题/控件/绘图\n";
echo "----------------------------------------\n";

// 自动退出（CI/无人值守）
if (getenv('PHP_UI_AUTO_EXIT') === '1') {
    echo "PHP_UI_AUTO_EXIT=1，5 秒后自动退出\n";
    App::timer(5000, function (): void {
        echo "自动退出触发\n";
        App::quit();
    });
}

echo "进入事件循环（关闭窗口或等待自动退出）...\n";
App::run();

echo "已退出\n";
