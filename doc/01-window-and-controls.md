# 窗口与控件

## Window 类

`Kingbes\Ui\Window` 是顶层窗口，所有 GUI 应用的根容器。

### 创建窗口

```php
use Kingbes\Ui\Window;

$win = new Window("标题", 800, 600);  // 标题, 宽, 高
```

### 标题与位置

```php
$win->setTitle("新标题");
$title = $win->getTitle();

$win->setPosition(100, 100);          // 屏幕坐标
$pos = $win->getPosition();           // 返回 Point
echo $pos->x . "," . $pos->y;

$win->setSize(1024, 768);
$size = $win->getSize();              // 返回 Size
$clientSize = $win->getClientSize();  // 不含标题栏/边框
```

### 窗口样式

```php
$win->setFullscreen(true);    // 全屏
$win->setBorderless(true);    // 无边框
$win->setResizeable(false);   // 禁止调整大小
$win->setTopmost(true);       // 窗口置顶
$win->setMargined(12);        // 客户区内边距（像素）
```

### 窗口状态

```php
$win->maximize();   // 最大化
$win->minimize();   // 最小化
$win->restore();    // 恢复
$win->show();       // 显示
$win->hide();       // 隐藏
$win->close();      // 关闭（触发 onClose）
$win->isFocused();  // 是否有焦点
```

### 挂载子容器

```php
$root = new VBox($win);
$win->setChild($root);  // 设置顶层容器，窗口尺寸变化时自动重布局
```

### 滚动窗口

```php
$win->setScrollable(2000);  // 启用垂直滚动条，内容高度 2000 像素
```

### 窗口图标

```php
// 方式 1：从 .ico 文件加载
$win->setIconFromFile('C:/path/to/icon.ico');

// 方式 2：预定义系统图标
use Kingbes\Ui\TrayIcon;
$win->setIconFromId(TrayIcon::IDI_APPLICATION);
// 可选：IDI_APPLICATION / IDI_HAND / IDI_QUESTION / IDI_EXCLAMATION / IDI_ASTERISK

// 方式 3：从 Image 对象加载（PNG/JPEG/BMP/GIF/TIFF，支持透明通道）
use Kingbes\Ui\Graphics\Image;
$img = Image::fromFile('C:/path/to/logo.png');
$win->setIconFromImage($img);
```

同时设置 ICON_BIG（Alt+Tab 显示）和 ICON_SMALL（标题栏/任务栏）。

### 事件

| 事件 | 参数 | 说明 |
| --- | --- | --- |
| `onClose` | 无 | 关闭按钮点击。需调用 `App::quit()` 退出 |
| `onResize` | `ResizeEvent` | 窗口尺寸变化 |
| `onFocus` | `bool $focused` | 获得/失去焦点 |
| `onPositionChanged` | `Point` | 窗口位置变化（WM_MOVE） |

```php
$win->onClose = function () {
    // 返回 false 可阻止关闭（部分平台支持）
    App::quit();
};

$win->onResize = function ($event) {
    echo "新尺寸: {$event->width}x{$event->height}\n";
};
```

### 多窗口

```php
$win1 = new Window("窗口 1", 400, 300);
$win2 = new Window("窗口 2", 400, 300);

$win1->onClose = fn() => App::quit();
$win2->onClose = fn() => $win2->hide();  // 仅隐藏第二个窗口

$win1->show();
$win2->show();
App::run();
```

> 所有窗口关闭后事件循环自动退出。

---

## Control 基类

所有控件继承自 `Kingbes\Ui\Control`，共享以下 API：

### 通用方法

```php
$btn = new Button($parent);          // 父容器：Control 或 Window
$btn->setText("按钮");                // 设置文本
$text = $btn->getText();
$btn->setBounds(10, 10, 80, 24);     // 位置和尺寸
$btn->show();
$btn->hide();
$btn->setEnabled(false);              // 禁用
$btn->destroy();                      // 销毁
$btn->getHwnd();                      // 平台句柄
$btn->getParent();                    // 父容器
$btn->getWindow();                    // 所属窗口
```

### 通用事件

```php
$control->onClick = function () { ... };
$control->onMouseDown = function ($event) {  // MouseEvent
    echo "down at {$event->x},{$event->y} btn={$event->button}\n";
};
$control->onMouseUp = function ($event) { ... };
$control->onMouseMove = function ($event) { ... };
$control->onKeyDown = function ($event) {     // KeyEvent
    echo "key: {$event->code} ctrl={$event->ctrl}\n";
};
$control->onKeyUp = function ($event) { ... };
```

---

## 基础控件

### Button 按钮

```php
use Kingbes\Ui\Control\Button;

$btn = new Button($parent, "确定");
$btn->onClick = fn() => print("clicked");

// 图标按钮
use Kingbes\Ui\Graphics\Image;
$btn->setImage(Image::fromFile('icon.png'));
$btn->setImage(null);  // 清除图标
```

### Label 标签

```php
use Kingbes\Ui\Control\Label;

$label = new Label($parent, "文本");
$label->setText("新文本");

// 对齐方式（构造器第二参数）
$l1 = new Label($parent, "左对齐", Label::ALIGN_LEFT);    // 默认
$l2 = new Label($parent, "居中",   Label::ALIGN_CENTER);
$l3 = new Label($parent, "右对齐", Label::ALIGN_RIGHT);

// 图像标签
$label->setImage(Image::fromFile('pic.png'));
```

### Entry 单行输入

```php
use Kingbes\Ui\Control\Entry;

$entry = new Entry($parent);  // 可选第二参数 placeholder
$entry->setText("初始值");
$text = $entry->getText();

$entry->onChange = fn() => print($entry->getText());
$entry->onEnter = fn() => print("Enter 键按下");
```

### PasswordEntry 密码框

```php
use Kingbes\Ui\Control\PasswordEntry;
$pwd = new PasswordEntry($parent);
$pwd->onChange = fn() => print("输入中");
$pwd->onEnter = fn() => print("回提交");
```

### TextArea 多行输入

```php
use Kingbes\Ui\Control\TextArea;
$ta = new TextArea($parent);
$ta->setText("多行\n文本");
$ta->onChange = fn() => print("changed");
```

### Checkbox 复选框

```php
use Kingbes\Ui\Control\Checkbox;
$cb = new Checkbox($parent, "启用此选项");
$cb->setChecked(true);
if ($cb->isChecked()) { ... }
```

### RadioBox 单选框

```php
use Kingbes\Ui\Control\RadioBox;
$r1 = new RadioBox($parent, "选项 A");
$r2 = new RadioBox($parent, "选项 B");
$r1->setChecked(true);
// 同一容器内的 RadioBox 自动互斥
```

### ComboBox 下拉框

```php
use Kingbes\Ui\Control\ComboBox;
$cb = new ComboBox($parent);
$cb->addItem("选项 1");
$cb->addItem("选项 2");
$cb->select(0);                    // 选中第 0 项
$idx = $cb->getSelectedIndex();
$cb->onSelect = function () use ($cb) {
    echo "选中: " . $cb->getSelectedIndex() . "\n";
};
```

### EditableComboBox 可编辑下拉框

```php
use Kingbes\Ui\Control\EditableComboBox;
$ecb = new EditableComboBox($parent);
$ecb->addItem("PHP");
$ecb->addItem("Python");
$ecb->setText("自定义值");
$ecb->onChange = fn() => print($ecb->getText());
$ecb->onSelect = fn() => print($ecb->getSelectedIndex());
```

### ListBox 列表框

```php
use Kingbes\Ui\Control\ListBox;
$lb = new ListBox($parent);
$lb->addItem("项目 1");
$lb->addItem("项目 2");
$lb->select(1);
$lb->onSelect = fn() => print($lb->getSelectedIndex());
```

---

## 数值类控件

### Slider 滑块

```php
use Kingbes\Ui\Control\Slider;
$s = new Slider($parent);
$s->setRange(0, 100);
$s->setValue(50);
$val = $s->getValue();

$s->onChanged = fn() => print($s->getValue());    // 拖动中触发
$s->onReleased = fn() => print($s->getValue());   // 释放时触发
```

### SpinBox 数值调节

```php
use Kingbes\Ui\Control\SpinBox;
$sb = new SpinBox($parent);
$sb->setRange(0, 100);
$sb->setValue(50);
echo $sb->getValue();
$sb->onChanged = fn() => print($sb->getValue());
```

### ProgressBar 进度条

```php
use Kingbes\Ui\Control\ProgressBar;
$pb = new ProgressBar($parent);
$pb->setRange(0, 100);
$pb->setValue(30);

// 不确定状态（滚动动画）
$pb->setIndeterminate(true);    // 启用 marquee 动画
$pb->setIndeterminate(false);   // 恢复确定状态
```

---

## 高级控件

### DateTimePicker 日期时间选择器

```php
use Kingbes\Ui\Control\DateTimePicker;

// 三种模式
$d1 = new DateTimePicker($parent, DateTimePicker::DATE);     // 仅日期
$d2 = new DateTimePicker($parent, DateTimePicker::TIME);     // 仅时间
$d3 = new DateTimePicker($parent, DateTimePicker::DATETIME); // 日期+时间

// 获取值（返回数组或 null）
$time = $d3->getTime();
if ($time !== null) {
    echo "{$time['year']}-{$time['month']}-{$time['day']} "
       . "{$time['hour']}:{$time['minute']}:{$time['second']}\n";
}

// 设置值
$d3->setTime(2026, 7, 25, 14, 30, 0);

// 自定义格式
$d3->setFormat('yyyy-MM-dd HH:mm');

$d3->onChanged = fn() => print(json_encode($d3->getTime()));
```

### ColorButton 颜色按钮

```php
use Kingbes\Ui\Control\ColorButton;
use Kingbes\Ui\Graphics\Color;

$cb = new ColorButton($parent);
$cb->setColor(Color::rgb(255, 0, 0));
$color = $cb->getColor();
echo $color->red . "," . $color->green . "," . $color->blue;
$cb->onColorChanged = fn() => print($cb->getColor()->toColorRef());
```

### FontButton 字体按钮

```php
use Kingbes\Ui\Control\FontButton;
$fb = new FontButton($parent);
$fb->setFont(['name' => '微软雅黑', 'size' => 14, 'bold' => true]);
$info = $fb->getFont();
echo $info['name'];
$fb->onFontChanged = fn() => print(json_encode($fb->getFont()));
```

### Separator 分隔线

```php
use Kingbes\Ui\Control\Separator;
$sepH = new Separator($parent, Separator::HORIZONTAL);
$sepV = new Separator($parent, Separator::VERTICAL);
```

---

## Table 表格控件

`Table` 是功能最丰富的控件，基于 ListView 虚拟模式（LVS_OWNERDATA）实现，支持 6 种列类型。

### TableModel 接口

```php
use Kingbes\Ui\Control\Table;
use Kingbes\Ui\Control\TableModel;
use Kingbes\Ui\Graphics\Image;
use Kingbes\Ui\Graphics\Color;

$model = new class implements TableModel {
    public function getRowCount(): int { return 100; }
    public function getColumnCount(): int { return 3; }
    public function getCellValue(int $row, int $col): string {
        return "[$row,$col]";
    }

    // 可选方法：返回 Image 则该单元格显示图像
    // public function getCellImage(int $row, int $col): ?Image { ... }

    // 可选方法：返回 bool 则显示复选框
    // public function getCellCheckbox(int $row, int $col): ?bool { ... }

    // 可选方法：返回 0-100 整数则显示进度条
    // public function getCellProgress(int $row, int $col): ?int { ... }

    // 可选方法：返回 Color 则显示颜色块
    // public function getCellColor(int $row, int $col): ?Color { ... }

    // 可选方法：返回字符串则显示按钮
    // public function getCellButton(int $row, int $col): string { ... }
};
```

### 创建表格

```php
$table = new Table($parent);
$table->setColumns(['名称', '进度', '颜色', '操作'], 100);

// 设置列类型
$table->setColumnType(0, Table::TYPE_TEXT);
$table->setColumnType(1, Table::TYPE_PROGRESS);
$table->setColumnType(2, Table::TYPE_COLOR);
$table->setColumnType(3, Table::TYPE_BUTTON);

$table->setModel($model);
```

### 列类型常量

| 常量 | 说明 |
| --- | --- |
| `Table::TYPE_TEXT` | 文本（默认） |
| `Table::TYPE_IMAGE` | 图像 |
| `Table::TYPE_CHECKBOX` | 复选框 |
| `Table::TYPE_PROGRESS` | 进度条 |
| `Table::TYPE_COLOR` | 颜色块 |
| `Table::TYPE_BUTTON` | 按钮 |

### 事件

```php
$table->onSelectionChanged = function (int $row) {
    echo "选中行: $row\n";
};
$table->onRowDoubleClicked = function (int $row) {
    echo "双击行: $row\n";
};
$table->onCellCheckboxToggle = function (int $row, int $col, bool $checked) {
    echo "[$row,$col] 复选框: " . ($checked ? "勾" : "消") . "\n";
};
$table->onCellButtonClick = function (int $row, int $col) {
    echo "[$row,$col] 按钮点击\n";
};
```

### 程序化操作

```php
$table->select(3);              // 选中第 3 行（-1 取消）
$row = $table->getSelectedRow();
$table->refresh();              // 刷新整张表
$table->refreshRow(2);          // 仅刷新第 2 行
$table->setRowCount(1000);      // 设置虚拟行数

// 行级着色（NM_CUSTOMDRAW）
$table->setRowBackgroundColor(0, 0xFF0000);  // ARGB
$table->setRowTextColor(0, 0xFFFFFF);
$table->setRowBackgroundColor(0, null);      // 清除
```

### 图像列

```php
$table->setImageSize(16);                 // 图标尺寸
$iconIdx = $table->addImage(Image::fromFile('icon.png'));

// 在 Model::getCellImage 中返回 Image 即可
```

---

## Area 自定义绘图画布

`Area` 是空白控件，提供 `onDraw` 回调让用户用 GDI+ 绘制任意内容。

```php
use Kingbes\Ui\Control\Area;
use Kingbes\Ui\Graphics\Color;

$area = new Area($parent);
$area->onDraw = function ($ctx) {
    $ctx->setBrush(Color::rgb(255, 200, 0));
    $ctx->fillRect(10, 10, 100, 60);

    $ctx->setPen(Color::rgb(255, 0, 0), 2);
    $ctx->drawLine(0, 0, 200, 100);

    $ctx->setFont('Segoe UI', 14);
    $ctx->setColor(Color::black());
    $ctx->drawText(20, 20, "Hello Area");
};

// 鼠标事件
$area->onMouseDown = function ($e) { ... };
$area->onMouseUp = function ($e) { ... };
$area->onMouseMove = function ($e) { ... };
$area->onMouseEnter = fn() => print("enter");
$area->onMouseLeave = fn() => print("leave");

// 滚动支持
$area->setSize(2000, 1500);              // 设置虚拟内容尺寸，启用滚动条
$area->scrollTo(100, 200);               // 程序化滚动
$pos = $area->getScrollPos();            // ['x' => int, 'y' => int]
$area->invalidate();                     // 触发重绘（通过平台 areaInvalidate）
```

详细的绘图 API 见 [03-drawing.md](03-drawing.md)。
