# Checklist

## Task 1: Container 窗口消息路由补全
- [x] WindowsPlatform.php 的 `dispatchWindowProc` Container 分支已增加 `WM_HSCROLL/WM_VSCROLL` case，调用 `dispatchScroll`
- [x] Container 分支的 `WM_NOTIFY` 处理前已先调用 `handleTableNotify`，返回非 null 直接返回
- [x] `dispatchWmNotify` 已增加 `UDN_DELTAPOS`(-722) 通知码分支，能反查到 SpinBox 实例并触发 `onChanged`
- [x] `examples/controls_test.php` 中 SpinBox 加减按钮点击后 onChanged 回调能触发
- [x] `examples/full_test.php` 中 SpinBox 加减按钮可正常工作
- [x] `examples/p2_test.php` 中 Slider 拖拽时 onChanged 触发，Label 值实时更新
- [x] `examples/table_test.php` 中 Table 正常显示行数据（不再为空）
- [x] `examples/table_test.php` 中"选中"按钮点击后行被选中且 `onSelectionChanged` 回调触发
- [x] `examples/image_test.php` 中 Table 正常显示行数据 + 图片列
- [x] `examples/image_test.php` 中 checkbox toggle、按钮列点击、双击行回调全部生效

## Task 2: Area 双缓冲防闪屏
- [x] `dispatchAreaMessage` 的 switch 中已增加 `case WM_ERASEBKGND: return 1;`
- [x] `areaInvalidate` 的 `InvalidateRect` 第三参数已改为 0
- [x] `examples/area_test.php` 在窗口尺寸变化或重绘时无闪屏
- [x] `examples/graphics_advanced_test.php` 在动画/重绘时无闪屏

## Task 3: Label 垂直居中
- [x] `Label.php` 已增加 `SS_CENTERIMAGE = 0x00000200` 常量
- [x] `Label::create()` 文本模式分支已合并 `SS_CENTERIMAGE` 到样式
- [x] 图像模式分支未添加 `SS_CENTERIMAGE`（保持 SS_BITMAP 自管位置）
- [x] `examples/controls_test.php` 中 Label 文本在控件矩形内垂直居中显示

## Task 4: Process 非阻塞 I/O
- [x] `Process::drainStdout` 已改用 `stream_select` + `fread` 组合
- [x] `Process` 类已新增 `$stdoutBuffer` 属性缓冲不完整行
- [x] 完整行才触发 `onOutput` 回调，不完整行留在缓冲区
- [x] EOF 时（`fread` 返回空字符串）已正确触发 `onExit`，并 flush 缓冲区最后一行
- [x] `stream_select` 返回 0 可读时 `drainStdout` 立即返回，不阻塞主循环
- [x] `examples/full_test.php` 启动子进程后主窗口不卡死，能正常响应 UI 操作
- [x] `examples/process_advanced_test.php` 启动子进程后主进程不卡死，输出能正常接收
- [x] 子进程退出后主进程恢复正常响应

## Task 5: 布局系统 Preferred Size + 对齐
- [x] `Control` 基类已新增 `getPreferredWidth(): int` 和 `getPreferredHeight(): int` 默认实现（返回 0）
- [x] `Button` 已重写 `getPreferredHeight()` 返回自然高度（约 23px）
- [x] `Label` 已重写 `getPreferredHeight()` 返回单行字体高度
- [x] `Entry`、`SpinBox` 已重写 `getPreferredHeight()` 返回输入框自然高度
- [x] `Box::layout` 已实现 Preferred Size 优先分配 + stretch 策略 + 空间不足回退均分
- [x] `Box::add` 已增加 `bool $stretch = false` 第 2 参数
- [x] `Grid` 已新增 6 个对齐常量 `ALIGN_FILL/ALIGN_CENTER/ALIGN_LEFT/ALIGN_RIGHT/ALIGN_TOP/ALIGN_BOTTOM`
- [x] `Grid::add` 已增加第 4 参数 `$align`，默认 `ALIGN_CENTER`
- [x] `Grid::layout` 已根据 `$align` 计算控件实际位置和尺寸
- [x] `Form` 已新增对齐常量（同 Grid）
- [x] `Form::addRow` 已增加第 3 参数 `$align`，默认 `ALIGN_CENTER`
- [x] `Form::layout` 已应用对齐
- [x] `examples/controls_advanced_test.php` 中按钮不被压扁，高度接近自然高度
- [x] `examples/layout_advanced_test.php` 中按钮在格内居中而非填满整格，输入框不被拉满
- [x] 其他已有示例（layout_test.php / full_test.php 等）在新布局算法下视觉无回归

## Task 6: 跨平台主题统一
- [x] `GtkPlatform.php` 已重写 `setAppTheme`，通过 `g_object_set(GtkSettings, "gtk-application-prefer-dark-theme", ...)` 切换深色
- [x] `GtkPlatform` 的 FFI 块已声明 `gtk_settings_get_default` 和 `g_object_set` 相关函数
- [x] `CocoaPlatform.php` 已重写 `setAppTheme`，通过 `[NSApp setAppearance:]` 切换 NSAppearance
- [x] `CocoaPlatform` 已声明所需 Objective-C runtime API
- [x] `PlatformInterface.php` 的 `setAppTheme` PHPDoc 已更新为跨平台契约
- [x] `Window.php` 的 `setDarkMode` PHPDoc 已更新为跨平台
- [x] `doc/06-advanced.md` 已更新主题小节，移除"Windows 特有"，补充 Linux/macOS 说明
- [x] `GtkPlatform.php` / `CocoaPlatform.php` 通过 `php -l` 语法检查（运行环境无 GTK/Cocoa，仅检查语法）

## Task 7: 示例文件复核
- [x] 已扫描所有示例文件，无 `->add(...)->setEnabled(...)` 之类的链式 API 误用
- [x] `examples/image_test.php` Table 6 列类型（text/image/checkbox/progress/color/button）全部正常显示
- [x] `examples/table_test.php` 所有操作按钮（选中/反选/修改/获取选中行/着色）生效
- [x] `examples/p2_test.php` Slider 拖拽值实时更新
- [x] `examples/controls_test.php` Label 居中 + SpinBox 可用
- [x] `examples/area_test.php` / `graphics_advanced_test.php` 无闪屏
- [x] `examples/full_test.php` / `process_advanced_test.php` 子进程不卡死主进程
- [x] `examples/controls_advanced_test.php` / `layout_advanced_test.php` 按钮不被压扁

## 额外修复：drawTableCell 跨作用域调用
- [x] `drawCellColor` 中 `CreateSolidBrush`/`DeleteObject` 改为通过 `$this->gdi32->` 调用，HBRUSH 通过 int 中转传给 user32 `FillRect`
- [x] `drawCellButton` 中 `SetBkMode` 改为通过 `$this->gdi32->` 调用，HDC 通过 int 中转传给 gdi32
- [x] `handleTableClick` 签名从 `\FFI\CData $fromHwnd` 改为 `int $fromHwnd`，内部用 `intToHwnd` 转换
- [x] `handleTableClick` 中 `SendMessageW` 的 lParam `\FFI::addr($hti)` 改为 `ptrToInt` 转换
