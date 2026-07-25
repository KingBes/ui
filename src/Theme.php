<?php
declare(strict_types=1);

namespace Kingbes\Ui;

/**
 * 主题预设常量容器。
 *
 * 仅作为 App::setTheme() 的入参类型，不含任何平台特定逻辑。
 *
 * 平台支持：
 *   - Windows：完整实现（ComCtl32 v6 视觉样式 + PerMonitorV2 DPI + 深色模式）
 *   - Linux/macOS：空实现，静默忽略，不影响 GTK/Cocoa 自带主题系统
 *
 * 注意：Linux GTK 和 macOS Cocoa 各自有完整的主题系统（GTK Theme / NSAppearance），
 * 后续可独立设计各自的主题 API，不受本类常量语义约束。
 */
final class Theme
{
    /** 跟随系统主题（默认，启用视觉样式） */
    public const SYSTEM = 'system';

    /** 经典风格（不启用视觉样式，保持 ComCtl32 v5 经典灰外观） */
    public const CLASSIC = 'classic';

    /** 深色模式（启用视觉样式 + 强制深色） */
    public const DARK = 'dark';

    /** 浅色模式（启用视觉样式 + 强制浅色） */
    public const LIGHT = 'light';

    /**
     * 所有合法主题值。
     */
    private const ALL = [
        self::SYSTEM,
        self::CLASSIC,
        self::DARK,
        self::LIGHT,
    ];

    /**
     * 禁止实例化（纯常量容器）。
     */
    private function __construct()
    {
    }

    /**
     * 校验主题值是否合法。
     */
    public static function isValid(string $theme): bool
    {
        return in_array($theme, self::ALL, true);
    }
}
