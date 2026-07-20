<?php
declare(strict_types=1);

namespace Kingbes\Ui;

/**
 * 按钮控件。
 */
class Button extends Control
{
    /**
     * 创建按钮。
     *
     * @param string $text 按钮文本
     */
    public function __construct(string $text)
    {
        $this->handle = static::platform()->buttonCreate($text);
    }

    /**
     * 获取按钮文本。
     *
     * @return string 按钮文本
     */
    public function getText(): string
    {
        return static::platform()->buttonGetText($this->handle);
    }

    /**
     * 设置按钮文本。
     *
     * @param string $text 文本
     * @return static 当前实例（支持链式调用）
     */
    public function setText(string $text): static
    {
        static::platform()->buttonSetText($this->handle, $text);
        return $this;
    }

    /**
     * 注册按钮点击回调。
     *
     * @param \Closure $cb 回调函数
     * @return static 当前实例（支持链式调用）
     */
    public function onClicked(\Closure $cb): static
    {
        static::platform()->buttonOnClicked($this->handle, $cb);
        return $this;
    }
}
