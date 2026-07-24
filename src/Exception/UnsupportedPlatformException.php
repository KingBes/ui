<?php
declare(strict_types=1);

namespace Kingbes\Ui\Exception;

/**
 * 当前平台无可用后端实现时抛出。
 *
 * 例如在 BSD 上启动 App::run() 时，因既无 WindowsPlatform 也不支持
 * GtkPlatform/CocoaPlatform 而抛出。
 */
class UnsupportedPlatformException extends UiException
{
    /**
     * 构造时附带当前 OS 家族信息，便于诊断。
     *
     * @param string $message 异常消息
     * @param string|null $osFamily 当前 PHP_OS_FAMILY；为 null 时由调用方填写
     */
    public static function forOs(string $message, ?string $osFamily = null): static
    {
        $os = $osFamily ?? \PHP_OS_FAMILY;
        return new static(sprintf('%s (current OS family: %s)', $message, $os));
    }
}
