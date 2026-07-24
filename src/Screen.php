<?php
declare(strict_types=1);

namespace Kingbes\Ui;

use Kingbes\Ui\Geometry\Size;

/**
 * 屏幕信息服务（静态门面）。
 *
 * 通过 App::platform()->screenSize() 获取屏幕尺寸，平台后端实现具体逻辑：
 *   - Windows：GetSystemMetrics(SM_CXSCREEN/SM_CYSCREEN)
 *
 * 用法：
 *   $size = Screen::size();          // Size{width, height}
 *   $w = Screen::width();            // 1920
 *   $h = Screen::height();           // 1080
 */
class Screen
{
    /**
     * 获取主屏幕尺寸。
     */
    public static function size(): Size
    {
        return App::platform()->screenSize();
    }

    /**
     * 获取主屏幕宽度（像素）。
     */
    public static function width(): int
    {
        return self::size()->width;
    }

    /**
     * 获取主屏幕高度（像素）。
     */
    public static function height(): int
    {
        return self::size()->height;
    }
}
