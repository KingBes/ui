# 视觉样式与主题选择 Spec（Windows 平台特有）

## Why
当前 Windows 平台未启用 ComCtl32 v6 视觉样式（manifest），所有控件以 Windows 95/2000 经典灰风格渲染，外观陈旧（像 XP 年代）。同时进程未声明 DPI 感知，高分屏下字体发虚。需要在 Windows 平台运行时激活现代视觉样式，并让用户能在"系统主题 / 经典风格 / 深色模式 / 浅色模式"之间选择。

**平台范围声明**：本 spec 是 **Windows 平台特有功能**。Linux GTK 和 macOS Cocoa 各自有完整的主题系统（GTK Theme / NSAppearance），不需要 manifest 激活机制。本 spec 的所有平台 API 在非 Windows 平台 SHALL 静默忽略，**不约束** GTK/Cocoa 后续实现各自的主题系统。

## What Changes
- 新增 `Theme` 类（`Kingbes\Ui\Theme`）：主题预设常量容器，仅作为 `App::setTheme()` 的入参类型，不绑定具体平台实现
- **WindowsPlatform 初始化阶段**：在 `registerWindowClass()` 之前根据主题调用 `enableVisualStyles()`，运行时创建激活上下文（`CreateActCtxW` + `ActivateActCtx`）加载临时 manifest，启用 ComCtl32 v6 + PerMonitorV2 DPI 感知
- **主题预设**（仅影响 Windows 平台）：
  - `Theme::SYSTEM`（默认）：启用视觉样式，深浅色跟随系统
  - `Theme::CLASSIC`：不启用视觉样式，保持 ComCtl32 v5 经典灰外观
  - `Theme::DARK`：启用视觉样式 + `SetPreferredAppMode(ForceDark)` 强制深色
  - `Theme::LIGHT`：启用视觉样式 + `SetPreferredAppMode(ForceLight)` 强制浅色
- **App 启动前配置**：新增 `App::setTheme(string $theme): void` 静态方法，跨平台 API，在 `App::run()` 之前调用
- **窗口标题栏深色**（仅 Windows 10 1809+）：深色模式下通过 `DwmSetWindowAttribute(DWMWA_USE_IMMERSIVE_DARK_MODE)` 设置窗口标题栏为深色
- **新增 Windows FFI 块**：`uxtheme.dll`（`SetPreferredAppMode`）、`dwmapi.dll`（`DwmSetWindowAttribute`）、扩展 `kernel32.dll`（`CreateActCtxW`/`ActivateActCtx`/`DeactivateActCtx`/`ReleaseActCtx`）
- **示例**：新增 4 个独立示例 `examples/theme_{system,classic,dark,light}.php`，每个硬编码一种主题，运行后直接展示该主题下控件外观
- **文档**：`doc/06-advanced.md` 新增"主题与视觉样式（Windows 特有）"小节
- **BREAKING**：Windows 默认主题从"经典灰"改为 `Theme::SYSTEM`（启用视觉样式）。如需保留旧外观，调用 `App::setTheme(Theme::CLASSIC)` 即可。Linux/macOS 不受影响（本来就是空实现）

## Impact
- 受影响的 spec：`build-cross-platform-gui-library`（平台抽象层新增主题相关方法，但仅 Windows 有具体实现）
- 受影响的代码：
  - `src/App.php`：新增 `setTheme()` 静态方法（跨平台，记录到静态属性）
  - `src/Theme.php`（新文件）：4 个预设常量
  - `src/Platform/PlatformInterface.php`：新增 `enableVisualStyles()`/`setAppTheme(string)`/`setWindowDarkMode(int $hwnd, bool)` 三个方法，**文档明确为 Windows 特有，GTK/Cocoa 可空实现**
  - `src/Platform/AbstractPlatform.php`：提供默认空实现
  - `src/Platform/Windows/WindowsPlatform.php`：实现运行时 manifest 激活、DPI 感知、深色模式
  - `src/Platform/Linux/GtkPlatform.php`：继承空实现，**不修改**（GTK 自带主题系统，后续如有需要可独立实现 GTK 主题切换，不受本 spec 约束）
  - `src/Platform/Mac/CocoaPlatform.php`：继承空实现，**不修改**（Cocoa 自带 NSAppearance，后续如有需要可独立实现，不受本 spec 约束）
  - `src/Window.php`：新增 `setDarkMode(bool): self` 方法（跨平台 API，非 Windows 静默忽略）
  - `examples/theme_system.php` / `theme_classic.php` / `theme_dark.php` / `theme_light.php`（新文件）：4 个独立主题示例，每个硬编码一种主题
  - `doc/06-advanced.md`：新增"主题与视觉样式（Windows 特有）"小节

## ADDED Requirements

### Requirement: 主题预设常量
系统 SHALL 提供 `Kingbes\Ui\Theme` 类作为主题预设常量容器，导出 4 个字符串常量：`SYSTEM`/`CLASSIC`/`DARK`/`LIGHT`。该类仅作为 `App::setTheme()` 的入参类型，不包含任何平台特定逻辑。`Theme::isValid(string): bool` 静态方法用于校验入参合法性。

#### Scenario: 常量值
- **WHEN** 引用 `Theme::SYSTEM`/`CLASSIC`/`DARK`/`LIGHT`
- **THEN** 返回对应的字符串值（如 'system'/'classic'/'dark'/'light'）

### Requirement: 跨平台主题设置 API
系统 SHALL 提供 `App::setTheme(string $theme): void` 静态方法作为跨平台主题设置入口。用户 SHALL 在 `App::run()` 之前调用，运行后调用 SHALL 触发 E_USER_WARNING 且不生效。非法值 SHALL 抛 `InvalidArgumentException`。`App::getTheme(): string` 返回当前设置的主题（默认 `Theme::SYSTEM`）。

#### Scenario: 默认主题
- **WHEN** 用户未调用 `setTheme` 直接 `App::run()`
- **THEN** 主题为 `Theme::SYSTEM`

#### Scenario: 显式设置主题
- **WHEN** 用户调用 `App::setTheme(Theme::DARK)` 后 `App::run()`
- **THEN** 主题记录为 `Theme::DARK`，传给平台后端

#### Scenario: 运行后修改无效
- **WHEN** `App::run()` 之后调用 `App::setTheme(Theme::CLASSIC)`
- **THEN** 触发 E_USER_WARNING，主题不变

### Requirement: 平台契约（Windows 特有，其他平台空实现）
`PlatformInterface` 新增三个方法，**文档明确为 Windows 平台特有**：
```php
/** Windows 特有：启用 ComCtl32 v6 视觉样式 + DPI 感知。其他平台空实现。 */
public function enableVisualStyles(): void;

/** Windows 特有：设置应用主题（dark/light/system/classic）。其他平台空实现。 */
public function setAppTheme(string $theme): void;

/** Windows 特有：设置窗口标题栏深色模式。其他平台空实现。 */
public function setWindowDarkMode(int $hwnd, bool $dark): void;
```

`AbstractPlatform` SHALL 提供三个方法的空实现。`WindowsPlatform` SHALL 重写为具体实现。`GtkPlatform` 和 `CocoaPlatform` SHALL 继承空实现，**不修改**，**不受本 spec 约束**——后续 GTK/Cocoa 可独立设计各自的主题 API（如 `GtkSettings::gtk_application_prefer_dark_theme` / `NSAppearance`），与本 spec 的常量语义无关。

#### Scenario: Windows 平台
- **WHEN** 在 Windows 调用 `App::setTheme(Theme::DARK)` 后 `App::run()`
- **THEN** `WindowsPlatform::setAppTheme('dark')` 被调用，启用视觉样式 + 深色模式

#### Scenario: Linux/macOS 平台
- **WHEN** 在 Linux/macOS 调用 `App::setTheme(Theme::DARK)` 后 `App::run()`
- **THEN** `GtkPlatform::setAppTheme('dark')` / `CocoaPlatform::setAppTheme('dark')` 被调用，空实现静默忽略，不影响 GTK/Cocoa 自带主题系统

### Requirement: Windows 运行时视觉样式激活
`WindowsPlatform` SHALL 在平台初始化时（注册窗口类之前）根据当前主题决定是否启用视觉样式。启用方式为：将 manifest XML 写入临时文件，调用 `CreateActCtxW` 创建激活上下文，`ActivateActCtx` 激活当前线程。manifest 内容 SHALL 声明：
- 依赖 `Microsoft.Windows.Common-Controls` 6.0.0.0
- `dpiAwareness=PerMonitorV2`（Windows 10 1703+）
- `dpiAware=true`（兼容旧系统）

`enableVisualStyles()` SHALL 幂等：已激活则直接返回。`__destruct` SHALL 调用 `DeactivateActCtx` + `ReleaseActCtx` 释放资源。

#### Scenario: SYSTEM/DARK/LIGHT 主题
- **WHEN** 主题为 `SYSTEM`/`DARK`/`LIGHT`
- **THEN** 创建并激活 manifest，ComCtl32 v6 加载，控件以现代风格渲染

#### Scenario: CLASSIC 主题
- **WHEN** 主题为 `CLASSIC`
- **THEN** 不创建激活上下文，保持 ComCtl32 v5 经典外观

### Requirement: PerMonitorV2 DPI 感知（Windows）
启用视觉样式后，进程 SHALL 获得 PerMonitorV2 DPI 感知能力，控件在高分屏下自动缩放，字体清晰。`Theme::CLASSIC` 下 SHALL 启用 `dpiAware=true`（系统级 DPI 感知）作为最低保证。

#### Scenario: 4K 显示器
- **WHEN** 在 200% 缩放的 4K 显示器上运行（非 CLASSIC 主题）
- **THEN** 控件按 200% 缩放渲染，字体清晰无发虚

### Requirement: Windows 深色模式支持
`Theme::DARK` 和 `Theme::LIGHT` 主题 SHALL 通过 `uxtheme.dll` 的 `SetPreferredAppMode` 强制应用深色/浅色。`Theme::DARK` 下创建的窗口 SHALL 通过 `dwmapi.dll` 的 `DwmSetWindowAttribute(DWMWA_USE_IMMERSIVE_DARK_MODE, TRUE)` 让标题栏变深色。用户也可对单个窗口调用 `Window::setDarkMode(bool)` 覆盖全局设置。

#### Scenario: DARK 主题
- **WHEN** 设置 `Theme::DARK`
- **THEN** 所有新建窗口标题栏为深色，控件背景偏深色

#### Scenario: 单窗口覆盖
- **WHEN** 全局为 `Theme::DARK`，但调用 `$win->setDarkMode(false)`
- **THEN** 该窗口标题栏为浅色，其他窗口仍为深色

#### Scenario: 旧 Windows 版本
- **WHEN** 在不支持 `SetPreferredAppMode` 或 `DwmSetWindowAttribute(DWMWA_USE_IMMERSIVE_DARK_MODE)` 的 Windows 版本（< 10 1809）
- **THEN** 静默跳过，不报错，视觉样式仍正常启用

### Requirement: 跨平台降级（不约束 GTK/Cocoa）
非 Windows 平台 SHALL 继承 `AbstractPlatform` 的空实现，`App::setTheme()` 在这些平台 SHALL 不抛异常、不影响 GTK/Cocoa 自带主题系统。**本 spec 不定义 GTK/Cocoa 的主题行为**——后续若需要 GTK/Cocoa 主题切换，应独立设计 spec，不复用 `Theme::DARK`/`LIGHT` 常量的语义。

#### Scenario: Linux GTK
- **WHEN** 在 Linux 调用 `App::setTheme(Theme::DARK)`
- **THEN** 静默忽略，GTK 使用系统主题（或用户后续独立实现的 GTK 主题系统）

#### Scenario: macOS Cocoa
- **WHEN** 在 macOS 调用 `App::setTheme(Theme::DARK)`
- **THEN** 静默忽略，Cocoa 使用系统 NSAppearance

## MODIFIED Requirements

### Requirement: App 静态门面
`App` 类新增 `setTheme(string $theme): void` 和 `getTheme(): string` 静态方法。`setTheme` 合法性校验通过 `Theme::isValid()`，非法值抛 `InvalidArgumentException`。方法内部记录主题到静态属性，在 `App::run()` 启动平台时传给 `PlatformInterface::setAppTheme(string)` 和 `enableVisualStyles()`。`App::run()` 之后调用 SHALL 触发 E_USER_WARNING。

### Requirement: Window 类
`Window` 类新增 `setDarkMode(bool $dark): self` 方法。该方法委托 `App::platform()->setWindowDarkMode($this->hwnd, $dark)`，返回 `$this` 支持链式调用。非 Windows 平台下平台方法为空实现，链式调用仍正常返回。

## REMOVED Requirements

### Requirement: 经典灰为默认外观
**Reason**：Windows 默认外观升级为 `Theme::SYSTEM`（现代视觉样式），与 Win32 应用现代预期一致
**Migration**：如需保留旧外观，调用 `App::setTheme(Theme::CLASSIC)` 后再 `App::run()`。Linux/macOS 不受影响
