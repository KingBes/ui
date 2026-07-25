<?php
declare(strict_types=1);

namespace Kingbes\Ui;

use Kingbes\Ui\Exception\UnsupportedPlatformException;
use Kingbes\Ui\Platform\PlatformInterface;

/**
 * 应用静态门面。
 *
 * 上层 API 通过 App 静态方法访问平台能力，避免直接持有 PlatformInterface
 * 实例。App 在首次访问时按 PHP_OS_FAMILY 惰性创建对应平台后端：
 *   - Windows → WindowsPlatform（批次 2 实现）
 *   - Linux   → GtkPlatform（批次 8 实现）
 *   - Darwin  → CocoaPlatform（批次 8 实现）
 *   - 其他    → 抛 UnsupportedPlatformException
 *
 * 注意：WindowsPlatform/GtkPlatform/CocoaPlatform 在后续批次创建，
 * platform() 用 class_exists 检查可用性，未实现时抛出明确异常。
 */
final class App
{
    /**
     * 已初始化的平台实例。
     */
    private static ?PlatformInterface $platform = null;

    /**
     * 当前主题（App::run 启动前可通过 setTheme 修改）。
     *
     * 默认 Theme::SYSTEM（启用视觉样式，跟随系统深浅色）。
     * 平台首次创建时（platform() 方法内）应用此值。
     */
    private static string $theme = Theme::SYSTEM;

    /**
     * 平台类名映射表。
     *
     * 键为 PHP_OS_FAMILY，值为对应平台实现类的完全限定名。
     */
    private const PLATFORM_MAP = [
        'Windows' => \Kingbes\Ui\Platform\Windows\WindowsPlatform::class,
        'Linux'   => \Kingbes\Ui\Platform\Linux\GtkPlatform::class,
        'Darwin'  => \Kingbes\Ui\Platform\Mac\CocoaPlatform::class,
    ];

    /**
     * 禁止实例化（纯静态类）。
     */
    private function __construct()
    {
    }

    /**
     * 获取或惰性创建当前平台的实例。
     *
     * @return PlatformInterface
     * @throws UnsupportedPlatformException 当前平台无可用后端实现时抛出。
     */
    public static function platform(): PlatformInterface
    {
        if (self::$platform !== null) {
            return self::$platform;
        }
        $os = \PHP_OS_FAMILY;
        $class = self::PLATFORM_MAP[$os] ?? null;
        if ($class === null) {
            throw UnsupportedPlatformException::forOs(
                "No platform backend registered for this OS family", $os
            );
        }
        // 平台实现类在后续批次创建；class_exists 触发自动加载
        if (!class_exists($class)) {
            throw UnsupportedPlatformException::forOs(
                sprintf(
                    "Platform backend class '%s' is not available; "
                    . "the corresponding backend has not been implemented yet",
                    $class
                ),
                $os
            );
        }
        /** @var PlatformInterface $class */
        self::$platform = new $class();
        // 平台创建后立即应用主题（必须在任何窗口创建/控件创建之前）
        self::$platform->setAppTheme(self::$theme);
        self::$platform->enableVisualStyles();
        return self::$platform;
    }

    /**
     * 显式注入平台实例（主要用于测试与自定义后端注入）。
     *
     * 调用后 platform() 将直接返回注入的实例，跳过自动选择。
     */
    public static function setPlatform(PlatformInterface $platform): void
    {
        self::$platform = $platform;
    }

    /**
     * 重置平台实例（主要用于测试隔离）。
     */
    public static function resetPlatform(): void
    {
        self::$platform = null;
    }

    /**
     * 进入事件循环（阻塞直到 quit）。
     */
    public static function run(): void
    {
        self::platform()->run();
    }

    /**
     * 退出事件循环。若注册了 onShouldQuit 回调且返回 false，则不退出。
     */
    public static function quit(): void
    {
        self::platform()->quit();
    }

    /**
     * 注册退出确认回调。
     *
     * 回调签名：fn(): bool；返回 false 阻止退出。传 null 清除回调。
     */
    public static function onShouldQuit(?callable $cb): void
    {
        $closure = $cb === null ? null : \Closure::fromCallable($cb);
        self::platform()->onShouldQuit($closure);
    }

    /**
     * 投递闭包到主线程，下一轮事件循环执行。
     */
    public static function queueMain(\Closure $fn): void
    {
        self::platform()->queueMain($fn);
    }

    /**
     * 注册周期性定时器。
     *
     * @param int      $intervalMs 间隔（毫秒）。
     * @param callable $cb         回调，签名 fn(int $id): void。
     * @return int 定时器 ID（用于 clearTimer）。
     */
    public static function timer(int $intervalMs, callable $cb): int
    {
        return self::platform()->timer($intervalMs, \Closure::fromCallable($cb));
    }

    /**
     * 取消定时器。
     */
    public static function clearTimer(int $id): void
    {
        self::platform()->clearTimer($id);
    }

    /**
     * 设置主题。
     *
     * 必须在 App::run() 之前、且在任何 Window/Control 创建之前调用（因为
     * platform() 惰性创建时会立即应用主题，平台首次创建后再修改无效）。
     *
     * 合法值：Theme::SYSTEM / Theme::CLASSIC / Theme::DARK / Theme::LIGHT。
     * 非法值抛 InvalidArgumentException。
     *
     * 平台已初始化后调用会触发 E_USER_WARNING（主题不会重新应用，需重启进程）。
     *
     * @param string $theme 主题常量（见 Theme 类）。
     * @throws \InvalidArgumentException 非法主题值时抛出。
     */
    public static function setTheme(string $theme): void
    {
        if (!Theme::isValid($theme)) {
            throw new \InvalidArgumentException(
                "Invalid theme '{$theme}'; expected one of: "
                . implode(', ', [Theme::SYSTEM, Theme::CLASSIC, Theme::DARK, Theme::LIGHT])
            );
        }
        if (self::$platform !== null) {
            trigger_error(
                "App::setTheme() called after platform initialization; "
                . "theme change has no effect, restart the process to apply new theme",
                \E_USER_WARNING
            );
        }
        self::$theme = $theme;
    }

    /**
     * 获取当前主题。
     */
    public static function getTheme(): string
    {
        return self::$theme;
    }
}
