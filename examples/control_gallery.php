<?php
declare(strict_types=1);

/**
 * 控件综合演示示例。
 *
 * 演示 VBox / HBox / Button / Label / Entry / Checkbox / Separator
 * 以及各类回调的用法。
 *
 * 运行（需开启 FFI）：
 *   php -d ffi.enable=true -f examples/control_gallery.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Kingbes\Ui\App;
use Kingbes\Ui\Window;
use Kingbes\Ui\Button;
use Kingbes\Ui\Label;
use Kingbes\Ui\Entry;
use Kingbes\Ui\Checkbox;
use Kingbes\Ui\Separator;
use Kingbes\Ui\VBox;
use Kingbes\Ui\HBox;

App::run(function () {
    $window = new Window("PHP UI 控件演示", 420, 360);

    // 根布局：垂直
    $root = new VBox();
    $root->setPadded(true);
    $window->setChild($root);

    // 1. Label 用于显示各类回调反馈
    $status = new Label("状态：等待操作…");
    $root->append($status, false);

    // 2. Button：点击修改 Label 文本
    $btnClick = new Button("点我修改状态");
    $clickCount = 0;
    $btnClick->onClicked(function () use ($status, &$clickCount) {
        $clickCount++;
        $status->setText(sprintf("状态：按钮已点击 %d 次", $clickCount));
    });
    $root->append($btnClick, false);

    // 3. Entry：内容变化时同步到 Label
    $entry = new Entry();
    $entry->onChanged(function () use ($entry, $status) {
        $text = $entry->getText();
        $status->setText("输入内容：" . ($text === '' ? '(空)' : $text));
    });
    $root->append($entry, false);

    // 4. Checkbox：切换状态时反馈到 Label
    $checkbox = new Checkbox("启用某项功能");
    $checkbox->onToggled(function () use ($checkbox, $status) {
        $checked = $checkbox->isChecked();
        $status->setText("复选框：" . ($checked ? "已勾选" : "已取消"));
    });
    $root->append($checkbox, false);

    // 5. Separator 水平分隔线
    $root->append(Separator::horizontal(), false);

    // 6. HBox 内放两个 Button：清空 / 退出
    $row = new HBox();
    $row->setPadded(true);

    $btnClear = new Button("清空输入");
    $btnClear->onClicked(function () use ($entry, $status) {
        $entry->setText("");
        $status->setText("状态：输入已清空");
    });
    $row->append($btnClear, true);

    $btnQuit = new Button("退出");
    $btnQuit->onClicked(function () {
        App::quit();
    });
    $row->append($btnQuit, true);

    $root->append($row, false);

    // 窗口关闭回调：退出主循环
    $window->onClosing(function () {
        App::quit();
    });

    $window->show();
});
