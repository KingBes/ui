<?php
declare(strict_types=1);

namespace Kingbes\Ui;

use Kingbes\Ui\Graphics\Color;

/**
 * 对话框服务（静态门面）。
 *
 * 通过 App::platform() 调用平台后端的对话框方法。Windows 后端使用
 * Unicode W 系列 API（MessageBoxW/GetOpenFileNameW/ChooseColorW/
 * ChooseFontW/SHBrowseForFolderW），支持中文与 emoji 文本。
 *
 * 所有模态对话框期间，平台 WindowProc 对非模态消息调 DefWindowProcW
 * 默认处理（inModalDialog 守护），允许底层窗口重绘，不卡死。
 *
 * 用法：
 *   Dialogs::msgBox($win, "保存成功 ✅");
 *   Dialogs::msgBoxError($win, "文件不存在 ❌");
 *   if (Dialogs::msgBoxAsk($win, "确定删除？")) { ... }
 *   $path = Dialogs::openFile($win, ["文本|*.txt", "所有|*.*"]);
 *   $color = Dialogs::chooseColor($win);
 *
 * parent 参数为 null 时 hwndOwner=NULL（无父窗口）。
 */
class Dialogs
{
    // ============================================================
    // MessageBox 类型标志（与 Win32 MB_* 常量一致）
    // ============================================================

    private const MB_OK               = 0x0000;
    private const MB_OKCANCEL         = 0x0001;
    private const MB_YESNO            = 0x0004;
    private const MB_ICONERROR        = 0x0010;
    private const MB_ICONQUESTION     = 0x0020;
    private const MB_ICONWARNING      = 0x0030;
    private const MB_ICONINFORMATION  = 0x0040;

    // MessageBox 返回值
    private const IDOK     = 1;
    private const IDYES    = 6;
    private const IDNO     = 7;

    // ============================================================
    // 消息框
    // ============================================================

    /**
     * 信息消息框（MB_OK | MB_ICONINFORMATION）。
     *
     * @param Window|null $parent   父窗口；null 则无父。
     * @param string      $text     消息文本（支持中文/emoji）。
     * @param string      $caption  标题。
     */
    public static function msgBox(?Window $parent, string $text, string $caption = "提示"): void
    {
        $hwnd = $parent !== null ? $parent->getHwnd() : 0;
        App::platform()->dialogMsgBox(
            $hwnd,
            $text,
            $caption,
            self::MB_OK | self::MB_ICONINFORMATION
        );
    }

    /**
     * 错误消息框（MB_OK | MB_ICONERROR）。
     */
    public static function msgBoxError(?Window $parent, string $text, string $caption = "错误"): void
    {
        $hwnd = $parent !== null ? $parent->getHwnd() : 0;
        App::platform()->dialogMsgBox(
            $hwnd,
            $text,
            $caption,
            self::MB_OK | self::MB_ICONERROR
        );
    }

    /**
     * 警告消息框（MB_OK | MB_ICONWARNING）。
     */
    public static function msgBoxWarn(?Window $parent, string $text, string $caption = "警告"): void
    {
        $hwnd = $parent !== null ? $parent->getHwnd() : 0;
        App::platform()->dialogMsgBox(
            $hwnd,
            $text,
            $caption,
            self::MB_OK | self::MB_ICONWARNING
        );
    }

    /**
     * 询问消息框（MB_YESNO | MB_ICONQUESTION）。
     *
     * @return bool true=用户点击"是"(IDYES)，false=用户点击"否"(IDNO)。
     */
    public static function msgBoxAsk(?Window $parent, string $text, string $caption = "询问"): bool
    {
        $hwnd = $parent !== null ? $parent->getHwnd() : 0;
        $result = App::platform()->dialogMsgBox(
            $hwnd,
            $text,
            $caption,
            self::MB_YESNO | self::MB_ICONQUESTION
        );
        return $result === self::IDYES;
    }

    // ============================================================
    // 文件对话框
    // ============================================================

    /**
     * 打开文件对话框。
     *
     * @param Window|null        $parent  父窗口。
     * @param array<int,string>  $filters 过滤器列表，元素格式：
     *   - "描述|过滤"  如 "文本文件|*.txt"
     *   - "*.txt"     无 | 时用模式本身作为描述
     * @return string|null 选中文件路径（UTF-8）；取消返回 null。
     */
    public static function openFile(?Window $parent, array $filters = ["所有文件|*.*"]): ?string
    {
        $hwnd = $parent !== null ? $parent->getHwnd() : 0;
        return App::platform()->dialogOpenFile($hwnd, $filters);
    }

    /**
     * 保存文件对话框。
     *
     * @param array<int,string> $filters 过滤器列表，同 openFile。
     * @return string|null 选中文件路径（UTF-8）；取消返回 null。
     */
    public static function saveFile(?Window $parent, array $filters = ["所有文件|*.*"]): ?string
    {
        $hwnd = $parent !== null ? $parent->getHwnd() : 0;
        return App::platform()->dialogSaveFile($hwnd, $filters);
    }

    /**
     * 打开文件夹对话框。
     *
     * @param string $title 对话框标题（注：当前平台接口 dialogOpenFolder
     *   不接收 title 参数，使用平台默认标题"选择文件夹"；此参数保留供
     *   后续接口扩展使用）。
     * @return string|null 选中文件夹路径（UTF-8）；取消返回 null。
     */
    public static function openFolder(?Window $parent, string $title = "选择文件夹"): ?string
    {
        $hwnd = $parent !== null ? $parent->getHwnd() : 0;
        return App::platform()->dialogOpenFolder($hwnd);
    }

    // ============================================================
    // 颜色与字体对话框
    // ============================================================

    /**
     * 颜色选择对话框。
     *
     * @return Color|null 选中的颜色；取消返回 null。
     */
    public static function chooseColor(?Window $parent): ?Color
    {
        $hwnd = $parent !== null ? $parent->getHwnd() : 0;
        return App::platform()->dialogChooseColor($hwnd);
    }

    /**
     * 字体选择对话框。
     *
     * @return array<string,mixed>|null 字体信息：
     *   - 'name'  : string 字体名（如 "Segoe UI"）
     *   - 'size'  : int    字号（磅）
     *   - 'color' : Color  字体颜色
     *   取消返回 null。
     */
    public static function chooseFont(?Window $parent): ?array
    {
        $hwnd = $parent !== null ? $parent->getHwnd() : 0;
        return App::platform()->dialogChooseFont($hwnd);
    }
}
