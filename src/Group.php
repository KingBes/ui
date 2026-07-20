<?php
declare(strict_types=1);

namespace Kingbes\Ui;

/**
 * 带标题的容器。
 *
 * 用边框和标题文字包裹一个子控件，常用于视觉分组。
 */
class Group extends Control
{
    /**
     * 创建带标题容器。
     *
     * @param string $title 标题文字
     */
    public function __construct(string $title)
    {
        $this->handle = static::platform()->groupCreate($title);
    }

    /**
     * 获取标题。
     *
     * @return string 标题
     */
    public function getTitle(): string
    {
        return static::platform()->groupGetTitle($this->handle);
    }

    /**
     * 设置标题。
     *
     * @param string $title 标题
     * @return static 当前实例（支持链式调用）
     */
    public function setTitle(string $title): static
    {
        static::platform()->groupSetTitle($this->handle, $title);
        return $this;
    }

    /**
     * 设置子控件。
     *
     * @param Control $child 子控件
     * @return static 当前实例（支持链式调用）
     */
    public function setChild(Control $child): static
    {
        static::platform()->groupSetChild($this->handle, $child->getHandle());
        return $this;
    }

    /**
     * 查询是否启用边距。
     *
     * @return bool 是否启用
     */
    public function getMargined(): bool
    {
        return static::platform()->groupGetMargined($this->handle);
    }

    /**
     * 设置是否启用边距。
     *
     * @param bool $m 是否启用
     * @return static 当前实例（支持链式调用）
     */
    public function setMargined(bool $m): static
    {
        static::platform()->groupSetMargined($this->handle, $m);
        return $this;
    }
}
