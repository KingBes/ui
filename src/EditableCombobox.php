<?php
declare(strict_types=1);

namespace Kingbes\Ui;

/**
 * 可编辑下拉列表控件。
 *
 * 用户既可从预置选项中选择，也可直接输入自定义文本。
 */
class EditableCombobox extends Control
{
    /**
     * 创建可编辑下拉列表。
     */
    public function __construct()
    {
        $this->handle = static::platform()->editableComboboxCreate();
    }

    /**
     * 末尾追加一个选项。
     *
     * @param string $name 选项文本
     * @return static 当前实例（支持链式调用）
     */
    public function append(string $name): static
    {
        static::platform()->editableComboboxAppend($this->handle, $name);
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
        static::platform()->editableComboboxInsertAt($this->handle, $name, $index);
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
        static::platform()->editableComboboxDelete($this->handle, $index);
        return $this;
    }

    /**
     * 清空所有选项。
     *
     * @return static 当前实例（支持链式调用）
     */
    public function clear(): static
    {
        static::platform()->editableComboboxClear($this->handle);
        return $this;
    }

    /**
     * 获取选项数量。
     *
     * @return int 数量
     */
    public function numItems(): int
    {
        return static::platform()->editableComboboxNumItems($this->handle);
    }

    /**
     * 获取当前选中项索引，-1 表示无选中。
     *
     * @return int 索引
     */
    public function getSelected(): int
    {
        return static::platform()->editableComboboxGetSelected($this->handle);
    }

    /**
     * 设置当前选中项。
     *
     * @param int $index 索引
     * @return static 当前实例（支持链式调用）
     */
    public function setSelected(int $index): static
    {
        static::platform()->editableComboboxSetSelected($this->handle, $index);
        return $this;
    }

    /**
     * 设置输入框文本。
     *
     * @param string $text 文本
     * @return static 当前实例（支持链式调用）
     */
    public function setText(string $text): static
    {
        static::platform()->editableComboboxSetText($this->handle, $text);
        return $this;
    }

    /**
     * 获取输入框文本。
     *
     * @return string 文本
     */
    public function getText(): string
    {
        return static::platform()->editableComboboxGetText($this->handle);
    }

    /**
     * 注册文本或选中变化回调。
     *
     * @param \Closure $cb 回调函数
     * @return static 当前实例（支持链式调用）
     */
    public function onChanged(\Closure $cb): static
    {
        static::platform()->editableComboboxOnChanged($this->handle, $cb);
        return $this;
    }
}
