# API 速查

## App 静态门面

```php
App::run(): void                          // 进入事件循环
App::quit(): void                          // 退出事件循环
App::onShouldQuit(?callable $cb): void     // 退出确认（返回 false 阻止）
App::timer(int $ms, callable $cb): int     // 周期定时器，返回 ID
App::clearTimer(int $id): void             // 取消定时器
App::queueMain(\Closure $fn): void         // 投递到主线程队列
App::platform(): PlatformInterface         // 平台实例
App::setPlatform(PlatformInterface $p)     // 注入自定义平台
App::resetPlatform(): void                 // 重置（测试用）
App::setTheme(string $theme): void         // 设置主题（Windows 特有，run 之前）
App::getTheme(): string                    // 读取当前主题
```

## Theme

主题预设常量类，仅 Windows 平台生效；Linux / macOS 调用相关 API 会被静默忽略。

```php
Theme::SYSTEM   // 'system'  跟随系统（默认）
Theme::CLASSIC  // 'classic' 经典外观，不加载 ComCtl32 v6 manifest
Theme::DARK     // 'dark'    强制深色（uxtheme ForceDark）
Theme::LIGHT    // 'light'   强制浅色（uxtheme ForceLight）
```

| 调用点 | 说明 |
| --- | --- |
| `App::setTheme(Theme::DARK)` | 必须在 `App::run()` 之前、任何 Window/Control 创建之前调用；非法值抛 `InvalidArgumentException`；平台已初始化后调用触发 `E_USER_WARNING` |
| `Window::setDarkMode(bool): self` | 单窗口标题栏深色覆盖（`DwmSetWindowAttribute(DWMWA_USE_IMMERSIVE_DARK_MODE)`，旧版本静默失败） |

详见 [06-advanced.md 主题与视觉样式](06-advanced.md#主题与视觉样式windows-特有)。

## Window

```php
new Window(string $title, int $w, int $h)

// 标题
setTitle(string) / getTitle(): string

// 位置尺寸
setPosition(int $x, int $y) / getPosition(): Point
setSize(int $w, int $h) / getSize(): Size
getClientSize(): Size

// 样式
setFullscreen(bool) / setBorderless(bool) / setResizeable(bool)
setTopmost(bool) / setMargined(int $pixels) / getMargined(): int
setDarkMode(bool $dark): self            // 标题栏深色覆盖（Windows 特有）

// 状态
maximize() / minimize() / restore() / show() / hide() / close()
isFocused(): bool

// 子容器
setChild(Control $c): self
setScrollable(int $contentHeight): self
getChildContainer(): ?Control

// 菜单
setMenu(Menu $m): self / getMenu(): ?Menu

// 图标
setIconFromFile(string $file): self
setIconFromId(int $iconId): self
setIconFromImage(Image $img): self

// 事件闭包
onClose / onResize / onFocus / onPositionChanged

getHwnd(): int
```

## Control 基类

```php
new Control(Control|Window $parent)  // 实际由子类构造

setText(string) / getText(): string
setBounds(int $x, int $y, int $w, int $h)
show() / hide() / setEnabled(bool)
destroy() / getHwnd(): int
getParent(): Control|Window|null
getWindow(): ?Window

// 事件闭包
onClick / onMouseDown / onMouseUp / onMouseMove
onKeyDown / onKeyUp
```

## 基础控件

### Button
```php
new Button($parent, string $text = '')
setImage(?Image) / onClick
```

### Label
```php
new Label($parent, string $text = '', int $align = ALIGN_LEFT)
// ALIGN_LEFT / ALIGN_CENTER / ALIGN_RIGHT
setImage(Image)
```

### Entry / PasswordEntry
```php
new Entry($parent, string $placeholder = '')
new PasswordEntry($parent)
setText(string) / getText(): string
onChange / onEnter
```

### TextArea
```php
new TextArea($parent)
setText(string) / getText(): string
onChange
```

### Checkbox / RadioBox
```php
new Checkbox($parent, string $text)
new RadioBox($parent, string $text)
setChecked(bool) / isChecked(): bool
```

### ComboBox / EditableComboBox
```php
new ComboBox($parent)
new EditableComboBox($parent)
addItem(string) / removeItem(int) / clear()
select(int) / getSelectedIndex(): int
// EditableComboBox 额外:
setText(string) / getText(): string
onSelect / (EditableComboBox: onChange)
```

### ListBox
```php
new ListBox($parent)
addItem(string) / removeItem(int) / clear()
select(int) / getSelectedIndex(): int
onSelect
```

## 数值控件

### Slider
```php
new Slider($parent)
setRange(int $min, int $max) / setValue(int) / getValue(): int
onChanged / onReleased
```

### SpinBox
```php
new SpinBox($parent)
setRange(int $min, int $max) / setValue(int) / getValue(): int
onChanged
```

### ProgressBar
```php
new ProgressBar($parent)
setRange(int $min, int $max) / setValue(int)
setIndeterminate(bool)
```

## 高级控件

### DateTimePicker
```php
new DateTimePicker($parent, int $mode = DATE)
// DATE / TIME / DATETIME
getTime(): ?array  // [year,month,day,hour,minute,second] 或 null
setTime(int $y, int $mo, int $d, int $h, int $mi, int $s)
setFormat(string)  // 如 'yyyy-MM-dd HH:mm'
onChanged
```

### ColorButton
```php
new ColorButton($parent)
setColor(Color) / getColor(): Color
onColorChanged
```

### FontButton
```php
new FontButton($parent)
setFont(array) / getFont(): array
// 字体数组: [name, size, bold, italic, underline, strikeout]
onFontChanged
```

### Separator
```php
new Separator($parent, int $orient = HORIZONTAL)
// HORIZONTAL / VERTICAL
```

### Table
```php
new Table($parent)
setColumns(array $texts, int $width = 100)
setColumnType(int $col, string $type)
// TYPE_TEXT / TYPE_IMAGE / TYPE_CHECKBOX
// TYPE_PROGRESS / TYPE_COLOR / TYPE_BUTTON
setModel(TableModel $m)
setRowCount(int) / refresh() / refreshRow(int)
select(int $row) / getSelectedRow(): int
setRowBackgroundColor(int $row, ?int $argb)
setRowTextColor(int $row, ?int $argb)
setImageSize(int) / addImage(Image): int
// 事件:
onSelectionChanged / onRowDoubleClicked
onCellCheckboxToggle / onCellButtonClick
```

### TableModel（接口）
```php
interface TableModel {
    getRowCount(): int;
    getColumnCount(): int;
    getCellValue(int $row, int $col): string;

    // 可选:
    // getCellImage(int $row, int $col): ?Image;
    // getCellCheckbox(int $row, int $col): ?bool;
    // getCellProgress(int $row, int $col): ?int;
    // getCellColor(int $row, int $col): ?Color;
    // getCellButton(int $row, int $col): string;
}
```

### Area
```php
new Area($parent)
setSize(int $w, int $h)          // 启用滚动条
scrollTo(int $x, int $y)
getScrollPos(): array            // ['x' => int, 'y' => int]
invalidate()                     // 触发重绘
// 事件:
onDraw($ctx) / onMouseDown / onMouseUp / onMouseMove
onMouseEnter / onMouseLeave
```

## 布局容器

### Container 基类
```php
add(Control $c): static
remove(Control $c): static
getChildren(): array
count(): int
setToplevel(bool) / isToplevel(): bool
destroy()
abstract layout(int $x, int $y, int $w, int $h)
```

### HBox / VBox
```php
new HBox($parent)
new VBox($parent)
setPadding(int) / getPadding(): int
```

### Grid
```php
new Grid($parent, int $rows, int $cols)
getRows(): int / getCols(): int
```

### Form
```php
new Form($parent)
addRow(Control $label, Control $field): static
```

### Group
```php
new Group($parent, string $title = '')
setPadding(int): static
```

### Tab
```php
new Tab($parent)
addPage(string $name, Container $page): int
getPage(int $index): ?Container
getPageCount(): int
addImage(Image): int
onPageChanged
```

## Menu

### Menu
```php
new Menu(bool $isBar = false)
addItem(string $text): MenuItem
addSeparator(): MenuItem
addSubmenu(string $text, Menu $submenu): MenuItem
getHwnd(): int / isBar(): bool
getItems(): array
findItemById(int $id): ?MenuItem
destroy()
```

### MenuItem
```php
setEnabled(bool) / setChecked(bool) / setImage(Image)
setSubmenu(Menu)
getId(): int / getText(): string
isEnabled(): bool / isChecked(): bool
isSeparator(): bool / getSubmenu(): ?Menu
onClick
```

## Dialogs（静态）

```php
Dialogs::msgBox(?Window $parent, string $text, string $caption = "提示")
Dialogs::msgBoxError(?Window $parent, string $text, string $caption = "错误")
Dialogs::msgBoxWarn(?Window $parent, string $text, string $caption = "警告")
Dialogs::msgBoxAsk(?Window $parent, string $text, string $caption = "询问"): bool

Dialogs::openFile(?Window $parent, array $filters = ["所有文件|*.*"]): ?string
Dialogs::saveFile(?Window $parent, array $filters = ["所有文件|*.*"]): ?string
Dialogs::openFolder(?Window $parent, string $title = "选择文件夹"): ?string
Dialogs::chooseColor(?Window $parent): ?Color
Dialogs::chooseFont(?Window $parent): ?array
```

## TrayIcon

```php
new TrayIcon(Window $win, string $tooltip = '')

// 图标
setIconFromFile(string $file)
setIconFromIconId(int $iconId)
setIconFromImage(Image $img)

// 文本
setTooltip(string)

// 气球
showBalloon(string $title, string $msg, int $type, int $timeoutMs)
// BALLOON_NONE / BALLOON_INFO / BALLOON_WARNING / BALLOON_ERROR

// 菜单
setContextMenu(Menu $m)

// 生命周期
addOrUpdate() / remove()

// 事件
onClick / onDoubleClick / onRightClick
onBalloonClick / onBalloonTimeout

// 系统图标常量
IDI_APPLICATION / IDI_HAND / IDI_QUESTION / IDI_EXCLAMATION / IDI_ASTERISK
```

## 绘图

### DrawContext
```php
// 画笔/画刷
setPen(Color, int $width = 1)
setBrush(Color)
setGradientBrush(?GradientBrush)

// 基本图形
drawLine(int $x1, int $y1, int $x2, int $y2)
drawRect(int $x, int $y, int $w, int $h)
fillRect(int $x, int $y, int $w, int $h)
strokeRect(int $x, int $y, int $w, int $h)
drawEllipse(int $x, int $y, int $w, int $h)
fillEllipse(int $x, int $y, int $w, int $h)
strokeEllipse(int $x, int $y, int $w, int $h)
drawArc(int $x, int $y, int $w, int $h, float $start, float $sweep)
drawBezier(int $x1, int $y1, int $x2, int $y2, int $x3, int $y3, int $x4, int $y4)

// 文本
setFont(string $name, int $size)
setColor(Color)
drawText(int $x, int $y, string $text)
drawTextAttributed(int $x, int $y, int $asId)

// 路径
createPath(int $fillMode = FILL_ALTERNATE): DrawPath
fillPath(DrawPath) / strokePath(DrawPath)

// 变换
translate(float $dx, float $dy)
scale(float $sx, float $sy)
rotate(float $angle)
save(): int / restore(int $state)

// 裁剪
setClipRect(int $x, int $y, int $w, int $h)
setClipPath(DrawPath)
resetClip()

// 图像
drawImage(Image, int $x, int $y)
drawImageScaled(Image, int $x, int $y, int $w, int $h)
drawImageCropped(Image, int $dx, int $dy, int $dw, int $dh, int $sx, int $sy, int $sw, int $sh)
```

### DrawPath
```php
new DrawPath($platform, int $fillMode = FILL_ALTERNATE)
// FILL_ALTERNATE / FILL_WINDING
moveTo(float $x, float $y)
lineTo(float $x, float $y)
arcTo(float $x, float $y, float $w, float $h, float $start, float $sweep)
bezierTo(float $x1, float $y1, float $x2, float $y2, float $x3, float $y3)
quadTo(float $x1, float $y1, float $x2, float $y2)
closeFigure()
free()
```

### Color
```php
Color::rgb(int $r, int $g, int $b): static
Color::rgba(int $r, int $g, int $b, int $a): static
Color::fromColorRef(int $colorRef): static
toColorRef(): int
toArray(): array

// 预定义
black() white() red() green() lime() blue() navy()
yellow() cyan() magenta() gray() grey() silver()
maroon() purple() olive() teal() orange() transparent()
```

### Image
```php
Image::fromFile(string $file): static
getWidth(): int / getHeight(): int
free()
```

### GradientBrush
```php
new GradientBrush(
    float $x1, float $y1, float $x2, float $y2,
    Color $startColor, Color $endColor
)
setStops(array $stops)  // [[float, Color], ...]
free()
```

### AttributedString
```php
new AttributedString()
append(string $text, string $font = 'Segoe UI', int $size = 14, ?Color $color = null): self
getId(): int
measure(DrawContext $ctx): array  // [width, height]
draw(DrawContext $ctx, int $x, int $y)
```

## 系统服务

### Clipboard
```php
Clipboard::setText(string)
Clipboard::getText(): string
```

### Screen
```php
Screen::size(): Size
Screen::width(): int
Screen::height(): int
```

### Process
```php
Process::start(string $cmd, ?\Closure $onLine = null, ?\Closure $onExit = null): self
$proc->isRunning(): bool
$proc->getExitCode(): int
$proc->stop(): void
$proc->wait(): int
```

## 几何对象

### Point
```php
$pt->x / $pt->y  // int
```

### Size
```php
$size->width / $size->height  // int
```

## 平台契约（PlatformInterface）

主题相关方法在 `PlatformInterface` 中定义，Windows 平台完整实现，Linux / macOS 为空实现：

```php
interface PlatformInterface {
    // ...

    // 启用 ComCtl32 v6 视觉样式（CreateActCtxW + ActivateActCtx，幂等）
    public function enableVisualStyles(): void;

    // 设置应用主题（SYSTEM/CLASSIC/DARK/LIGHT），内部决定是否调用 enableVisualStyles + uxtheme
    public function setAppTheme(string $theme): void;

    // 单窗口标题栏深色模式（DwmSetWindowAttribute，旧版本静默失败）
    public function setWindowDarkMode(int $hwnd, bool $dark): void;
}
```

| 方法 | Windows 实现 | Linux / macOS |
| --- | --- | --- |
| `enableVisualStyles()` | 加载 ComCtl32 v6 manifest，启用 PerMonitorV2 DPI 感知，幂等 | 空实现 |
| `setAppTheme(string)` | DARK=ForceDark / LIGHT=ForceLight / SYSTEM=Default / CLASSIC 不调用 uxtheme | 空实现，静默忽略 |
| `setWindowDarkMode(int, bool)` | `DwmSetWindowAttribute(DWMWA_USE_IMMERSIVE_DARK_MODE)`，旧版本（< 10 1809）静默失败 | 空实现 |

> 非 CLASSIC 主题下控件默认字体为 "Segoe UI" 9pt。

## 命名空间速查

| 命名空间 | 主要类 |
| --- | --- |
| `Kingbes\Ui` | App, Window, Control, Theme, TrayIcon, Clipboard, Dialogs, Process, Screen |
| `Kingbes\Ui\Control` | Button, Label, Entry, TextArea, Checkbox, RadioBox, ComboBox, EditableComboBox, ListBox, Slider, SpinBox, ProgressBar, DateTimePicker, ColorButton, FontButton, Separator, Table, TableModel, Area, PasswordEntry |
| `Kingbes\Ui\Layout` | Container, Box, HBox, VBox, Grid, Form, Group, Tab |
| `Kingbes\Ui\Graphics` | Color, Image, DrawContext, DrawPath, GradientBrush, AttributedString |
| `Kingbes\Ui\Menu` | Menu, MenuItem |
| `Kingbes\Ui\Geometry` | Point, Size |
| `Kingbes\Ui\Events` | ResizeEvent, MouseEvent, KeyEvent |
| `Kingbes\Ui\Platform` | PlatformInterface, AbstractPlatform |
| `Kingbes\Ui\Platform\Windows` | WindowsPlatform |
| `Kingbes\Ui\Exception` | UnsupportedPlatformException |
