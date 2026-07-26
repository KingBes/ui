<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use Kingbes\Ui\App;
use Kingbes\Ui\Theme;
use Kingbes\Ui\Window;
use Kingbes\Ui\Layout\VBox;
use Kingbes\Ui\Control\SpinBox;
use Kingbes\Ui\Control\Label;

App::setTheme(Theme::SYSTEM);

$win = new Window("SpinBox 诊断", 500, 300);
$win->onClose = fn() => App::quit();
$root = new VBox($win);
$spin = new SpinBox($root);
$root->add(new Label($root, "SpinBox:"));
$root->add($spin);
$win->setChild($root);
$win->show();

$spin->setRange(0, 999);
$spin->setValue(10);

echo "=== SpinBox 诊断 ===" . PHP_EOL;
echo "setValue(10) 后 Edit 文本: [" . $spin->getText() . "]" . PHP_EOL;
echo "setValue(10) 后 getValue(): " . $spin->getValue() . PHP_EOL;

$spin->onChanged = function () use ($spin): void {
    echo "[onChanged] getValue()=" . $spin->getValue()
        . " Edit文本=[" . $spin->getText() . "]" . PHP_EOL;
};

echo "10秒后退出（请点击 SpinBox 上下按钮测试）" . PHP_EOL;
App::timer(10000, fn() => App::quit());
App::run();
