<?php
declare(strict_types=1);

namespace Kingbes\Ui;

/**
 * 顶层窗口。
 *
 * 封装 Platform 的 window* 系列方法。Window 重写了 show / hide /
 * destroy，使其委托给 windowShow / windowHide / windowDestroy，
 * 而非基类的 control* 系列方法（不同后端对窗口与子控件的处理不同）。
 */
class Window extends Control
{
    /**
     * 创建窗口。
     *
     * @param string $title  窗口标题
     * @param int    $width  宽度（像素）
     * @param int    $height 高度（像素）
     */
    public function __construct(string $title, int $width, int $height)
    {
        $this->handle = static::platform()->windowCreate($title, $width, $height);
        // 注册到 App 持有引用，避免用户在 App::run 闭包内创建的 Window
        // 因闭包结束被 GC 回收（Control::__destruct 会调 destroy 销毁窗口）。
        App::registerWindow($this);
    }

    /**
     * 设置窗口标题。
     *
     * @param string $title 标题
     * @return static 当前实例（支持链式调用）
     */
    public function setTitle(string $title): static
    {
        static::platform()->windowSetTitle($this->handle, $title);
        return $this;
    }

    /**
     * 设置窗口尺寸。
     *
     * @param int $width  宽度（像素）
     * @param int $height 高度（像素）
     * @return static 当前实例（支持链式调用）
     */
    public function setSize(int $width, int $height): static
    {
        static::platform()->windowSetSize($this->handle, $width, $height);
        return $this;
    }

    /**
     * 设置窗口位置。
     *
     * @param int $x X 坐标
     * @param int $y Y 坐标
     * @return static 当前实例（支持链式调用）
     */
    public function setPosition(int $x, int $y): static
    {
        static::platform()->windowSetPosition($this->handle, $x, $y);
        return $this;
    }

    /**
     * 获取窗口位置。
     *
     * @return array 形如 ['x' => int, 'y' => int]
     */
    public function getPosition(): array
    {
        return static::platform()->windowGetPosition($this->handle);
    }

    /**
     * 设置窗口的子控件。
     *
     * Window 与 Control 同命名空间且为子类，可直接访问
     * protected getHandle()。
     *
     * @param Control $child 子控件实例
     * @return static 当前实例（支持链式调用）
     */
    public function setChild(Control $child): static
    {
        static::platform()->windowSetChild($this->handle, $child->getHandle());
        return $this;
    }

    /**
     * 注册窗口关闭回调。
     *
     * @param \Closure $cb 回调函数
     * @return static 当前实例（支持链式调用）
     */
    public function onClosing(\Closure $cb): static
    {
        static::platform()->windowOnClosing($this->handle, $cb);
        return $this;
    }

    /**
     * 注册窗口尺寸变化回调。
     *
     * @param \Closure $cb 回调函数
     * @return static 当前实例（支持链式调用）
     */
    public function onResize(\Closure $cb): static
    {
        static::platform()->windowOnResize($this->handle, $cb);
        return $this;
    }

    /**
     * 显示窗口（重写：委托给 windowShow 而非 controlShow）。
     *
     * @return static 当前实例（支持链式调用）
     */
    public function show(): static
    {
        if ($this->handle !== null) {
            static::platform()->windowShow($this->handle);
        }
        return $this;
    }

    /**
     * 隐藏窗口（重写：委托给 windowHide 而非 controlHide）。
     *
     * @return static 当前实例（支持链式调用）
     */
    public function hide(): static
    {
        if ($this->handle !== null) {
            static::platform()->windowHide($this->handle);
        }
        return $this;
    }

    /**
     * 关闭并销毁窗口。
     *
     * 重复调用为 no-op：销毁后将 handle 置为 null。
     */
    public function close(): void
    {
        if ($this->handle !== null) {
            static::platform()->windowDestroy($this->handle);
            $this->handle = null;
            App::unregisterWindow($this);
        }
    }

    /**
     * 销毁窗口（重写：委托给 close() 进而调用 windowDestroy）。
     */
    public function destroy(): void
    {
        $this->close();
    }
}
