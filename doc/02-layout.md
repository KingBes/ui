# 布局系统

布局容器自动管理子控件的位置和尺寸，窗口大小变化时自动重布局。

## 容器层级

```
Container (抽象)
├── Box (抽象)
│   ├── HBox  水平排列
│   └── VBox  垂直排列
├── Grid      网格布局
├── Form      表单布局（标签+字段两列）
├── Group     带标题边框分组
└── Tab       标签页容器
```

所有容器继承自 `Kingbes\Ui\Layout\Container`，本身也是 `Control`，可嵌套。

## 顶层容器 vs 嵌套容器

- **顶层容器**：由 `$win->setChild($container)` 设置，由窗口尺寸变化时重布局
- **嵌套容器**：作为子控件 add 到其他容器中，由父容器 placeChild 递归处理

> 同一个 Container 类既可做顶层也可做嵌套，由 `toplevel` 标记区分，框架自动处理。

## Container 基类 API

```php
$container->add($control);              // 添加子控件
$container->remove($control);           // 移除子控件
$container->getChildren();              // 获取所有子控件
$container->count();                    // 子控件数量
$container->setToplevel(true);          // 标记为顶层（一般无需手动调用）
$container->destroy();                  // 销毁容器及所有子控件
```

---

## HBox / VBox 线性布局

`HBox` 水平排列，`VBox` 垂直排列。

### 创建

```php
use Kingbes\Ui\Layout\HBox;
use Kingbes\Ui\Layout\VBox;

$hbox = new HBox($parent);  // 水平
$vbox = new VBox($parent);  // 垂直
```

### 添加控件

```php
$vbox->add(new Label($vbox, "姓名:"));
$vbox->add(new Entry($vbox));
$vbox->add(new Button($vbox, "提交"));
```

### 内边距

```php
$vbox->setPadding(12);  // 容器内边距（像素）
echo $vbox->getPadding();
```

### 示例：表单布局

```php
$win = new Window("登录", 320, 200);
$root = new VBox($win);
$root->setPadding(16);

$row1 = new HBox($root);  $root->add($row1);
$row1->add(new Label($row1, "用户名:"));
$row1->add(new Entry($row1));

$row2 = new HBox($root);  $root->add($row2);
$row2->add(new Label($row2, "密码:"));
$row2->add(new PasswordEntry($row2));

$btnRow = new HBox($root);  $root->add($btnRow);
$btnRow->add(new Button($btnRow, "登录"));
$btnRow->add(new Button($btnRow, "取消"));

$win->setChild($root);
$win->show();
```

---

## Grid 网格布局

固定行列数的二维网格，每个单元格占 1x1。

### 创建

```php
use Kingbes\Ui\Layout\Grid;

$grid = new Grid($parent, 3, 4);  // 3 行 4 列
echo $grid->getRows();  // 3
echo $grid->getCols();  // 4
```

### 使用

```php
$grid = new Grid($win, 2, 2);
$grid->add(new Label($grid, "(0,0)"));
$grid->add(new Label($grid, "(0,1)"));
$grid->add(new Label($grid, "(1,0)"));
$grid->add(new Label($grid, "(1,1)"));

$win->setChild($grid);
```

> 控件按 add 顺序填入单元格，行优先。

---

## Form 表单布局

两列布局：左列标签，右列字段。常用于设置面板。

### 创建

```php
use Kingbes\Ui\Layout\Form;
use Kingbes\Ui\Control\Entry;
use Kingbes\Ui\Control\Checkbox;

$form = new Form($win);
$form->addRow(new Label($form, "姓名"), new Entry($form));
$form->addRow(new Label($form, "邮箱"), new Entry($form));
$form->addRow(new Label($form, "记住我"), new Checkbox($form, ""));

$win->setChild($form);
```

---

## Group 分组容器

带标题边框的容器，用于视觉分组。

```php
use Kingbes\Ui\Layout\Group;
use Kingbes\Ui\Control\Button;

$group = new Group($win, "操作");
$group->setPadding(10);
$group->add(new Button($group, "新增"));
$group->add(new Button($group, "删除"));

$win->setChild($group);
```

---

## Tab 标签页容器

多页切换容器，每页是一个独立 Container。

### 创建

```php
use Kingbes\Ui\Layout\Tab;
use Kingbes\Ui\Layout\VBox;
use Kingbes\Ui\Control\Label;

$tab = new Tab($win);

// 添加页面
$page1 = new VBox($tab);
$page1->add(new Label($page1, "首页内容"));
$tab->addPage("首页", $page1);

$page2 = new VBox($tab);
$page2->add(new Label($page2, "设置内容"));
$tab->addPage("设置", $page2);

// 页面切换事件
$tab->onPageChanged = function (int $index) {
    echo "切换到第 $index 页\n";
};

// 获取页面
$page = $tab->getPage(0);
echo $tab->getPageCount();  // 2

$win->setChild($tab);
```

### 页签图标

```php
use Kingbes\Ui\Graphics\Image;

$icon = Image::fromFile('tab1.png');
$iconIdx = $tab->addImage($icon);
$tab->setPageImage(0, $icon);  // 给第 0 页设置图标
```

---

## 嵌套布局示例

```php
$win = new Window("综合布局", 640, 480);

$root = new VBox($win);
$root->setPadding(8);

// 顶部菜单条（HBox）
$topBar = new HBox($root);
$root->add($topBar);
$topBar->add(new Button($topBar, "新建"));
$topBar->add(new Button($topBar, "打开"));
$topBar->add(new Button($topBar, "保存"));

// 中部主区域（Tab）
$tab = new Tab($root);
$root->add($tab);

// 第一页：表单
$formPage = new VBox($tab);
$tab->addPage("表单", $formPage);
$form = new Form($formPage);
$formPage->add($form);
$form->addRow(new Label($form, "名称"), new Entry($form));

// 第二页：表格
$tablePage = new VBox($tab);
$tab->addPage("数据", $tablePage);
$tablePage->add(new Label($tablePage, "数据列表"));

// 底部状态栏
$statusBar = new HBox($root);
$root->add($statusBar);
$statusBar->add(new Label($statusBar, "就绪"));

$win->setChild($root);
$win->show();
```

---

## 布局时机

布局在以下时机自动触发：

1. `$win->setChild($container)` 设置顶层容器时
2. 窗口尺寸变化（WM_SIZE）时
3. 调用 `$win->setMargined()` 后
4. 调用 `App::platform()->triggerRelayout($hwnd)` 异步触发

> 无需手动调用 `layout()`，框架会自动处理。

## 内边距 vs Margined

| 属性 | 作用域 | 设置方法 |
| --- | --- | --- |
| Window margined | 窗口客户区与顶层容器之间 | `$win->setMargined(12)` |
| Container padding | 容器边界与子控件之间 | `$container->setPadding(8)` |

```php
$win->setMargined(12);    // 窗口客户区留 12 像素边距
$root = new VBox($win);
$root->setPadding(8);     // 容器内部再留 8 像素边距
$win->setChild($root);
```
