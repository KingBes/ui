<?php
declare(strict_types=1);

namespace Kingbes\Ui;

/**
 * 单选按钮组控件。
 *
 * 一组互斥的选项按钮，用户只能选中其中一个。
 */
class RadioButtons extends Control
{
    /**
     * 创建单选按钮组。
     */
    public function __construct()
    {
        $this->handle = static::platform()->radioButtonsCreate();
    }

    /**
     * 追加一个选项。
     *
     * @param string $text 选项文本
     * @return static 当前实例（支持链式调用）
     */
    public function append(string $text): static
    {
        static::platform()->radioButtonsAppend($this->handle, $text);
        return $this;
    }

    /**
     * 获取当前选中项索引，-1 表示无选中。
     *
     * @return int 索引
     */
    public function getSelected(): int
    {
        return static::platform()->radioButtonsGetSelected($this->handle);
    }

    /**
     * 设置当前选中项。
     *
     * @param int $index 索引
     * @return static 当前实例（支持链式调用）
     */
    public function setSelected(int $index): static
    {
        static::platform()->radioButtonsSetSelected($this->handle, $index);
        return $this;
    }

    /**
     * 注册选中变化回调。
     *
     * @param \Closure $cb 回调函数
     * @return static 当前实例（支持链式调用）
     */
    public function onSelected(\Closure $cb): static
    {
        static::platform()->radioButtonsOnSelected($this->handle, $cb);
        return $this;
    }
}
