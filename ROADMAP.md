# PHP UI 库功能路线图

> 基于 libui-ng 功能对比，不模仿其设计，按自有 OOP 风格实现。
> 最后更新：2026-07-25（系统托盘 + 窗口图标支持）

## 一、已完成功能

### 窗口
- 标题/位置/尺寸/全屏/无边框/可调整大小
- 最大化/最小化/恢复/置顶/焦点
- 多窗口支持
- 垂直滚动（windowSetScrollable）
- 内边距（setMargined / getMargined，像素数）
- 窗口图标（setIconFromFile / setIconFromId / setIconFromImage，
  LoadImageW + WM_SETICON 同时设置 ICON_BIG（Alt+Tab）和 ICON_SMALL（标题栏/任务栏）；
  支持 .ico 文件、预定义系统图标 IDI_APPLICATION/HAND/QUESTION/EXCLAMATION/ASTERISK、
  以及 Image 对象（PNG/JPEG/BMP/GIF/TIFF 任意 GDI+ 格式，GdipCreateHICONFromBitmap 转换，
  支持 alpha 透明通道））
- 事件：onClose / onResize / onFocus / onPositionChanged（WM_MOVE）

### 控件
- Button / Label / Entry / TextArea
- Checkbox / RadioBox
- ComboBox / ListBox
- Slider / ProgressBar / SpinBox
- ProgressBar 不确定状态（setIndeterminate，PBS_MARQUEE 滚动动画）
- Slider onReleased 事件（拖动/操作结束，SB_ENDSCROLL）
- Area（自定义绘图画布）
- Area 滚动（setSize 启用 WS_HSCROLL/WS_VSCROLL，scrollTo 程序化滚动，
  鼠标坐标自动转换为内容坐标系）
- Table 表格（MVC 虚拟模式 LVS_OWNERDATA，TableModel 接口按需取数据，
  支持大数据集；setColumns / setModel / select / refresh / refreshRow；
  onSelectionChanged / onRowDoubleClicked 事件；
  NM_CUSTOMDRAW 行级背景色/文字色 setRowBackgroundColor / setRowTextColor；
  图像列支持 - TableModel::getCellImage 可选方法返回 Image，
  LVS_EX_SUBITEMIMAGES + LVIF_IMAGE + ImageList 渲染单元格图标；
  多类型列支持 - setColumnType 标记列类型，NM_CUSTOMDRAW CDDS_SUBITEM
  阶段自绘 checkbox/progress/color/button 单元格，NM_CLICK +
  LVM_SUBITEMHITTEST 命中测试触发 onCellCheckboxToggle/onCellButtonClick 回调）
- 控件图像支持：
  - Button 图标按钮（setImage，BS_BITMAP + BM_SETIMAGE）
  - Label 图像标签（构造器 Image 参数，SS_BITMAP + STM_SETIMAGE）
  - Tab 页签图标（setPageImage，TCM_SETIMAGELIST + TCM_SETITEMW）
  - MenuItem 菜单项图标（setImage，SetMenuItemInfoW MIIM_BITMAP）
- ImageList 通用 API（imageListCreate / imageListAddImage / imageListDestroy）
- GDI+ 图像转 HBITMAP（gdipImageToHbitmapInt，GdipCreateHBITMAPFromBitmap）
- PasswordEntry（密码框，ES_PASSWORD）
- EditableComboBox（可编辑下拉框，CBS_DROPDOWN）
- ColorButton（颜色选择按钮，封装 chooseColor 对话框）
- FontButton（字体选择按钮，封装 chooseFont 对话框）
- Separator（分隔线，水平/垂直 SS_ETCHEDHORZ/VERT）
- DateTimePicker（日期时间选择器，DATE/TIME/DATETIME 三种模式）
- 事件：onClick / onMouseDown/Up/Move / onKeyDown/Up
- 事件：onMouseEnter / onMouseLeave（Area，TrackMouseEvent + WM_MOUSELEAVE）

### 布局
- Box（HBox / VBox）
- Grid
- Form
- Group（带标题边框分组容器，BS_GROUPBOX）
- Tab（标签页容器，SysTabControl32，多页切换 + onPageChanged）
- toplevel 标记区分顶层与嵌套容器，支持递归布局

### 菜单
- 菜单栏 / 弹出菜单 / 子菜单 / 分隔符
- 菜单项启用/禁用/勾选
- 深层嵌套子菜单

### 对话框
- msgBox / msgBoxError
- openFile / saveFile / openFolder
- chooseColor / chooseFont

### 绘图
- 线 / 矩形 / 椭圆
- 文本 / 富文本（AttributedString 多段不同样式）
- pen / brush / font / color 设置
- 路径系统（DrawPath：moveTo / lineTo / arcTo / bezierTo / quadTo / closeFigure）
- fill / stroke 分离（fillRect / strokeRect / fillEllipse / strokeEllipse）
- 路径填充 / 描边（fillPath / strokePath，支持 winding / alternate 填充规则）
- 渐变画笔（GradientBrush 线性渐变，两色 + 多色停止点）
- 贝塞尔曲线（drawBezier 三次曲线 + 路径 bezierTo / quadTo）
- 圆弧（drawArc + 路径 arcTo，支持起始角和扫掠角）
- 变换矩阵（translate / scale / rotate / save / restore 状态栈）
- 裁剪（setClipPath / setClipRect / resetClip）
- 图像加载与绘制（Image::fromFile 加载 BMP/PNG/JPEG/GIF/TIFF，
  drawImage / drawImageScaled / drawImageCropped）

### 系统服务
- 剪贴板（setText / getText）
- 屏幕尺寸
- 定时器（timer / clearTimer）
- queueMain（主线程任务投递）
- onShouldQuit（退出确认）

### 系统托盘（Windows 特有）
- TrayIcon 类封装 Shell_NotifyIconW（NIM_ADD / NIM_MODIFY / NIM_DELETE）
- 图标设置（setIconFromFile / setIconFromIconId / setIconFromImage）：
  - LoadImageW 加载 .ico 文件
  - LoadIconW 加载预定义系统图标 IDI_APPLICATION/HAND/QUESTION/EXCLAMATION/ASTERISK
  - GDI+ GdipCreateHICONFromBitmap 从 Image 对象创建（PNG/JPEG/BMP/GIF/TIFF，
    支持 alpha 透明通道，适合彩色或半透明自定义图标）
- 提示文本（setTooltip，鼠标悬停显示，最多 128 字符）
- 气球通知（showBalloon，4 种类型 BALLOON_NONE/INFO/WARNING/ERROR，
  NIIF_INFO/WARNING/ERROR/NONE 标志）
- 事件回调（onClick / onDoubleClick / onRightClick，
  通过 WM_APP+0x8000 自定义消息接收托盘鼠标事件）
- 气球事件回调（onBalloonClick / onBalloonTimeout，
  处理 NIN_BALLOONUSERCLICK=0x0405 / NIN_BALLOONTIMEOUT=0x0404 / NIN_BALLOONHIDE=0x0403）
- 右键上下文菜单（setContextMenu，TrackPopupMenu 在鼠标位置弹出，
  SetForegroundWindow + PostMessage(WM_NULL) 确保 Windows 菜单首次点击响应）
- 托盘菜单命令分发（dispatchWmCommand 在窗口菜单栏找不到时遍历
  trayIcons 的 contextMenu 查找菜单项）
- 资源管理（ownsIcon 标记，析构时 NIM_DELETE + DestroyIcon）

### 进程管理（独有，libui-ng 无）
- Process 类封装 proc_open
- 非阻塞读取子进程输出
- 通过 queueMain 投递到主线程

### 平台架构
- PlatformInterface 抽象层
- Windows 后端完整实现（user32/gdi32/gdiplus 等 FFI）
- Linux GTK / macOS Cocoa 占位

## 二、待实现功能清单

（P1/P2/P3 优先级任务全部完成。后续按需新增。）

## 三、技术限制（PHP FFI）

以下功能因 PHP FFI 限制**无法实现**：

### 彩色 emoji
- **原因**：DirectWrite 通过 COM vtable 调用多参数方法，PHP FFI 调用 vtable 函数指针（2+ 参数）会访问违规崩溃（0xC0000005），即使传 null 也崩溃。cdef 直接声明的函数多参数正常，vtable 1 参数（AddRef）正常，vtable 2+ 参数必崩。
- **现状**：DirectWriteRenderer.php 保留但不实例化，emoji 回退到 GDI TextOutW 单色渲染。
- **参考**：libui-ng 也没有实现彩色 emoji，标记为 LONGTERM TODO。

### OpenType 字体特性
- 同上，依赖 DirectWrite COM 接口。

### 富文本扩展属性
- 下划线颜色、OpenType 特性等依赖 DirectWrite。

## 四、设计原则（区别于 libui-ng）

1. **强类型 OOP**：declare(strict_types=1)，类型化属性/参数/返回值
2. **不可变值对象**：Color / Point / Size / Rect 用 readonly 属性
3. **闭包事件模型**：`$btn->onClick = fn() => ...`，不用回调函数指针
4. **平台抽象层**：PlatformInterface 隔离平台差异，上层不直接调 FFI
5. **句柄用 int 传递**：避免跨 FFI 作用域 cast 崩溃
6. **PSR-4 自动加载**：不用 composer dump-autoload

## 五、文件结构

```
src/
├── Control/          控件（Button/Entry/Area/...）
├── Layout/           布局容器（Box/Grid/Form/Container）
├── Graphics/         绘图（DrawContext/Color/AttributedString/DirectWriteRenderer）
├── Platform/         平台后端（Windows/Linux/Mac + AbstractPlatform + Interface）
├── Menu/             菜单（Menu/MenuItem）
├── Events/           事件对象（KeyEvent/MouseEvent/ResizeEvent）
├── Geometry/         几何值对象（Point/Size/Rect）
├── Exception/        异常
├── App.php           应用入口
├── Window.php        窗口
├── Control.php       控件基类
├── TrayIcon.php      系统托盘
├── Clipboard.php     剪贴板
├── Dialogs.php       对话框
├── Process.php       进程管理
└── Screen.php        屏幕
```
