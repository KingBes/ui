# Kingbes UI

> 基于 PHP FFI 实现的跨平台 GUI 库，纯 PHP 编写，无需编译扩展。

## 简介

Kingbes UI 是一个用 PHP 编写的桌面 GUI 库，通过 PHP FFI 直接调用操作系统原生 GUI API（Windows 下为 user32/gdi32/gdiplus），提供面向对象的 API，让 PHP 开发者也能快速构建桌面应用。

### 特性

- **纯 PHP 实现**：无需编译 C 扩展，仅启用 `ffi.enable` 即可
- **原生性能**：直接调用系统 API，无中间层开销
- **面向对象**：统一的 Widget/Container/Event 模型，链式 API
- **丰富控件**：按钮、输入框、表格、树形列表、日期选择器、颜色/字体选择器等
- **自定义绘图**：基于 GDI+ 的 2D 绘图，支持路径、渐变、变换、贝塞尔曲线
- **完整窗口管理**：多窗口、菜单栏、对话框、滚动、置顶、全屏
- **系统集成**：系统托盘、气球通知、窗口图标、剪贴板、进程管理
- **跨平台架构**：PlatformInterface 抽象层支持多平台后端

### 平台支持

| 平台 | 状态 |
| --- | --- |
| Windows | 完整实现（user32/gdi32/gdiplus/comdlg32/shell32） |
| Linux (GTK) | 占位，未实现 |
| macOS (Cocoa) | 占位，未实现 |

## 快速开始

### 环境要求

- PHP 8.1+（推荐 8.3+）
- 启用 FFI 扩展

### 安装

```bash
composer require kingbes/ui
```

### Hello World

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Kingbes\Ui\App;
use Kingbes\Ui\Window;
use Kingbes\Ui\Layout\VBox;
use Kingbes\Ui\Control\Button;
use Kingbes\Ui\Control\Label;

$win = new Window("Hello PHP UI", 480, 320);
$win->onClose = fn() => App::quit();
$win->setMargined(12);

$root = new VBox($win);
$root->setPadding(8);

$label = new Label($root, "Hello, World!");
$root->add($label);

$btn = new Button($root, "点击我");
$btn->onClick = function () use ($label) {
    static $n = 0;
    $label->setText("已点击 " . ++$n . " 次");
};
$root->add($btn);

$win->setChild($root);
$win->show();
App::run();
```

运行：

```bash
php hello.php
```

## 主要功能

- **窗口**：多窗口、菜单栏、滚动、全屏、置顶、自定义图标（PNG/ICO）
- **控件**：Button / Label / Entry / TextArea / Checkbox / RadioBox / ComboBox / ListBox / Slider / SpinBox / ProgressBar / DateTimePicker / ColorButton / FontButton / Separator / Table / Area
- **布局**：HBox / VBox / Grid / Form / Group / Tab
- **绘图**：DrawContext（线条/矩形/椭圆/弧线/贝塞尔/路径/渐变/变换/裁剪/图像）
- **菜单**：菜单栏、弹出菜单、子菜单、勾选状态、图标
- **对话框**：消息框、文件打开/保存、文件夹选择、颜色选择、字体选择
- **系统托盘**：托盘图标、气球通知、右键菜单、点击/双击事件
- **系统集成**：剪贴板、屏幕尺寸、进程管理

## 文档

详细使用文档位于 [`doc/`](doc/README.md) 目录：

- [快速开始](doc/00-getting-started.md)
- [窗口与控件](doc/01-window-and-controls.md)
- [布局系统](doc/02-layout.md)
- [绘图与图像](doc/03-drawing.md)
- [菜单与对话框](doc/04-menu-dialogs.md)
- [系统托盘与窗口图标](doc/05-tray-icon.md)
- [高级主题](doc/06-advanced.md)
- [API 速查](doc/07-api-reference.md)

## 示例

`examples/` 目录包含丰富示例：

```bash
# 运行综合演示
php examples/full_test.php

# 系统托盘 + 窗口图标
php examples/tray_test.php

# 表格多类型列（图像/复选框/进度/颜色/按钮）
php examples/table_test.php

# 高级绘图（路径/渐变/变换/裁剪）
php examples/graphics_advanced_test.php
```

## 项目结构

```
src/
├── App.php             应用入口（静态门面）
├── Window.php          窗口
├── Control.php         控件基类
├── TrayIcon.php        系统托盘
├── Clipboard.php       剪贴板
├── Dialogs.php         对话框
├── Process.php         进程管理
├── Screen.php          屏幕
├── Control/            控件实现
├── Layout/             布局容器
├── Graphics/           绘图与图像
├── Menu/               菜单
├── Platform/           平台后端
├── Geometry/           几何值对象
├── Events/             事件对象
└── Exception/          异常
```

## 设计理念

- **安全 FFI**：对外只暴露 `int` 句柄，FFI CData 不跨作用域传递。内部用 `INT_TO_PTR` 联合体安全转换指针与整数
- **GC 防护**：Window/Control 实例注册到平台注册表，防止被 GC 回收
- **事件驱动**：闭包属性绑定事件回调，无需继承或接口实现
- **跨平台抽象**：PlatformInterface 定义统一契约，平台后端可独立实现

## License

MIT
