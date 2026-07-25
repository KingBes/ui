<?php
declare(strict_types=1);

namespace Kingbes\Ui\Platform;

use Kingbes\Ui\Geometry\Point;
use Kingbes\Ui\Geometry\Size;
use Kingbes\Ui\Graphics\Color;

/**
 * 平台后端契约。
 *
 * Windows / GTK / Cocoa 三个后端都将实现此接口。
 *
 * 设计要点：
 *   - 所有窗口/控件/菜单句柄统一用 `int` 传递。FFI CData 指针不能
 *     跨作用域 cast（PHP 8.5+ 静态 cast 已废弃），因此平台实现内部
 *     用 INT_TO_PTR 联合体完成 int↔HWND 转换，对外只暴露 int。
 *   - DrawContext（批次 6 创建）在接口里以 `mixed` 表示，避免前向
 *     引用未定义类导致语法错误。
 *   - 上层 Widget/Window/Layout 只依赖此接口，不直接调用 FFI。
 */
interface PlatformInterface
{
    // ============================================================
    // 窗口方法
    // ============================================================

    /**
     * 创建顶层窗口。
     *
     * @return int 窗口句柄 hwnd。
     */
    public function windowCreate(string $title, int $width, int $height): int;

    /**
     * 销毁窗口（及其所有子控件）。
     */
    public function windowDestroy(int $hwnd): void;

    public function windowSetTitle(int $hwnd, string $title): void;
    public function windowGetTitle(int $hwnd): string;

    public function windowSetPosition(int $hwnd, int $x, int $y): void;
    public function windowGetPosition(int $hwnd): Point;

    public function windowSetSize(int $hwnd, int $width, int $height): void;
    public function windowGetSize(int $hwnd): Size;

    /**
     * 获取窗口客户区尺寸（不含标题栏/边框）。
     */
    public function windowGetClientSize(int $hwnd): Size;

    public function windowSetFullscreen(int $hwnd, bool $fullscreen): void;
    public function windowSetBorderless(int $hwnd, bool $borderless): void;
    public function windowSetResizeable(int $hwnd, bool $resizeable): void;

    public function windowMaximize(int $hwnd): void;
    public function windowMinimize(int $hwnd): void;
    public function windowRestore(int $hwnd): void;

    public function windowShow(int $hwnd): void;
    public function windowHide(int $hwnd): void;

    public function windowSetTopmost(int $hwnd, bool $topmost): void;

    /**
     * 设置窗口的顶层子容器（toplevel=true），由 WM_SIZE 触发重布局。
     */
    public function windowSetChild(int $hwnd, int $childHwnd): void;

    /**
     * 启用窗口垂直滚动条，并指定内容总高度。
     */
    public function windowSetScrollable(int $hwnd, int $contentHeight): void;

    public function windowIsFocused(int $hwnd): bool;

    /**
     * 将菜单栏挂到窗口。
     */
    public function windowSetMenu(int $hwnd, int $menuHwnd): void;

    /**
     * 从 .ico 文件加载窗口图标并设置（大图标 + 小图标）。
     *
     * @param int    $hwnd 窗口句柄。
     * @param string $file .ico 文件路径。
     */
    public function windowSetIconFromFile(int $hwnd, string $file): void;

    /**
     * 设置窗口图标为预定义系统图标。
     *
     * @param int $hwnd   窗口句柄。
     * @param int $iconId IDI_APPLICATION/HAND/QUESTION/EXCLAMATION/ASTERISK。
     */
    public function windowSetIconFromId(int $hwnd, int $iconId): void;

    /**
     * 从 Image 对象设置窗口图标（PNG/JPEG/BMP/GIF/TIFF 任意 GDI+ 格式）。
     *
     * @param int    $hwnd  窗口句柄。
     * @param object $image Kingbes\Ui\Graphics\Image 实例。
     */
    public function windowSetIconFromImage(int $hwnd, object $image): void;

    // ============================================================
    // 系统托盘方法
    // ============================================================

    /**
     * 注册 TrayIcon 实例（用于回调消息分发）。
     */
    public function registerTrayIcon(\Kingbes\Ui\TrayIcon $tray): void;

    /**
     * 注销 TrayIcon 实例。
     */
    public function unregisterTrayIcon(\Kingbes\Ui\TrayIcon $tray): void;

    /**
     * 添加托盘图标到系统托盘（Shell_NotifyIconW NIM_ADD）。
     *
     * @param int    $hwnd    关联窗口句柄（接收回调消息）。
     * @param int    $trayId  托盘 ID（同一窗口内唯一）。
     * @param int    $hiconInt 图标句柄（int）。
     * @param string $tooltip 提示文本。
     */
    public function trayAdd(int $hwnd, int $trayId, int $hiconInt, string $tooltip): void;

    /**
     * 修改托盘图标（NIM_MODIFY）。
     */
    public function trayModify(int $hwnd, int $trayId, int $hiconInt, string $tooltip): void;

    /**
     * 移除托盘图标（NIM_DELETE）。
     */
    public function trayRemove(int $hwnd, int $trayId): void;

    /**
     * 显示气球通知。
     *
     * @param int    $hwnd     关联窗口句柄。
     * @param int    $trayId   托盘 ID。
     * @param string $title    标题。
     * @param string $message  内容。
     * @param int    $type     0=none 1=info 2=warning 3=error。
     * @param int    $timeoutMs 超时毫秒。
     */
    public function trayShowBalloon(int $hwnd, int $trayId, string $title, string $message, int $type, int $timeoutMs): void;

    /**
     * 弹出托盘右键菜单。
     *
     * @param int $hwnd    关联窗口句柄。
     * @param int $menuHwnd 弹出菜单 HMENU。
     */
    public function trayShowContextMenu(int $hwnd, int $menuHwnd): void;

    /**
     * 从 .ico 文件加载图标，返回 HICON int 句柄。
     *
     * @param string $file .ico 文件路径。
     * @param int    $cx   目标宽度（0=原始尺寸）。
     * @param int    $cy   目标高度（0=原始尺寸）。
     */
    public function loadIconFromFile(string $file, int $cx = 0, int $cy = 0): int;

    /**
     * 加载预定义系统图标，返回 HICON int 句柄。
     */
    public function loadSystemIcon(int $iconId): int;

    /**
     * 从 Image 对象创建 HICON（GDI+ GpImage → HICON）。
     *
     * 支持任意 GDI+ 可加载的图像格式（PNG/JPEG/BMP/GIF/TIFF）。
     * 返回的 HICON 调用方需 destroyIconInt 释放。
     *
     * @param object $image Image 对象（Kingbes\Ui\Graphics\Image 实例）。
     * @return int HICON int 句柄，失败返回 0。
     */
    public function iconCreateFromImage(object $image): int;

    /**
     * 通过 int 句柄 DestroyIcon。
     */
    public function destroyIconInt(int $hiconInt): void;

    // ============================================================
    // 控件方法
    // ============================================================

    /**
     * 创建子控件。
     *
     * @param string $className 平台原生控件类名（如 'Button'、'EDIT'）。
     * @param string $text       控件初始文本。
     * @param int    $style      平台样式标志位。
     * @param int    $exStyle    平台扩展样式标志位。
     * @param int    $parentHwnd 父窗口/容器句柄；0 表示无父。
     * @param int    $id         控件 ID（用于事件分发）。
     * @return int 控件句柄 hwnd。
     */
    public function controlCreate(
        string $className,
        string $text,
        int $style,
        int $exStyle,
        int $parentHwnd,
        int $id
    ): int;

    public function controlDestroy(int $hwnd): void;

    public function controlSetText(int $hwnd, string $text): void;
    public function controlGetText(int $hwnd): string;

    public function controlSetBounds(int $hwnd, int $x, int $y, int $width, int $height): void;

    public function controlShow(int $hwnd): void;
    public function controlHide(int $hwnd): void;

    public function controlEnable(int $hwnd, bool $enabled): void;

    public function controlIsChecked(int $hwnd): bool;
    public function controlSetChecked(int $hwnd, bool $checked): void;

    public function controlAddString(int $hwnd, string $text): void;
    public function controlRemoveString(int $hwnd, int $index): void;
    public function controlClear(int $hwnd): void;

    public function controlGetSelectedIndex(int $hwnd): int;
    public function controlSetSelectedIndex(int $hwnd, int $index): void;

    public function controlSetRange(int $hwnd, int $min, int $max): void;
    public function controlSetValue(int $hwnd, int $value): void;
    public function controlGetValue(int $hwnd): int;

    /**
     * 启用/关闭 ProgressBar 不确定状态（marquee 滚动动画）。
     *
     * @param int  $hwnd       ProgressBar 控件句柄。
     * @param bool $enabled    true=启用滚动动画，false=恢复确定状态。
     * @param int  $updateMs   动画更新间隔（毫秒），仅 enabled=true 时生效。
     */
    public function progressBarSetMarquee(int $hwnd, bool $enabled, int $updateMs = 30): void;

    // ============================================================
    // Tab 标签页方法
    // ============================================================

    /**
     * 插入标签项。
     *
     * @param int    $tabHwnd Tab 控件句柄。
     * @param int    $index   插入位置（-1 表示追加到末尾）。
     * @param string $text    标签文本。
     */
    public function tabInsertItem(int $tabHwnd, int $index, string $text): void;

    /**
     * 删除指定索引的标签项。
     */
    public function tabDeleteItem(int $tabHwnd, int $index): void;

    /**
     * 获取当前选中标签索引（-1 表示未选中）。
     */
    public function tabGetSelected(int $tabHwnd): int;

    /**
     * 设置当前选中标签。
     */
    public function tabSetSelected(int $tabHwnd, int $index): void;

    /**
     * 获取标签数量。
     */
    public function tabGetItemCount(int $tabHwnd): int;

    // ============================================================
    // DateTimePicker 方法
    // ============================================================

    /**
     * 获取当前日期时间。
     *
     * @return array{year:int,month:int,day:int,hour:int,minute:int,second:int}|null
     *     用户未选择（DTS_SHOWNONE）时返回 null。
     */
    public function dateTimePickerGetTime(int $hwnd): ?array;

    /**
     * 设置当前日期时间。
     */
    public function dateTimePickerSetTime(
        int $hwnd,
        int $year,
        int $month,
        int $day,
        int $hour,
        int $minute,
        int $second
    ): void;

    /**
     * 设置自定义显示格式（如 "yyyy-MM-dd HH:mm"）。
     */
    public function dateTimePickerSetFormat(int $hwnd, string $format): void;

    // ============================================================
    // 菜单方法
    // ============================================================

    /**
     * 创建菜单栏。返回 menuHwnd。
     */
    public function menuCreateBar(): int;

    /**
     * 创建弹出菜单（子菜单/上下文菜单）。返回 menuHwnd。
     */
    public function menuCreatePopup(): int;

    public function menuAppendItem(int $menuHwnd, string $text, int $id): void;
    public function menuAppendSeparator(int $menuHwnd): void;
    public function menuAppendSubmenu(int $menuHwnd, string $text, int $submenuHwnd): void;
    public function menuSetEnabled(int $menuHwnd, int $id, bool $enabled): void;
    public function menuSetChecked(int $menuHwnd, int $id, bool $checked): void;
    public function menuDestroy(int $menuHwnd): void;

    // ============================================================
    // 对话框方法
    // ============================================================

    /**
     * 消息框。
     *
     * @param int $type 平台消息框标志位组合（信息/警告/错误/询问）。
     * @return int 用户点击的按钮代码。
     */
    public function dialogMsgBox(int $parentHwnd, string $text, string $caption, int $type): int;

    /**
     * @param array<int,string> $filters 过滤器列表，如 ['*.txt', '*.md']。
     */
    public function dialogOpenFile(int $parentHwnd, array $filters): ?string;
    public function dialogSaveFile(int $parentHwnd, array $filters): ?string;
    public function dialogOpenFolder(int $parentHwnd): ?string;

    public function dialogChooseColor(int $parentHwnd): ?Color;

    /**
     * 字体选择对话框。
     *
     * @return array<string,mixed>|null 字体信息（name/size/style），用户取消返回 null。
     */
    public function dialogChooseFont(int $parentHwnd): ?array;

    // ============================================================
    // 绘图方法
    // ============================================================

    /**
     * 创建自定义绘图区（Area）控件，返回其 hwnd。
     */
    public function areaCreate(int $parentHwnd): int;

    /**
     * 标记绘图区为脏，触发下一帧重绘。
     */
    public function areaInvalidate(int $hwnd): void;

    /**
     * 设置 Area 的虚拟内容尺寸，启用滚动条。
     *
     * 当内容尺寸大于 Area 可视区域时显示 WS_HSCROLL/WS_VSCROLL 滚动条。
     * 调用此方法后 onDraw 回调收到的 DrawContext 已应用滚动偏移，
     * 用户按内容坐标系（0,0 到 contentW,contentH）绘制即可。
     * 传 (0, 0) 关闭滚动条。
     *
     * @param int $hwnd        Area 控件句柄。
     * @param int $contentW    内容总宽度（像素）。
     * @param int $contentH    内容总高度（像素）。
     */
    public function areaSetScrollable(int $hwnd, int $contentW, int $contentH): void;

    /**
     * 程序化滚动 Area 到指定内容坐标。
     *
     * @param int $hwnd Area 控件句柄。
     * @param int $x    目标 x（内容坐标系，自动夹取到有效范围）。
     * @param int $y    目标 y（内容坐标系，自动夹取到有效范围）。
     */
    public function areaScrollTo(int $hwnd, int $x, int $y): void;

    /**
     * 获取 Area 当前滚动位置。
     *
     * @return array{x:int, y:int} 滚动偏移（内容坐标系）。
     */
    public function areaGetScrollPos(int $hwnd): array;

    /**
     * 设置 ListView 扩展样式（LVS_EX_FULLROWSELECT 等）。
     *
     * @param int $hwnd    ListView 句柄。
     * @param int $exStyle 扩展样式位掩码。
     */
    public function tableSetExtendedStyle(int $hwnd, int $exStyle): void;

    /**
     * 插入一列。
     *
     * @param int    $hwnd  ListView 句柄。
     * @param int    $index 列索引（0-based）。
     * @param string $text  列标题。
     * @param int    $width 列宽（像素）。
     */
    public function tableInsertColumn(int $hwnd, int $index, string $text, int $width): void;

    /**
     * 清空所有列。
     */
    public function tableClearColumns(int $hwnd): void;

    /**
     * 设置虚拟模式行数（LVM_SETITEMCOUNT）。
     */
    public function tableSetItemCount(int $hwnd, int $count): void;

    /**
     * 刷新整张表（重绘所有可见行）。
     */
    public function tableRefresh(int $hwnd): void;

    /**
     * 刷新指定行（LVM_REDRAWITEMS）。
     */
    public function tableRefreshRow(int $hwnd, int $row): void;

    /**
     * 选中指定行并滚动到可见。
     *
     * @param int $row 行索引，-1 取消选中。
     */
    public function tableSelectRow(int $hwnd, int $row): void;

    /**
     * 获取当前选中行索引。
     *
     * @return int 行索引，-1 表示无选中。
     */
    public function tableGetSelectedRow(int $hwnd): int;

    /**
     * 设置某行背景色（NM_CUSTOMDRAW 着色）。
     *
     * @param int      $row   行索引。
     * @param int|null $color ARGB（0xAARRGGBB），null 清除。
     */
    public function tableSetRowBgColor(int $hwnd, int $row, ?int $color): void;

    /**
     * 设置某行文字颜色。
     *
     * @param int      $row   行索引。
     * @param int|null $color ARGB，null 清除。
     */
    public function tableSetRowTextColor(int $hwnd, int $row, ?int $color): void;

    // ============================================================
    // ImageList 通用 API（Table / Tab 共用）
    // ============================================================

    /**
     * 创建 ImageList，返回 int 句柄。
     *
     * @param int $cx       图像宽度（像素）。
     * @param int $cy       图像高度（像素）。
     * @param int $cInitial 初始容量。
     * @param int $cGrow    增长量。
     */
    public function imageListCreate(int $cx, int $cy, int $cInitial = 4, int $cGrow = 4): int;

    /**
     * 将 Image 添加到 ImageList，返回图像索引。
     *
     * @param int                                $imageListId ImageList 句柄。
     * @param \Kingbes\Ui\Graphics\Image          $image       图像对象。
     * @return int 图像索引（0-based），-1 失败。
     */
    public function imageListAddImage(int $imageListId, \Kingbes\Ui\Graphics\Image $image): int;

    /**
     * 销毁 ImageList。
     */
    public function imageListDestroy(int $imageListId): void;

    /**
     * 将 ImageList 关联到 ListView（LVSIL_SMALL 槽位）。
     */
    public function tableSetImageList(int $hwnd, int $imageListId): void;

    // ============================================================
    // 控件图像支持（Button / Label / Tab / MenuItem）
    // ============================================================

    /**
     * 将 GDI+ GpImage 转换为 HBITMAP（int 句柄返回）。
     *
     * 调用方负责通过 deleteGdiObjectInt() 释放。
     *
     * @param \FFI\CData $gpImage gdiplus 作用域的 GpImage CData。
     * @return int HBITMAP 的 int 表示，0 失败。
     */
    public function gdipImageToHbitmapInt(\FFI\CData $gpImage): int;

    /**
     * 通过 int 句柄 DeleteObject 释放 GDI 对象。
     */
    public function deleteGdiObjectInt(int $hObj): void;

    /**
     * 读取窗口/控件样式（GWL_STYLE）。
     */
    public function controlGetStyle(int $hwnd): int;

    /**
     * 设置窗口/控件样式（GWL_STYLE）。
     */
    public function controlSetStyle(int $hwnd, int $style): void;

    /**
     * 设置 Button 的图像（BM_SETIMAGE，IMAGE_BITMAP）。
     *
     * @param int $hwnd    Button 句柄。
     * @param int $hbmInt  HBITMAP 的 int 句柄。
     */
    public function buttonSetImage(int $hwnd, int $hbmInt): void;

    /**
     * 设置 Static（Label）控件的图像（STM_SETIMAGE，IMAGE_BITMAP）。
     *
     * 要求 Label 创建时使用 SS_BITMAP 样式。
     *
     * @param int $hwnd   Static 控件句柄。
     * @param int $hbmInt HBITMAP 的 int 句柄。
     * @return int 旧图像的 int 句柄（需调用方释放），0 表示无旧图像。
     */
    public function labelSetImage(int $hwnd, int $hbmInt): int;

    /**
     * 将 ImageList 关联到 Tab 控件（TCM_SETIMAGELIST）。
     */
    public function tabSetImageList(int $hwnd, int $imageListId): void;

    /**
     * 设置 Tab 某页签的图像索引（TCM_SETITEMW，TCIF_IMAGE）。
     *
     * @param int $hwnd       Tab 控件句柄。
     * @param int $pageIndex  页签索引（0-based）。
     * @param int $imageIndex ImageList 中的图像索引，-1 清除。
     */
    public function tabSetItemImage(int $hwnd, int $pageIndex, int $imageIndex): void;

    /**
     * 设置菜单项的位图图标（SetMenuItemInfoW，MIIM_BITMAP）。
     *
     * @param int $menuHwnd 菜单 HMENU 的 int 句柄。
     * @param int $itemId   菜单项 ID。
     * @param int $hbmInt   HBITMAP 的 int 句柄，0 清除图标。
     */
    public function menuSetItemBitmap(int $menuHwnd, int $itemId, int $hbmInt): void;

    /**
     * 创建绘图上下文（包装平台 DC）。
     *
     * 返回值用 mixed 表示；具体平台返回 DrawContext 实例（批次 6 创建）。
     *
     * @return mixed DrawContext 实例。
     */
    public function drawContextCreate(int $hwnd): mixed;

    /**
     * 释放绘图上下文（恢复 GDI 对象栈、EndPaint 等）。
     *
     * @param mixed $ctx DrawContext 实例。
     */
    public function drawContextFree(mixed $ctx): void;

    /**
     * @param mixed $ctx DrawContext 实例。
     */
    public function drawLine(mixed $ctx, int $x1, int $y1, int $x2, int $y2): void;
    public function drawRect(mixed $ctx, int $x, int $y, int $width, int $height): void;
    public function drawEllipse(mixed $ctx, int $x, int $y, int $width, int $height): void;
    public function drawText(mixed $ctx, int $x, int $y, string $text): void;

    /**
     * 绘制富文本（AttributedString，批次 6 创建）。
     *
     * @param int $attributedStringId 富文本对象 ID。
     */
    public function drawTextAttributed(mixed $ctx, int $x, int $y, int $attributedStringId): void;

    public function setPen(mixed $ctx, Color $color, int $width): void;
    public function setBrush(mixed $ctx, Color $color): void;
    public function setFont(mixed $ctx, string $name, int $size): void;
    public function setColor(mixed $ctx, Color $color): void;

    // ============================================================
    // 事件循环
    // ============================================================

    /**
     * 进入事件循环（阻塞直到 quit）。
     */
    public function run(): void;

    /**
     * 退出事件循环。
     */
    public function quit(): void;

    /**
     * 投递闭包到主线程，下一轮事件循环执行。
     *
     * 由 AbstractPlatform 提供共享实现。
     */
    public function queueMain(\Closure $fn): void;

    /**
     * 触发窗口的 toplevel 容器重新布局（异步）。
     */
    public function triggerRelayout(int $hwnd): void;

    // ============================================================
    // 系统服务
    // ============================================================

    /**
     * 屏幕尺寸。
     */
    public function screenSize(): Size;

    public function clipboardSetText(string $text): void;
    public function clipboardGetText(): string;

    // ============================================================
    // 定时器与生命周期（共享实现由 AbstractPlatform 提供）
    // ============================================================

    /**
     * 注册周期性定时器。
     *
     * @param int $intervalMs 间隔（毫秒）。
     * @return int 定时器 ID。
     */
    public function timer(int $intervalMs, \Closure $cb): int;

    /**
     * 取消定时器。
     */
    public function clearTimer(int $id): void;

    /**
     * 注册退出确认回调。回调返回 false 则不退出。
     */
    public function onShouldQuit(?\Closure $cb): void;
}
