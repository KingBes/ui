<?php
declare(strict_types=1);

namespace Kingbes\Ui;

/**
 * 进度条控件。
 *
 * 值范围为 0-100；设为 -1 表示不确定动画（-marquee）。
 */
class ProgressBar extends Control
{
    /**
     * 创建进度条。
     */
    public function __construct()
    {
        $this->handle = static::platform()->progressBarCreate();
    }

    /**
     * 获取当前值（0-100）。
     *
     * @return int 当前值
     */
    public function getValue(): int
    {
        return static::platform()->progressBarGetValue($this->handle);
    }

    /**
     * 设置当前值（0-100，-1 表示不确定动画）。
     *
     * @param int $value 值
     * @return static 当前实例（支持链式调用）
     */
    public function setValue(int $value): static
    {
        static::platform()->progressBarSetValue($this->handle, $value);
        return $this;
    }
}
