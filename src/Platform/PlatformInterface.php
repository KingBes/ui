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
