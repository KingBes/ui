# 控件 Bug 修复与 Win11 现代视觉样式 Spec

## Why
用户在 Win11 环境测试示例时发现 4 类问题：(1) SpinBox 加减按钮点击后值不变；(2) ComboBox 删除选中项、ListBox 清空全部失效；(3) Table 操作列按钮文字溢出、且用户不清楚"启用"列是什么控件；(4) 控件整体外观停留在 Win7 Aero 时代，未体现 Win11 现代外观。这些问题根因集中在：UpDown 通知时序错误、消息发送链路缺陷、表格自绘不测量文字、以及缺少 `SetWindowTheme` / `DrawThemeBackground` 等 UxTheme API 调用。

## What Changes
- **SpinBox UDN_DELTAPOS 通知时序修复**：`dispatchWmNotify` 的 `UDN_DELTAPOS`(-722) 分支不再在"即将变更"阶段同步触发 `onChanged` 回调（此时值未变，且回调内 `getValue()` 重入 UpDown 干扰变更流程）。改为允许 UpDown 完成变更后，通过 buddy Edit 的 `EN_CHANGE` 通知或异步 `queueMain` 触发回调
- **ComboBox removeItem / ListBox clear 修复**：先复现验证根因（`controlRemoveString` / `controlClear` 的消息发送链路、`getSelectedIndex` 返回值、`controlTypes` 类名匹配），修复后确保删除/清空操作立即生效
- **Table 操作列按钮宽度修复**：`drawCellButton` 用 `DrawTextW(DT_CALCRECT)` 预测量文字宽度，按钮按文字尺寸渲染而非铺满整格；`Table::setColumns` 支持按列单独指定宽度（接收宽度数组或新增 `setColumnWidth` API）
- **Win11 现代视觉样式**：扩展 `UXTHEME_HEADER` FFI 声明（`SetWindowTheme` / `OpenThemeData` / `CloseThemeData` / `DrawThemeBackground` / `DrawThemeText` / `IsThemeActive`）；`controlCreate` 对 `SysListView32` / `SysTreeView32` 等控件调 `SetWindowTheme(hwnd, "Explorer", NULL)` 获得 Explorer 现代外观；`drawCellCheckbox` / `drawCellButton` / `drawCellProgress` 从 `DrawFrameControl` 改为 `DrawThemeBackground` 主题化绘制
- **BREAKING**：无（所有新增 API 可选，默认行为改进不破坏现有调用）

## Impact
- 受影响的 spec：`fix-examples-and-cross-platform-themes`（SpinBox UDN_DELTAPOS 处理是该 spec Task 1 引入的，本次修正其时序缺陷）、`add-visual-styles-and-theme-selection`（视觉样式机制扩展，UxTheme API 补全）
- 受影响的代码：
  - `src/Platform/Windows/WindowsPlatform.php`：`dispatchWmNotify` UDN_DELTAPOS 分支重写、`UXTHEME_HEADER` 扩展、`controlCreate` 增加 `SetWindowTheme` 调用、`drawCellCheckbox` / `drawCellButton` / `drawCellProgress` 改用 `DrawThemeBackground`、`controlRemoveString` / `controlClear` 修复
  - `src/Control/SpinBox.php`：`onChanged` 触发时机调整（可能需要监听 buddy Edit 的 EN_CHANGE）
  - `src/Control/Table.php`：`setColumns` 支持宽度数组或新增 `setColumnWidth`
  - `examples/controls_test.php` / `examples/controls_advanced_test.php` / `examples/image_test.php`：验证修复后行为正确，image_test.php 按钮列宽度调整
- **非破坏性变更**：`SetWindowTheme` 和 `DrawThemeBackground` 在主题不可用时（CLASSIC 主题或旧系统）回退到原 `DrawFrameControl` 行为；`setColumns` 宽度数组参数向后兼容（传 int 时保持原行为）

## ADDED Requirements

### Requirement: SpinBox 变更完成后再触发 onChanged
`dispatchWmNotify` 的 `UDN_DELTAPOS`(-722) 分支 SHALL 允许 UpDown 完成位置变更（不阻断 `DefWindowProcW` 默认处理），不在"即将变更"阶段同步触发 `onChanged` 回调。变更完成后 SHALL 通过以下机制之一触发回调：
- 监听 buddy Edit 的 `EN_CHANGE` 通知（`UDS_SETBUDDYINT` 会在值变化时更新 Edit 文本，触发 `EN_CHANGE`）
- 或在 `UDN_DELTAPOS` 后用 `queueMain` 异步触发回调（避免重入）

回调触发时 `getValue()` SHALL 返回变更后的最新值。

#### Scenario: 点击 UpDown 加号
- **WHEN** 用户点击 SpinBox 的 UpDown 加号按钮
- **THEN** UpDown 完成位置 +1 变更，buddy Edit 文本更新为新值，`onChanged` 回调被触发，回调内 `getValue()` 返回新值

#### Scenario: 连续点击加减
- **WHEN** 用户连续快速点击 UpDown 加号 3 次
- **THEN** SpinBox 值递增 3 次，每次变更完成后 `onChanged` 触发，回调内 `getValue()` 反映最新值，不出现重入崩溃

### Requirement: ComboBox removeItem 立即生效
`ComboBox::removeItem(int $index)` SHALL 通过 `CB_DELETESTRING` 消息立即删除指定索引的选项，删除后选中状态更新（若删除的是当前选中项，选中索引变为 -1 或前一项）。`getSelectedIndex()` 在删除后 SHALL 返回有效的索引值。

#### Scenario: 删除当前选中项
- **WHEN** ComboBox 当前选中第 1 项（index=0），用户点"删除选中项"按钮调用 `removeItem(0)`
- **THEN** 第 0 项从下拉列表中消失，`getSelectedIndex()` 返回 -1 或新的有效索引，UI 立即反映删除结果

### Requirement: ListBox clear 清空全部选项
`ListBox::clear()` SHALL 通过 `LB_RESETCONTENT` 消息清空所有列表项，清空后 `getSelectedIndex()` 返回 -1。

#### Scenario: 清空列表
- **WHEN** ListBox 有 3 个选项，用户点"清空全部"按钮调用 `clear()`
- **THEN** 列表框立即变为空，`getSelectedIndex()` 返回 -1

### Requirement: Table 操作列按钮按文字尺寸渲染
`drawCellButton` SHALL 用 `DrawTextW(DT_CALCRECT)` 预测量按钮文字宽度，按钮宽度 = 文字宽度 + 左右 padding（各 8px），按钮在单元格内居中显示。文字超出单元格宽度时加 `DT_END_ELLIPSIS` 省略号截断。

#### Scenario: 按钮文字"查看"在 90px 列中
- **WHEN** 操作列宽度 90px，按钮文字"查看"（2 个中文字符，约 28px）
- **THEN** 按钮宽度约 44px（28+16 padding），在 90px 单元格内居中显示，文字完整不溢出

#### Scenario: 按钮文字过长
- **WHEN** 按钮文字"查看详细信息"超出列宽
- **THEN** 文字用省略号截断为"查看详细信…"，不溢出按钮边框

### Requirement: Table 按列单独指定宽度
`Table::setColumns(array $columnTexts, int|array $columnWidth = 100)` SHALL 支持第二个参数传数组，按列单独指定宽度。传 int 时保持原行为（所有列统一宽度）。新增 `Table::setColumnWidth(int $col, int $width)` 可在 `setColumns` 后单独调整某列宽度。

#### Scenario: 按列指定宽度
- **WHEN** 调用 `setColumns(['图标','名称','操作'], [40, 200, 80])`
- **THEN** 图标列 40px，名称列 200px，操作列 80px

#### Scenario: 向后兼容 int 参数
- **WHEN** 调用 `setColumns(['图标','名称'], 100)`
- **THEN** 所有列统一 100px，行为与修复前一致

### Requirement: UxTheme API 声明扩展
`WindowsPlatform` 的 `UXTHEME_HEADER` FFI 声明 SHALL 扩展为包含以下函数：
- `HRESULT SetWindowTheme(HWND hwnd, LPCWSTR pszSubAppName, LPCWSTR pszSubIdList)`
- `void* OpenThemeData(HWND hwnd, LPCWSTR pszClassList)`
- `HRESULT CloseThemeData(void* hTheme)`
- `HRESULT DrawThemeBackground(void* hTheme, HDC hdc, int iPartId, int iStateId, const RECT* pRect, const RECT* pClipRect)`
- `HRESULT DrawThemeText(void* hTheme, HDC hdc, int iPartId, int iStateId, LPCWSTR pszText, int iCharCount, DWORD dwTextFlags, DWORD dwTextFlags2, const RECT* pRect)`
- `BOOL IsThemeActive(void)`
- `BOOL IsAppThemed(void)`

#### Scenario: 主题不可用时回退
- **WHEN** 系统主题被禁用（`IsThemeActive()` 返回 false）或 CLASSIC 主题模式
- **THEN** 自绘控件回退到原 `DrawFrameControl` 行为，不崩溃

### Requirement: 控件创建时应用 Explorer 主题
`controlCreate` 在创建控件后，SHALL 根据 `className` 调用 `SetWindowTheme(hwnd, "Explorer", NULL)`：
- `SysListView32`：Explorer 主题（去 3D 边框、行 hover 高亮、现代表头）
- `SysTreeView32`：Explorer 主题
- 其他控件类：保持默认（不调 `SetWindowTheme`）

CLASSIC 主题模式下 SHALL 跳过 `SetWindowTheme` 调用。

#### Scenario: Win11 下 ListView 外观
- **WHEN** 在 Win11 系统创建 Table（SysListView32），主题非 CLASSIC
- **THEN** ListView 应用 Explorer 主题，表头扁平化、行 hover 高亮、无 3D 边框

### Requirement: 表格自绘控件主题化
`drawCellCheckbox` / `drawCellButton` / `drawCellProgress` SHALL 在主题激活时（`IsThemeActive()` 返回 true 且非 CLASSIC 主题）改用 `DrawThemeBackground` + `DrawThemeText` 绘制：
- checkbox：`OpenThemeData(hwnd, "Button")` → `DrawThemeBackground(hTheme, hdc, BP_CHECKBOX, CBS_CHECKED_NORMAL/CBS_UNCHECKED_NORMAL, &rc, NULL)`
- button：`OpenThemeData(hwnd, "Button")` → `DrawThemeBackground(hTheme, hdc, BP_PUSHBUTTON, PBS_NORMAL/PBS_HOT/PBS_PRESSED, &btnRect, NULL)` + `DrawThemeText(...)`
- progress：`OpenThemeData(hwnd, "Progress")` → `DrawThemeBackground(hTheme, hdc, PP_BAR/PP_FILL, ...)`

每次绘制后 SHALL `CloseThemeData` 释放主题句柄。主题不可用时回退到 `DrawFrameControl`。

#### Scenario: Win11 下表格 checkbox 外观
- **WHEN** 在 Win11 系统查看表格 checkbox 列，主题激活
- **THEN** checkbox 显示为 Win11 原生复选框外观（圆角、主题色填充），而非 Win95 经典灰方框

#### Scenario: CLASSIC 主题回退
- **WHEN** 设置 `App::setTheme(Theme::CLASSIC)` 后查看表格
- **THEN** checkbox/button/progress 回退到 `DrawFrameControl` 经典外观

## MODIFIED Requirements

### Requirement: SpinBox onChanged 触发机制
原 `dispatchWmNotify` 的 `UDN_DELTAPOS` 分支在"即将变更"阶段同步触发 `onChanged`，导致回调内 `getValue()` 重入 UpDown 干扰变更。修改为：`UDN_DELTAPOS` 允许变更完成（不阻断），回调延迟到变更完成后触发（通过 buddy Edit `EN_CHANGE` 或 `queueMain` 异步）。

### Requirement: enableVisualStyles 视觉样式启用
原 `enableVisualStyles` 仅通过运行时 manifest 激活 ComCtl32 v6。修改为：manifest 激活后，`controlCreate` 对特定控件类追加 `SetWindowTheme(hwnd, "Explorer", NULL)` 调用，使 ListView/TreeView 获得 Win11 Explorer 现代外观。manifest 机制本身不变。

### Requirement: drawCell* 自绘方法
原 `drawCellCheckbox` / `drawCellButton` / `drawCellProgress` 使用 `DrawFrameControl`（Win95 API，不响应主题）。修改为：主题激活时优先用 `DrawThemeBackground` + `DrawThemeText`，主题不可用时回退到 `DrawFrameControl`。

## REMOVED Requirements
（无）
