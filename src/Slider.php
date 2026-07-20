<?php
declare(strict_types=1);

namespace Kingbes\Ui;

/**
 * 滑块控件。
 *
 * 用户拖动滑块在 min/max 范围内选择整数值。
 */
class Slider extends Control
{
    /**
     * 创建滑块。
     *
     * @param int $min 最小值
     * @param int $max 最大值
     */
    public function __construct(int $min, int $max)
    {
        $this->handle = static::platform()->sliderCreate($min, $max);
    }

    /**
     * 获取当前值。
     *
     * @return int 当前值
     */
    public function getValue(): int
    {
        return static::platform()->sliderGetValue($this->handle);
    }

    /**
     * 设置当前值。
     *
     * @param int $value 值
     * @return static 当前实例（支持链式调用）
     */
    public function setValue(int $value): static
    {
        static::platform()->sliderSetValue($this->handle, $value);
        return $this;
    }

    /**
     * 注册值变化回调。
     *
     * @param \Closure $cb 回调函数
     * @return static 当前实例（支持链式调用）
     */
    public function onChanged(\Closure $cb): static
    {
        static::platform()->sliderOnChanged($this->handle, $cb);
        return $this;
    }
}
