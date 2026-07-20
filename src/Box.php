<?php
declare(strict_types=1);

namespace Kingbes\Ui;

/**
 * 容器盒子控件。
 *
 * 通过静态工厂 horizontal() / vertical() 创建，或使用子类
 * HBox / VBox 作为语法糖。Box 可通过 append() 装入其它控件，
 * 并按水平或垂直方向排列子控件。
 */
class Box extends Control
{
    /**
     * 创建盒子。
     *
     * 构造器为 protected，强制通过静态工厂 horizontal() / vertical()
     * 或子类 HBox / VBox 创建实例。
     *
     * @param bool $horizontal true 为水平布局，false 为垂直布局
     */
    protected function __construct(bool $horizontal)
    {
        $this->handle = static::platform()->boxCreate($horizontal);
    }

    /**
     * 创建水平布局盒子。
     *
     * @return static 水平 Box 实例
     */
    public static function horizontal(): static
    {
        return new static(true);
    }

    /**
     * 创建垂直布局盒子。
     *
     * @return static 垂直 Box 实例
     */
    public static function vertical(): static
    {
        return new static(false);
    }

    /**
     * 向盒子追加子控件。
     *
     * Box 与 Control 同命名空间且为子类，可直接访问
     * protected getHandle()。
     *
     * @param Control $child    子控件实例
     * @param bool    $stretchy 是否拉伸占据剩余空间
     * @return static 当前实例（支持链式调用）
     */
    public function append(Control $child, bool $stretchy = false): static
    {
        static::platform()->boxAppend($this->handle, $child->getHandle(), $stretchy);
        return $this;
    }

    /**
     * 从盒子移除指定索引位置的子控件。
     *
     * @param int $index 子控件索引
     * @return static 当前实例（支持链式调用）
     */
    public function remove(int $index): static
    {
        static::platform()->boxRemove($this->handle, $index);
        return $this;
    }

    /**
     * 设置盒子是否启用内边距填充。
     *
     * @param bool $padded 是否填充
     * @return static 当前实例（支持链式调用）
     */
    public function setPadded(bool $padded): static
    {
        static::platform()->boxSetPadded($this->handle, $padded);
        return $this;
    }
}
