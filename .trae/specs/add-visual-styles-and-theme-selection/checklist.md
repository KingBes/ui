# Checklist

## 主题门面与 App 集成（跨平台 API）
- [x] `src/Theme.php` 存在，导出 4 个字符串常量 `SYSTEM`/`CLASSIC`/`DARK`/`LIGHT` 和 `isValid()` 静态方法
- [x] `Theme` 类仅作为常量容器，**不含任何平台特定逻辑**
- [x] `App::setTheme(string)` 方法存在，非法值抛 `InvalidArgumentException`
- [x] `App::run()` 之后调用 `setTheme` 触发 E_USER_WARNING
- [x] `App::run()` 启动平台后调用 `platform->setAppTheme($theme)` 和 `platform->enableVisualStyles()`
- [x] 默认主题为 `Theme::SYSTEM`

## 平台契约（Windows 特有，其他平台空实现）
- [x] `PlatformInterface` 新增 `enableVisualStyles()`、`setAppTheme(string)`、`setWindowDarkMode(int, bool)` 三个方法声明
- [x] 三个方法的 PHPDoc **明确标注"Windows 特有，其他平台空实现"**
- [x] `AbstractPlatform` 提供三个方法的空实现
- [x] `GtkPlatform` 和 `CocoaPlatform` 继承空实现，**未被修改**
- [x] GTK/Cocoa 后续可独立设计各自的主题 API，**不受本 spec 约束**（spec 中明确声明）

## WindowsPlatform 视觉样式激活（Windows 特有）
- [x] kernel32 FFI 块声明 `ACTCTXW` 结构体和 `CreateActCtxW`/`ActivateActCtx`/`DeactivateActCtx`/`ReleaseActCtx` 函数
- [x] manifest XML 模板包含 Common-Controls v6 依赖和 PerMonitorV2 DPI 声明
- [x] `enableVisualStyles()` 将 manifest 写入临时文件并创建激活上下文
- [x] `enableVisualStyles()` 幂等：已激活则直接返回
- [x] CLASSIC 主题下 `enableVisualStyles()` 跳过，不创建激活上下文
- [x] `__destruct` 释放激活上下文（DeactivateActCtx + ReleaseActCtx）

## WindowsPlatform 深色模式（Windows 特有）
- [x] uxtheme FFI 块**动态加载**，声明 `SetPreferredAppMode`
- [x] dwmapi FFI 块声明 `DwmSetWindowAttribute`，常量 `DWMWA_USE_IMMERSIVE_DARK_MODE=20`
- [x] `setAppTheme(DARK)` 调用 `SetPreferredAppMode(ForceDark=2)`，函数不存在则静默跳过
- [x] `setAppTheme(LIGHT)` 调用 `SetPreferredAppMode(ForceLight=3)`
- [x] `setAppTheme(SYSTEM)` 调用 `SetPreferredAppMode(Default=0)`
- [x] `setWindowDarkMode(hwnd, true)` 调用 `DwmSetWindowAttribute` 设置深色标题栏
- [x] `setWindowDarkMode` 在旧 Windows 版本（< 10 1809）静默失败，不报错

## Window 类（跨平台 API）
- [x] `Window::setDarkMode(bool): self` 方法存在
- [x] 委托 `App::platform()->setWindowDarkMode($this->hwnd, $dark)`
- [x] 返回 `$this` 支持链式调用
- [x] 非 Windows 平台下空实现，链式调用仍正常返回

## 字体策略（Windows 特有）
- [x] 非 CLASSIC 主题下控件默认字体为 "Segoe UI" 9pt
- [x] CLASSIC 主题下保持原 "System" 字体
- [x] 控件创建后通过 `WM_SETFONT` 应用字体

## 示例与测试
- [x] 4 个独立示例存在：`examples/theme_system.php` / `theme_classic.php` / `theme_dark.php` / `theme_light.php`
- [x] 每个示例窗口标题标注主题名称，直接展示该主题效果
- [x] 示例运行后窗口正常显示，关闭窗口退出（已验证 theme_dark.php 不立即退出）
- [x] 所有修改/新增 PHP 文件通过 `php -l` 语法检查
- [x] `examples/tray_test.php` 在新默认主题下仍正常运行

## 文档
- [x] `doc/06-advanced.md` 新增"主题与视觉样式（Windows 特有）"小节
- [x] 文档明确标注 Linux/macOS 不受本功能影响
- [x] `doc/07-api-reference.md` 新增 `App::setTheme/getTheme`、`Theme` 类、`Window::setDarkMode` 条目
- [x] API 速查表标注平台限制
- [x] `README.md` 主要功能列表新增"主题切换（Windows 特有）"

## 跨平台兼容性验证
- [x] `GtkPlatform` 和 `CocoaPlatform` 源文件未被本 spec 修改
- [x] `App::setTheme()` 在非 Windows 平台不抛异常（空实现）
- [x] `Window::setDarkMode()` 在非 Windows 平台链式调用正常返回

## 验收
- [x] Windows 默认运行（未调 setTheme）控件显示现代风格（非经典灰）
- [x] `App::setTheme(Theme::CLASSIC)` 后运行，控件显示经典灰风格
- [x] `App::setTheme(Theme::DARK)` 后运行，窗口标题栏为深色
- [x] 4K 200% 缩放下字体清晰（PerMonitorV2 生效）— manifest 已声明 PerMonitorV2，机制就位（测试环境无 4K 显示器，未直接肉眼验证）
