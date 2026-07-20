<?php
declare(strict_types=1);

/**
 * 全功能测试示例。
 *
 * 覆盖所有 public API：
 *   - App: run / quit / timer / mainStep
 *   - Window: __construct / setTitle / setSize / setPosition / getPosition /
 *             setChild / onClosing / onResize / show / hide / close / destroy
 *   - Button: __construct / getText / setText / onClicked
 *   - Label: __construct / getText / setText
 *   - Entry: __construct / getText / setText / onChanged / setReadOnly
 *   - Checkbox: __construct / getText / setText / isChecked / setChecked / onToggled
 *   - Box: horizontal / vertical / append / remove / setPadded
 *   - HBox / VBox 语法糖
 *   - Separator: horizontal / vertical
 *   - Control: show / hide / enable / disable / destroy
 *
 * 运行：
 *   php -d ffi.enable=true -f examples/full_test.php
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
    // ============================================================
    // Window 构造 + setPosition + getPosition
    // ============================================================
    $window = new Window("PHP UI 全功能测试", 520, 640);
    $window->setPosition(100, 100);
    $pos = $window->getPosition();

    // onResize：窗口尺寸变化时改标题
    $window->onResize(function () use ($window) {
        // 仅做演示，不实际 setTitle 避免每次拖动都改标题
    });

    // onClosing：第一次返回 false 阻止关闭并提示，第二次允许退出
    $closeAttempts = 0;
    $window->onClosing(function () use (&$closeAttempts, $window) {
        $closeAttempts++;
        if ($closeAttempts === 1) {
            // 第一次：改标题提示用户再点一次
            $window->setTitle("再点一次关闭按钮才会退出");
            return false; // 阻止关闭
        }
        App::quit();
        return true; // 允许退出
    });

    // 根布局
    $root = new VBox();
    $root->setPadded(true);
    $window->setChild($root);

    // ============================================================
    // 日志区：显示所有 API 测试输出
    // ============================================================
    $log = new Label("=== 测试日志 ===");
    $root->append($log, true);

    $logFn = function (string $msg) use ($log): void {
        $old = $log->getText();
        // 只保留最后 30 行避免无限增长
        $lines = explode("\n", $old);
        if (count($lines) > 30) {
            $lines = array_slice($lines, -30);
        }
        $log->setText(implode("\n", $lines) . "\n> " . $msg);
    };

    $logFn("Window.setPosition(100,100), getPosition=" . json_encode($pos));

    // ============================================================
    // Label 测试
    // ============================================================
    $label = new Label("Label 初始文本");
    $logFn("Label.getText() = " . $label->getText());
    $label->setText("Label.setText 已调用");
    $logFn("Label.setText 后 getText = " . $label->getText());
    $root->append($label, false);

    // ============================================================
    // Button 测试：getText / setText / onClicked
    // ============================================================
    $btn = new Button("Button 初始文本");
    $logFn("Button.getText() = " . $btn->getText());
    $clickCount = 0;
    $btn->onClicked(function () use ($btn, &$clickCount, $logFn) {
        $clickCount++;
        $btn->setText("已点击 {$clickCount} 次");
        $logFn("Button.onClicked fired, setText='{$btn->getText()}'");
    });
    $root->append($btn, false);

    // ============================================================
    // Entry 测试：getText / setText / onChanged / setReadOnly
    // ============================================================
    $entry = new Entry();
    $entry->setText("Entry 初始内容");
    $logFn("Entry.getText() = " . $entry->getText());
    $entry->onChanged(function () use ($entry, $logFn) {
        $logFn("Entry.onChanged: text='" . $entry->getText() . "'");
    });
    $root->append($entry, false);

    // 切换 Entry 只读状态
    $btnRO = new Button("切换 Entry 只读");
    $roState = false;
    $btnRO->onClicked(function () use ($entry, &$roState, $logFn) {
        $roState = !$roState;
        $entry->setReadOnly($roState);
        $logFn("Entry.setReadOnly(" . ($roState ? "true" : "false") . ")");
    });
    $root->append($btnRO, false);

    // ============================================================
    // Checkbox 测试：getText / setText / isChecked / setChecked / onToggled
    // ============================================================
    $cb = new Checkbox("Checkbox 初始文本");
    $logFn("Checkbox.getText() = " . $cb->getText());
    $cb->setText("Checkbox 文本已改");
    $logFn("Checkbox.isChecked() = " . ($cb->isChecked() ? "true" : "false"));
    $cb->onToggled(function () use ($cb, $logFn) {
        $logFn("Checkbox.onToggled: checked=" . ($cb->isChecked() ? "true" : "false"));
    });
    $root->append($cb, false);

    // 通过按钮调用 setChecked
    $btnCheck = new Button("Checkbox.setChecked(true)");
    $btnCheck->onClicked(function () use ($cb, $logFn) {
        $cb->setChecked(true);
        $logFn("Checkbox.setChecked(true) called");
    });
    $root->append($btnCheck, false);

    // ============================================================
    // Separator 测试
    // ============================================================
    $root->append(Separator::horizontal(), false);
    $logFn("Separator.horizontal() created");

    // ============================================================
    // HBox 测试：水平布局 + 垂直 Separator + setPadded
    // ============================================================
    $hbox = new HBox();
    $hbox->setPadded(true);
    $hbox->append(new Label("HBox-左"), false);
    $hbox->append(Separator::vertical(), false);
    $hbox->append(new Label("HBox-右"), false);
    $root->append($hbox, false);
    $logFn("HBox with setPadded(true) + 2 Labels + vertical Separator");

    // ============================================================
    // Box.remove 测试：动态删除子控件
    // ============================================================
    $removeBox = VBox::vertical();
    $removeBox->setPadded(true);
    for ($i = 1; $i <= 3; $i++) {
        $removeBox->append(new Label("动态项目 #{$i}"), false);
    }
    $btnRemove = new Button("Box.remove(0) 删除首项");
    $removeIdx = 0;
    $btnRemove->onClicked(function () use ($removeBox, &$removeIdx, $logFn) {
        if ($removeIdx < 3) {
            $removeBox->remove(0);
            $removeIdx++;
            $logFn("Box.remove(0) called, total removed={$removeIdx}");
        } else {
            $logFn("Box.remove: 已全部删除");
        }
    });
    $root->append($btnRemove, false);
    $root->append($removeBox, false);

    // ============================================================
    // Control 测试：show / hide / enable / disable
    // ============================================================
    $ctrlTarget = new Label("Control 操作目标");
    $root->append($ctrlTarget, false);

    $ctrlBox = new HBox();
    $ctrlBox->setPadded(true);

    $btnHide = new Button("hide");
    $btnHide->onClicked(function () use ($ctrlTarget, $logFn) {
        $ctrlTarget->hide();
        $logFn("Control.hide()");
    });
    $ctrlBox->append($btnHide, false);

    $btnShow = new Button("show");
    $btnShow->onClicked(function () use ($ctrlTarget, $logFn) {
        $ctrlTarget->show();
        $logFn("Control.show()");
    });
    $ctrlBox->append($btnShow, false);

    $btnDisable = new Button("disable");
    $btnDisable->onClicked(function () use ($ctrlTarget, $logFn) {
        $ctrlTarget->disable();
        $logFn("Control.disable()");
    });
    $ctrlBox->append($btnDisable, false);

    $btnEnable = new Button("enable");
    $btnEnable->onClicked(function () use ($ctrlTarget, $logFn) {
        $ctrlTarget->enable();
        $logFn("Control.enable()");
    });
    $ctrlBox->append($btnEnable, false);

    $root->append($ctrlBox, false);

    // ============================================================
    // Window.setTitle + setSize 测试
    // ============================================================
    $btnWin = new Button("Window.setTitle + setSize(400, 300)");
    $btnWin->onClicked(function () use ($window, $logFn) {
        $window->setTitle("已通过 setTitle 修改");
        $window->setSize(400, 300);
        $logFn("Window.setTitle + setSize(400,300)");
    });
    $root->append($btnWin, false);

    // ============================================================
    // Control.destroy 测试
    // ============================================================
    $destroyTarget = new Label("我将被 destroy 销毁");
    $root->append($destroyTarget, false);
    $btnDestroy = new Button("Control.destroy()");
    $btnDestroy->onClicked(function () use ($destroyTarget, $logFn) {
        $destroyTarget->destroy();
        $logFn("Control.destroy() called");
    });
    $root->append($btnDestroy, false);

    // ============================================================
    // App::timer 测试：3 次后自动停止
    // ============================================================
    $tickCount = 0;
    App::timer(2000, function () use (&$tickCount, $logFn) {
        $tickCount++;
        $logFn("App.timer tick #{$tickCount}");
        return $tickCount < 3; // false 停止
    });
    $logFn("App.timer(2000ms) registered, will tick 3 times");

    // ============================================================
    // App.mainStep 测试：单次非阻塞处理消息
    // ============================================================
    $btnStep = new Button("App.mainStep(false) 单次迭代");
    $btnStep->onClicked(function () use ($logFn) {
        $still = App::mainStep(false);
        $logFn("App.mainStep(false) returned " . ($still ? "true" : "false"));
    });
    $root->append($btnStep, false);
    $logFn("App.mainStep() ready");

    // ============================================================
    // Window.hide / show 测试：切换主窗口可见性
    // ============================================================
    $btnWinHide = new Button("Window.hide() / show() 切换");
    $winHidden = false;
    $btnWinHide->onClicked(function () use ($window, &$winHidden, $logFn) {
        if ($winHidden) {
            $window->show();
            $winHidden = false;
            $logFn("Window.show()");
        } else {
            $window->hide();
            $winHidden = true;
            $logFn("Window.hide() — 窗口已隐藏，再点一次恢复");
        }
    });
    $root->append($btnWinHide, false);

    // ============================================================
    // Window.close / destroy 测试：另开一个子窗口然后销毁
    // ============================================================
    $btnCloseNew = new Button("创建子窗口并 close() 销毁");
    $btnCloseNew->onClicked(function () use ($logFn) {
        $sub = new Window("子窗口（3 秒后自动 close）", 280, 180);
        $sub->setPosition(400, 200);
        $sub->setChild(new Label("我是子窗口\n3 秒后自动 close()"));
        $sub->onClosing(fn() => true);
        $sub->show();
        $logFn("Window created + show()");

        // 3 秒后调 close() 销毁（同时验证 App::timer 一次性用法）
        App::timer(3000, function () use ($sub, $logFn) {
            $sub->close();
            $logFn("Window.close() called — 子窗口应消失");
            return false; // 一次性
        });
    });
    $root->append($btnCloseNew, false);

    // ============================================================
    // 安全退出按钮：跳过 onClosing 拦截直接退出
    // ============================================================
    $btnQuit = new Button("立即退出 App.quit()");
    $btnQuit->onClicked(function () {
        App::quit();
    });
    $root->append($btnQuit, false);

    // ============================================================
    // 启动
    // ============================================================
    $logFn("=== 启动完成 ===");
    $window->show();
});
