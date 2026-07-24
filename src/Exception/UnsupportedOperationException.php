<?php
declare(strict_types=1);

namespace Kingbes\Ui\Exception;

/**
 * 当某平台后端未实现接口中的特定方法时抛出。
 *
 * 典型场景：GTK/Cocoa 桩实现中尚未完成的控件或对话框方法。
 * 消息应明确指明哪个方法在哪个后端尚未实现，便于定位。
 */
class UnsupportedOperationException extends UiException
{
    /**
     * 构造时附带方法名与平台标识，便于诊断。
     *
     * @param string $method 未实现的方法名（如 'controlCreate'）
     * @param string $platform 平台标识（如 'Gtk'、'Cocoa'）
     */
    public static function forMethod(string $method, string $platform): static
    {
        return new static(
            sprintf("Method '%s' is not implemented on platform '%s'", $method, $platform)
        );
    }
}
