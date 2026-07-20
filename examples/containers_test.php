<?php
declare(strict_types=1);

/**
 * 4 个布局容器测试示例：Tab / Group / Form / Grid
 *
 * 运行：
 *   php -d ffi.enable=true -f examples/containers_test.php
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
use Kingbes\Ui\Tab;
use Kingbes\Ui\Group;
use Kingbes\Ui\Form;
use Kingbes\Ui\Grid;

App::run(function () {
    $window = new Window("容器控件测试", 700, 600);
    $window->setPosition(100, 100);
    $window->onClosing(fn() => App::quit());

    // 根布局：左侧切换按钮，右侧测试目标
    $root = new HBox();
    $root->setPadded(true);
    $window->setChild($root);

    // 日志区
    $log = new Label("=== 容器测试日志 ===\n点左侧按钮切换不同容器演示");

    // 左侧导航按钮
    $nav = VBox::vertical();
    $nav->setPadded(true);

    // 右侧内容区（用 Tab 切换 4 个容器演示）
    $content = new VBox();
    $content->setPadded(true);

    $root->append($nav, false);
    $root->append($content, true);

    // ============================================================
    // 1. Tab 演示
    // ============================================================
    $tabDemo = function () use ($log) {
        $tab = new Tab();
        $tab->append("第一页", new Label("这是 Tab 第一页内容"));
        $tab->append("第二页", new Label("这是 Tab 第二页内容\n可以放任何控件"));
        $tab->append("第三页", new Button("Tab 第三页中的按钮"));

        $info = new VBox();
        $info->setPadded(true);
        $info->append(new Label("Tab.numPages() = " . $tab->numPages()));
        $info->append(new Label("Tab.getSelected() = " . $tab->getSelected()));
        $info->append($tab);

        $log->setText($log->getText() . "\n> Tab: 3 pages, current=" . $tab->getSelected());
        return $info;
    };

    // ============================================================
    // 2. Group 演示
    // ============================================================
    $groupDemo = function () use ($log) {
        $g1 = new Group("用户信息");
        $g1->setMargined(true);
        $g1->setChild(new Label("姓名：张三\n邮箱：test@example.com"));

        $g2 = new Group("系统设置");
        $g2->setMargined(true);
        $g2->setChild(new Checkbox("启用通知"));

        $g3 = new Group("可修改标题");
        $g3->setMargined(true);
        $g3->setChild(new Label("点下面按钮改 Group 标题"));
        $btn = new Button("改 Group 标题");
        $i = 0;
        $btn->onClicked(function () use ($g3, &$i, $log) {
            $i++;
            $g3->setTitle("新标题 #" . $i);
            $log->setText($log->getText() . "\n> Group.setTitle: 新标题 #" . $i);
        });
        $inner = VBox::vertical();
        $inner->setPadded(true);
        $inner->append($g3->setChild($inner)); // 这样不行，简化下：
        // 重新组织：
        $inner2 = VBox::vertical();
        $inner2->setPadded(true);
        $inner2->append(new Label("点下面按钮改 Group 标题"));
        $inner2->append($btn);
        $g3->setChild($inner2);

        $box = VBox::vertical();
        $box->setPadded(true);
        $box->append($g1);
        $box->append($g2);
        $box->append($g3);

        $log->setText($log->getText() . "\n> Group: 3 个分组已创建");
        return $box;
    };

    // ============================================================
    // 3. Form 演示
    // ============================================================
    $formDemo = function () use ($log) {
        $form = new Form();
        $form->setPadded(true);
        $form->append("用户名", new Entry());
        $form->append("密码", new Entry());
        $form->append("邮箱", new Entry());
        $form->append("备注", new Label("只读字段"), false);
        $form->append("启用", new Checkbox("接收通知"), false);

        $info = new Label("Form.numChildren() = " . $form->numChildren());

        $btnDelete = new Button("删除最后一行 (Form.delete)");
        $btnDelete->onClicked(function () use ($form, $info, $log) {
            $n = $form->numChildren();
            if ($n > 0) {
                $form->delete($n - 1);
                $info->setText("Form.numChildren() = " . $form->numChildren());
                $log->setText($log->getText() . "\n> Form.delete(" . ($n - 1) . ")");
            }
        });

        $box = VBox::vertical();
        $box->setPadded(true);
        $box->append($info);
        $box->append($form);
        $box->append($btnDelete);

        $log->setText($log->getText() . "\n> Form: 5 行表单已创建");
        return $box;
    };

    // ============================================================
    // 4. Grid 演示
    // ============================================================
    $gridDemo = function () use ($log) {
        $grid = new Grid();
        $grid->setPadded(true);

        // 3x3 网格
        // Align: Fill=0, Start=1, Center=2, End=3
        $grid->append(new Label("(0,0)"), 0, 0, 1, 1, false, 1, false, 1);
        $grid->append(new Label("(1,0)"), 1, 0, 1, 1, false, 1, false, 1);
        $grid->append(new Label("(2,0)"), 2, 0, 1, 1, false, 1, false, 1);

        $grid->append(new Button("A"), 0, 1, 1, 1, false, 0, false, 0);
        $grid->append(new Button("B"), 1, 1, 1, 1, false, 0, false, 0);
        $grid->append(new Button("C"), 2, 1, 1, 1, false, 0, false, 0);

        // 占 2 列的按钮
        $grid->append(new Button("占两列"), 0, 2, 2, 1, true, 0, false, 0);
        $grid->append(new Button("D"), 2, 2, 1, 1, false, 0, false, 0);

        $log->setText($log->getText() . "\n> Grid: 3x3 + 跨列演示");
        return $grid;
    };

    // ============================================================
    // 切换逻辑：每次点击导航按钮，替换 content 内容
    // ============================================================
    $current = null;
    $switchTo = function ($demoName) use (&$current, $content, $tabDemo, $groupDemo, $formDemo, $gridDemo) {
        // 销毁当前内容
        if ($current !== null) {
            $current->destroy();
        }
        $demo = match ($demoName) {
            'tab' => $tabDemo(),
            'group' => $groupDemo(),
            'form' => $formDemo(),
            'grid' => $gridDemo(),
        };
        $content->append($demo, true);
        $current = $demo;
    };

    $btnTab = new Button("Tab 演示");
    $btnTab->onClicked(fn() => $switchTo('tab'));
    $nav->append($btnTab, false);

    $btnGroup = new Button("Group 演示");
    $btnGroup->onClicked(fn() => $switchTo('group'));
    $nav->append($btnGroup, false);

    $btnForm = new Button("Form 演示");
    $btnForm->onClicked(fn() => $switchTo('form'));
    $nav->append($btnForm, false);

    $btnGrid = new Button("Grid 演示");
    $btnGrid->onClicked(fn() => $switchTo('grid'));
    $nav->append($btnGrid, false);

    $nav->append(Separator::horizontal(), false);
    $nav->append($log, true);

    // 默认显示 Tab 演示
    $switchTo('tab');

    $window->show();
});
