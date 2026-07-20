<?php
declare(strict_types=1);

namespace Kingbes\Ui;

/**
 * 日期时间选择器控件。
 *
 * 时间以 Unix 时间戳（秒）形式读写。
 */
class DateTimePicker extends Control
{
    /**
     * 创建日期时间选择器。
     */
    public function __construct()
    {
        $this->handle = static::platform()->dateTimePickerCreate();
    }

    /**
     * 获取当前选择的时间。
     *
     * @return int Unix 时间戳（秒）
     */
    public function getTime(): int
    {
        return static::platform()->dateTimePickerGetTime($this->handle);
    }

    /**
     * 设置当前选择的时间。
     *
     * @param int $timestamp Unix 时间戳（秒）
     * @return static 当前实例（支持链式调用）
     */
    public function setTime(int $timestamp): static
    {
        static::platform()->dateTimePickerSetTime($this->handle, $timestamp);
        return $this;
    }
}
