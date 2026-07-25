# Tasks

- [x] Task 1: Container 窗口消息路由补全（Windows）
  - [ ] SubTask 1.1: 在 `src/Platform/Windows/WindowsPlatform.php` 的 `dispatchWindowProc` Container 分支（行 2340-2349 附近）增加 `case self::WM_HSCROLL` / `case self::WM_VSCROLL`，调用 `return $this->dispatchScroll($hwndInt, $msg, $wParam, $lParam);`，与主窗口分支对齐
  - [ ] SubTask 1.2: 在 Container 分支的 `WM_NOTIFY` 处理前先调用 `$tableResult = $this->handleTableNotify($lParam);`，若 `$tableResult !== null` 直接返回该值；否则继续走 `dispatchWmNotify`
  - [ ] SubTask 1.3: 在 `dispatchWmNotify` 增加 `UDN_DELTAPOS`(-722) 通知码分支：从 `NMHDR->hwndFrom`（或 `NMUPDOWN->hdr.hwndFrom`）反查 `$this->controls[$hwndFrom]`，若为 `SpinBox` 实例则调用其 `onChanged` 回调（注意 UDN_DELTAPOS 是"即将改变"通知，iDelta 表示增量，可让 SpinBox 内部值按 iDelta 调整后触发回调）
  - [ ] SubTask 1.4: 验证 `examples/controls_test.php`、`examples/full_test.php` 中 SpinBox 加减按钮可触发 onChanged；`examples/p2_test.php` 中 Slider 拖拽时 Label 更新；`examples/table_test.php`、`examples/image_test.php` 中 Table 有内容显示且选中按钮有效

- [x] Task 2: Area 双缓冲防闪屏（Windows）
  - [ ] SubTask 2.1: 在 `src/Platform/Windows/WindowsPlatform.php` 的 `dispatchAreaMessage` switch 中增加 `case self::WM_ERASEBKGND: return 1;`（返回非 0 抑制 `DefWindowProcW` 的背景擦除）
  - [ ] SubTask 2.2: 修改 `areaInvalidate` 方法（约行 5512-5515），将 `InvalidateRect($hwnd, null, 1)` 的第三参数改为 `0`（不要求擦除背景）
  - [ ] SubTask 2.3: 验证 `examples/area_test.php`、`examples/graphics_advanced_test.php` 在重绘时无闪屏

- [x] Task 3: Label 垂直居中（Windows）
  - [ ] SubTask 3.1: 在 `src/Control/Label.php` 增加 `private const SS_CENTERIMAGE = 0x00000200;` 常量
  - [ ] SubTask 3.2: 在 `Label::create()` 文本模式分支（非 imageMode）中，将 `SS_CENTERIMAGE` 合并到传入 `controlCreate` 的 `$style` 参数
  - [ ] SubTask 3.3: 验证 `examples/controls_test.php` 中 Label 文本垂直居中显示

- [x] Task 4: Process 非阻塞 I/O 重写
  - [ ] SubTask 4.1: 在 `src/Process.php` 的 `drainStdout` 方法中，将 `fgets` 读取改为 `stream_select($read, $write, $except, 0)` + `fread($pipe, 4096)` 的组合；保留 `stream_set_blocking($pipe, false)`
  - [ ] SubTask 4.2: 在 `Process` 类中新增私有属性 `string $stdoutBuffer = ''`，将 `fread` 读取的数据追加到缓冲区，按 `\n` 分割行：完整行触发 `onOutput` 回调，剩余不完整行留在缓冲区
  - [ ] SubTask 4.3: 处理 EOF 情况：`stream_select` 返回可读但 `fread` 返回空字符串时，判定子进程结束，触发 `onExit` 回调；若缓冲区仍有数据，先 flush 为最后一行 `onOutput` 再触发 `onExit`
  - [ ] SubTask 4.4: 验证 `examples/full_test.php`、`examples/process_advanced_test.php` 启动子进程时主进程不卡死，能正常接收子进程输出

- [x] Task 5: 布局系统引入 Preferred Size + 对齐
  - [ ] SubTask 5.1: 在 `src/Control.php` 基类新增 `public function getPreferredWidth(): int { return 0; }` 和 `public function getPreferredHeight(): int { return 0; }` 两个方法（默认返回 0 表示无偏好）
  - [ ] SubTask 5.2: 在 `src/Control/Button.php` 重写 `getPreferredHeight()` 返回基于字体高度的自然高度（约 23px）；`getPreferredWidth()` 返回基于文本宽度的自然宽度（可用 `GetTextExtentPoint32W` 粗略估算，或返回 0 让容器决定）
  - [ ] SubTask 5.3: 在 `src/Control/Label.php` 重写 `getPreferredHeight()` 返回单行字体高度
  - [ ] SubTask 5.4: 在 `src/Control/Entry.php`、`src/Control/SpinBox.php` 重写 `getPreferredHeight()` 返回输入框自然高度（约 23px）
  - [ ] SubTask 5.5: 修改 `src/Layout/Box.php` 的 `layout` 方法：先计算所有子控件 Preferred Size 总和，若 <= 可用空间则按 Preferred 分配 + stretch 标记的子控件瓜分剩余空间；若 > 可用空间回退原均分算法
  - [ ] SubTask 5.6: 修改 `Box::add(Control $c)` 增加第 2 参数 `bool $stretch = false`，标记子控件是否参与剩余空间分配；在类中新增 `protected array $stretchFlags = [];` 记录每个子控件的 stretch 标记
  - [ ] SubTask 5.7: 在 `src/Layout/Grid.php` 新增对齐常量 `ALIGN_FILL=0`/`ALIGN_CENTER=1`/`ALIGN_LEFT=2`/`ALIGN_RIGHT=3`/`ALIGN_TOP=4`/`ALIGN_BOTTOM=5`，修改 `add(Control $c, int $col, int $row, int $align = self::ALIGN_CENTER)` 增加第 4 参数并记录对齐标记
  - [ ] SubTask 5.8: 修改 `Grid::layout` 在分配格子尺寸后根据 `$align` 计算控件实际位置和尺寸：`ALIGN_FILL` 拉伸到整格；其他模式按 Preferred Size 居中/对齐
  - [ ] SubTask 5.9: 在 `src/Layout/Form.php` 新增对齐常量（同 Grid），修改 `addRow(string $label, Control $c, int $align = self::ALIGN_CENTER)` 增加第 3 参数；修改 `Form::layout` 应用对齐
  - [ ] SubTask 5.10: 验证 `examples/controls_advanced_test.php`、`examples/layout_advanced_test.php` 中按钮不被压扁，输入框不被拉满整格

- [x] Task 6: 跨平台主题统一（Linux GTK + macOS Cocoa）
  - [ ] SubTask 6.1: 在 `src/Platform/Linux/GtkPlatform.php` 重写 `setAppTheme(string $theme)`：DARK 调用 `g_object_set(GtkSettings::get_default(), "gtk-application-prefer-dark-theme", true, null)`；LIGHT/CLASSIC/SYSTEM 调用 `... false, null`（让 GTK 跟随系统）
  - [ ] SubTask 6.2: 在 `GtkPlatform` 的 FFI 块中声明 `GtkSettings* gtk_settings_get_default(void)` 和 `void g_object_set(GObject* object, const char* first_property_name, ...)`（注意 vararg 在 PHP FFI 中需特殊处理，可用 `g_object_set_int` 包装或直接调用）
  - [ ] SubTask 6.3: 在 `src/Platform/Mac/CocoaPlatform.php` 重写 `setAppTheme(string $theme)`：DARK 调用 `[NSApp setAppearance:[NSAppearance appearanceNamed:NSAppearanceNameDarkAqua]]`；LIGHT 调用 Aqua；SYSTEM/CLASSIC 调用 `setAppearance:nil`
  - [ ] SubTask 6.4: 在 `CocoaPlatform` 中声明所需 Objective-C runtime API（`objc_msgSend` 调 `NSAppearance appearanceNamed:` 和 `NSApp setAppearance:`）
  - [ ] SubTask 6.5: 更新 `src/Platform/PlatformInterface.php` 中 `setAppTheme` 的 PHPDoc，移除"Windows 特有"标注，改为"跨平台：Windows 启用视觉样式+深色模式，GTK 切换深色偏好，Cocoa 切换 NSAppearance"
  - [ ] SubTask 6.6: 更新 `src/Window.php` 中 `setDarkMode` 的 PHPDoc，说明跨平台行为（Windows 标题栏深色、macOS NSWindow 外观、GTK 空实现）
  - [ ] SubTask 6.7: 更新 `doc/06-advanced.md` 的"主题与视觉样式"小节，移除"Windows 特有"标题，改为"主题切换（跨平台）"，补充 Linux/macOS 实现说明
  - [ ] SubTask 6.8: 因当前环境为 Windows，无法直接验证 Linux/macOS 主题行为，仅做 `php -l` 语法检查确认 GtkPlatform.php / CocoaPlatform.php 无语法错误

- [x] Task 7: 示例文件复核与 API 误用修正
  - [ ] SubTask 7.1: 检查所有示例文件中是否有 `->add(...)->setEnabled(...)`、`->add(...)->setXxx(...)` 之类的链式调用误用（add 返回 Container 而非新添加的控件）；若有则修正为"先创建控件、设置属性、再 add"模式
  - [ ] SubTask 7.2: 验证 `examples/image_test.php`、`examples/table_test.php` 在 Task 1 修复后 Table 正常显示数据，选中按钮、双击、checkbox toggle、按钮列点击全部生效
  - [ ] SubTask 7.3: 验证 `examples/p2_test.php` Slider 拖拽时 Label 实时更新值
  - [ ] SubTask 7.4: 验证 `examples/controls_test.php` Label 垂直居中、SpinBox 加减按钮触发回调
  - [ ] SubTask 7.5: 验证 `examples/area_test.php`、`examples/graphics_advanced_test.php` 无闪屏
  - [ ] SubTask 7.6: 验证 `examples/full_test.php`、`examples/process_advanced_test.php` 启动子进程时主进程不卡死，能正常接收输出并在子进程退出后恢复响应
  - [ ] SubTask 7.7: 验证 `examples/controls_advanced_test.php`、`examples/layout_advanced_test.php` 按钮不被压扁，输入框不被拉满

# Task Dependencies
- [Task 1] 独立（仅修改 WindowsPlatform.php 消息路由部分）
- [Task 2] 独立（仅修改 WindowsPlatform.php Area 部分）
- [Task 3] 独立（仅修改 Label.php）
- [Task 4] 独立（仅修改 Process.php）
- [Task 5] 独立但影响范围最大（Control.php + Button/Label/Entry/SpinBox + Box/Grid/Form）
- [Task 6] 独立（仅修改 GtkPlatform.php / CocoaPlatform.php / PlatformInterface.php / Window.php / doc）
- [Task 7] 依赖 [Task 1-6] 全部完成，作为最终验证
- Task 1/2/3/4/6 之间无依赖，可并行
- Task 5 改动布局核心算法，建议单独执行避免与其他任务冲突
