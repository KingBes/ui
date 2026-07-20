<?php
declare(strict_types=1);

namespace Kingbes\Ui;

/**
 * 表单布局容器。
 *
 * 每行由左侧的标签文字 + 右侧的子控件组成，多行垂直堆叠。
 * 适用于设置面板等"标签-输入"对齐布局。
 */
class Form extends Control
{
    /**
     * 创建表单布局容器。
     */
    public function __construct()
    {
        $this->handle = static::platform()->formCreate();
    }

    /**
     * 追加一行：标签 + 子控件。
     *
     * @param string  $label    左侧标签文字
     * @param Control $child    右侧子控件
     * @param bool    $stretchy 该行是否拉伸占据剩余垂直空间
     * @return static 当前实例（支持链式调用）
     */
    public function append(string $label, Control $child, bool $stretchy = false): static
    {
        static::platform()->formAppend($this->handle, $label, $child->getHandle(), $stretchy);
        return $this;
    }

    /**
     * 删除指定行。
     *
     * @param int $index 行索引
     * @return static 当前实例（支持链式调用）
     */
    public function delete(int $index): static
    {
        static::platform()->formDelete($this->handle, $index);
        return $this;
    }

    /**
     * 获取行数。
     *
     * @return int 行数
     */
    public function numChildren(): int
    {
        return static::platform()->formNumChildren($this->handle);
    }

    /**
     * 查询是否启用内边距。
     *
     * @return bool 是否启用
     */
    public function getPadded(): bool
    {
        return static::platform()->formGetPadded($this->handle);
    }

    /**
     * 设置是否启用内边距。
     *
     * @param bool $padded 是否启用
     * @return static 当前实例（支持链式调用）
     */
    public function setPadded(bool $padded): static
    {
        static::platform()->formSetPadded($this->handle, $padded);
        return $this;
    }
}
