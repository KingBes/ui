<?php
declare(strict_types=1);

namespace Kingbes\Ui;

use Kingbes\Ui\Platform\Platform;

/**
 * 所有控件的抽象基类。
 *
 * 持有底层原生句柄（HWND / GtkWidget* / id 等），统一通用操作：
 * 显示/隐藏、启用/禁用、销毁等。子类负责在构造时调用对应 Platform
 * 工厂方法创建句柄并赋值给 $handle。
 */
abstract class Control
{
    /**
     * 底层原生句柄（FFI\CData）。
     *
     * @var mixed
     */
    protected mixed $handle = null;

    /**
     * 获取当前 Platform 后端单例。
     *
     * @return Platform 当前平台后端
     */
    protected static function platform(): Platform
    {
        return Platform::current();
    }

    /**
     * 获取底层句柄（供容器类使用）。
     *
     * @return mixed 底层句柄
     */
    protected function getHandle(): mixed
    {
        return $this->handle;
    }

    /**
     * 显示控件。
     *
     * @return static 当前实例（支持链式调用）
     */
    public function show(): static
    {
        if ($this->handle !== null) {
            static::platform()->controlShow($this->handle);
        }
        return $this;
    }

    /**
     * 隐藏控件。
     *
     * @return static 当前实例（支持链式调用）
     */
    public function hide(): static
    {
        if ($this->handle !== null) {
            static::platform()->controlHide($this->handle);
        }
        return $this;
    }

    /**
     * 启用控件。
     *
     * @return static 当前实例（支持链式调用）
     */
    public function enable(): static
    {
        if ($this->handle !== null) {
            static::platform()->controlEnable($this->handle);
        }
        return $this;
    }

    /**
     * 禁用控件。
     *
     * @return static 当前实例（支持链式调用）
     */
    public function disable(): static
    {
        if ($this->handle !== null) {
            static::platform()->controlDisable($this->handle);
        }
        return $this;
    }

    /**
     * 销毁控件并释放底层资源。
     *
     * 重复调用为 no-op：销毁后将 handle 置为 null，
     * 后续调用因 $this->handle !== null 判断不成立而直接返回。
     */
    public function destroy(): void
    {
        if ($this->handle !== null) {
            static::platform()->controlDestroy($this->handle);
            $this->handle = null;
        }
    }

    /**
     * 析构时自动销毁控件，避免资源泄漏。
     *
     * 若子类（如 Window）需要在销毁时执行平台特定的 window*
     * 方法，应重写 destroy() 而非本方法。
     */
    public function __destruct()
    {
        $this->destroy();
    }
}
