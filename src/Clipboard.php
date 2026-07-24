<?php
declare(strict_types=1);

namespace Kingbes\Ui;

/**
 * 剪贴板服务（静态门面）。
 *
 * 通过 App::platform() 调用平台后端的剪贴板方法，Windows 后端使用
 * OpenClipboard/EmptyClipboard/SetClipboardData/GetClipboardData（CF_UNICODETEXT），
 * 支持 Unicode 文本（含中文与 emoji）。
 *
 * 用法：
 *   Clipboard::setText("中文测试 🎨");
 *   $text = Clipboard::getText();
 */
class Clipboard
{
    /**
     * 将文本写入系统剪贴板（覆盖原有内容）。
     *
     * @param string $text UTF-8 文本（支持中文/emoji）。
     */
    public static function setText(string $text): void
    {
        App::platform()->clipboardSetText($text);
    }

    /**
     * 从系统剪贴板读取文本。
     *
     * @return string UTF-8 文本；剪贴板无文本内容时返回空字符串。
     */
    public static function getText(): string
    {
        return App::platform()->clipboardGetText();
    }
}
