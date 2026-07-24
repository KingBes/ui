# PHP UI 库功能路线图

> 基于 libui-ng 功能对比，不模仿其设计，按自有 OOP 风格实现。
> 最后更新：2026-07-24

## 一、已完成功能

### 窗口
- 标题/位置/尺寸/全屏/无边框/可调整大小
- 最大化/最小化/恢复/置顶/焦点
- 多窗口支持
- 垂直滚动（windowSetScrollable）
- 事件：onClose / onResize / onFocus

### 控件
- Button / Label / Entry / TextArea
- Checkbox / RadioBox
- ComboBox / ListBox
- Slider / ProgressBar / SpinBox
- Area（自定义绘图画布）
- 事件：onClick / onMouseDown/Up/Move / onKeyDown/Up

### 布局
- Box（HBox / VBox）
- Grid
- Form
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

### 系统服务
- 剪贴板（setText / getText）
- 屏幕尺寸
- 定时器（timer / clearTimer）
- queueMain（主线程任务投递）
- onShouldQuit（退出确认）

### 进程管理（独有，libui-ng 无）
- Process 类封装 proc_open
- 非阻塞读取子进程输出
- 通过 queueMain 投递到主线程

### 平台架构
- PlatformInterface 抽象层
- Windows 后端完整实现（user32/gdi32/gdiplus 等 FFI）
- Linux GTK / macOS Cocoa 占位

## 二、待实现功能清单

### 优先级 P0（低难度高价值）

#### 1. Tab 标签页容器
- Win32 用 SysTabControl（ICC_TAB_CLASSES）
- 方法：addPage(name, child) / removePage(index) / selectPage(index) / getSelectedPage()
- 事件：onPageChanged

#### 2. Group 分组容器
- Win32 用 BUTTON + BS_GROUPBOX 或 WC_STATIC
- 带标题的边框容器，内嵌单个子控件
- 方法：setTitle(string) / setChild(Control)

#### 3. Separator 分隔线
- Win32 用 WC_STATIC + SS_ETCHEDHORZ / SS_ETCHEDVERT
- 方法：new Separator(Orientation::HORIZONTAL | VERTICAL)

#### 4. PasswordEntry 密码框
- Entry 子类，加 ES_PASSWORD 样式
- 方法：setMaskChar(char) / unmask()

#### 5. EditableComboBox 可编辑下拉框
- ComboBox 改用 CBS_DROPDOWN（非 CBS_DROPDOWNLIST）
- 用户可输入自定义值

#### 6. ColorButton 颜色选择按钮
- 已有 chooseColor 对话框，封装成控件
- 显示当前颜色，点击打开 chooseColor
- 事件：onColorChanged

#### 7. FontButton 字体选择按钮
- 已有 chooseFont 对话框，封装成控件
- 显示当前字体名，点击打开 chooseFont
- 事件：onFontChanged

### 优先级 P1（中难度高价值）

#### 8. 绘图增强 - 路径系统
- GDI BeginPath / LineTo / Arc / PolyBezier / EndPath
- GDI+ 对应 API
- 类：DrawPath（moveTo / lineTo / arcTo / bezierTo / closeFigure）
- 方法：ctx->fillPath(path) / ctx->strokePath(path)
- 填充规则：winding / alternate

#### 9. 绘图增强 - fill/stroke 分离
- 当前 drawRect 同时填充+边框，应分离
- 方法：fillRect / strokeRect / fillEllipse / strokeEllipse
- 或统一用 path + fillPath/strokePath

#### 10. 绘图增强 - 渐变画笔
- GDI+ LinearGradientBrush / RadialGradientBrush
- 类：GradientBrush（linear / radial，多色停止点）
- 方法：setGradientBrush(GradientBrush)

#### 11. 绘图增强 - 贝塞尔曲线
- GDI PolyBezier / GDI+ DrawBezier / DrawBeziers
- 二次/三次贝塞尔

#### 12. 绘图增强 - 圆弧
- GDI Arc / GDI+ DrawArc / AddArc
- 支持起始角和扫掠角

#### 13. 绘图增强 - 变换矩阵
- GDI+ SetTransform / MultiplyTransform / ResetTransform
- 方法：translate(x,y) / scale(sx,sy) / rotate(angle) / skew
- save() / restore() 状态栈

#### 14. 绘图增强 - 裁剪
- GDI+ SetClip / ResetClip / IntersectClip
- 方法：setClipPath(path) / setClipRect(rect) / resetClip()

#### 15. Area 键盘事件接入
- WM_KEYDOWN/UP 已有，Area 需接入
- Area 添加 onKeyDown / onKeyUp 闭包属性

#### 16. Area 鼠标进入/离开
- TrackMouseEvent + WM_MOUSELEAVE
- Area 添加 onMouseEnter / onMouseLeave 闭包属性

#### 17. DateTimePicker 日期时间选择器
- Win32 用 DATETIMEPICK_CLASS（ICC_DATE_CLASSES）
- 三种变体：日期+时间 / 仅日期 / 仅时间
- 方法：getTime() / setTime() / 事件 onChanged

### 优先级 P2（低难度中价值）

#### 18. Slider onReleased 事件
- WM_HSCROLL 的 SB_ENDSCROLL 通知码

#### 19. ProgressBar 不确定状态
- PBS_MARQUEE 样式 + PBM_SETMARQUEE 消息
- 方法：setIndeterminate(bool)

#### 20. 窗口 onPositionChanged 事件
- WM_MOVE 消息
- 闭包属性：onPositionChanged

#### 21. 窗口边距 Margined
- 客户区内偏移，布局时考虑边距
- 方法：setMargined(bool) / getMargined()

### 优先级 P3（高难度，需评估）

#### 22. Table 表格（MVC）
- Win32 ListView + NM_CUSTOMDRAW 自绘
- 工程量巨大：LVITEM 操作、列类型、编辑、选择、排序
- 评估是否值得投入

#### 23. 图像加载与绘制
- GDI+ Image / Bitmap 加载（BMP/PNG/JPEG/GIF）
- 方法：drawImage(image, x, y) / drawImageScaled(...)
- 支持高 DPI 多尺寸

#### 24. Area 滚动
- 滚动条 + 虚拟内容尺寸
- 方法：setSize(w,h) / scrollTo(x,y,w,h)

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
├── Clipboard.php     剪贴板
├── Dialogs.php       对话框
├── Process.php       进程管理
└── Screen.php        屏幕
```
