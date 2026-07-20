# Tasks

按依赖顺序拆分。阶段 0 是后续所有任务的基础；阶段 1（Platform 抽象与三个后端）是核心难点；阶段 2+ 的控件类可在 Platform 抽象确定后并行。

## 阶段 0：基础设施

- [x] Task 1: 创建异常类与 App 入口骨架
  - [x] SubTask 1.1: 创建 `src/Exception/UiException.php`（继承 `\Exception`，构造方法兼容 `$message` / `$code` / `$previous`）
  - [x] SubTask 1.2: 创建 `src/Platform/Platform.php` 抽象基类：定义全部原始操作方法签名（见 spec「Platform 后端原始操作契约」），提供 `protected static ?Platform $current` 与 `public static function current(): static` 工厂方法，工厂内根据 `PHP_OS_FAMILY` 实例化对应后端，未知平台抛 `UiException`。后端单例缓存
  - [x] SubTask 1.3: 创建 `src/App.php`：`run(Closure $main): void`（依次 `Platform::current()->init()` → `$main()` → `Platform::current()->main()` → `Platform::current()->uninit()`，异常时仍调用 `uninit`）、`quit(): void`、`timer(int $ms, Closure $cb): void`、`mainStep(bool $wait = false): bool`

## 阶段 1：Platform 后端实现（三个并行，但每个内部步骤串行）

- [x] Task 2: 实现 Windows 后端（WindowsPlatform）
  - [x] SubTask 2.1: 创建 `src/Platform/Windows/WindowsPlatform.php`：构造时 `Library::permit('user32.dll')` + `Library::permit('kernel32.dll')`，`load()` 加载 user32 与 kernel32 的 FFI 头声明（参考 phpc `16_win32_gui.php`：`WNDCLASSA`/`MSG`/`RegisterClassA`/`CreateWindowExA`/`ShowWindow`/`GetMessageA`/`PeekMessageA`/`TranslateMessage`/`DispatchMessageA`/`DefWindowProcA`/`PostQuitMessage`/`SendMessageA`/`SetWindowLongPtrA`/`GetWindowLongPtrA`/`MoveWindow`/`GetWindowRect`/`SetWindowTextA`/`GetWindowTextA`/`DestroyWindow`/`InvalidateRect`）
  - [x] SubTask 2.2: 实现 `init()`：用 `Struct::make($user32, 'WNDCLASSA')` 构造共享窗口类 `PhpUiWindow`，`lpfnWndProc` 设为实例持有的 PHP 闭包（参考示例 16 的 `$wndProc` 写法），闭包内维护 `HWND → [onClosing, onResize]` 与子控件 `HWND → [onClicked, onToggled, onChanged]` 回调表，按 `WM_CLOSE`/`WM_SIZE`/`WM_COMMAND`/`WM_NOTIFY` 分发。`RegisterClassA` 注册
  - [x] SubTask 2.3: 实现 `main()`：阻塞 `GetMessageA`/`TranslateMessage`/`DispatchMessageA` 循环；`mainStep(bool $wait)`：用 `PeekMessageA` 非阻塞轮询；`quit()`：`PostQuitMessage(0)`
  - [x] SubTask 2.4: 实现 `timer(int $ms, Closure $cb)`：用 `SetTimer`（user32）注册定时器，回调在 WindowProc 中处理 `WM_TIMER` 时调用
  - [x] SubTask 2.5: 实现 Window 系列方法：`windowCreate` 调 `CreateWindowExA(0, "PhpUiWindow", $title, WS_OVERLAPPEDWINDOW, ...)` 返回 HWND；`windowSetTitle` 调 `SetWindowTextA`；`windowSetSize`/`windowSetPosition` 调 `MoveWindow`；`windowGetPosition` 调 `GetWindowRect`；`windowSetChild` 调 `SetParent`；`windowShow`/`windowHide` 调 `ShowWindow(SW_SHOW/SW_HIDE)`；`windowOnClosing`/`windowOnResize` 存入回调表；`windowDestroy` 调 `DestroyWindow`
  - [x] SubTask 2.6: 实现通用 `controlShow`/`controlHide`/`controlEnable`/`controlDisable`/`controlDestroy`：调用 `ShowWindow`/`EnableWindow`/`DestroyWindow`
  - [x] SubTask 2.7: 实现 Button：`buttonCreate` 用 `CreateWindowExA(0, "BUTTON", $text, WS_CHILD | WS_VISIBLE, ...)`；`buttonGetText`/`buttonSetText` 调 `GetWindowTextA`/`SetWindowTextA`；`buttonOnClicked` 把 `HWND → Closure` 存入 `WM_COMMAND` 回调表
  - [x] SubTask 2.8: 实现 Label：`labelCreate` 用 `"STATIC"` 类；`labelGetText`/`labelSetText`
  - [x] SubTask 2.9: 实现 Entry：`entryCreate` 用 `"EDIT"` 类；`entryGetText`/`entrySetText`/`entrySetReadOnly`（`SendMessageA(EM_SETREADONLY)`）；`entryOnChanged` 处理 `EN_CHANGE` 通知
  - [x] SubTask 2.10: 实现 Checkbox：`checkboxCreate` 用 `"BUTTON"` 类加 `BS_AUTOCHECKBOX` 样式；`checkboxIsChecked` 调 `SendMessageA(BM_GETCHECK)`；`checkboxSetChecked` 调 `SendMessageA(BM_SETCHECK)`；`checkboxOnToggled` 处理 `BN_CLICKED` 后查状态
  - [x] SubTask 2.11: 实现 Box：Windows 没有原生 Box，需自实现一个简单布局容器。内部维护子控件列表，在 WindowProc 收到 `WM_SIZE` 时按 horizontal/vertical 方向重新计算子控件位置并调 `MoveWindow`。`boxCreate(bool $horizontal)` 返回一个内部句柄（可以是父窗口句柄或自分配的结构体指针包装为 CData）；`boxAppend` 加入子控件列表；`boxSetPadded` 设置间距参数；`boxRemove` 按索引移除
  - [x] SubTask 2.12: 实现 Separator：`separatorCreate(true)` 用 `"STATIC"` 类加 `SS_ETCHEDHORZ` 样式，`separatorCreate(false)` 加 `SS_ETCHEDVERT`
- [x] Task 3: 实现 Linux GTK3 后端（GtkPlatform）
  - [x] SubTask 3.1: 创建 `src/Platform/Linux/GtkPlatform.php`：`Library::permit('libgtk-3.so.0')` + `Library::permit('libgobject-2.0.so.0')`，加载 GTK3 与 GObject FFI 头声明（参考 phpc `19_linux_gtk_gui.php`：`gtk_init`/`gtk_main`/`gtk_main_quit`/`gtk_window_new`/`gtk_window_set_title`/`gtk_window_set_default_size`/`gtk_widget_show_all`/`gtk_widget_show`/`gtk_widget_hide`/`gtk_widget_destroy`/`gtk_widget_set_sensitive`/`gtk_button_new_with_label`/`gtk_button_get_label`/`gtk_button_set_label`/`gtk_label_new`/`gtk_label_set_text`/`gtk_label_get_text`/`gtk_entry_new`/`gtk_entry_get_text`/`gtk_entry_set_text`/`gtk_editable_set_editable`/`gtk_check_button_new_with_label`/`gtk_check_button_get_active`/`gtk_check_button_set_active`/`gtk_box_new`/`gtk_box_pack_start`/`gtk_box_set_spacing`/`gtk_container_add`/`gtk_container_remove`/`g_signal_connect_data`/`g_timeout_add`）
  - [x] SubTask 3.2: 实现 `init()`：调 `gtk_init(NULL, NULL)`；`main()` 调 `gtk_main()`；`mainStep` 用 `g_main_context_iteration`；`quit()` 调 `gtk_main_quit()`；`uninit()` 无操作
  - [x] SubTask 3.3: 实现 `timer(int $ms, Closure $cb)`：调 `g_timeout_add($ms, $cb, null)`，闭包签名 `fn($data) => bool`（返回 true 继续，false 停止）；闭包存入注册表
  - [x] SubTask 3.4: 实现 Window 系列：`windowCreate` 调 `gtk_window_new(GTK_WINDOW_TOPLEVEL)` + `gtk_window_set_title` + `gtk_window_set_default_size`；`windowSetTitle`/`windowSetSize`/`windowSetPosition`/`windowGetPosition` 调对应 gtk_window_* 函数；`windowSetChild` 调 `gtk_container_add`；`windowShow`/`windowHide` 调 `gtk_widget_show`/`gtk_widget_hide`；`windowOnClosing` 用 `g_signal_connect_data($w, "delete-event", $cb, ...)`（闭包返回 true 阻止关闭）；`windowOnResize` 用 `"configure-event"`；`windowDestroy` 调 `gtk_widget_destroy`
  - [x] SubTask 3.5: 实现通用 `controlShow`/`controlHide`/`controlEnable`/`controlDisable`/`controlDestroy`：调 `gtk_widget_show`/`gtk_widget_hide`/`gtk_widget_set_sensitive($w, TRUE/FALSE)`/`gtk_widget_destroy`
  - [x] SubTask 3.6: 实现 Button：`buttonCreate` 调 `gtk_button_new_with_label`；`buttonGetText`/`buttonSetText` 调 `gtk_button_get_label`/`gtk_button_set_label`；`buttonOnClicked` 用 `g_signal_connect_data($w, "clicked", $cb, ...)`
  - [x] SubTask 3.7: 实现 Label：`labelCreate` 调 `gtk_label_new`；`labelGetText`/`labelSetText` 调 `gtk_label_get_text`/`gtk_label_set_text`
  - [x] SubTask 3.8: 实现 Entry：`entryCreate` 调 `gtk_entry_new`；`entryGetText`/`entrySetText` 调 `gtk_entry_get_text`/`gtk_entry_set_text`；`entrySetReadOnly` 调 `gtk_editable_set_editable($w, !$ro)`；`entryOnChanged` 用 `"changed"` 信号
  - [x] SubTask 3.9: 实现 Checkbox：`checkboxCreate` 调 `gtk_check_button_new_with_label`；`checkboxIsChecked` 调 `gtk_check_button_get_active`；`checkboxSetChecked` 调 `gtk_check_button_set_active`；`checkboxOnToggled` 用 `"toggled"` 信号
  - [x] SubTask 3.10: 实现 Box：`boxCreate(true)` 调 `gtk_box_new(GTK_ORIENTATION_HORIZONTAL, 0)`，`boxCreate(false)` 调 `gtk_box_new(GTK_ORIENTATION_VERTICAL, 0)`；`boxAppend` 调 `gtk_box_pack_start($box, $child, $expand, $fill, $padding)`（`$stretchy` 映射到 `expand=TRUE, fill=TRUE`）；`boxRemove` 调 `gtk_container_remove`；`boxSetPadded` 调 `gtk_box_set_spacing`
  - [x] SubTask 3.11: 实现 Separator：`separatorCreate(true)` 调 `gtk_separator_new(GTK_ORIENTATION_HORIZONTAL)`，`separatorCreate(false)` 调 `gtk_separator_new(GTK_ORIENTATION_VERTICAL)`
- [x] Task 4: 实现 macOS Cocoa 后端（CocoaPlatform）
  - [x] SubTask 4.1: 创建 `src/Platform/Macos/CocoaPlatform.php`：`Library::permit('libobjc.dylib')`，加载 ObjC runtime FFI 头声明（参考 phpc `18_macos_cocoa_gui.php`：`objc_getClass`/`sel_registerName`/`objc_msgSend`/`objc_msgSend_stret`/`class_createInstance`/`class_addMethod`/`object_setClass`/`sel_registerName`）。AppKit 类通过 `objc_getClass("NSApplication")` 等动态获取
  - [x] SubTask 4.2: 实现 `init()`：调 `[NSApplication sharedApplication]` 然后 `[NSApp finishLaunching]`；`main()` 调 `[NSApp run]`；`mainStep` 用 `nextEventMatchingMask:untilDate:inMode:dequeue:` 手动处理事件；`quit()` 调 `[NSApp terminate:nil]`；`uninit()` 无操作
  - [x] SubTask 4.3: 实现 `timer(int $ms, Closure $cb)`：用 `NSTimer scheduledTimerWithTimeInterval:repeats:block:` 或 `performSelector:withObject:afterDelay:`，闭包存入注册表
  - [x] SubTask 4.4: 实现 Window 系列：`windowCreate` 调 `[NSWindow alloc]` 然后 `initWithContentRect:styleMask:backing:defer:`（styleMask 用 `NSTitledWindowMask | NSClosableWindowMask | NSMiniaturizableWindowMask | NSResizableWindowMask`）；`windowSetTitle` 调 `[NSWindow setTitle:]`；`windowSetSize` 调 `[NSWindow setContentSize:]` 或 `setFrame:display:`；`windowSetPosition` 调 `setFrameOrigin:`；`windowSetChild` 调 `[NSWindow setContentView:]`；`windowShow` 调 `[NSWindow makeKeyAndOrderFront:]`；`windowHide` 调 `orderOut:`；`windowOnClosing` 用 `setDelegate:` + 实现 `windowWillClose:`；`windowOnResize` 实现 `windowDidResize:`；`windowDestroy` 调 `[NSWindow close]` + `release`
  - [x] SubTask 4.5: 实现通用 `controlShow`/`controlHide`：调 `setHidden:YES/NO`；`controlEnable`/`controlDisable` 调 `setEnabled:`；`controlDestroy` 调 `release`
  - [x] SubTask 4.6: 实现 Button：`buttonCreate` 调 `[NSButton alloc] initWithFrame:defaultRect]` 然后 `setTitle:`；`buttonGetText`/`buttonSetText` 调 `title`/`setTitle:`；`buttonOnClicked` 调 `setTarget:` + `setAction:`，target 用一个内部 helper 对象（通过 `class_addMethod` 动态注册 `invoke:` 方法指向 PHP 闭包）
  - [x] SubTask 4.7: 实现 Label：`labelCreate` 调 `[NSTextField alloc] initWithFrame:]` 然后 `setEditable:NO` + `setBezeled:NO` + `setDrawsBackground:NO`；`labelGetText`/`labelSetText` 调 `stringValue`/`setStringValue:`
  - [x] SubTask 4.8: 实现 Entry：`entryCreate` 调 `[NSTextField alloc] initWithFrame:]`；`entryGetText`/`entrySetText` 调 `stringValue`/`setStringValue:`；`entrySetReadOnly` 调 `setEditable:`；`entryOnChanged` 用 `setDelegate:` + 实现 `controlTextDidChange:`
  - [x] SubTask 4.9: 实现 Checkbox：`checkboxCreate` 调 `[NSButton alloc] initWithFrame:]` 然后 `setButtonType:NSSwitchButton` + `setTitle:`；`checkboxIsChecked` 调 `state`（`NSOnState=1`）；`checkboxSetChecked` 调 `setState:`；`checkboxOnToggled` 同 Button 的 `setTarget:`/`setAction:`
  - [x] SubTask 4.10: 实现 Box：`boxCreate(true/false)` 调 `[NSView alloc] initWithFrame:]`，维护子控件列表，在 `resizeSubviewsWithOldSize:` 或手动 layout 时按方向 `setFrame:` 排列子控件；`boxAppend` 调 `[box addSubview:$child]` 并加入列表；`boxRemove` 调 `[child removeFromSuperview]`；`boxSetPadded` 设置间距参数
  - [x] SubTask 4.11: 实现 Separator：`separatorCreate(true)` 调 `[NSBox alloc] initWithFrame:]` + `setBoxType:NSBoxSeparator`；`separatorCreate(false)` 同样用 NSBox 但水平方向

## 阶段 2：Control 基类与控件类（依赖阶段 0 与 1）

- [x] Task 5: 创建 Control 抽象基类与核心控件类
  - [x] SubTask 5.1: 创建 `src/Control.php`：抽象类，`protected mixed $handle = null`，方法 `show()`/`hide()`/`enable()`/`disable()`/`destroy()`/`isEnabled(): bool`/`isVisible(): bool` 全部委托 `Platform::current()->controlXxx($this->handle)`，`destroy()` 后置 `$this->handle = null`；`getHandle(): mixed`（protected，供容器类使用）
  - [x] SubTask 5.2: 创建 `src/Window.php`：继承 `Control`，`__construct(string $title, int $w, int $h)` 调 `Platform::current()->windowCreate()`；方法 `setTitle`/`setSize`/`setPosition`/`getPosition`/`setChild(Control)`/`onClosing(Closure)`/`onResize(Closure)`/`show`/`hide`/`close`（close 调 `windowDestroy`）
  - [x] SubTask 5.3: 创建 `src/Button.php`：继承 `Control`，`__construct(string $text)` 调 `buttonCreate`；方法 `getText`/`setText`/`onClicked`
  - [x] SubTask 5.4: 创建 `src/Label.php`：继承 `Control`，`__construct(string $text)` 调 `labelCreate`；方法 `getText`/`setText`
  - [x] SubTask 5.5: 创建 `src/Entry.php`：继承 `Control`，`__construct()` 调 `entryCreate`；方法 `getText`/`setText`/`onChanged`/`setReadOnly`
  - [x] SubTask 5.6: 创建 `src/Checkbox.php`：继承 `Control`，`__construct(string $text)` 调 `checkboxCreate`；方法 `getText`/`setText`/`isChecked`/`setChecked`/`onToggled`
  - [x] SubTask 5.7: 创建 `src/Box.php`：继承 `Control`，protected 构造，静态工厂 `horizontal()`/`vertical()` 调 `boxCreate(true/false)`；方法 `append(Control, bool $stretchy=false)`/`remove(int)`/`setPadded(bool)`；子类 `HBox`（构造调 `Box::horizontal()`）、`VBox`（构造调 `Box::vertical()`）作为语法糖
  - [x] SubTask 5.8: 创建 `src/Separator.php`：继承 `Control`，protected 构造，静态工厂 `horizontal()`/`vertical()`

## 阶段 3：示例与验证

- [x] Task 6: 创建示例脚本
  - [x] SubTask 6.1: `examples/hello_world.php`：`App::run(function() { $w = new Window("Hello World", 300, 200); $w->setChild(new Label("Hello, World!")); $w->onClosing(fn() => App::quit()); $w->show(); })`
  - [x] SubTask 6.2: `examples/control_gallery.php`：综合演示——`App::run` 内创建 Window，setChild 一个 VBox，VBox 内放 Button（onClicked 弹新 Window 或改 Label 文本）/ Label / Entry（onChanged 同步 Label 文本）/ Checkbox（onToggled 改 Label 文本）/ Separator / HBox（内含两个 Button）；windowOnClosing 调 `App::quit()`

# Task Dependencies

- Task 1（异常 + App + Platform 抽象）是所有其他任务的前置
- Task 2/3/4 三个 Platform 后端实现相互独立，可并行
- Task 5（控件类）依赖 Task 1 与 Task 2/3/4 全部完成（控件类需调用 Platform 后端方法）
- Task 6（示例）依赖 Task 5 完成
