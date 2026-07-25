<?php
declare(strict_types=1);

/**
 * 新增控件综合测试示例（P0 批次）。
 *
 * 运行：php -d ffi.enable=true examples/widgets_test.php
 *
 * 覆盖控件：
 *   - Separator 水平/垂直分隔线
 *   - Group 分组容器（带标题边框）
 *   - PasswordEntry 密码框
 *   - EditableComboBox 可编辑下拉框
 *   - ColorButton 颜色选择按钮
 *   - FontButton 字体选择按钮
 *   - Tab 标签页容器（多页切换）
 *
 * 交互：
 *   - 操作各控件时控制台打印事件回调，底部状态栏同步更新。
 *   - 设环境变量 PHP_UI_AUTO_EXIT=1 时，5 秒后自动退出（CI/无人值守）。
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Kingbes\Ui\App;
use Kingbes\Ui\Window;
use Kingbes\Ui\Layout\VBox;
use Kingbes\Ui\Layout\HBox;
use Kingbes\Ui\Layout\Tab;
use Kingbes\Ui\Layout\Group;
use Kingbes\Ui\Control\Button;
use Kingbes\Ui\Control\Label;
use Kingbes\Ui\Control\Entry;
use Kingbes\Ui\Control\Separator;
use Kingbes\Ui\Control\PasswordEntry;
use Kingbes\Ui\Control\EditableComboBox;
use Kingbes\Ui\Control\ColorButton;
use Kingbes\Ui\Control\FontButton;
use Kingbes\Ui\Graphics\Color;

echo "========================================\n";
echo " PHP UI 新增控件测试 🧩\n";
echo "========================================\n";

$screen = App::platform()->screenSize();
echo "屏幕尺寸: {$screen->width} x {$screen->height}\n";

// 创建窗口（标题含中文与 emoji，验证 Unicode W 系列 API）
$win = new Window("PHP UI 新增控件测试 🧩 中文 😀", 720, 640);
$win->onClose = fn() => App::quit();

// 顶层 VBox：所有内容纵向排列
$root = new VBox($win);
$root->setPadding(4);

// 状态栏 Label（先创建以便闭包捕获，最后再加入布局放在底部）
$status = new Label($root, "就绪 - 操作控件查看事件输出", Label::ALIGN_CENTER);

/**
 * 辅助：在 root 下创建一行 [描述 Label + 控件] 的 HBox。
 * 返回该 HBox，调用方把控件 add 进去。
 */
$mkRow = function (string $desc) use ($root): HBox {
    $row = new HBox($root);
    $row->setPadding(4);
    $root->add($row);
    $lab = new Label($row, $desc);
    $row->add($lab);
    return $row;
};

// ============================================================
// Tab 标签页容器（核心展示，放在顶部）
// ============================================================
echo "创建 Tab 标签页容器...\n";
$tabRow = $mkRow("标签页 Tab:");
$tab = new Tab($tabRow);
$tabRow->add($tab);

// 第 1 页：密码框 + 可编辑下拉框
$page1 = new VBox($tab);
$page1->setPadding(8);
$tab->addPage("认证页 🔐", $page1);

// 密码框
$pwRow = new HBox($page1);
$page1->add($pwRow);
$pwLabel = new Label($pwRow, "密码 PasswordEntry:");
$pwRow->add($pwLabel);
$pw = new PasswordEntry($pwRow, "");
$pwRow->add($pw);
$pw->onChange = function () use ($pw, $status): void {
    echo "[事件] PasswordEntry onChange, length=" . strlen($pw->getText()) . "\n";
    $status->setText("PasswordEntry 内容变化（长度=" . strlen($pw->getText()) . "）");
};
$pw->onEnter = function () use ($pw, $status): void {
    echo "[事件] PasswordEntry onEnter: " . $pw->getText() . "\n";
    $status->setText("PasswordEntry 回车按下");
};

// 可编辑下拉框
$ecbRow = new HBox($page1);
$page1->add($ecbRow);
$ecbLabel = new Label($ecbRow, "可编辑下拉 EditableComboBox:");
$ecbRow->add($ecbLabel);
$ecb = new EditableComboBox($ecbRow);
$ecbRow->add($ecb);
$ecb->addItem("苹果 🍎");
$ecb->addItem("香蕉 🍌");
$ecb->addItem("中文选项 馒头");
$ecb->addItem("emoji 🍇");
$ecb->select(0);
$ecb->onSelect = function () use ($ecb, $status): void {
    $idx = $ecb->getSelectedIndex();
    echo "[事件] EditableComboBox onSelect index={$idx}\n";
    $status->setText("EditableComboBox 选择 index={$idx}");
};
$ecb->onChange = function () use ($ecb, $status): void {
    echo "[事件] EditableComboBox onChange: " . $ecb->getText() . "\n";
    $status->setText("EditableComboBox 文本变化");
};

// 第 2 页：ColorButton + FontButton
$page2 = new VBox($tab);
$page2->setPadding(8);
$tab->addPage("外观页 🎨", $page2);

// 颜色选择按钮
$colorRow = new HBox($page2);
$page2->add($colorRow);
$colorLabel = new Label($colorRow, "颜色 ColorButton:");
$colorRow->add($colorLabel);
$colorBtn = new ColorButton($colorRow, Color::rgb(255, 128, 0));
$colorRow->add($colorBtn);
$colorBtn->onColorChanged = function (Color $c) use ($status): void {
    echo "[事件] ColorButton onColorChanged: rgb({$c->r},{$c->g},{$c->b})\n";
    $status->setText("ColorButton 颜色变化 rgb({$c->r},{$c->g},{$c->b})");
};

// 字体选择按钮
$fontRow = new HBox($page2);
$page2->add($fontRow);
$fontLabel = new Label($fontRow, "字体 FontButton:");
$fontRow->add($fontLabel);
$fontBtn = new FontButton($fontRow);
$fontRow->add($fontBtn);
$fontBtn->onFontChanged = function (array $font) use ($status): void {
    $name = $font['name'];
    $size = $font['size'];
    echo "[事件] FontButton onFontChanged: {$name} {$size}pt\n";
    $status->setText("FontButton 字体变化: {$name} {$size}pt");
};

// 第 3 页：Separator + Group
$page3 = new VBox($tab);
$page3->setPadding(8);
$tab->addPage("分组页 📦", $page3);

// 水平分隔线
$page3->add(new Label($page3, "下面是水平分隔线 Separator(HORIZONTAL)："));
$page3->add(new Separator($page3, true));

// Group 分组容器
$group = new Group($page3, "用户信息分组 👥");
$page3->add($group);

// Group 内嵌一个 VBox，放置两个 Entry
$groupContent = new VBox($group);
$group->setChild($groupContent);

$nameRow = new HBox($groupContent);
$groupContent->add($nameRow);
$nameRow->add(new Label($nameRow, "姓名:"));
$nameRow->add(new Entry($nameRow, "张三 📛"));

$ageRow = new HBox($groupContent);
$groupContent->add($ageRow);
$ageRow->add(new Label($ageRow, "年龄:"));
$ageRow->add(new Entry($ageRow, "28"));

// 垂直分隔线 + 两个按钮
$vsepRow = new HBox($page3);
$page3->add($vsepRow);
$vsepRow->add(new Label($vsepRow, "左侧文本"));
$vsepRow->add(new Separator($vsepRow, false));  // 垂直分隔
$vsepRow->add(new Label($vsepRow, "右侧文本"));

// Tab 切换事件
$tab->onPageChanged = function () use ($tab, $status): void {
    $idx = $tab->getSelectedIndex();
    echo "[事件] Tab onPageChanged index={$idx}\n";
    $status->setText("Tab 切换到第 " . ($idx + 1) . " 页");
};

// ============================================================
// 顶层水平分隔线（分隔 Tab 区域与底部控件）
// ============================================================
$root->add(new Separator($root, true));

// ============================================================
// 底部：测试 Group 标题修改 + 显示当前 Tab 信息
// ============================================================
$bottomRow = new HBox($root);
$root->add($bottomRow);

$btn1 = new Button($bottomRow, "修改 Group 标题 ✏️");
$bottomRow->add($btn1);
$btn1->onClick = function () use ($group, $status): void {
    static $toggle = false;
    $toggle = !$toggle;
    $newTitle = $toggle ? "已修改的分组标题 🔄" : "用户信息分组 👥";
    $group->setTitle($newTitle);
    echo "[事件] Group setTitle: {$newTitle}\n";
    $status->setText("Group 标题修改为: {$newTitle}");
};

$btn2 = new Button($bottomRow, "切换到第2页 📑");
$bottomRow->add($btn2);
$btn2->onClick = function () use ($tab, $status): void {
    $tab->selectPage(1);
    echo "[事件] Button 切换 Tab 到第2页\n";
    $status->setText("通过按钮切换到 Tab 第2页");
};

$btn3 = new Button($bottomRow, "显示 Tab 信息 ℹ️");
$bottomRow->add($btn3);
$btn3->onClick = function () use ($tab, $status): void {
    $count = $tab->getPageCount();
    $idx = $tab->getSelectedIndex();
    echo "[事件] Tab 信息: count={$count}, selected={$idx}\n";
    $status->setText("Tab 共 {$count} 页，当前第 " . ($idx + 1) . " 页");
};

// ============================================================
// 状态栏（加入 root 末尾，位于底部）
// ============================================================
$root->add($status);

// 设置顶层容器并显示
$win->setChild($root);
$win->show();

echo "窗口标题: " . $win->getTitle() . "\n";
echo "已创建控件: Tab/Group/Separator/PasswordEntry/EditableComboBox/ColorButton/FontButton\n";

// 自动退出（CI/无人值守）
if (getenv('PHP_UI_AUTO_EXIT') === '1') {
    echo "PHP_UI_AUTO_EXIT=1，5 秒后自动退出\n";
    App::timer(5000, function (): void {
        echo "自动退出触发\n";
        App::quit();
    });
}

echo "进入事件循环（关闭窗口退出）...\n";
App::run();

echo "已退出\n";
