<?php
declare(strict_types=1);

/**
 * 批次 2 控件测试示例：数值与选择控件
 *
 * 覆盖 10 个新控件：
 *   - Spinbox: __construct / getValue / setValue / onChanged
 *   - Slider:  __construct / getValue / setValue / onChanged
 *   - ProgressBar: __construct / getValue / setValue (-1 marquee)
 *   - Combobox: __construct / append / insertAt / delete / clear / numItems /
 *               getSelected / setSelected / onSelected
 *   - EditableCombobox: __construct / append / insertAt / delete / clear / numItems /
 *                        getSelected / setSelected / setText / getText / onChanged
 *   - RadioButtons: __construct / append / getSelected / setSelected / onSelected
 *   - MultilineEntry: __construct / getText / setText / append / onChanged / setReadOnly
 *   - PasswordEntry: __construct / getText / setText / onChanged
 *   - SearchEntry: __construct / getText / setText / onChanged
 *   - DateTimePicker: __construct / getTime / setTime
 *
 * 运行：
 *   php -d ffi.enable=true -f examples/controls_test.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Kingbes\Ui\App;
use Kingbes\Ui\Window;
use Kingbes\Ui\Label;
use Kingbes\Ui\Button;
use Kingbes\Ui\HBox;
use Kingbes\Ui\VBox;
use Kingbes\Ui\Tab;
use Kingbes\Ui\Spinbox;
use Kingbes\Ui\Slider;
use Kingbes\Ui\ProgressBar;
use Kingbes\Ui\Combobox;
use Kingbes\Ui\EditableCombobox;
use Kingbes\Ui\RadioButtons;
use Kingbes\Ui\MultilineEntry;
use Kingbes\Ui\PasswordEntry;
use Kingbes\Ui\SearchEntry;
use Kingbes\Ui\DateTimePicker;

App::run(function () {
    $window = new Window("PHP UI 批次 2 控件测试", 720, 640);
    $window->setPosition(80, 80);
    $window->onClosing(fn() => App::quit());

    // 顶部状态栏：显示控件事件日志
    $status = new Label("事件日志：等待操作…");
    $log = function (string $msg) use ($status) {
        $status->setText("事件日志：" . $msg);
    };

    // 用 Tab 组织 10 个控件
    $tab = new Tab();
    $window->setChild($tab);

    // ============================================================
    // Tab 1：Spinbox + Slider + ProgressBar
    // ============================================================
    $numericBox = new VBox();
    $numericBox->setPadded(true);

    $numericBox->append(new Label("Spinbox (0-100)："));
    $spinbox = new Spinbox(0, 100);
    $spinbox->setValue(50)
        ->onChanged(function () use ($spinbox, $log) {
            $log("Spinbox 值变化: " . $spinbox->getValue());
        });
    $numericBox->append($spinbox);

    $numericBox->append(new Label("Slider (0-100)："));
    $slider = new Slider(0, 100);
    $slider->setValue(30)
        ->onChanged(function () use ($slider, $log) {
            $log("Slider 值变化: " . $slider->getValue());
        });
    $numericBox->append($slider);

    $numericBox->append(new Label("ProgressBar："));
    $progress = new ProgressBar();
    $progress->setValue(0);
    $numericBox->append($progress);

    // 按钮：让进度条递增 10，达到 100 后切到 -1（marquee 动画）
    $progressBtn = new Button("进度 +10");
    $progressBtn->onClicked(function () use ($progress, $log) {
        $cur = $progress->getValue();
        if ($cur < 0) {
            // 当前 marquee，重置为 0
            $progress->setValue(0);
            $log("ProgressBar 重置为 0");
            return;
        }
        $cur += 10;
        if ($cur >= 100) {
            $progress->setValue(-1);
            $log("ProgressBar 满 100，切换为不确定动画");
        } else {
            $progress->setValue($cur);
            $log("ProgressBar 进度: " . $cur);
        }
    });
    $numericBox->append($progressBtn);

    $tab->append("数值", $numericBox);

    // ============================================================
    // Tab 2：Combobox + EditableCombobox
    // ============================================================
    $comboBox = new VBox();
    $comboBox->setPadded(true);

    $comboBox->append(new Label("Combobox (不可编辑)："));
    $combo = new Combobox();
    $combo->append("苹果")
        ->append("香蕉")
        ->append("橙子")
        ->insertAt("葡萄", 0)  // 插入到首位
        ->setSelected(0)
        ->onSelected(function () use ($combo, $log) {
            $idx = $combo->getSelected();
            $log("Combobox 选中索引: " . $idx);
        });
    $comboBox->append($combo);

    // 按钮：测试 delete / clear / numItems
    $comboOpsBtn = new Button("删除首项并显示数量");
    $comboOpsBtn->onClicked(function () use ($combo, $log) {
        if ($combo->numItems() > 0) {
            $combo->delete(0);
        }
        $log("Combobox 当前数量: " . $combo->numItems());
    });
    $comboBox->append($comboOpsBtn);

    $comboClearBtn = new Button("清空");
    $comboClearBtn->onClicked(function () use ($combo, $log) {
        $combo->clear();
        $log("Combobox 已清空，数量: " . $combo->numItems());
    });
    $comboBox->append($comboClearBtn);

    $comboBox->append(new Label("EditableCombobox (可编辑)："));
    $ecombo = new EditableCombobox();
    $ecombo->append("Red")
        ->append("Green")
        ->append("Blue")
        ->setText("自定义文本")
        ->onChanged(function () use ($ecombo, $log) {
            $log("EditableCombobox 变化: text='" . $ecombo->getText()
                . "' sel=" . $ecombo->getSelected());
        });
    $comboBox->append($ecombo);

    $tab->append("下拉", $comboBox);

    // ============================================================
    // Tab 3：RadioButtons
    // ============================================================
    $radioBox = new VBox();
    $radioBox->setPadded(true);

    $radioBox->append(new Label("RadioButtons (单选组)："));
    $radio = new RadioButtons();
    $radio->append("选项 A")
        ->append("选项 B")
        ->append("选项 C")
        ->setSelected(0)
        ->onSelected(function () use ($radio, $log) {
            $log("RadioButtons 选中: " . $radio->getSelected());
        });
    $radioBox->append($radio);

    $tab->append("单选", $radioBox);

    // ============================================================
    // Tab 4：MultilineEntry + PasswordEntry + SearchEntry
    // ============================================================
    $entryBox = new VBox();
    $entryBox->setPadded(true);

    $entryBox->append(new Label("MultilineEntry："));
    $mle = new MultilineEntry();
    $mle->setText("第一行初始文本\n")
        ->append("第二行追加文本\n")
        ->onChanged(function () use ($mle, $log) {
            $len = strlen($mle->getText());
            $log("MultilineEntry 内容变化，长度: " . $len);
        });
    $entryBox->append($mle);

    $mleToggleBtn = new Button("切换只读");
    $mleToggleBtn->onClicked(function () use ($mle, $log) {
        // 简单切换：当前文本含 "[RO]" 表示已只读
        $text = $mle->getText();
        if (str_contains($text, "[RO]")) {
            $mle->setReadOnly(false);
            $mle->setText(str_replace("[RO]", "", $text));
            $log("MultilineEntry 取消只读");
        } else {
            $mle->setReadOnly(true);
            $mle->setText("[RO]" . $text);
            $log("MultilineEntry 设为只读");
        }
    });
    $entryBox->append($mleToggleBtn);

    $entryBox->append(new Label("PasswordEntry："));
    $pw = new PasswordEntry();
    $pw->setText("secret")
        ->onChanged(function () use ($pw, $log) {
            $log("PasswordEntry 变化: " . $pw->getText());
        });
    $entryBox->append($pw);

    $entryBox->append(new Label("SearchEntry："));
    $search = new SearchEntry();
    $search->setText("initial")
        ->onChanged(function () use ($search, $log) {
            $log("SearchEntry 变化: " . $search->getText());
        });
    $entryBox->append($search);

    $tab->append("输入", $entryBox);

    // ============================================================
    // Tab 5：DateTimePicker
    // ============================================================
    $dateBox = new VBox();
    $dateBox->setPadded(true);

    $dateBox->append(new Label("DateTimePicker："));
    $dtp = new DateTimePicker();
    $dtp->setTime(time());
    $dateBox->append($dtp);

    $dtpBtn = new Button("显示当前选择时间戳");
    $dtpBtn->onClicked(function () use ($dtp, $log) {
        $ts = $dtp->getTime();
        $log("DateTimePicker 时间戳: " . $ts . " (" . date('Y-m-d H:i:s', $ts) . ")");
    });
    $dateBox->append($dtpBtn);

    $dtpSetBtn = new Button("设为 2026-01-01 00:00:00");
    $dtpSetBtn->onClicked(function () use ($dtp, $log) {
        $dtp->setTime(strtotime('2026-01-01 00:00:00'));
        $log("DateTimePicker 已设为 2026-01-01");
    });
    $dateBox->append($dtpSetBtn);

    $tab->append("日期", $dateBox);

    // 把状态栏放在窗口底部
    $root = new VBox();
    $root->append($tab);
    $root->append($status);
    $window->setChild($root);

    $window->show();
});
