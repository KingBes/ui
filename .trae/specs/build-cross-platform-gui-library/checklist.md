# Checklist

## 批次 1：项目骨架与平台抽象层
- [x] `src/Geometry/Point.php`、`Size.php`、`Rect.php` 启用 strict_types，readonly 属性，含构造与静态工厂
- [x] `src/Graphics/Color.php` 提供 RGB 构造与常用颜色静态方法，含 alpha 通道
- [x] `src/Exception/` 下 UiException/UnsupportedPlatformException/UiRuntimeException 继承关系正确
- [x] `PlatformInterface` 声明窗口/控件/菜单/对话框/绘图/事件循环/剪贴板/定时器/queueMain 全部抽象方法
- [x] `AbstractPlatform` 提供共享状态（窗口表/控件表/定时器表/queueMain 队列）与公共逻辑
- [x] `App` 静态门面：run/quit/onShouldQuit/queueMain/timer/clearTimer/platform 方法签名完整
- [x] `App::run()` 在 Windows 上选择 WindowsPlatform，在未支持平台抛 UnsupportedPlatformException
- [x] `Control` 抽象基类含事件闭包属性（?Closure 类型化）与通用方法签名
- [x] `Window` 类绑定 PlatformInterface 窗口方法
- [x] MouseEvent/KeyEvent/ResizeEvent 为 readonly 值对象，含必要字段

## 批次 2：Windows 后端 FFI 基础与窗口
- [x] WindowsPlatform 构造器通过 Library::permit + Library::load 加载 6 个系统 DLL，无白名单错误
- [x] FFI C 头声明完整（HWND/HMENU/HDC/MSG/WNDCLASSEX/RECT/POINT 等类型与 API 函数）
- [x] ptrToInt/hwndInt/intToHwnd 使用 INT_TO_PTR 联合体，无跨作用域 cast 崩溃
- [x] WindowProc 闭包用 static 引用保活，能分发 WM_CREATE/WM_SIZE/WM_CLOSE/WM_DESTROY/WM_PAINT/WM_COMMAND/WM_NOTIFY/鼠标/键盘
- [x] GWLP_USERDATA 存窗口实例，HWND→Window/Control 映射表正确
- [x] run() 用 PeekMessageA + usleep(1000) 非阻塞轮询，runTimers 与 runQueueMain 每轮执行
- [x] queueMain 投递闭包后 PostMessageA(WM_NULL) 唤醒，任务在主线程同步执行
- [x] quit() 询问 shouldQuitCallback，返回 false 不退出
- [x] Window 包装方法完整：title/position/size/fullscreen/borderless/resizeable/maximize/minimize/restore/show/hide/topmost/setChild/onClose/onResize/onFocus
- [x] examples/window_test.php 能创建窗口、显示、响应关闭，无崩溃与 PHP 警告

## 批次 3：Windows 基础控件
- [x] controlCreate 用 CreateWindowExW 子窗口（Unicode W 系列），GWLP_USERDATA 存 Control 引用
- [x] controlDestroy/controlSetText/controlGetText/controlSetBounds/controlShow/controlEnable 实现正确
- [x] Button 点击触发 onClick 回调
- [x] Label 支持文本与对齐
- [x] Entry 触发 onChange（EN_CHANGE）与 onEnter（VK_RETURN）
- [x] TextArea 多行文本，getText/setText 正常
- [x] Checkbox isChecked/setChecked 正确，BS_AUTOCHECKBOX
- [x] RadioBox 分组正确，BS_AUTORADIOBUTTON
- [x] ComboBox addItem/removeItem/clear/select/onSelect 正常
- [x] ListBox addItem/removeItem/select/onSelect 正常
- [x] Slider setRange/setValue/getValue/onChanged 正常
- [x] ProgressBar setValue/setRange 正常
- [x] SpinBox 数值微调正常
- [x] examples/controls_test.php 展示所有控件，事件回调打印正确

## 批次 4：布局容器与菜单
- [x] HBox/VBox 水平/垂直分配子控件，支持 padding/stretch
- [x] toplevel 标记正确区分顶层与嵌套容器
- [x] WM_SIZE 触发 layoutBoxesInWindow（仅 toplevel=true）
- [x] 嵌套 Box 不覆盖兄弟控件，按父容器分配尺寸布局
- [x] Grid 行列网格分配正确
- [x] Form 标签-控件两列对齐
- [x] examples/layout_test.php 嵌套 HBox+VBox+Grid+Form 布局正确
- [x] menuCreateBar/menuCreatePopup/menuAppendItem/menuAppendSeparator/menuAppendSubmenu/menuSetEnabled/menuSetChecked 实现正确
- [x] 菜单项用原始 HMENU CData 引用，hmenuToInt 转换，无地址失效
- [x] WM_COMMAND 处理菜单点击，WM_INITMENUPOPUP 刷新状态
- [x] 禁用项灰色不可点击，勾选项前显示勾选标记
- [x] examples/menu_test.php 含禁用/勾选/子菜单，行为正确

## 批次 5：对话框与系统服务
- [x] msgBox/msgBoxError/msgBoxWarn/msgBoxAsk 调用 MessageBoxW（W 系列），返回按钮结果
- [x] 模态期间 inModalDialog 守护重入，WindowProc 调 DefWindowProcW 允许重绘，不卡死
- [x] openFile/saveFile 调用 GetOpenFileNameW/GetSaveFileNameW，返回路径或 null
- [x] openFolder 调用 SHBrowseForFolderW + SHGetPathFromIDListW（CoTaskMemFree 释放 PIDL）
- [x] chooseColor 调用 ChooseColorW，返回 Color 或 null
- [x] chooseFont 调用 ChooseFontW，返回字体信息或 null
- [x] Screen::size() 通过 GetSystemMetrics 返回 Size
- [x] Clipboard::set/get 文本正确（OpenClipboard/EmptyClipboard/SetClipboardData/GetClipboardData，CF_UNICODETEXT）
- [x] examples/dialogs_test.php 已创建，php -l 通过，覆盖全部对话框与系统服务

## 批次 6：绘图与 Area
- [x] DrawContext 包装 HDC + GDI 对象栈，析构恢复 SelectObject 并 DeleteObject
- [x] gdi32 FFI 块 BOOL 类型前有 `typedef int BOOL;`，无 ParserException
- [x] setPen/setBrush/setFont/setColor/drawLine/drawRect/drawEllipse/drawText 实现正确
- [x] Area 用 WS_CHILD 子窗口（PhpUiWindow 类）
- [x] WM_PAINT: BeginPaint → createDrawContext → onDraw → drawContextFree → EndPaint
- [x] WM_MOUSEMOVE/LBUTTONDOWN/LBUTTONUP/RBUTTONDOWN/RBUTTONUP/MBUTTONDOWN/MBUTTONUP 处理，lParam 符号扩展，wParam MK_ 标志
- [x] AttributedString 用整数 ID + 段落数组，append/measure/draw 正确
- [x] attributedStringDraw 按段 drawText 独立 font/color
- [x] examples/area_test.php 画线/矩形/椭圆/文本/富文本，响应鼠标事件

## 批次 7：定时器、进程与窗口高级
- [x] App::timer 用 hrtime(true) 周期检查，返回 timer ID
- [x] App::clearTimer 从表移除，回调不再触发
- [x] runTimers 每轮检查到期回调
- [x] Process::start 用 proc_open，非阻塞读 stdout，每行 queueMain 投递
- [x] Process isRunning/stop/wait 正确
- [x] setFullscreen/setBorderless/setResizeable 改 GWL_STYLE，SetWindowPos 刷新框架，triggerRelayout 异步重布局
- [x] windowIsFocused 两侧用 hwndInt() 比较 GWLP_USERDATA
- [x] windowSetScrollable 加 WS_VSCROLL，layoutBox 用 contentHeight，WM_VSCROLL 更新 scrollPos 重布局
- [x] examples/full_test.php 覆盖全部 API，无崩溃与警告

## 批次 8：Linux GTK 与 macOS Cocoa 桩实现
- [x] GtkPlatform 加载 libgtk-3.so.0/libgobject-2.0.so.0，gtk_init/gtk_main/gtk_main_quit
- [x] GTK 实现至少 Window/Button/Label/Entry/Box 可运行
- [x] GTK 未实现控件抛 UnsupportedOperationException 明确指明
- [x] CocoaPlatform 加载 libobjc.dylib/CoreFoundation，objc_msgSend 封装
- [x] Cocoa 实现至少 Window/Button/Label 可运行
- [x] Cocoa 未实现控件抛 UnsupportedOperationException 明确指明
- [x] 本机 Windows 上对 GTK/Cocoa 平台文件做 `php -l` 语法检查通过

## 通用质量
- [x] 所有 src/ 下 PHP 文件 `php -l` 语法检查通过
- [x] 所有文件启用 `declare(strict_types=1)`
- [x] 类属性、方法参数、返回值显式声明类型
- [x] 不可变值对象使用 readonly 属性
- [x] 运行示例需 `php -d ffi.enable=true`，无 FFI 加载失败
- [x] WindowProc 闭包、菜单 HMENU、GDI 对象等 C 资源正确保活与释放，无 GC 导致崩溃
- [x] Windows 后端使用 Unicode W 系列 API（CreateWindowExW/SetWindowTextW/DefWindowProcW 等）
- [x] 窗口标题、控件文本支持中文，无乱码
- [x] Area 绘图通过 GDI+ 渲染彩色 emoji 与中文
- [x] 字符串编码正确转换（PHP UTF-8 ↔ UTF-16LE）
