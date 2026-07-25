<?php
declare(strict_types=1);

/**
 * 深色主题示例（Windows 特有）。
 *
 * 对比其他主题示例：
 *   theme_system.php   跟随系统（默认）
 *   theme_classic.php  经典灰外观
 *   theme_light.php    强制浅色
 *
 * 运行：php -d ffi.enable=true examples/theme_dark.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Kingbes\Ui\App;
use Kingbes\Ui\Theme;
use Kingbes\Ui\Window;
use Kingbes\Ui\Layout\VBox;
use Kingbes\Ui\Control\Label;
use Kingbes\Ui\Control\Button;
use Kingbes\Ui\Control\Entry;
use Kingbes\Ui\Control\Checkbox;
use Kingbes\Ui\Control\ComboBox;
use Kingbes\Ui\Control\ProgressBar;
use Kingbes\Ui\Control\Slider;

App::setTheme(Theme::DARK);

echo "主题: " . App::getTheme() . "\n";
echo "平台: " . \PHP_OS_FAMILY . "\n";
if (\PHP_OS_FAMILY !== 'Windows') {
    echo "注意：主题功能仅 Windows 有效，其他平台静默忽略\n";
}

$win = new Window("深色主题 - Dark", 480, 380);
$win->onClose = fn() => App::quit();
$win->setMargined(14);

$root = new VBox($win);
$root->setPadding(10);

$root->add(new Label($root, "Theme::DARK", Label::ALIGN_CENTER));
$root->add(new Button($root, "普通按钮"));
$disabledBtn = new Button($root, "禁用按钮");
$disabledBtn->setEnabled(false);
$root->add($disabledBtn);
$root->add(new Entry($root, "输入框"));
$root->add(new Checkbox($root, "复选框"));

$combo = new ComboBox($root);
$root->add($combo);
$combo->addItem("选项 A");
$combo->addItem("选项 B");
$combo->select(0);

$pb = new ProgressBar($root);
$root->add($pb);
$pb->setRange(0, 100);
$pb->setValue(40);

$slider = new Slider($root);
$root->add($slider);
$slider->setRange(0, 100);
$slider->setValue(60);

$win->setChild($root);
$win->show();

App::run();
echo "已退出\n";
