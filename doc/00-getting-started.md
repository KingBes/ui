# 快速开始

## 环境要求

- PHP 8.1 或更高版本（推荐 8.3+）
- 启用 FFI 扩展：`php -d ffi.enable=true`
- Composer（用于依赖管理）
- 操作系统支持：
  - **Windows**：完整实现（user32/gdi32/gdiplus/comdlg32/shell32 FFI）
  - **Linux**：GTK 后端（占位，未实现）
  - **macOS**：Cocoa 后端（占位，未实现）

## 安装

### 1. 通过 Composer 安装

```bash
composer require kingbes/ui
```

### 2. 手动克隆（开发用途）

```bash
git clone <repo-url> php-ui
cd php-ui
composer install
```

## 第一个窗口

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Kingbes\Ui\App;
use Kingbes\Ui\Window;
use Kingbes\Ui\Layout\VBox;
use Kingbes\Ui\Control\Button;
use Kingbes\Ui\Control\Label;

// 1. 创建窗口
$win = new Window("我的第一个 PHP UI 应用", 480, 320);
$win->onClose = fn() => App::quit();
$win->setMargined(12);

// 2. 创建布局容器并挂载到窗口
$root = new VBox($win);
$root->setPadding(8);

// 3. 添加控件
$label = new Label($root, "Hello, PHP UI!");
$root->add($label);

$btn = new Button($root, "点击我");
$btn->onClick = function () use ($label) {
    static $count = 0;
    $count++;
    $label->setText("已点击 {$count} 次");
};
$root->add($btn);

// 4. 挂载布局到窗口并显示
$win->setChild($root);
$win->show();

// 5. 进入事件循环
App::run();
```

### 运行

```bash
php -d ffi.enable=true hello.php
```

> **Windows 用户**：也可在 `php.ini` 中设置 `ffi.enable=true`，之后直接 `php hello.php`。

## 启用 FFI 的几种方式

| 方式 | 命令 |
| --- | --- |
| 命令行参数 | `php -d ffi.enable=true script.php` |
| php.ini 永久启用 | `ffi.enable=true` |
| 环境变量 | `PHP_FFI_ENABLE=true`（部分 SAPI） |

> **注意**：`ffi.enable` 的值有 `true`/`preload`/`false` 三种。`preload` 仅在预加载阶段启用，运行时禁用，不适合本库。请使用 `true`。

## 目录结构

```
php-ui/
├── src/                    源代码
│   ├── App.php             应用入口（静态门面）
│   ├── Window.php          窗口
│   ├── Control.php         控件基类
│   ├── TrayIcon.php        系统托盘
│   ├── Clipboard.php       剪贴板
│   ├── Dialogs.php         对话框
│   ├── Process.php         进程管理
│   ├── Screen.php          屏幕
│   ├── Control/            控件实现
│   ├── Layout/             布局容器
│   ├── Graphics/           绘图与图像
│   ├── Menu/               菜单
│   ├── Platform/           平台后端
│   ├── Geometry/           几何值对象
│   ├── Events/             事件对象
│   └── Exception/          异常
├── examples/               示例代码
├── doc/                    文档（本目录）
├── composer.json
└── README.md
```

## 下一步

- [窗口与控件](01-window-and-controls.md) — 学习创建窗口、添加控件、绑定事件
- [布局系统](02-layout.md) — 掌握 VBox/HBox/Grid/Form/Tab 的使用
- [绘图与图像](03-drawing.md) — 在 Area 画布上绘制自定义内容
- [菜单与对话框](04-menu-dialogs.md) — 菜单栏、弹出菜单、文件/颜色/字体对话框
- [系统托盘与窗口图标](05-tray-icon.md) — 托盘图标、气球通知、窗口图标
- [高级主题](06-advanced.md) — 定时器、queueMain、进程管理、多窗口

## 示例索引

| 示例 | 说明 |
| --- | --- |
| `examples/window_test.php` | 窗口基础操作 |
| `examples/controls_test.php` | 基础控件 |
| `examples/controls_advanced_test.php` | 高级控件 |
| `examples/layout_test.php` | 布局容器 |
| `examples/menu_test.php` | 菜单栏 |
| `examples/dialogs_test.php` | 对话框 |
| `examples/area_test.php` | 自定义绘图 |
| `examples/graphics_advanced_test.php` | 高级绘图（路径/渐变/变换） |
| `examples/table_test.php` | 表格（多类型列） |
| `examples/image_test.php` | 图片/图标支持 |
| `examples/tray_test.php` | 系统托盘 + 窗口图标 |
| `examples/process_test.php` | 进程管理 |
| `examples/full_test.php` | 综合演示 |

运行示例：

```bash
php -d ffi.enable=true examples/window_test.php
```

无人值守自动测试（CI 用）：

```bash
# Linux/macOS
PHP_UI_AUTO_EXIT=1 php -d ffi.enable=true examples/tray_test.php

# Windows PowerShell
$env:PHP_UI_AUTO_EXIT='1'; php -d ffi.enable=true examples/tray_test.php
```

## 常见问题

### Q: 运行时报 "FFI is not enabled"？

A: 确保启动时带 `-d ffi.enable=true`，或在 `php.ini` 中设置 `ffi.enable=true`。

### Q: 启动后窗口不显示？

A: 必须调用 `$win->show()` 和 `App::run()` 才会显示窗口并进入事件循环。

### Q: 点击关闭按钮程序没退出？

A: `Window::onClose` 默认不退出事件循环。需在回调中调用 `App::quit()`：

```php
$win->onClose = fn() => App::quit();
```

### Q: 控件不显示或重叠？

A: 控件必须先 `add()` 到容器，再调用 `$win->setChild($container)`。嵌套容器由 `placeChild` 自动处理，顶层容器由 `windowSetChild` 标记。

### Q: FFI 跨作用域 cast 崩溃？

A: 本库内部已用 `INT_TO_PTR` 联合体处理句柄转换，对外只暴露 `int`。请勿在用户代码中直接操作 FFI CData。
