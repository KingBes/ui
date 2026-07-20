<?php
declare(strict_types=1);

namespace Kingbes\Ui;

/**
 * 分隔线控件。
 *
 * 通过静态工厂 horizontal() / vertical() 创建。
 * 水平分隔线用于在垂直布局中分隔上下控件，
 * 垂直分隔线用于在水平布局中分隔左右控件。
 */
class Separator extends Control
{
    /**
     * 创建分隔线。
     *
     * 构造器为 protected，强制通过静态工厂 horizontal() / vertical()
     * 创建实例。
     *
     * @param bool $horizontal true 为水平分隔线，false 为垂直分隔线
     */
    protected function __construct(bool $horizontal)
    {
        $this->handle = static::platform()->separatorCreate($horizontal);
    }

    /**
     * 创建水平分隔线。
     *
     * @return static 水平 Separator 实例
     */
    public static function horizontal(): static
    {
        return new static(true);
    }

    /**
     * 创建垂直分隔线。
     *
     * @return static 垂直 Separator 实例
     */
    public static function vertical(): static
    {
        return new static(false);
    }
}
