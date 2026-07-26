# Checklist

## Task 1: SpinBox UDN_DELTAPOS 通知时序修复
- [x] `dispatchWmNotify` 的 UDN_DELTAPOS 分支不再同步触发 onChanged 回调
- [x] UDN_DELTAPOS 不阻断 DefWindowProcW 默认处理（UpDown 能完成位置变更）
- [x] 变更完成后通过 EN_CHANGE 或 queueMain 异步触发 onChanged 回调
- [x] 回调内 `getValue()` 返回变更后的最新值（非旧值）
- [x] `examples/controls_test.php` 中 SpinBox 点击加减按钮后 Edit 框值更新
- [x] 连续快速点击加减按钮不崩溃、不丢事件

## Task 2: ComboBox removeItem / ListBox clear 修复
- [x] 已复现验证根因（确认是消息常量值错误：CB_DELETESTRING 原 0x0154 应为 0x0144，LB_RESETCONTENT 原 0x0185 应为 0x0184）
- [x] ComboBox `removeItem(index)` 立即从下拉列表删除指定项
- [x] ListBox `clear()` 立即清空所有列表项
- [x] 删除/清空后 `getSelectedIndex()` 返回合理值（-1 或新索引）
- [x] `examples/controls_advanced_test.php` 中"删除选中项"和"清空全部"按钮立即生效

## Task 3: Table 操作列按钮宽度修复
- [x] `drawCellButton` 用 `DrawTextW(DT_CALCRECT)` 预测量文字宽高
- [x] 按钮宽度 = 文字宽度 + 16px padding，在单元格内居中
- [x] `DrawTextW` 渲染加 `DT_END_ELLIPSIS`，文字超宽时省略号截断
- [x] `Table::setColumns` 支持第二个参数传数组按列指定宽度
- [x] 新增 `Table::setColumnWidth(int $col, int $width)` 方法（前向兼容，平台层 tableSetColumnWidth 已补充）
- [x] `setColumns` 传 int 时向后兼容（所有列统一宽度）
- [x] `examples/image_test.php` 操作列按钮文字"查看"/"编辑"/"删除"完整显示不溢出

## Task 4: UxTheme API 声明扩展与 Explorer 主题应用
- [x] `UXTHEME_HEADER` 已扩展，包含 `SetWindowTheme` / `OpenThemeData` / `CloseThemeData` / `DrawThemeBackground` / `DrawThemeText` / `IsThemeActive` / `IsAppThemed`（拆分为 UXTHEME_HEADER + UXTHEME_DARK_HEADER 两个 cdef，解决 SetPreferredAppMode ordinal 135 解析失败问题）
- [x] `controlCreate` 对 `SysListView32` 调用 `SetWindowTheme(hwnd, "Explorer", NULL)`
- [x] `controlCreate` 对 `SysTreeView32` 调用 `SetWindowTheme(hwnd, "Explorer", NULL)`
- [x] CLASSIC 主题模式下跳过 `SetWindowTheme` 调用
- [x] `examples/image_test.php` / `table_test.php` 中 Table 显示 Explorer 现代风格（扁平表头、行 hover 高亮）

## Task 5: 表格自绘控件主题化（DrawThemeBackground）
- [x] `drawCellCheckbox` 主题激活时用 `DrawThemeBackground(BP_CHECKBOX, CBS_CHECKED_NORMAL/CBS_UNCHECKED_NORMAL)`
- [x] `drawCellButton` 主题激活时用 `DrawThemeBackground(BP_PUSHBUTTON, PBS_NORMAL)` + `DrawThemeText`
- [x] `drawCellProgress` 主题激活时用 `DrawThemeBackground(PP_BAR/PP_FILL)`
- [x] 每次绘制后 `CloseThemeData` 释放主题句柄
- [x] 主题不可用时（CLASSIC 或 `IsThemeActive()` 返回 false）回退到 `DrawFrameControl` / `FillRect`
- [x] `examples/image_test.php` 中表格 checkbox/button/progress 显示为 Win11 原生外观
- [x] `App::setTheme(Theme::CLASSIC)` 后回退到经典外观不崩溃

## Task 6: 综合验证与示例复核
- [x] `examples/controls_test.php` SpinBox 加减按钮值变化正常
- [x] `examples/controls_advanced_test.php` ComboBox 删除 / ListBox 清空立即生效
- [x] `examples/image_test.php` 表格操作列按钮文字完整、主题化外观正常
- [x] `examples/full_test.php` / `examples/table_test.php` 无视觉回归
- [x] CLASSIC 主题模式下所有自绘控件回退正常不崩溃
- [x] 所有修改文件通过 `php -l` 语法检查
