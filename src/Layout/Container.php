<?php
declare(strict_types=1);

namespace Kingbes\Ui\Layout;

use Kingbes\Ui\Control;

/**
 * 布局容器抽象基类。
 *
 * Container 是一种特殊的 Control：它持有子控件列表，并通过 layout()
 * 算法在给定矩形内排列子控件。具体布局算法（水平/垂直均分、网格、
 * 标签-控件对齐）由子类实现。
 *
 * toplevel 标记：
 *   - true  表示该容器是窗口的顶层布局容器，窗口尺寸变化时触发其 layout()。
 *   - false 表示嵌套容器，由父容器的 layout() 递归调用其 layout()。
 *   windowSetChild 自动将 child 容器标记为 toplevel=true。
 *   Container::add 自动将嵌套子容器标记为 toplevel=false。
 */
abstract class Container extends Control
{
    /**
     * 子控件列表（按添加顺序）。
     *
     * @var list<Control>
     */
    protected array $children = [];

    /**
     * 顶层容器标记。仅 toplevel=true 的容器由窗口尺寸变化触发重布局。
     */
    protected bool $toplevel = false;

    /**
     * 添加子控件。
     *
     * 若 $c 是 Container，自动标记其 toplevel=false（嵌套容器）。
     */
    public function add(Control $c): static
    {
        $this->children[] = $c;
        if ($c instanceof self) {
            $c->setToplevel(false);
        }
        return $this;
    }

    /**
     * 移除子控件。
     */
    public function remove(Control $c): static
    {
        $key = array_search($c, $this->children, true);
        if ($key !== false) {
            array_splice($this->children, (int) $key, 1);
        }
        return $this;
    }

    /**
     * 获取子控件列表。
     *
     * @return list<Control>
     */
    public function getChildren(): array
    {
        return $this->children;
    }

    /**
     * 子控件数量。
     */
    public function count(): int
    {
        return count($this->children);
    }

    /**
     * 是否为顶层容器。
     */
    public function isToplevel(): bool
    {
        return $this->toplevel;
    }

    /**
     * 设置顶层标记（由 Window::setChild 与 Container::add 调用）。
     */
    public function setToplevel(bool $toplevel): void
    {
        $this->toplevel = $toplevel;
    }

    /**
     * 布局算法：在给定矩形内排列所有子控件。
     *
     * @param int $x      容器左上角 x（相对于父窗口客户区）。
     * @param int $y      容器左上角 y。
     * @param int $width  容器宽度。
     * @param int $height 容器高度。
     */
    abstract public function layout(int $x, int $y, int $width, int $height): void;

    /**
     * 销毁容器及其所有子控件。
     */
    public function destroy(): void
    {
        foreach ($this->children as $child) {
            $child->destroy();
        }
        $this->children = [];
        parent::destroy();
    }
}
