# Tasks

- [x] Task 1: SpinBox UDN_DELTAPOS 通知时序修复
  - [x] SubTask 1.1: 在 `src/Platform/Windows/WindowsPlatform.php` 的 `dispatchWmNotify` 中，移除 `UDN_DELTAPOS`(-722) 分支里同步触发 `onChanged` 回调的逻辑，改为允许 UpDown 完成位置变更（不 return true 阻断，让 `DefWindowProcW` 正常处理）
  - [x] SubTask 1.2: 实现"变更完成后触发回调"机制：在 `dispatchWmNotify` 或 `dispatchCommand` 中监听 buddy Edit 的 `EN_CHANGE` 通知（`HIWORD(wParam) == EN_CHANGE`，`lParam` 为 Edit HWND），反查 SpinBox 实例触发 `onChanged`；或在 `UDN_DELTAPOS` 后用 `App::queueMain` 异步触发回调
  - [x] SubTask 1.3: 验证 `examples/controls_test.php` 中 SpinBox 点击加减按钮后 Edit 框值更新、`onChanged` 回调触发且 `getValue()` 返回新值；连续快速点击不崩溃

- [x] Task 2: ComboBox removeItem / ListBox clear 修复
  - [x] SubTask 2.1: 先复现验证根因——检查 `controlRemoveString`（CB_DELETESTRING）和 `controlClear`（LB_RESETCONTENT）的 `SendMessageW` 调用是否成功返回；检查 `getSelectedIndex` 返回值是否正确；检查 `controlTypes` 中 ComboBox/ListBox 注册的类名是否匹配消息选择逻辑
  - [x] SubTask 2.2: 根据验证结果修复缺陷（可能是 HWND 传递、消息常量、类名匹配、或选中状态未更新）
  - [x] SubTask 2.3: 验证 `examples/controls_advanced_test.php` 中 ComboBox "删除选中项"立即从下拉列表消失；ListBox "清空全部"立即清空列表框；删除/清空后 `getSelectedIndex()` 返回合理值

- [x] Task 3: Table 操作列按钮宽度修复
  - [x] SubTask 3.1: 在 `src/Platform/Windows/WindowsPlatform.php` 的 `drawCellButton` 中，用 `DrawTextW(DT_CALCRECT)` 预测量按钮文字宽高，按钮宽度 = 文字宽度 + 16px padding（左右各 8），高度 = 文字高度 + 6px padding（上下各 3），在单元格内居中定位
  - [x] SubTask 3.2: `DrawTextW` 渲染时加 `DT_END_ELLIPSIS` 标志，文字超出按钮宽度时省略号截断
  - [x] SubTask 3.3: 在 `src/Control/Table.php` 修改 `setColumns(array $columnTexts, int|array $columnWidth = 100)` 支持第二个参数传数组按列单独指定宽度；新增 `setColumnWidth(int $col, int $width)` 方法（前向兼容，待平台层补充 tableSetColumnWidth 后即时生效）
  - [x] SubTask 3.4: 修改 `examples/image_test.php` 第 306 行 `setColumns` 调用，操作列单独加宽（如 `[50, 130, 50, 80, 60, 80]`），验证按钮文字"查看"/"编辑"/"删除"完整显示不溢出

- [x] Task 4: UxTheme API 声明扩展与 Explorer 主题应用
  - [x] SubTask 4.1: 在 `src/Platform/Windows/WindowsPlatform.php` 扩展 `UXTHEME_HEADER` FFI 声明，增加 `SetWindowTheme` / `OpenThemeData` / `CloseThemeData` / `DrawThemeBackground` / `DrawThemeText` / `IsThemeActive` / `IsAppThemed` 函数声明（拆分为 UXTHEME_HEADER + UXTHEME_DARK_HEADER 两个 cdef，解决 SetPreferredAppMode ordinal 135 解析失败问题）
  - [x] SubTask 4.2: 在 `controlCreate` 方法末尾，根据 `className` 对 `SysListView32` / `SysTreeView32` 调用 `SetWindowTheme(hwnd, "Explorer", NULL)`；CLASSIC 主题模式下跳过
  - [x] SubTask 4.3: 验证 `examples/image_test.php` / `examples/table_test.php` 中 Table 外观变为 Explorer 现代风格（扁平表头、行 hover 高亮、无 3D 边框）

- [x] Task 5: 表格自绘控件主题化（DrawThemeBackground）
  - [x] SubTask 5.1: 修改 `drawCellCheckbox`：主题激活时（`IsThemeActive()` 且非 CLASSIC）用 `OpenThemeData(hwnd, "Button")` + `DrawThemeBackground(BP_CHECKBOX, CBS_CHECKED_NORMAL/CBS_UNCHECKED_NORMAL)` 绘制；主题不可用时回退到 `DrawFrameControl`；绘制后 `CloseThemeData`
  - [x] SubTask 5.2: 修改 `drawCellButton`：主题激活时用 `OpenThemeData(hwnd, "Button")` + `DrawThemeBackground(BP_PUSHBUTTON, PBS_NORMAL)` + `DrawThemeText` 绘制（保留 Task 3 的文字测量逻辑）；主题不可用时回退到 `DrawFrameControl`
  - [x] SubTask 5.3: 修改 `drawCellProgress`：主题激活时用 `OpenThemeData(hwnd, "Progress")` + `DrawThemeBackground(PP_BAR/PP_FILL)` 绘制；主题不可用时回退到原 `FillRect` 实现
  - [x] SubTask 5.4: 验证 `examples/image_test.php` 中表格 checkbox/button/progress 列显示为 Win11 原生外观；`App::setTheme(Theme::CLASSIC)` 后回退到经典外观不崩溃

- [x] Task 6: 综合验证与示例复核
  - [x] SubTask 6.1: 运行 `examples/controls_test.php` 验证 SpinBox 加减按钮值变化、ComboBox/ListBox 操作正常
  - [x] SubTask 6.2: 运行 `examples/controls_advanced_test.php` 验证 ComboBox 删除选中项、ListBox 清空全部立即生效
  - [x] SubTask 6.3: 运行 `examples/image_test.php` 验证表格操作列按钮文字完整、checkbox/button/progress 主题化外观、Explorer 风格表头
  - [x] SubTask 6.4: 运行 `examples/full_test.php` / `examples/table_test.php` 确认无视觉回归
  - [x] SubTask 6.5: 设置 `App::setTheme(Theme::CLASSIC)` 验证主题化回退不崩溃

# Task Dependencies
- [Task 1] 独立（仅修改 WindowsPlatform.php UDN_DELTAPOS 处理 + SpinBox.php）
- [Task 2] 独立（需先复现验证根因，再修复 controlRemoveString/controlClear）
- [Task 3] 独立（修改 drawCellButton + Table.php）
- [Task 4] 独立（扩展 UXTHEME_HEADER + controlCreate 调 SetWindowTheme）
- [Task 5] 依赖 [Task 4]（需要 UXTHEME_HEADER 扩展后的 FFI 声明）
- [Task 6] 依赖 [Task 1-5] 全部完成，作为最终验证
- Task 1/2/3/4 之间无依赖，可并行
