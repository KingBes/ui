# 批次 2 进度与续作笔记

> 本文件用于跨机器续作。上次工作在 Windows 主机完成，下次可在另一台机器（包括非 Windows）继续。
> 时间：2026-07-20

## 一、总目标回顾

使用 `kingbes/phpc` 库的 FFI 机制调用系统原生 GUI 动态库，编写跨平台 PHP GUI 库（`Kingbes\Ui` 命名空间）。
三平台后端：Windows (user32.dll) + Linux (GTK3) + macOS (Cocoa/ObjC runtime)。
仅以 `C:\Users\kllxs\Desktop\libui-ng-master` 作 API 参考，**不依赖 libui-ng**。
全部功能都做：布局容器 / 数值选择控件 / 标准对话框 / 菜单 / Window·Control·App 扩展 / 高级功能（Area+绘图+富文本+FontButton+ColorButton+Image+Table）。

按 7 批次推进。本次完成了 **批次 2 调试 + 包装类**，未完成 **批次 2 跨平台后端 + 测试验证**。

## 二、批次 2 完成情况

### 已完成（本次会话）

1. **修复 WindowsPlatform.php 两处已知 bug**
   - `private array $spinboxRanges = [];` 属性声明（在第 82 行附近，紧跟 `$radioChildToParent`）
   - `comboboxAppend` / `comboboxInsertAt` / `multilineEntryAppend` 三处的 CData→LPARAM 转换
     模式：`$lParam = (int) $this->user32->cast('LPARAM', \FFI::addr($buf))->cdata;` 再传 `$lParam`

2. **创建 10 个 PHP 包装类**（在 `src/` 下）
   - `Spinbox.php` / `Slider.php` / `ProgressBar.php` / `Combobox.php` / `EditableCombobox.php`
   - `RadioButtons.php` / `MultilineEntry.php` / `PasswordEntry.php` / `SearchEntry.php` / `DateTimePicker.php`
   - 全部遵循既有模式：继承 `Control`，构造调 `platform()->xxxCreate()`，setter 返回 `static` 支持链式
   - 11 个文件（含 WindowsPlatform.php）`php -l` 全部通过

3. **创建测试示例** `examples/controls_test.php`（语法检查通过）

### 未完成（明天续作）

#### A. 已知未修复 bug —— 必须先修

**位置**：`src/Platform/Windows/WindowsPlatform.php` 第 2349-2351 行 和 2373-2375 行

**问题**：DateTimePicker 的 `dateTimePickerGetTime` / `dateTimePickerSetTime` 仍直接把 `\FFI::addr($st)` 作为 LPARAM 传给 SendMessageA，触发 `FFI\CData could not be converted to int` 警告 + 0xC0000005 访问违规崩溃。

**修复方式**（与 insertTabItem / comboboxAppend 相同模式）：

```php
// dateTimePickerGetTime（第 2349 行附近）
public function dateTimePickerGetTime(mixed $h): int
{
    $st = $this->user32->new('SYSTEMTIME');
    $lParam = (int) $this->user32->cast('LPARAM', \FFI::addr($st))->cdata;
    $r = SafeCall::invoke($this->user32, 'SendMessageA', [
        $h, 0x1001 /*DTM_GETSYSTEMTIME*/, 0, $lParam
    ]);
    if ((int)$r !== 0 /*GDT_VALID*/) {
        return time();
    }
    return mktime(
        (int)$st->wHour, (int)$st->wMinute, (int)$st->wSecond,
        (int)$st->wMonth, (int)$st->wDay, (int)$st->wYear
    );
}

// dateTimePickerSetTime（第 2361 行附近）
public function dateTimePickerSetTime(mixed $h, int $t): void
{
    $arr = getdate($t);
    $st = $this->user32->new('SYSTEMTIME');
    $st->wYear = $arr['year'];
    $st->wMonth = $arr['mon'];
    $st->wDay = $arr['mday'];
    $st->wHour = $arr['hours'];
    $st->wMinute = $arr['minutes'];
    $st->wSecond = $arr['seconds'];
    $st->wDayOfWeek = $arr['wday'];
    $st->wMilliseconds = 0;
    $lParam = (int) $this->user32->cast('LPARAM', \FFI::addr($st))->cdata;
    SafeCall::invoke($this->user32, 'SendMessageA', [
        $h, 0x1002 /*DTM_SETSYSTEMTIME*/, 0 /*GDT_VALID*/, $lParam
    ]);
}
```

**关键经验**：所有 `SendMessageA($h, $msg, $w, $lParam)` 调用，只要第 4 参数是 `\FFI::addr($xxx)`（即 CData 指针），都必须先 cast 为 LPARAM 取整数值。已经修复的位置可作参考：`insertTabItem`（line 1234）、`comboboxAppend`（line 1974）、`comboboxInsertAt`（line 1989）、`multilineEntryAppend`（line 2247）。
**全文件再次排查命令**：`grep -n "FFI::addr" src/Platform/Windows/WindowsPlatform.php` 然后逐一确认每个 `\FFI::addr` 是否作为 LPARAM 传入 SendMessageA。如果是其它函数（如 GetWindowRect(HWND, RECT*)）的参数则无需修复，FFI 会按声明的指针类型自动转换。

#### B. 待运行验证

修复 A 后运行：
```
php -d ffi.enable=true -f examples/controls_test.php
```
期望：无 warning，窗口打开，5 个 Tab 都能交互。

#### C. 待实现（Linux / macOS 后端）

`src/Platform/Linux/GtkPlatform.php` 和 `src/Platform/Macos/CocoaPlatform.php` 都需实现批次 2 的全部抽象方法（约 50 个）。具体方法签名见 `src/Platform/Platform.php` 第 700 行起。

GTK3 对应控件：
- Spinbox → `GtkSpinButton`
- Slider → `GtkScale`（横向 `GtkOrientation::HORIZONTAL`）
- ProgressBar → `GtkProgressBar`（`gtk_progress_bar_pulse()` 实现 -1 不确定动画）
- Combobox → `GtkComboBoxText`（`gtk_combo_box_text_new()`，不可编辑）
- EditableCombobox → `GtkComboBoxText` with `has-entry` 属性 = true
- RadioButtons → `GtkRadioButton` 组（首项 `gtk_radio_button_new(NULL)`，后续 `gtk_radio_button_new_from_widget(first)`）
- MultilineEntry → `GtkTextView` 包在 `GtkScrolledWindow` 内
- PasswordEntry → `GtkEntry` + `gtk_entry_set_visibility(entry, FALSE)`
- SearchEntry → `GtkSearchEntry`
- DateTimePicker → GTK 无原生 DTP，可用 `GtkCalendar` 简化实现（仅日期）

Cocoa 对应控件（参考 libui-ng `darwin_*.m`）：
- Spinbox → `NSStepper` + `NSTextField` 组合
- Slider → `NSSlider`
- ProgressBar → `NSProgressIndicator`
- Combobox → `NSPopUpButton`
- EditableCombobox → `NSComboBox`
- RadioButtons → `NSMatrix` + `NSButtonCell`（`NSRadioButton`）
- MultilineEntry → `NSTextView` 包在 `NSScrollView` 内
- PasswordEntry → `NSSecureTextField`
- SearchEntry → `NSSearchField`
- DateTimePicker → `NSDatePicker`

Cocoa 实现需要 ObjC runtime FFI（`objc_msgSend` 等），复杂度高。可暂缓或先做基础占位实现。

## 三、剩余批次（批次 3-7）路线图

### 批次 3：标准对话框
- 在 Platform 抽象基类新增 5 个方法：`openFile` / `saveFile` / `openFolder` / `msgBox` / `msgBoxError`
- Windows 实现：`GetOpenFileNameA` / `GetSaveFileNameA` / `SHBrowseForFolderA` / `MessageBoxA`（comdlg32.dll / shell32.dll）
- Linux 实现：`gtk_file_chooser_dialog_new` / `gtk_message_dialog_new`
- macOS 实现：`NSOpenPanel` / `NSSavePanel` / `NSAlert`

### 批次 4：菜单 Menu / MenuItem
- Platform 新增：`menuCreate` / `menuAppendItem` / `menuAppendSeparator` / `menuAppendCheckItem` / `menuAppendQuitItem` / `menuAppendPreferencesItem` / `menuAppendAboutItem` / `menuItemEnable` / `menuItemOnClicked` 等
- Windows 实现：`CreateMenu` / `AppendMenuA` / `WM_COMMAND` 路由
- Linux 实现：`GtkMenuBar` / `GtkMenuItem`
- macOS 实现：`NSMenu` / `NSMenuItem`（需 application menu 规范）

### 批次 5：Window / Control / App 扩展
- Window：`setFullscreen` / `setBorderless` / `setResizeable` / `setMargined` / `setContentSize` / `isFocused`
- Control：`getParent` / `setParent` / `isToplevel` / `isVisible` / `isEnabled`（getter）
- App：`queueMain` / `onShouldQuit`

### 批次 6：高级功能
- Area + 2D 绘图（`AreaHandler` 接口：draw / mouse / key）
- 富文本（`AttributedString` + `drawText`）
- `FontButton` / `ColorButton`
- `Image`（PNG/JPEG 加载）
- `Table`（`TableModel` + `TableParams`）

### 批次 7：扩展 full_test.php
覆盖所有新增 API，确保 full_test.php 仍是回归测试基准。

## 四、关键工程约束（已固化）

- **Win64 LLP64**：所有 `LPARAM` / `WPARAM` / `LONG_PTR` / `LRESULT` 必须用 `long long` 而非 `long`（long 只有 4 字节，会截断 8 字节指针）
- **CData→LPARAM 模式**：`$lParam = (int) $this->user32->cast('LPARAM', \FFI::addr($ptr))->cdata;`
- **UTF-8↔GBK 编码**：所有 Windows A 系列 API 调用前用 `toAnsi()` 转换；读出用 `fromAnsi()`
- **WindowProc 必须 PostQuitMessage(0)**：否则窗口关闭后进程不退出
- **HWND 标识**：用 `hwndInt()` 方法（区分指针类型与数组类型，详见 WindowsPlatform.php）
- **App::$windows 引用表**：防止 `App::run` 闭包内 Window 被 GC 回收导致闪退
- **闭包注册表**：所有 On*/timer 回调闭包存入 Platform 后端 `$closures` / `$timers` 数组防 GC
- **占位 handle 模式**：Box/Form/Grid/RadioButtons 在 Windows 无原生对应，用 `int[1]` CData 作占位 handle，PHP 端维护布局状态
- **RadioButtons 互斥**：首项加 `WS_GROUP(0x00020000)` + `BS_AUTORADIOBUTTON(0x9)` 实现自动互斥
- **bny php 包装器可能禁用 FFI**：测试时直接用 `php` 命令，不要用 bny 包装

## 五、文件清单（批次 2 相关）

修改：
- `src/Platform/Platform.php`（批次 2 抽象方法，约 50 个，第 700 行起）
- `src/Platform/Windows/WindowsPlatform.php`（批次 2 Windows 实现，约 600 行新增）

新增：
- `src/Spinbox.php`
- `src/Slider.php`
- `src/ProgressBar.php`
- `src/Combobox.php`
- `src/EditableCombobox.php`
- `src/RadioButtons.php`
- `src/MultilineEntry.php`
- `src/PasswordEntry.php`
- `src/SearchEntry.php`
- `src/DateTimePicker.php`
- `examples/controls_test.php`

待修改（未开始）：
- `src/Platform/Linux/GtkPlatform.php`
- `src/Platform/Macos/CocoaPlatform.php`

## 六、明天续作的最小步骤

1. **修复 DTP bug**（见二·A，5 分钟）
2. **运行测试** `php -d ffi.enable=true -f examples/controls_test.php` 验证 5 个 Tab 都正常
3. **排查 WindowsPlatform.php 是否还有其它 SendMessageA + `\FFI::addr` 漏网**（用 `grep -n "FFI::addr" src/Platform/Windows/WindowsPlatform.php`）
4. 选择下一步：
   - 4a. 实现 GtkPlatform 批次 2（如目标机器是 Linux 可即时验证）
   - 4b. 实现 CocoaPlatform 批次 2（如目标机器是 macOS 可即时验证）
   - 4c. 直接进入批次 3（标准对话框，Windows 端先实现可即时验证）
5. 进入批次 3-7 按既定路线推进
