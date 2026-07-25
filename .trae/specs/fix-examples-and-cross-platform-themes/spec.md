# 示例 Bug 修复与跨平台主题统一 Spec

## Why
用户在测试示例文件时发现 11 个问题：闪屏、控件不可交互、表格无内容、子进程卡死、布局挤压等。这些问题集中在三个层面：(1) Container 窗口消息路由不完整导致 Slider/SpinBox/Table 在嵌套场景下事件失效；(2) Area 双缓冲缺失导致重绘闪屏；(3) Process 阻塞 I/O 导致主进程冻结。同时 Linux GTK 和 macOS Cocoa 平台未实现主题相关方法，与 Windows 不统一。

## What Changes
- **Container 窗口消息路由补全**：在 `dispatchWindowProc` 的 Container 分支补 `WM_HSCROLL/WM_VSCROLL` 路由（修复 Slider 拖拽、SpinBox 经父窗口的路径）；在 Container 的 `WM_NOTIFY` 处理前先调 `handleTableNotify`（修复嵌套 Table 的所有通知：内容填充、选中、双击、checkbox、按钮、自绘）
- **SpinBox 经 `WM_NOTIFY/UDN_DELTAPOS` 路径修复**：在 `dispatchWmNotify` 增加 `UDN_DELTAPOS`(-722) 分支，反查 UpDown 注册的 SpinBox 实例并触发 `onChanged`
- **Area 双缓冲**：在 `dispatchAreaMessage` 的 switch 增加 `case WM_ERASEBKGND: return 1;` 抑制背景擦除（`onDraw` 回调会完整绘制背景）；`areaInvalidate` 的 `InvalidateRect` 第三参数改为 0（不要求擦除背景）
- **Label 垂直居中**：`Label::create()` 文本模式合并 `SS_CENTERIMAGE = 0x00000200` 样式，使单行文本在控件内垂直居中
- **Process 非阻塞 I/O**：`Process::drainStdout` 改用 `stream_select` 先探测管道可读性，再 `fread` 块读取并自行拼接行边界，避免 `fgets` 在 Windows 管道上无完整行时阻塞
- **布局系统引入 Preferred Size + 居中**：`Control` 基类新增 `getPreferredWidth(): int` / `getPreferredHeight(): int`（默认返回 0 表示无偏好）；`Button`/`Label` 重写返回自然尺寸；`Box::layout` 在空间充足时按 Preferred Size 分配剩余空间按 stretch 策略，空间不足时仍按均分避免空隙；`Grid::add` / `Form::addRow` 增加对齐参数（`ALIGN_FILL`/`ALIGN_CENTER`/`ALIGN_LEFT`/`ALIGN_RIGHT`/`ALIGN_TOP`/`ALIGN_BOTTOM`），默认 `ALIGN_CENTER` 让按钮等控件在格内居中而非拉伸
- **跨平台主题统一**：`GtkPlatform` 重写 `setAppTheme` 通过 `GtkSettings::gtk_application_prefer_dark_theme` 切换深色；`CocoaPlatform` 重写 `setAppTheme` 通过 `NSAppearance` 切换深浅色；两平台 `enableVisualStyles` 保持空实现（无需 manifest）；`Window::setDarkMode` 在 GTK/Cocoa 上调用对应平台 API
- **示例文件修复**：针对被报告的示例（image_test.php / table_test.php / p2_test.php / controls_test.php / controls_advanced_test.php / layout_advanced_test.php / full_test.php / process_advanced_test.php / area_test.php / graphics_advanced_test.php）确认上述修复后无需修改示例代码即可正常工作；如示例本身存在 API 误用（如 `add()->setEnabled(false)` 误禁用 Container）则修正示例

## Impact
- 受影响的 spec：`add-visual-styles-and-theme-selection`（主题功能从 Windows 特有扩展为跨平台，Theme 常量语义在 GTK/Cocoa 上获得实际效果）
- 受影响的代码：
  - `src/Platform/Windows/WindowsPlatform.php`：Container 分支补消息路由、Area WM_ERASEBKGND、dispatchWmNotify 增加 UDN_DELTAPOS
  - `src/Control/Label.php`：合并 SS_CENTERIMAGE 样式
  - `src/Control/SpinBox.php`：可能需要补 `getPreferredHeight`（布局用）
  - `src/Control/Button.php`：重写 `getPreferredWidth/Height`
  - `src/Control.php`：新增 `getPreferredWidth/Height` 默认实现
  - `src/Layout/Box.php` / `Grid.php` / `Form.php`：引入 Preferred Size 分配 + 对齐参数
  - `src/Process.php`：`drainStdout` 改用 `stream_select` + `fread`
  - `src/Platform/Linux/GtkPlatform.php`：重写 `setAppTheme` / `setWindowDarkMode`
  - `src/Platform/Mac/CocoaPlatform.php`：重写 `setAppTheme` / `setWindowDarkMode`
  - `src/Window.php`：`setDarkMode` 文档更新为跨平台
- **非破坏性变更**：所有新增 API 与现有 API 兼容；Preferred Size 默认返回 0 时布局行为与原来一致；Container 消息路由补全不影响原有主窗口路径

## ADDED Requirements

### Requirement: Container 窗口消息路由补全
`WindowsPlatform::dispatchWindowProc` 的 Container 分支 SHALL 处理以下消息：
- `WM_HSCROLL` / `WM_VSCROLL`：调用 `dispatchScroll($hwndInt, $msg, $wParam, $lParam)`，与主窗口分支对齐
- `WM_NOTIFY`：先调 `handleTableNotify($lParam)`，返回非 null 则直接返回；否则继续走 `dispatchWmNotify`

#### Scenario: Slider 嵌在 HBox 中拖拽
- **WHEN** Slider 嵌在 HBox（Container）中，用户拖拽滑块
- **THEN** HBox 收到 `WM_HSCROLL`，`dispatchScroll` 通过 lParam 反查到 Slider 实例，触发 `onChanged` 回调

#### Scenario: SpinBox 嵌在 HBox 中点击加减
- **WHEN** SpinBox 嵌在 HBox（Container）中，用户点击 UpDown 加减按钮
- **THEN** HBox 收到 `WM_NOTIFY(UDN_DELTAPOS)`，`dispatchWmNotify` 反查到 SpinBox 实例，触发 `onChanged` 回调

#### Scenario: Table 嵌在 HBox 中显示数据
- **WHEN** Table 嵌在 HBox（Container）中，ListView 发送 `LVN_GETDISPINFO`
- **THEN** HBox 收到 `WM_NOTIFY`，`handleTableNotify` 填充行数据，表格正常显示内容

#### Scenario: Table 选中行触发回调
- **WHEN** 用户点击 Table 行，ListView 发送 `LVN_ITEMCHANGED`
- **THEN** HBox 收到 `WM_NOTIFY`，`handleTableNotify` 触发 `onSelectionChanged` 回调

### Requirement: SpinBox UDN_DELTAPOS 通知处理
`dispatchWmNotify` SHALL 增加 `UDN_DELTAPOS`(-722) 通知码分支：从 `NMUPDOWN` 结构的 `hdr.hwndFrom` 反查 `controls[$hwndFrom]`，若为 SpinBox 实例则触发 `onChanged` 回调。

#### Scenario: 点击 UpDown 加号
- **WHEN** 用户点击 SpinBox 的 UpDown 加号按钮
- **THEN** `UDN_DELTAPOS` 的 `iDelta > 0`，SpinBox 当前值 +1，触发 `onChanged`

### Requirement: Area 双缓冲防闪屏
`dispatchAreaMessage` SHALL 在 switch 中增加 `case WM_ERASEBKGND: return 1;`，返回非 0 抑制 `DefWindowProcW` 的背景擦除。`areaInvalidate` 调用 `InvalidateRect($hwnd, null, 0)` 时第三参数 SHALL 为 0（不要求擦除背景）。

#### Scenario: Area 重绘
- **WHEN** Area 触发重绘（`areaInvalidate` 或尺寸变化）
- **THEN** Windows 发送 `WM_ERASEBKGND` 被拦截返回 1，不再用类背景刷擦除；`WM_PAINT` 由 `onDraw` 回调完整绘制，无闪屏

### Requirement: Label 垂直居中
`Label::create()` 在文本模式（非 imageMode）下 SHALL 在控件样式中合并 `SS_CENTERIMAGE = 0x00000200`，使单行文本在控件矩形内垂直居中。图像模式下不添加该样式（SS_BITMAP 已自管位置）。

#### Scenario: Label 在 VBox 中高度大于文本
- **WHEN** Label 在 VBox 中被分配到 40px 高度，文本为单行
- **THEN** 文本垂直居中显示在 40px 矩形中央，而非贴顶

### Requirement: Process 非阻塞 I/O
`Process::drainStdout` SHALL 改用 `stream_select($read, $write, $except, 0)` 先探测管道可读性（超时 0 表示非阻塞），仅当管道有数据可读时才调用 `fread` 读取块（如 4096 字节），并自行在缓冲区中按换行符分割行。`stream_set_blocking($pipe, false)` 仍保留。读取循环 SHALL 在 `stream_select` 返回 0 可读时立即退出，避免阻塞主事件循环。

#### Scenario: 子进程输出不完整行
- **WHEN** 子进程输出 "abc" 但未跟换行符，主进程轮询读取
- **THEN** `stream_select` 探测到可读，`fread` 读取 "abc" 存入缓冲区，因无换行不触发 `onOutput` 回调；下次轮询子进程输出 "\n" 时缓冲区拼接为 "abc\n" 触发回调

#### Scenario: 子进程无输出
- **WHEN** 子进程运行中但未产生输出，主进程轮询读取
- **THEN** `stream_select` 返回 0 可读，`drainStdout` 立即返回，主事件循环不被阻塞

#### Scenario: 子进程结束后读取剩余输出
- **WHEN** 子进程已退出但管道仍有缓冲数据
- **THEN** `stream_select` 探测到 EOF，`fread` 返回空字符串，触发 `onExit` 回调并清理资源

### Requirement: 控件 Preferred Size 接口
`Control` 基类 SHALL 新增两个方法：
```php
public function getPreferredWidth(): int { return 0; }
public function getPreferredHeight(): int { return 0; }
```
返回 0 表示无偏好（由容器决定）。具体控件可重写：
- `Button::getPreferredHeight()` 返回基于字体高度的按钮自然高度（约 23px + padding）
- `Button::getPreferredWidth()` 返回基于文本宽度的自然宽度
- `Label::getPreferredHeight()` 返回基于字体高度的单行高度
- `Entry::getPreferredHeight()` / `SpinBox::getPreferredHeight()` 返回基于字体高度的输入框自然高度

#### Scenario: Button 在 VBox 中空间充足
- **WHEN** VBox 高度 200px，含 2 个 Button（Preferred Height 各 25px），padding=10
- **THEN** 每个 Button 分到 25px（Preferred），剩余 140px 空间由 stretch 策略分配或留白，按钮不被压扁

#### Scenario: Preferred Size 为 0 时兼容旧行为
- **WHEN** 控件未重写 `getPreferredHeight` 返回 0
- **THEN** 容器按原均分策略分配空间，行为与修复前一致

### Requirement: Grid/Form 对齐参数
`Grid::add(Control $c, int $col, int $row, int $align = Grid::ALIGN_CENTER)` SHALL 增加第 4 参数 `$align`，支持 `ALIGN_FILL`(0) / `ALIGN_CENTER`(1) / `ALIGN_LEFT`(2) / `ALIGN_RIGHT`(3) / `ALIGN_TOP`(4) / `ALIGN_BOTTOM`(5)。默认 `ALIGN_CENTER`。`Grid::layout` 在分配格子尺寸后，根据 `$align` 计算控件实际位置和尺寸：`ALIGN_FILL` 拉伸到整格；其他模式按 Preferred Size 居中/对齐。

`Form::addRow(string $label, Control $c, int $align = Form::ALIGN_CENTER)` SHALL 增加第 3 参数 `$align`，语义同 Grid。

#### Scenario: Button 在 Grid 中默认居中
- **WHEN** `Grid::add($btn, 0, 0)` 不传 align，格子尺寸 100x80，Button Preferred 75x25
- **THEN** Button 实际位置 (12, 27) 尺寸 75x25，在格子内居中

#### Scenario: 显式 ALIGN_FILL 兼容旧行为
- **WHEN** `Grid::add($btn, 0, 0, Grid::ALIGN_FILL)`
- **THEN** Button 拉伸到整格 100x80，行为与修复前一致

### Requirement: Box 基于 Preferred Size 的布局
`Box::layout` SHALL 改进分配算法：
1. 先计算所有子控件的 Preferred Size（高度 for VBox，宽度 for HBox）
2. 若 Preferred 总和 + padding 间隙 <= 可用空间，则每个子控件分到 Preferred Size，剩余空间按 stretch 策略分配（默认留白在末尾，或均分给 stretch=true 的子控件）
3. 若 Preferred 总和 > 可用空间，回退到原均分算法（避免空隙）

`Box::add(Control $c, bool $stretch = false)` SHALL 增加第 2 参数 `$stretch`，标记该子控件是否参与剩余空间分配。默认 false。

#### Scenario: VBox 中 Button 和 Entry 共存
- **WHEN** VBox 高度 200px，含 1 个 Button（Preferred 25px）+ 1 个 Entry（Preferred 25px）+ 1 个 TextArea（无 Preferred，stretch=true），padding=10
- **THEN** Button 分到 25px，Entry 分到 25px，TextArea 分到剩余 140px

#### Scenario: 空间不足回退均分
- **WHEN** VBox 高度 40px，含 2 个 Button（Preferred 各 25px），padding=0
- **THEN** Preferred 总和 50px > 可用 40px，回退均分，每个 Button 分到 20px（不出现空隙）

### Requirement: 跨平台主题（Linux GTK）
`GtkPlatform` SHALL 重写 `setAppTheme(string $theme)`：
- `Theme::DARK`：通过 `g_object_set(GtkSettings::get_default(), "gtk-application-prefer-dark-theme", true, null)` 启用深色
- `Theme::LIGHT` / `Theme::CLASSIC`：通过 `g_object_set(..., "gtk-application-prefer-dark-theme", false, null)` 禁用深色
- `Theme::SYSTEM`：通过 `g_object_set(..., "gtk-application-prefer-dark-theme", false, null)` 让 GTK 跟随系统

`GtkPlatform::setWindowDarkMode(int $hwnd, bool $dark)` 可空实现（GTK 主题为应用级，不支持单窗口深色）。

#### Scenario: Linux 下设置 DARK 主题
- **WHEN** 在 Linux 调用 `App::setTheme(Theme::DARK)` 后 `App::run()`
- **THEN** GTK 应用启用深色主题，控件以深色渲染

### Requirement: 跨平台主题（macOS Cocoa）
`CocoaPlatform` SHALL 重写 `setAppTheme(string $theme)`：
- `Theme::DARK`：`[NSApp setAppearance:[NSAppearance appearanceNamed:NSAppearanceNameDarkAqua]]`
- `Theme::LIGHT`：`[NSApp setAppearance:[NSAppearance appearanceNamed:NSAppearanceNameAqua]]`
- `Theme::SYSTEM` / `Theme::CLASSIC`：`[NSApp setAppearance:nil]`（跟随系统）

`CocoaPlatform::setWindowDarkMode(int $hwnd, bool $dark)` 可通过 `NSWindow.appearance` 设置单窗口外观。

#### Scenario: macOS 下设置 LIGHT 主题
- **WHEN** 在 macOS 调用 `App::setTheme(Theme::LIGHT)` 后 `App::run()`
- **THEN** NSApp 外观设为 Aqua，控件以浅色渲染

## MODIFIED Requirements

### Requirement: 主题平台契约（原 Windows 特有，扩展为跨平台）
`PlatformInterface::setAppTheme(string $theme)` 不再标注"Windows 特有"，变为**跨平台契约**。三个平台 SHALL 各自实现：
- `WindowsPlatform`：原实现（manifest + SetPreferredAppMode + DwmSetWindowAttribute）
- `GtkPlatform`：通过 GtkSettings 切换深色（新增）
- `CocoaPlatform`：通过 NSAppearance 切换深浅色（新增）

`PlatformInterface::enableVisualStyles()` 保持"Windows 特有"语义（GTK/Cocoa 无需 manifest 机制，仍空实现）。

`PlatformInterface::setWindowDarkMode(int $hwnd, bool $dark)` 保持跨平台契约，但 GTK 因主题为应用级可能空实现，Cocoa 通过 NSWindow.appearance 实现。

### Requirement: Window::setDarkMode
`Window::setDarkMode(bool $dark): self` 的 PHPDoc 更新为跨平台 API（不再标注"Windows 特有"）。在 Windows 设置标题栏深色，在 macOS 设置 NSWindow 外观，在 GTK 空实现（链式调用仍正常返回）。

## REMOVED Requirements
（无）
