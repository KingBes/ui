<?php
declare(strict_types=1);

namespace Kingbes\Ui;

/**
 * 下拉列表控件（不可编辑）。
 *
 * 用户只能从预置选项中选择一项。
 */
class Combobox extends Control
{
    /**
     * 创建下拉列表。
     */
    public function __construct()
    {
        $this->handle = static::platform()->comboboxCreate();
    }

    /**
     * 末尾追加一个选项。
     *
     * @param string $name 选项文本
     * @return static 当前实例（支持链式调用）
     */
    public function append(string $name): static
    {
        static::platform()->comboboxAppend($this->handle, $name);
        return $this;
    }

    /**
     * 在指定位置插入选项。
     *
     * @param string $name  选项文本
     * @param int    $index 插入位置
     * @return static 当前实例（支持链式调用）
     */
    public function insertAt(string $name, int $index): static
    {
        static::platform()->comboboxInsertAt($this->handle, $name, $index);
        return $this;
    }

    /**
     * 删除指定位置的选项。
     *
     * @param int $index 位置
     * @return static 当前实例（支持链式调用）
     */
    public function delete(int $index): static
    {
        static::platform()->comboboxDelete($this->handle, $index);
        return $this;
    }

    /**
     * 清空所有选项。
     *
     * @return static 当前实例（支持链式调用）
     */
    public function clear(): static
    {
        static::platform()->comboboxClear($this->handle);
        return $this;
    }

    /**
     * 获取选项数量。
     *
     * @return int 数量
     */
    public function numItems(): int
    {
        return static::platform()->comboboxNumItems($this->handle);
    }

    /**
     * 获取当前选中项索引，-1 表示无选中。
     *
     * @return int 索引
     */
    public function getSelected(): int
    {
        return static::platform()->comboboxGetSelected($this->handle);
    }

    /**
     * 设置当前选中项。
     *
     * @param int $index 索引
     * @return static 当前实例（支持链式调用）
     */
    public function setSelected(int $index): static
    {
        static::platform()->comboboxSetSelected($this->handle, $index);
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
        static::platform()->comboboxOnSelected($this->handle, $cb);
        return $this;
    }
}
