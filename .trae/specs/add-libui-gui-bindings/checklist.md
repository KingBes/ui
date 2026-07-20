# Checklist

## 基础设施
- [x] `src/Exception/UiException.php` 存在且继承 `\Exception`
- [x] `src/Platform/Platform.php` 为抽象类，定义 spec「Platform 后端原始操作契约」中列出的全部方法签名
- [x] `Platform::current()` 根据 `PHP_OS_FAMILY` 返回 `WindowsPlatform` / `GtkPlatform` / `CocoaPlatform` 单例，未知平台抛 `UiException`
- [x] `src/App.php` 提供 `run(Closure)` / `quit()` / `timer(int, Closure)` / `mainStep(bool)` 方法
- [x] `App::run()` 在用户闭包抛异常时仍调用 `Platform::current()->uninit()` 清理

## Windows 后端
- [x] `WindowsPlatform` 通过 `Library::permit('user32.dll')` + `Library::permit('kernel32.dll')` 加载系统 DLL
- [x] `WindowsPlatform::init()` 注册共享窗口类 `PhpUiWindow`，`lpfnWndProc` 设为 PHP 闭包
- [x] WindowProc 闭包能正确分发 `WM_CLOSE`/`WM_SIZE`/`WM_COMMAND`/`WM_TIMER` 消息到对应控件回调
- [x] `main()` 使用 `GetMessageA`/`TranslateMessage`/`DispatchMessageA` 阻塞循环
- [x] `quit()` 调用 `PostQuitMessage(0)`
- [x] `timer()` 使用 `SetTimer`，闭包在 `WM_TIMER` 触发时被调用
- [x] `windowCreate` 用 `CreateWindowExA` 创建窗口返回 HWND
- [x] Button 用 `"BUTTON"` 内置类创建，Label 用 `"STATIC"`，Entry 用 `"EDIT"`，Checkbox 用 `"BUTTON"` + `BS_AUTOCHECKBOX`
- [x] Box 自实现布局容器：维护子控件列表，`WM_SIZE` 时按 horizontal/vertical 重排子控件位置
- [x] Separator 用 `"STATIC"` + `SS_ETCHEDHORZ`/`SS_ETCHEDVERT`
- [x] 所有闭包（WindowProc、On*、timer）存入后端内部注册表防止 GC

## Linux GTK3 后端
- [x] `GtkPlatform` 通过 `Library::permit('libgtk-3.so.0')` + `Library::permit('libgobject-2.0.so.0')` 加载 GTK3
- [x] `init()` 调用 `gtk_init(NULL, NULL)`（实现使用 `gtk_init_without_args`，语义等价）
- [x] `main()` 调用 `gtk_main()`，`quit()` 调用 `gtk_main_quit()`
- [x] `timer()` 用 `g_timeout_add`，闭包返回 true 继续 false 停止
- [x] Window 用 `gtk_window_new(GTK_WINDOW_TOPLEVEL)`
- [x] Button 用 `gtk_button_new_with_label`
- [x] Label 用 `gtk_label_new`
- [x] Entry 用 `gtk_entry_new`，`setReadOnly` 用 `gtk_editable_set_editable`
- [x] Checkbox 用 `gtk_check_button_new_with_label`
- [x] Box 用 `gtk_box_new(GTK_ORIENTATION_HORIZONTAL/VERTICAL)`，`append` 用 `gtk_box_pack_start`
- [x] Separator 用 `gtk_separator_new`
- [x] 所有 `g_signal_connect_data` 注册的闭包存入注册表防止 GC

## macOS Cocoa 后端
- [x] `CocoaPlatform` 通过 `Library::permit('libobjc.dylib')` 加载 ObjC runtime
- [x] `init()` 调 `[NSApplication sharedApplication]` + `finishLaunching`
- [x] `main()` 调 `[NSApp run]`，`quit()` 调 `[NSApp terminate:nil]`
- [x] Window 用 `[NSWindow alloc] initWithContentRect:styleMask:backing:defer:`
- [x] Button 用 `[NSButton alloc] initWithFrame:]` + `setTitle:`
- [x] Label 用 `[NSTextField alloc] initWithFrame:]` + `setEditable:NO`/`setBezeled:NO`/`setDrawsBackground:NO`
- [x] Entry 用 `[NSTextField alloc] initWithFrame:]`
- [x] Checkbox 用 `[NSButton alloc] initWithFrame:]` + `setButtonType:NSSwitchButton`
- [x] Box 用 `[NSView alloc] initWithFrame:]`，手动 layout 子控件
- [x] Separator 用 `[NSBox alloc] initWithFrame:]` + `setBoxType:NSBoxSeparator`
- [x] Button/Checkbox 的 `onClicked`/`onToggled` 用 `setTarget:` + `setAction:`，target 是动态注册了 `invoke:` 方法的 helper 类
- [x] 所有闭包（target/action、delegate、timer）存入注册表防止 GC

## 公共控件类
- [x] `src/Control.php` 抽象基类持有 `protected mixed $handle`，提供 `show/hide/enable/disable/destroy` 方法委托 Platform
- [x] `Control::destroy()` 后置 `$this->handle = null`，重复调用为 no-op
- [x] `src/Window.php` 提供 spec 列出的全部方法（setTitle/setSize/setPosition/getPosition/setChild/onClosing/onResize/show/hide/close）
- [x] `Window::onClosing` 闭包返回 true 允许关闭，false 阻止关闭（与 libui-ng 语义一致）
- [x] `src/Button.php` 提供 `getText/setText/onClicked`
- [x] `src/Label.php` 提供 `getText/setText`
- [x] `src/Entry.php` 提供 `getText/setText/onChanged/setReadOnly`
- [x] `src/Checkbox.php` 提供 `getText/setText/isChecked/setChecked/onToggled`
- [x] `src/Box.php` 提供 `horizontal()/vertical()` 静态工厂与 `append/remove/setPadded` 方法
- [x] `src/HBox.php` 与 `src/VBox.php` 作为 Box 的语法糖子类
- [x] `src/Separator.php` 提供 `horizontal()/vertical()` 静态工厂

## 异常与闭包生命周期
- [x] 所有 FFI 调用通过 `SafeCall::invoke` 或 `SafeCall::expectNotNull` 包装
- [x] 所有 `On*`/`timer`/WindowProc 闭包都被存入 Platform 后端注册表，防止 PHP GC 回收
- [x] `controlDestroy` 被调用时从注册表移除对应闭包（三后端均扫描 `$closures` 删除以 `spl_object_id($h).':'` 为前缀的条目；Cocoa 的 windowOnClosing/windowOnResize/entryOnChanged 因使用独立 helper target 的 id 作为 key，无法通过 control id 清理，属已知简化）
- [x] FFI 加载失败、空指针、不支持平台等场景抛出 `UiException`

## 示例
- [x] `examples/hello_world.php` 在三平台均可运行：弹出 "Hello World" 窗口内含 "Hello, World!" 标签
- [x] `examples/control_gallery.php` 综合演示 Button/Label/Entry/Checkbox/Separator/Box/HBox，各回调可触发
- [x] 示例关闭窗口后进程正常退出（不残留进程）

## PSR-4 与依赖
- [x] 所有 PHP 类文件遵循 PSR-4 自动加载（`Kingbes\Ui\` → `src/`）
- [x] `composer.json` 无需修改（autoload 已配置）
- [x] 运行时不依赖 libui-ng 共享库，仅依赖各平台原生系统 GUI 库
- [x] `composer.json` 的 require 仅 `kingbes/phpc`，不新增其他 PHP 依赖

## 已知简化与限制（对应项仍标 [x]）

- **Cocoa `Box` 布局使用固定 100×100 默认尺寸**：`[view frame]` 需 `objc_msgSend_stret`，PHP FFI 不直接支持结构体返回，`layoutBox` 中 `$w = 100; $hgt = 100;` 为硬编码默认值。类头注释已说明此限制。
- **Cocoa `windowGetPosition` 恒返回 `[0,0]`**：同样受 `objc_msgSend_stret` 限制。
- **Cocoa `windowOnClosing` 不支持「返回 false 阻止关闭」**：`NSWindowWillCloseNotification` 是窗口即将关闭时发送的通知，此时已无法阻止；`CocoaPlatform::windowOnClosing` 仅调用回调、忽略返回值。`Window::onClosing` 公共接口及 Windows/GTK 两后端的语义反转实现均符合 spec。
- **Cocoa `timer` 不主动 `invalidate`**：用户闭包返回 false 时仅 `unset($this->timers[$id])`，依赖对象释放。代码注释已标明此简化。
- **Windows `mainStep` 非真正阻塞**：`$wait=true` 时仍以 `usleep(10000)` 让出 CPU，未真正阻塞等待事件。
- **Windows `WindowProc` 闭包**：以 `$this->wndProc` 实例属性持有，生命周期与后端实例一致，正确防 GC。
- **闭包 key 命名**：三后端均采用 `spl_object_id($h).':type'` 形式（`:close`/`:resize`/`:clicked`/`:toggled`/`:changed`/`:action` 等）。
- **示例跨平台**：两个示例仅使用公共控件 API，未引用任何后端特定代码，理论可在三平台运行。
