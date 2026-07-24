# Tasks

实现按批次组织，每批次交付可独立验证的功能。Windows 后端优先，Linux/macOS 留接口与桩实现。

## 批次 1：项目骨架与平台抽象层
- [x] Task 1: 创建公共值对象与异常类
  - [ ] `src/Geometry/Point.php`（readonly int $x, $y）
  - [ ] `src/Geometry/Size.php`（readonly int $width, $height）
  - [ ] `src/Geometry/Rect.php`（readonly int $x, $y, $width, $height）
  - [ ] `src/Graphics/Color.php`（readonly int $r, $g, $b, $a=0；静态 red/blue/...）
  - [ ] `src/Exception/UiException.php`（基类）、`UnsupportedPlatformException.php`、`UiRuntimeException.php`
- [x] Task 2: 定义 `PlatformInterface` 抽象接口
  - [ ] `src/Platform/PlatformInterface.php`：声明窗口/控件/菜单/对话框/绘图/事件循环/剪贴板/定时器/queueMain 全部抽象方法签名
  - [ ] `src/Platform/AbstractPlatform.php`：提供公共状态（窗口注册表、控件注册表、定时器表、queueMain 队列、回调注册）与共享逻辑
- [x] Task 3: 创建 `App` 静态门面与 `Application` 生命周期类
  - [ ] `src/App.php`：`run()`/`quit()`/`onShouldQuit()`/`queueMain()`/`timer()`/`clearTimer()`/`platform()` 等静态方法
  - [ ] 根据 `PHP_OS_FAMILY` 选择平台实现，未支持抛 `UnsupportedPlatformException`
- [x] Task 4: 定义控件/窗口/布局抽象基类
  - [ ] `src/Control.php`：抽象基类，持有平台句柄、父容器、事件闭包属性（`?Closure`）、通用方法（show/hide/enable/setBounds/destroy）
  - [ ] `src/Window.php`：窗口类，绑定 `PlatformInterface` 窗口方法
  - [ ] `src/Layout/Container.php`（抽象）、`src/Layout/Box.php`、`src/Layout/Grid.php`、`src/Layout/Form.php`：布局容器骨架（平台无关逻辑，通过 platform 接口布局）
  - [ ] `src/Events/MouseEvent.php`、`KeyEvent.php`、`ResizeEvent.php`（readonly 值对象）

## 批次 2：Windows 后端 FFI 基础与窗口
- [x] Task 5: Windows FFI 加载层
  - [ ] `src/Platform/Windows/WindowsPlatform.php`：实现 `AbstractPlatform`，在构造器中通过 `Library::permit` + `Library::load` 加载 user32.dll/gdi32.dll/kernel32.dll/comctl32.dll/comdlg32.dll/shell32.dll
  - [ ] 定义各 FFI 块的 C 头声明（HWND/HMENU/HDC/MSG/WNDCLASSEX 等类型与函数）
  - [ ] 实现 `ptrToInt`/`hwndInt`/`intToHwnd` 辅助（使用 INT_TO_PTR 联合体，避免跨作用域 cast）
- [x] Task 6: Windows 窗口类注册与窗口创建
  - [ ] 实现 `windowCreate`：注册 `PhpUiWindow` 类（WindowProc 用 PHP 闭包，static 引用保活），`CreateWindowExA` 创建顶层窗口
  - [ ] 实现 WindowProc 闭包：分发 WM_CREATE/WM_SIZE/WM_CLOSE/WM_DESTROY/WM_PAINT/WM_COMMAND/WM_NOTIFY/鼠标/键盘消息到对应窗口/控件
  - [ ] GWLP_USERDATA 存窗口实例引用，HWND→Window/Control 映射表
- [x] Task 7: Windows 事件循环与 queueMain
  - [ ] 实现 `run()`：`PeekMessageA + usleep(1000)` 非阻塞轮询，每轮 `runTimers()`（hrtime）+ `runQueueMain()`
  - [ ] `queueMain()`：投递闭包到队列，`PostMessageA(WM_NULL)` 唤醒阻塞
  - [ ] `quit()`：询问 `shouldQuitCallback`，`PostQuitMessage`
- [x] Task 8: Window 包装类完整方法
  - [ ] `src/Window.php` 实现：setTitle/getTitle、setPosition/getPosition、setSize/getSize、setFullscreen、setBorderless、setResizeable、maximize/minimize/restore、show/hide、setTopmost、setChild（设置 toplevel 容器）、onClose/onResize/onFocus 回调
  - [ ] 验证：`examples/window_test.php` 创建窗口、显示、响应关闭

## 批次 3：Windows 基础控件

> **重要前置**：本批次需先将 WindowsPlatform 从 ANSI（A 系列）重构为 Unicode（W 系列）API 以支持中文与 emoji。改用 CreateWindowExW/RegisterClassExW/SetWindowTextW/GetWindowTextW/DefWindowProcW/PeekMessageW/DispatchMessageW/SendMessageW/PostMessageW 等；字符串 PHP UTF-8↔UTF-16LE（mb_convert_encoding）；FFI 用 wchar_t[]；默认字体 CreateFontW(\"Segoe UI\") 支持中文回退。

- [x] Task 9: 控件创建通用机制
  - [x] `WindowsPlatform::controlCreate(className, style, parent)`：CreateWindowExW 子窗口，GWLP_USERDATA 存 Control 引用
  - [x] `controlDestroy`/`controlSetText`/`controlGetText`/`controlSetBounds`/`controlShow`/`controlEnable`
- [x] Task 10: Button 与 Label
  - [x] `src/Control/Button.php`：onClick 回调（WM_COMMAND BN_CLICKED）
  - [x] `src/Control/Label.php`：文本/对齐
- [x] Task 11: Entry 与 TextArea
  - [x] `src/Control/Entry.php`：单行 EDIT，onChange（EN_CHANGE）/onEnter（WM_KEYDOWN VK_RETURN）
  - [x] `src/Control/TextArea.php`：多行 EDIT（ES_MULTILINE），getText/setText
- [x] Task 12: Checkbox 与 RadioBox
  - [x] `src/Control/Checkbox.php`：BUTTON BS_AUTOCHECKBOX，isChecked/setChecked
  - [x] `src/Control/RadioBox.php`：BUTTON BS_AUTORADIOBUTTON，分组
- [x] Task 13: ComboBox 与 ListBox
  - [x] `src/Control/ComboBox.php`：CBS_DROPDOWNLIST，addItem/removeItem/clear/select/onSelect
  - [x] `src/Control/ListBox.php`：LBS_STANDARD，addItem/removeItem/select/onSelect
- [x] Task 14: Slider、ProgressBar、SpinBox
  - [x] `src/Control/Slider.php`：TRACKBAR_CLASS，setRange/setValue/getValue/onChanged
  - [x] `src/Control/ProgressBar.php`：PROGRESS_CLASS，setValue/setRange
  - [x] `src/Control/SpinBox.php`：UDS_AUTOBUDDY UpDown 或 EDIT+UDS_SETBUDDY
- [x] Task 15: 验证控件示例
  - [x] `examples/controls_test.php`：窗口含 VBox，逐个展示所有控件并打印事件回调

## 批次 4：布局容器与菜单
- [x] Task 16: Box（HBox/VBox）布局实现
  - [ ] Windows `layoutBox`：水平/垂直分配子控件尺寸，支持 padding/stretch 选项
  - [ ] `toplevel` 标记区分顶层容器（windowSetChild 设 true）与嵌套容器（placeChild 递归）
  - [ ] WM_SIZE 触发 `layoutBoxesInWindow`（仅 toplevel=true）
- [x] Task 17: Grid 与 Form 布局
  - [ ] `src/Layout/Grid.php` + Windows `layoutGrid`：行列网格分配
  - [ ] `src/Layout/Form.php` + Windows `layoutForm`：标签-控件两列对齐
  - [ ] 验证：`examples/layout_test.php` 嵌套 HBox+VBox+Grid+Form
- [x] Task 18: 菜单系统
  - [ ] Windows：`menuCreateBar`/`menuCreatePopup`/`menuAppendItem`/`menuAppendSeparator`/`menuAppendSubmenu`/`menuSetEnabled`/`menuSetChecked`（用原始 HMENU CData 引用，hmenuToInt 转换）
  - [ ] `src/Menu/Menu.php`、`src/Menu/MenuItem.php` 包装类
  - [ ] WM_COMMAND 处理菜单项点击；WM_INITMENUPOPUP 处理状态刷新
  - [ ] 验证：`examples/menu_test.php` 含禁用/勾选/子菜单

## 批次 5：对话框与系统服务
- [x] Task 19: 消息框与文件对话框
  - [x] `Dialogs::msgBox/msgBoxError/msgBoxWarn/msgBoxAsk`：MessageBoxW（inModalDialog 守护重入，WindowProc 调 DefWindowProcW 默认处理允许重绘）
  - [x] `Dialogs::openFile/saveFile`：GetOpenFileNameW/GetSaveFileNameW（OPENFILENAMEW 结构，过滤器）
  - [x] `Dialogs::openFolder`：SHBrowseForFolderW + SHGetPathFromIDListW（CoTaskMemFree 释放 PIDL）
- [x] Task 20: 颜色与字体对话框
  - [x] `Dialogs::chooseColor`：ChooseColorW（CHOOSECOLORW 结构，自定义颜色数组）
  - [x] `Dialogs::chooseFont`：ChooseFontW（CHOOSEFONTW 结构，LOGFONTW）
- [x] Task 21: 系统服务
  - [x] `src/Screen.php`：size() 通过 GetSystemMetrics
  - [x] `src/Clipboard.php`：set/get 文本，OpenClipboard/EmptyClipboard/SetClipboardData/GetClipboardData（CF_UNICODETEXT）
  - [x] 验证：`examples/dialogs_test.php` 测试全部对话框

## 批次 6：绘图与 Area
- [ ] Task 22: DrawContext 与 GDI 封装
  - [ ] `src/Graphics/DrawContext.php`：包装 HDC + GDI 对象栈（objects/oldObjects），setPen/setBrush/setFont/setColor/drawLine/drawRect/drawEllipse/drawText/drawTextAttributed，析构恢复+DeleteObject
  - [ ] Windows FFI gdi32 块：CreatePen/CreateSolidBrush/CreateFontA/SelectObject/DeleteObject/MoveToEx/LineTo/Rectangle/Ellipse/TextOutA/SetTextColor/SetBkMode（BOOL 类型前 `typedef int BOOL;`）
- [ ] Task 23: Area 控件
  - [ ] `src/Control/Area.php`：WS_CHILD 子窗口（PhpUiWindow 类），onDraw/onMouseDown/Up/Move 闭包属性
  - [ ] Windows WM_PAINT：BeginPaint → createDrawContext → 调 onDraw → drawContextFree → EndPaint
  - [ ] WM_MOUSEMOVE/LBUTTONDOWN/LBUTTONUP/RBUTTONDOWN/RBUTTONUP/MBUTTONDOWN/MBUTTONUP 处理（lParam 符号扩展，wParam MK_ 标志）
- [x] Task 24: AttributedString 富文本
  - [ ] `src/Graphics/AttributedString.php`：整数 ID + 段落数组（text+attr），append/measure/draw
  - [ ] `attributedStringMeasure` 粗略估算，`attributedStringDraw` 按段 drawText 独立 font/color
- [x] Task 25: 验证绘图示例
  - [x] `examples/area_test.php`：Area 内画线/矩形/椭圆/文本/富文本，响应鼠标

## 批次 7：定时器、进程与窗口高级
- [x] Task 26: 定时器实现
  - [x] `App::timer(intervalMs, callable)`：hrtime(true) 周期检查，返回 timer ID
  - [x] `App::clearTimer(id)`：从表移除
  - [x] 主循环 runTimers() 检查到期回调
- [x] Task 27: Process 进程封装
  - [x] `src/Process.php`：`start($cmd, $onLine)` 用 proc_open，非阻塞读 stdout，每行通过 `App::queueMain` 投递
  - [x] `isRunning()`/`stop()`/`wait()`
- [x] Task 28: 窗口高级功能
  - [x] setFullscreen/setBorderless/setResizeable：GetWindowLongA/SetWindowLongA 改 GWL_STYLE，SetWindowPos 刷新框架，PostMessageA(WM_SIZE) 异步触发 triggerRelayout
  - [x] windowIsFocused：两侧 hwndInt() 比较 GWLP_USERDATA
  - [x] windowSetScrollable(hwnd, contentHeight)：WS_VSCROLL，layoutBox 用 contentHeight，WM_VSCROLL 更新 scrollPos 重布局
- [x] Task 29: 综合示例
  - [x] `examples/full_test.php`：覆盖窗口/控件/布局/菜单/对话框/绘图/定时器/进程全部 API

## 批次 8：Linux GTK 与 macOS Cocoa 桩实现
- [x] Task 30: Linux GtkPlatform 骨架
  - [ ] `src/Platform/Linux/GtkPlatform.php`：加载 libgtk-3.so.0/libgobject-2.0.so.0，gtk_init/gtk_main/gtk_main_quit
  - [ ] 实现 windowCreate（gtk_window_new）/controlCreate（gtk_button_new_with_label 等）/布局（gtk_box_pack_start）/信号回调（g_signal_connect_data）
  - [ ] 至少实现 Window/Button/Label/Entry/Box 可运行，其余控件抛 `UnsupportedOperationException` 指明待实现
  - [ ] 验证：在 Linux 上运行 window_test.php（本机 Windows 仅做语法检查）
- [x] Task 31: macOS CocoaPlatform 骨架
  - [ ] `src/Platform/Mac/CocoaPlatform.php`：加载 libobjc.dylib/CoreFoundation，objc_msgSend 封装
  - [ ] 实现 NSApplication/NSWindow/NSButton/NSTextField 基础流程
  - [ ] 至少实现 Window/Button/Label 可运行，其余抛 `UnsupportedOperationException`
  - [ ] 验证：在 macOS 上运行 window_test.php（本机 Windows 仅做语法检查）

# Task Dependencies
- Task 2 依赖 Task 1（值对象被接口签名引用）
- Task 3 依赖 Task 2
- Task 4 依赖 Task 2、Task 3
- 批次 2 全部依赖批次 1
- 批次 3-7 依赖批次 2（需要 WindowsPlatform 与窗口机制）
- 批次 3/4/5/6 之间可部分并行（控件、布局、对话框、绘图相互独立）
- 批次 8 依赖批次 1-2 的抽象层定义（PlatformInterface 与 App），不依赖 Windows 具体实现
