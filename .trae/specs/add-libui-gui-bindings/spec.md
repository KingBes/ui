# 跨平台 PHP GUI 库（基于 phpc 调用系统原生 GUI 库）Spec

## Why

PHP 生态缺少轻量、跨平台的原生 GUI 方案。`kingbes/phpc` 已经提供了安全的 FFI 包装（白名单、RAII、空指针保护、SafeCall 等），并附带 4 个 GUI 示例（`16_win32_gui.php` / `17_linux_x11_gui.php` / `18_macos_cocoa_gui.php` / `19_linux_gtk_gui.php`）演示了如何用 FFI 调用各平台原生 GUI 动态库。本库在此基础上抽象出统一的面向对象 PHP API，让用户写一份 PHP 代码就能在 Windows / Linux / macOS 上跑出原生界面的 GUI 程序。

`libui-ng` 仅作为 API 设计参考（控件命名、方法语义、回调签名风格），**不作为运行时依赖**——库在运行时不加载 libui-ng，而是直接调用各平台的原生 GUI 动态库：
- Windows：`user32.dll`（窗口类注册 / 创建 / 消息循环）+ 内置控件类 `BUTTON`/`EDIT`/`STATIC`
- Linux：`libgtk-3.so.0` + `libgobject-2.0.so.0`（GTK3 信号回调机制）
- macOS：`libobjc.dylib`（ObjC runtime）+ AppKit 框架（通过 `objc_msgSend` 调用 `NSApplication` / `NSWindow` / `NSButton` 等）

## What Changes

- 新增 `Kingbes\Ui` 命名空间下的面向对象 GUI 库（位于 `src/`），底层通过 `Kingbes\Phpc` 调用各平台原生 GUI 动态库
- 新增 `Kingbes\Ui\Platform\Platform` 抽象基类与三个具体后端：
  - `Platform\Windows\WindowsPlatform`（user32.dll + comctl32.dll）
  - `Platform\Linux\GtkPlatform`（libgtk-3.so.0 + libgobject-2.0.so.0）
  - `Platform\Macos\CocoaPlatform`（libobjc.dylib + AppKit）
- `Platform::current()` 工厂方法根据 `PHP_OS_FAMILY` 返回对应后端单例
- 新增 `Kingbes\Ui\App` 应用入口类：`run(Closure $main)` / `quit()` / `timer(int $ms, Closure $cb)` / `mainStep(bool $wait)`
- 新增 `Kingbes\Ui\Control` 抽象基类，统一 `show/hide/enable/disable/destroy` 等通用操作
- 新增核心控件类：`Window` `Button` `Label` `Box`（含 `HBox`/`VBox` 子类）`Entry` `Checkbox` `Separator`
- 新增 `Kingbes\Ui\Exception\UiException` 统一异常类型
- 所有 `On*` 类 C 回调用 PHP `Closure` 实现，闭包引用由各 Platform 后端统一持有避免 GC 释放
- 提供 `examples/hello_world.php` 与 `examples/control_gallery.php` 两个示例

## Impact

- Affected specs: 无（首次创建）
- Affected code:
  - `src/` 新增全部 PHP 类文件，子目录：`src/Exception/`、`src/Platform/`、`src/Platform/Windows/`、`src/Platform/Linux/`、`src/Platform/Macos/`
  - `composer.json` 已存在，autoload `Kingbes\Ui\` → `src/`，无需修改
  - 依赖 `vendor/kingbes/phpc` 现有 API（`Library`、`SafeCall`、`Struct`、`CData`、`Pointer`、`TypeCast`）
  - `examples/` 新增两个示例脚本
- 外部依赖：各平台需安装对应的系统 GUI 库（Windows 自带 user32；Linux 需 `libgtk-3.so.0`；macOS 自带 libobjc 与 AppKit）

## ADDED Requirements

### Requirement: 平台抽象基类

系统 SHALL 提供抽象类 `Kingbes\Ui\Platform\Platform`，定义所有后端必须实现的原始操作。`Platform::current()` 静态方法根据 `PHP_OS_FAMILY` 返回对应后端单例（`Windows` → `WindowsPlatform`，`Linux` → `GtkPlatform`，`Darwin` → `CocoaPlatform`），其他平台抛 `UiException`。

#### Scenario: 在 Windows 上获取后端
- **WHEN** 在 `Windows` 上调用 `Platform::current()`
- **THEN** 返回 `WindowsPlatform` 实例（单例）

#### Scenario: 不支持的平台
- **WHEN** 在 `BSD` 上调用 `Platform::current()`
- **THEN** 抛出 `UiException`，消息说明不支持该平台

### Requirement: Platform 后端原始操作契约

`Platform` 抽象类 SHALL 定义以下原始操作（每个后端必须实现）：

**生命周期**：`init()` `uninit()` `main()` `mainStep(bool $wait): bool` `quit()` `timer(int $ms, Closure $cb): void`

**窗口**：`windowCreate(string $title, int $w, int $h): mixed` `windowSetTitle(mixed $h, string $t): void` `windowSetSize(mixed $h, int $w, int $h): void` `windowSetPosition(mixed $h, int $x, int $y): void` `windowGetPosition(mixed $h): array` `windowSetChild(mixed $h, mixed $child): void` `windowShow(mixed $h): void` `windowHide(mixed $h): void` `windowOnClosing(mixed $h, Closure $cb): void` `windowOnResize(mixed $h, Closure $cb): void` `windowDestroy(mixed $h): void`

**通用控件**：`controlShow(mixed $h): void` `controlHide(mixed $h): void` `controlEnable(mixed $h): void` `controlDisable(mixed $h): void` `controlDestroy(mixed $h): void`

**Button**：`buttonCreate(string $text): mixed` `buttonGetText(mixed $h): string` `buttonSetText(mixed $h, string $t): void` `buttonOnClicked(mixed $h, Closure $cb): void`

**Label**：`labelCreate(string $text): mixed` `labelGetText(mixed $h): string` `labelSetText(mixed $h, string $t): void`

**Entry**：`entryCreate(): mixed` `entryGetText(mixed $h): string` `entrySetText(mixed $h, string $t): void` `entryOnChanged(mixed $h, Closure $cb): void` `entrySetReadOnly(mixed $h, bool $ro): void`

**Checkbox**：`checkboxCreate(string $text): mixed` `checkboxGetText(mixed $h): string` `checkboxSetText(mixed $h, string $t): void` `checkboxIsChecked(mixed $h): bool` `checkboxSetChecked(mixed $h, bool $c): void` `checkboxOnToggled(mixed $h, Closure $cb): void`

**Box**：`boxCreate(bool $horizontal): mixed` `boxAppend(mixed $h, mixed $child, bool $stretchy): void` `boxRemove(mixed $h, int $index): void` `boxSetPadded(mixed $h, bool $p): void`

**Separator**：`separatorCreate(bool $horizontal): mixed`

`mixed` 句柄类型在所有后端实现中实际为 `\FFI\CData`（指向原生窗口/控件句柄，如 `HWND`/`GtkWidget*`/`id`）。

#### Scenario: 后端未实现某操作
- **WHEN** 某后端未实现 `buttonCreate`
- **THEN** 在调用时 PHP 抛出 `Error`（抽象方法未实现），属于编程错误而非运行时异常

### Requirement: Windows 后端（WindowsPlatform）

`WindowsPlatform` SHALL 通过 `Library::permit('user32.dll')` 与 `Library::permit('kernel32.dll')` 加载 Windows 系统 DLL，并实现 `Platform` 全部抽象方法。

#### Scenario: 初始化
- **WHEN** 调用 `WindowsPlatform::init()`
- **THEN** 内部 `Library::permit()` + `Library::load()` 加载 user32/kernel32，注册一个共享窗口类（如 `PhpUiWindow`），WNDCLASS 的 `lpfnWndProc` 设为 PHP 闭包（参考 phpc 示例 `16_win32_gui.php`），闭包内分发 `WM_CLOSE`/`WM_SIZE`/`WM_COMMAND` 等消息到对应控件回调

#### Scenario: 创建窗口
- **WHEN** 调用 `windowCreate("Hello", 640, 480)`
- **THEN** 底层调用 `CreateWindowExA` 用共享窗口类创建窗口，返回 `HWND` CData

#### Scenario: 创建按钮
- **WHEN** 调用 `buttonCreate("OK")`
- **THEN** 底层调用 `CreateWindowExA` 用系统内置类 `"BUTTON"` 创建子窗口，返回 `HWND`

#### Scenario: 注册点击回调
- **WHEN** 调用 `buttonOnClicked($hwnd, $cb)`
- **THEN** 把 `$hwnd` → `$cb` 存入后端的 `array<int, Closure>` 表，WindowProc 收到 `WM_COMMAND` 且 `lParam` 等于该 `HWND` 时调用对应闭包

#### Scenario: 主循环
- **WHEN** 调用 `main()`
- **THEN** 进入 `GetMessageA`/`TranslateMessage`/`DispatchMessageA` 循环，直到收到 `WM_QUIT`

### Requirement: Linux GTK3 后端（GtkPlatform）

`GtkPlatform` SHALL 通过 `Library::permit('libgtk-3.so.0')` 与 `Library::permit('libgobject-2.0.so.0')` 加载 GTK3，并实现 `Platform` 全部抽象方法（参考 phpc 示例 `19_linux_gtk_gui.php`）。

#### Scenario: 初始化
- **WHEN** 调用 `GtkPlatform::init()`
- **THEN** 调用 `gtk_init(NULL, NULL)`

#### Scenario: 创建窗口
- **WHEN** 调用 `windowCreate("Hello", 640, 480)`
- **THEN** 底层调用 `gtk_window_new(GTK_WINDOW_TOPLEVEL)` + `gtk_window_set_title` + `gtk_window_set_default_size`，返回 `GtkWidget*`

#### Scenario: 创建按钮
- **WHEN** 调用 `buttonCreate("OK")`
- **THEN** 底层调用 `gtk_button_new_with_label("OK")`，返回 `GtkWidget*`

#### Scenario: 注册点击回调
- **WHEN** 调用 `buttonOnClicked($widget, $cb)`
- **THEN** 底层调用 `g_signal_connect_data($widget, "clicked", $cb, ...)`，把 `$cb` 存入后端的闭包注册表防 GC

#### Scenario: 主循环
- **WHEN** 调用 `main()`
- **THEN** 调用 `gtk_main()` 阻塞直到 `gtk_main_quit()`

### Requirement: macOS Cocoa 后端（CocoaPlatform）

`CocoaPlatform` SHALL 通过 `Library::permit('libobjc.dylib')` 加载 ObjC runtime，用 `objc_getClass` 获取 `NSApplication`/`NSWindow`/`NSButton`/`NSTextField`/`NSButton`/`NSBox`/`NSView` 等 AppKit 类，通过 `objc_msgSend` 调用方法（参考 phpc 示例 `18_macos_cocoa_gui.php`）。

#### Scenario: 初始化
- **WHEN** 调用 `CocoaPlatform::init()`
- **THEN** 获取 `NSApplication` 共享实例，调用 `NSApplication::finishLaunching` 启动事件循环准备

#### Scenario: 创建窗口
- **WHEN** 调用 `windowCreate("Hello", 640, 480)`
- **THEN** 通过 `objc_msgSend` 调用 `[NSWindow alloc]` 然后 `initWithContentRect:styleMask:backing:defer:`，返回 `NSWindow*` (id)

#### Scenario: 创建按钮
- **WHEN** 调用 `buttonCreate("OK")`
- **THEN** 通过 `[[NSButton alloc] initWithFrame:]` 创建，`setTitle:` 设置文本，返回 `NSButton*`

#### Scenario: 注册点击回调
- **WHEN** 调用 `buttonOnClicked($button, $cb)`
- **THEN** 创建一个 ObjC 子类或用 `setTarget:` + `setAction:` 注册回调。由于 ObjC runtime 直接接受 PHP 闭包作为 IMP 较复杂，CocoaPlatform 可在内部维护 `id → Closure` 表，并通过 `class_addMethod` 动态给一个 helper 类添加 `invoke:` 方法

#### Scenario: 主循环
- **WHEN** 调用 `main()`
- **THEN** 调用 `[NSApplication run]` 阻塞直到 `[NSApplication terminate:nil]`

### Requirement: App 应用入口

系统 SHALL 提供 `Kingbes\Ui\App` 类作为应用入口，封装初始化与主循环：

```php
App::run(Closure $main): void  // init() -> $main() -> main() -> uninit()
App::quit(): void              // 触发平台 quit
App::timer(int $ms, Closure $cb): void  // 注册定时器
App::mainStep(bool $wait = false): bool  // 分步循环
```

#### Scenario: 运行应用
- **WHEN** 调用 `App::run(fn() => { new Window("Hi", 300, 200)->show(); })`
- **THEN** 内部依次调用 `Platform::current()->init()`、用户闭包、`Platform::current()->main()`、`Platform::current()->uninit()`

#### Scenario: 退出应用
- **WHEN** 在窗口关闭回调中调用 `App::quit()`
- **THEN** 底层调用 `Platform::current()->quit()`（Windows 投递 `WM_QUIT`，GTK 调用 `gtk_main_quit()`，Cocoa 调用 `[NSApplication terminate:nil]`）

### Requirement: Control 抽象基类

系统 SHALL 提供抽象类 `Kingbes\Ui\Control`，所有控件继承自它。持有 `protected mixed $handle`（底层原生句柄），构造方法由子类调用 `Platform::current()->xxxCreate()` 设置。

#### Scenario: 显示/隐藏控件
- **WHEN** 调用 `$control->show()` 或 `$control->hide()`
- **THEN** 委托 `Platform::current()->controlShow($this->handle)` 或 `controlHide`

#### Scenario: 销毁控件
- **WHEN** 调用 `$control->destroy()`
- **THEN** 委托 `Platform::current()->controlDestroy($this->handle)`，并把 `$this->handle` 置 null

### Requirement: Window 控件

系统 SHALL 提供 `Kingbes\Ui\Window` 类（继承 `Control`），构造方法 `__construct(string $title, int $width, int $height)` 调用 `Platform::current()->windowCreate()`。方法：`setTitle(string)` `setSize(int,int)` `setPosition(int,int)` `getPosition(): array` `setChild(Control)` `onClosing(Closure)` `onResize(Closure)` `show()` `hide()` `close()`。

#### Scenario: 创建并显示窗口
- **WHEN** 调用 `$w = new Window("Title", 640, 480); $w->show();`
- **THEN** 底层先 `windowCreate` 返回句柄存入 `$w->handle`，再 `windowShow`

#### Scenario: 设置子控件
- **WHEN** 调用 `$window->setChild($button)`
- **THEN** 委托 `Platform::current()->windowSetChild($window->handle, $button->handle)`

#### Scenario: 注册关闭回调
- **WHEN** 调用 `$window->onClosing(fn($w) => App::quit())`
- **THEN** 委托 `windowOnClosing($window->handle, $cb)`，闭包在用户点关闭按钮时被调用，闭包返回 `true` 允许关闭，返回 `false` 阻止关闭

### Requirement: Box 容器

系统 SHALL 提供 `Kingbes\Ui\Box` 类（继承 `Control`），构造方法 protected，提供静态工厂 `Box::horizontal()` 与 `Box::vertical()`，以及子类 `HBox`、`VBox` 作为语法糖。方法：`append(Control $c, bool $stretchy = false)` `remove(int $index)` `setPadded(bool)`

#### Scenario: 创建水平 Box 并追加控件
- **WHEN** 调用 `$box = Box::horizontal(); $box->append($button, true);`
- **THEN** 底层 `boxCreate(true)` 创建水平 Box，然后 `boxAppend($box->handle, $button->handle, true)`

### Requirement: 基础控件（Button/Label/Entry/Checkbox/Separator）

系统 SHALL 提供以下控件类，每个继承 `Control`，构造方法调用对应 `Platform::current()->xxxCreate()`：

- `Button`：`__construct(string $text)` `getText(): string` `setText(string)` `onClicked(Closure)`
- `Label`：`__construct(string $text)` `getText(): string` `setText(string)`
- `Entry`：`__construct()` `getText(): string` `setText(string)` `onChanged(Closure)` `setReadOnly(bool)`
- `Checkbox`：`__construct(string $text)` `getText(): string` `setText(string)` `isChecked(): bool` `setChecked(bool)` `onToggled(Closure)`
- `Separator`：protected 构造，静态工厂 `Separator::horizontal()` / `Separator::vertical()`

#### Scenario: 按钮点击
- **WHEN** 调用 `$button->onClicked(fn($b) => null)`
- **THEN** 委托 `buttonOnClicked($button->handle, $cb)`，闭包由 Platform 后端统一持有引用

#### Scenario: 复选框切换
- **WHEN** 用户勾选复选框
- **THEN** 通过 `checkboxOnToggled` 注册的闭包被触发，闭包内可调用 `$checkbox->isChecked()` 获取当前状态

### Requirement: 闭包生命周期管理

系统 SHALL 在每个 Platform 后端中维护一个 `array<int, Closure>` 闭包注册表（或等价结构），所有 `On*`/`timer` 注册的闭包 MUST 被存入注册表，确保在 C 回调被触发前不会被 PHP GC 回收。MUST NOT 让用户负责手动管理闭包引用。

#### Scenario: 闭包在控件销毁前一直存活
- **WHEN** 用户调用 `$button->onClicked($cb)` 后不再持有 `$cb`
- **THEN** Platform 后端的闭包注册表持有 `$cb`，直到 `controlDestroy($button->handle)` 被调用时从注册表移除

### Requirement: 异常体系

系统 SHALL 提供统一的 `Kingbes\Ui\Exception\UiException`（继承 `\Exception`），所有库内异常继承自它。FFI 调用失败、空指针、初始化失败、不支持的平台等场景 MUST 抛出此异常或其子类。

#### Scenario: 库未找到
- **WHEN** `Library::load('libgtk-3.so.0', ...)` 因库未安装而失败
- **THEN** 捕获底层 `LibraryNotPermittedException` 并重新抛出为 `UiException`，消息提示用户安装 GTK3

### Requirement: 示例代码

系统 SHALL 提供 `examples/hello_world.php`（最小窗口 + 标签）与 `examples/control_gallery.php`（综合演示 Box/各输入控件/回调）。

#### Scenario: hello_world 可运行
- **WHEN** 用户执行 `php -d ffi.enable=true -f examples/hello_world.php`
- **THEN** 弹出标题为 "Hello World" 的窗口，内含 "Hello, World!" 标签，关闭窗口后进程退出

#### Scenario: control_gallery 可运行
- **WHEN** 用户执行 `php -d ffi.enable=true -f examples/control_gallery.php`
- **THEN** 弹出窗口含一个 Box，Box 内有 Button/Label/Entry/Checkbox/Separator，各控件回调可正常触发

## MODIFIED Requirements

无（首次创建）。

## REMOVED Requirements

无。
