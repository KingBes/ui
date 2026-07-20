<?php
declare(strict_types=1);

namespace Kingbes\Ui;

/**
 * 多页签容器。
 *
 * 通过 append() 添加页，每页有自己的标题和子控件。
 * 用户点页签头切换页，或通过 setSelected() 编程切换。
 *
 * 对齐常量（用于各后端实现）：
 *   - uiAlign: Fill=0, Start=1, Center=2, End=3
 */
class Tab extends Control
{
    /**
     * 创建多页签容器。
     *
     * @param string $title 占位参数（实际不使用，保持与其它控件构造签名一致）
     */
    public function __construct(string $title = "")
    {
        $this->handle = static::platform()->tabCreate();
    }

    /**
     * 末尾追加一页。
     *
     * @param string  $name  页签标题
     * @param Control $child 页内容
     * @return static 当前实例（支持链式调用）
     */
    public function append(string $name, Control $child): static
    {
        static::platform()->tabAppend($this->handle, $name, $child->getHandle());
        return $this;
    }

    /**
     * 在指定位置插入一页。
     *
     * @param string  $name  页签标题
     * @param int     $index 插入位置（0 表示最前）
     * @param Control $child 页内容
     * @return static 当前实例（支持链式调用）
     */
    public function insertAt(string $name, int $index, Control $child): static
    {
        static::platform()->tabInsertAt($this->handle, $name, $index, $child->getHandle());
        return $this;
    }

    /**
     * 删除指定位置的页。
     *
     * @param int $index 页索引
     * @return static 当前实例（支持链式调用）
     */
    public function delete(int $index): static
    {
        static::platform()->tabDelete($this->handle, $index);
        return $this;
    }

    /**
     * 获取页数。
     *
     * @return int 页数
     */
    public function numPages(): int
    {
        return static::platform()->tabNumPages($this->handle);
    }

    /**
     * 获取当前选中页索引。
     *
     * @return int 当前页索引（-1 表示无选中）
     */
    public function getSelected(): int
    {
        return static::platform()->tabGetSelected($this->handle);
    }

    /**
     * 设置当前选中页。
     *
     * @param int $index 页索引
     * @return static 当前实例（支持链式调用）
     */
    public function setSelected(int $index): static
    {
        static::platform()->tabSetSelected($this->handle, $index);
        return $this;
    }

    /**
     * 查询指定页是否启用边距。
     *
     * @param int $index 页索引
     * @return bool 是否启用边距
     */
    public function getMargined(int $index): bool
    {
        return static::platform()->tabGetMargined($this->handle, $index);
    }

    /**
     * 设置指定页是否启用边距。
     *
     * @param int  $index 页索引
     * @param bool $m     是否启用
     * @return static 当前实例（支持链式调用）
     */
    public function setMargined(int $index, bool $m): static
    {
        static::platform()->tabSetMargined($this->handle, $index, $m);
        return $this;
    }
}
