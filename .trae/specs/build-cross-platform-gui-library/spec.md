# 跨平台 GUI 库 Spec

## Why
PHP 生态缺少原生 GUI 方案。借助 `kingbes/phpc` 的 FFI 安全封装，可以直接调用操作系统原生 GUI 动态库（Windows user32/gdi32、Linux GTK、macOS Cocoa），提供一套功能齐全、强类型、面向对象的跨平台 GUI 库，让 PHP 程序员能构建桌面应用。

## What Changes
- 新建 `Kingbes\Ui` 命名空间下的完整 GUI 库，基于 `kingbes/phpc` 的 Library/SafeCall/Struct/Pointer 安全 FFI 封装
- 设计平台抽象层（`PlatformInterface`），各平台实现独立后端
- **Windows 后端（优先实现）**：通过 user32.dll/gdi32.dll/comctl32.dll/comdlg32.dll/shell32.dll/kernel32.dll 实现完整功能
- Linux GTK 后端与 macOS Cocoa 后端：定义接口契约与桩实现，留待后续阶段补全
- 强类型 OOP 设计：所有类启用 `declare(strict_types=1)`、类型化属性、返回类型声明
- 单线程事件循环（PHP 无异步/线程），通过 `queueMain` 主线程投递 + `Process` 进程封装模拟后台任务
- 提供 `examples/` 目录的示例程序验证各功能

## Impact
- 新增代码：`src/` 全部源码、`examples/` 示例
- 依赖：`kingbes/phpc ^0.0.2`（已在 composer.json）、PHP 8.1+（强类型、enum、readonly）
- 运行要求：`php -d ffi.enable=true`（FFI 必须开启）
- 不影响现有 `vendor/` 与 `composer.json`（仅可能按需扩展 autoload）

## ADDED Requirements

### Requirement: 平台抽象层
系统 SHALL 提供一个 `PlatformInterface` 抽象层，定义所有平台后端必须实现的契约（窗口、控件、菜单、对话框、绘图、事件循环、剪贴板、定时器、主线程投递）。`App` 门面 SHALL 在启动时根据 `PHP_OS_FAMILY` 自动选择具体实现。上层 Widget/Window/Layout 类 SHALL 只依赖抽象接口，不直接调用 FFI。

#### Scenario: Windows 平台自动选择
- **WHEN** 在 Windows 上调用 `App::run()`
- **THEN** 系统实例化 `WindowsPlatform` 并由其驱动事件循环

#### Scenario: 不支持的平台
- **WHEN** 在未实现后端的平台上启动
- **THEN** 抛出 `UnsupportedPlatformException`，消息指明当前系统与所需后端

### Requirement: 应用生命周期
系统 SHALL 提供 `App` 静态门面管理应用生命周期：`run()` 进入事件循环、`quit()` 退出、`onShouldQuit(callable)` 注册退出确认回调、`queueMain(callable)` 向主线程投递任务。

#### Scenario: 退出确认
- **WHEN** 用户关闭最后一个窗口或调用 `App::quit()`
- **AND** 已注册 `onShouldQuit` 回调返回 false
- **THEN** 应用不退出，继续运行事件循环

#### Scenario: 主线程投递
- **WHEN** 子进程回调通过 `App::queueMain($fn)` 投递任务
- **THEN** 任务在下一轮事件循环中于主线程同步执行

### Requirement: 窗口
系统 SHALL 提供 `Window` 类支持：标题、位置、尺寸、全屏、无边框、可调整大小、最大化/最小化/还原、可见性、置顶、关闭回调、尺寸变化回调、焦点变化回调。

#### Scenario: 创建并显示窗口
- **WHEN** `new Window("标题", 640, 480)` 后调用 `show()`
- **THEN** 系统创建原生顶层窗口并显示

#### Scenario: 全屏切换
- **WHEN** 调用 `setFullscreen(true)`
- **THEN** 窗口铺满屏幕、隐藏标题栏与边框；`setFullscreen(false)` 恢复

### Requirement: 控件
系统 SHALL 提供以下控件，全部继承自抽象基类 `Control`：`Button`、`Label`、`Entry`（单行文本）、`TextArea`（多行文本）、`Checkbox`、`RadioBox`、`ComboBox`（下拉选择）、`ListBox`（列表）、`Slider`（滑块）、`ProgressBar`（进度条）、`SpinBox`（数字微调）、`Area`（自定义绘图区域）。

#### Scenario: 按钮点击
- **WHEN** 用户点击 `Button` 控件
- **THEN** 触发已注册的 `onClick` 回调

#### Scenario: 文本输入
- **WHEN** 用户在 `Entry` 中输入并回车
- **THEN** 触发 `onChange` 与 `onEnter` 回调，`getText()` 返回最新内容

### Requirement: 布局容器
系统 SHALL 提供布局容器：`Box`（水平 `HBox`/垂直 `VBox`）、`Grid`（行列网格）、`Form`（标签-控件对齐）。容器可嵌套。窗口尺寸变化时 SHALL 自动触发重新布局。

#### Scenario: 垂直布局自适应
- **WHEN** 向 `VBox` 添加三个按钮并设置到窗口
- **AND** 用户纵向拉伸窗口
- **THEN** 三个按钮按比例纵向铺满客户区

#### Scenario: 嵌套容器
- **WHEN** `HBox` 内嵌套 `VBox`
- **THEN** 嵌套 `VBox` 按其父 `HBox` 分配的尺寸布局自身子控件，不覆盖兄弟控件

### Requirement: 菜单
系统 SHALL 提供菜单系统：`Menu`（下拉菜单/子菜单）、`MenuItem`（普通项/分隔符/可勾选项/禁用项）。窗口可挂载菜单栏。

#### Scenario: 禁用菜单项
- **WHEN** 调用 `MenuItem::setEnabled(false)`
- **THEN** 菜单项显示为灰色且不可点击

#### Scenario: 勾选切换
- **WHEN** 调用 `MenuItem::setChecked(true)`
- **THEN** 菜单项前显示勾选标记

### Requirement: 对话框
系统 SHALL 提供模态对话框：消息框（信息/错误/警告/询问）、打开文件、保存文件、打开文件夹、颜色选择、字体选择。模态期间 SHALL 守护重入，不阻塞窗口重绘。

#### Scenario: 打开文件
- **WHEN** 调用 `Dialogs::openFile($window, ["*.txt"])`
- **THEN** 显示系统打开文件对话框，返回选中文件绝对路径或 null

#### Scenario: 模态重入守护
- **WHEN** 模态对话框显示期间窗口过程收到非对话框消息
- **THEN** 系统转发到默认处理，窗口保持可重绘，不卡死

### Requirement: 绘图
系统 SHALL 提供 `Area` 控件用于自定义绘制，通过 `DrawContext` 暴露：画笔/画刷/字体/颜色设置、画线/矩形/椭圆/文本、富文本 `AttributedString`。鼠标事件（移动/按下/释放）与重绘回调 SHALL 通过闭包通知用户。

#### Scenario: 自定义绘制
- **WHEN** `Area` 的 `onDraw` 回调被调用
- **THEN** 用户通过 `DrawContext` 绘制图形，绘制结束系统自动释放 GDI 资源

#### Scenario: 鼠标事件
- **WHEN** 用户在 `Area` 内按下左键
- **THEN** 触发 `onMouseDown` 回调，参数包含 x/y 坐标与按键状态

### Requirement: 事件系统
系统 SHALL 通过类型化闭包属性暴露事件：`onClick`、`onChange`、`onClose`、`onResize`、`onFocus`、`onMouseDown/Up/Move`、`onKeyDown/Up`、`onDraw`。回调参数使用值对象 `MouseEvent`/`KeyEvent`/`ResizeEvent`。

#### Scenario: 键盘事件
- **WHEN** 焦点控件收到按键
- **THEN** 触发 `onKeyDown` 回调，`KeyEvent` 包含键码与修饰键状态

### Requirement: 定时器
系统 SHALL 提供周期性定时器（`App::timer(intervalMs, callable)`），返回定时器 ID；`App::clearTimer(id)` 取消。底层 SHALL 使用 PHP `hrtime(true)` 实现，不依赖 FFI `SetTimer`，主循环采用 `PeekMessageA + usleep` 非阻塞轮询。

#### Scenario: 周期触发
- **WHEN** 注册 500ms 定时器
- **THEN** 每 500ms 触发一次回调，直到清除或窗口销毁

### Requirement: 后台进程
系统 SHALL 提供 `Process` 类封装 `proc_open`，用于执行外部命令/PHP 子进程；通过 `App::queueMain` 将子进程输出回调投递回主线程，规避 PHP 无线程限制。

#### Scenario: 子进程输出回流
- **WHEN** `Process::start($cmd, fn($line) => App::queueMain(fn() => $label->setText($line)))`
- **THEN** 每读出一行子进程输出，主线程同步更新 UI

### Requirement: 系统服务
系统 SHALL 提供系统级服务：屏幕尺寸（`Screen::size()`）、剪贴板读写（`Clipboard::set/get`）、系统颜色/字体枚举。

#### Scenario: 屏幕尺寸
- **WHEN** 调用 `Screen::size()`
- **THEN** 返回 `Size` 值对象，含屏幕宽高

### Requirement: 字体与文本渲染
系统 SHALL 支持中文与 emoji（含彩色 emoji）文本渲染。Windows 后端 SHALL 使用 Unicode W 系列 API（CreateWindowExW/SetWindowTextW/DefWindowProcW 等）确保中文正确显示；Area 自定义绘图 SHALL 通过 GDI+（GdipDrawString）渲染彩色 emoji 与中文。控件 SHALL 支持设置字体（名称/大小），emoji 字体回退到 Segoe UI Emoji。

#### Scenario: 中文标题
- **WHEN** 创建窗口标题为中文
- **THEN** 标题栏正确显示中文，无乱码

#### Scenario: 彩色 emoji 绘制
- **WHEN** 在 Area 的 onDraw 回调中调用 drawText 绘制含 emoji 的文本（如 "😀你好"）
- **THEN** emoji 以彩色渲染，中文正确显示

#### Scenario: 控件中文文本
- **WHEN** 设置 Button/Label 文本为中文或含 emoji
- **THEN** 控件正确显示，中文无乱码，emoji 按系统字体渲染

### Requirement: 强类型与 OOP
所有源码 SHALL 启用 `declare(strict_types=1)`；类属性、方法参数、返回值 SHALL 显式声明类型；不可变值对象使用 `readonly` 属性；控件层级通过抽象基类与继承表达；事件回调使用 `?Closure` 类型化属性。

#### Scenario: 类型严格
- **WHEN** 调用方传入类型不匹配参数
- **THEN** PHP 抛出 `TypeError`，而非静默转换

## MODIFIED Requirements
（本项目为全新构建，无既有需求需要修改）

## REMOVED Requirements
（无移除项）
