# Tasks

- [x] Task 1: 创建 Theme 类和 App::setTheme 静态门面
  - [x] SubTask 1.1: 新建 `src/Theme.php`，定义 4 个字符串常量 `SYSTEM='system'`/`CLASSIC='classic'`/`DARK='dark'`/`LIGHT='light'` 和 `isValid(string $theme): bool` 静态方法。**仅常量容器，不含平台逻辑**
  - [x] SubTask 1.2: 在 `src/App.php` 添加静态属性 `$theme`（默认 `Theme::SYSTEM`）、`setTheme(string $theme): void`（含 `Theme::isValid` 校验，非法抛 `InvalidArgumentException`；运行后调用触发 E_USER_WARNING）、`getTheme(): string`
  - [x] SubTask 1.3: 在 `App::run()` 启动平台后、注册窗口类之前调用 `platform->setAppTheme($theme)` 和 `platform->enableVisualStyles()`

- [x] Task 2: 扩展 PlatformInterface 与 AbstractPlatform 默认实现
  - [x] SubTask 2.1: 在 `src/Platform/PlatformInterface.php` 添加三个方法声明，**PHPDoc 明确标注"Windows 特有，其他平台空实现"**：`enableVisualStyles(): void`、`setAppTheme(string): void`、`setWindowDarkMode(int, bool): void`
  - [x] SubTask 2.2: 在 `src/Platform/AbstractPlatform.php` 提供三个方法的空实现（`{}`），供 GTK/Cocoa 继承。**不修改 GtkPlatform.php 和 CocoaPlatform.php**（它们继承空实现即可）

- [x] Task 3: WindowsPlatform 实现运行时视觉样式激活（核心，Windows 特有）
  - [x] SubTask 3.1: 在 `WindowsPlatform.php` 顶部新增常量：manifest XML 字符串模板（含 Common-Controls v6 + PerMonitorV2 + dpiAware）、`ACTCTX_FLAG_*` 标志
  - [x] SubTask 3.2: 扩展 kernel32 FFI 块，声明 `ACTCTXW` 结构体 + `CreateActCtxW` + `ActivateActCtx` + `DeactivateActCtx` + `ReleaseActCtx`
  - [x] SubTask 3.3: 实现 `enableVisualStyles()`：写入临时 manifest 文件到 `sys_get_temp_dir()`、`CreateActCtxW` 创建上下文、`ActivateActCtx` 激活、记录 hActCtx；**幂等**：已激活则跳过
  - [x] SubTask 3.4: 实现 `setAppTheme(string)`：记录主题；若非 CLASSIC 则调用 `enableVisualStyles()`
  - [x] SubTask 3.5: 在 `__destruct` 中 `DeactivateActCtx` + `ReleaseActCtx` 释放激活上下文

- [x] Task 4: WindowsPlatform 实现深色模式支持（Windows 特有，可与 Task 3 并行）
  - [x] SubTask 4.1: 扩展 uxtheme FFI 块（**动态加载**，函数不存在时静默跳过），声明 `SetPreferredAppMode` 签名 `int (int mode)`
  - [x] SubTask 4.2: 扩展 dwmapi FFI 块，声明 `DwmSetWindowAttribute`（`HRESULT (HWND, DWORD, LPCVOID, DWORD)`）和常量 `DWMWA_USE_IMMERSIVE_DARK_MODE=20`
  - [x] SubTask 4.3: 在 `setAppTheme(string)` 中：DARK 调用 `SetPreferredAppMode(2=ForceDark)`、LIGHT 调用 `SetPreferredAppMode(3=ForceLight)`、SYSTEM 调用 `SetPreferredAppMode(0=Default)`；**函数不存在则静默跳过**（旧 Windows 版本）
  - [x] SubTask 4.4: 实现 `setWindowDarkMode(int $hwnd, bool $dark)`：调用 `DwmSetWindowAttribute(hwnd, DWMWA_USE_IMMERSIVE_DARK_MODE, &dark, sizeof(BOOL))`；**失败静默返回**（旧版本兼容）

- [x] Task 5: Window 类添加 setDarkMode 方法（跨平台 API）
  - [x] SubTask 5.1: 在 `src/Window.php` 添加 `setDarkMode(bool $dark): self` 方法，委托 `App::platform()->setWindowDarkMode($this->hwnd, $dark)`，返回 `$this` 支持链式调用。**非 Windows 平台空实现，链式调用仍正常返回**

- [x] Task 6: 注册窗口类时确保使用现代字体（Windows 特有）
  - [x] SubTask 6.1: 修改 `registerWindowClass()` 中默认控件字体：非 CLASSIC 主题用 `CreateFontW` 创建 "Segoe UI" 9pt 字体，CLASSIC 主题保持原 "System" 字体
  - [x] SubTask 6.2: 控件创建后通过 `WM_SETFONT` 应用 Segoe UI 字体

- [x] Task 7: 创建主题示例（4 个独立示例，每个硬编码一种主题）
  - [x] SubTask 7.1: 新建 `examples/theme_system.php` / `theme_classic.php` / `theme_dark.php` / `theme_light.php`，每个示例在代码顶部 `App::setTheme(Theme::XXX)` 后创建窗口展示同一组控件（Button/Entry/Checkbox/ComboBox/ProgressBar/Slider）
  - [x] SubTask 7.2: 每个示例窗口标题标注主题名称，运行后直接展示该主题效果，关闭窗口退出
  - [x] SubTask 7.3: 已验证 theme_dark.php 运行后窗口正常显示（进程持续运行，不立即退出）

- [x] Task 8: 语法检查与自动测试验证
  - [x] SubTask 8.1: 对所有修改和新增的 PHP 文件运行 `php -l` 语法检查
  - [x] SubTask 8.2: 运行 `examples/theme_dark.php` 验证窗口正常显示（进程持续运行，不立即退出）
  - [x] SubTask 8.3: 验证原有 `examples/tray_test.php` 在新默认主题下仍正常运行

- [x] Task 9: 更新文档
  - [x] SubTask 9.1: 在 `doc/06-advanced.md` 新增"主题与视觉样式（Windows 特有）"小节，说明 4 种主题、`App::setTheme()` 用法、`Window::setDarkMode()` 用法、CLASSIC 兼容场景，**明确标注 Linux/macOS 不受影响**
  - [x] SubTask 9.2: 在 `doc/07-api-reference.md` 的 App 部分添加 `setTheme()` 和 `getTheme()`，新增 Theme 类条目，Window 部分添加 `setDarkMode()`，**标注平台限制**
  - [x] SubTask 9.3: 更新 `README.md` 的"主要功能"列表，新增"主题切换（Windows 特有）"

# Task Dependencies
- [Task 2] depends on [Task 1]（App 先有 setTheme 才能定义平台契约）
- [Task 3] depends on [Task 2]（WindowsPlatform 实现依赖接口契约）
- [Task 4] depends on [Task 2]
- [Task 4] 和 [Task 3] 可并行（不同的 FFI 块）
- [Task 5] depends on [Task 2]
- [Task 6] depends on [Task 3]（字体策略依赖主题判定）
- [Task 7] depends on [Task 5, Task 6]（示例需要 Window API 和字体就绪）
- [Task 8] depends on [Task 7]（语法检查覆盖所有改动）
- [Task 9] depends on [Task 8]（文档基于已验证的实现）
