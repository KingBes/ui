<?php
declare(strict_types=1);

namespace Kingbes\Ui;

/**
 * 多行文本输入框控件。
 */
class MultilineEntry extends Control
{
    /**
     * 创建多行文本输入框。
     */
    public function __construct()
    {
        $this->handle = static::platform()->multilineEntryCreate();
    }

    /**
     * 获取全部文本。
     *
     * @return string 文本
     */
    public function getText(): string
    {
        return static::platform()->multilineEntryGetText($this->handle);
    }

    /**
     * 设置全部文本（替换）。
     *
     * @param string $text 文本
     * @return static 当前实例（支持链式调用）
     */
    public function setText(string $text): static
    {
        static::platform()->multilineEntrySetText($this->handle, $text);
        return $this;
    }

    /**
     * 追加文本到末尾。
     *
     * @param string $text 文本
     * @return static 当前实例（支持链式调用）
     */
    public function append(string $text): static
    {
        static::platform()->multilineEntryAppend($this->handle, $text);
        return $this;
    }

    /**
     * 注册内容变化回调。
     *
     * @param \Closure $cb 回调函数
     * @return static 当前实例（支持链式调用）
     */
    public function onChanged(\Closure $cb): static
    {
        static::platform()->multilineEntryOnChanged($this->handle, $cb);
        return $this;
    }

    /**
     * 设置只读状态。
     *
     * @param bool $readOnly 是否只读
     * @return static 当前实例（支持链式调用）
     */
    public function setReadOnly(bool $readOnly): static
    {
        static::platform()->multilineEntrySetReadOnly($this->handle, $readOnly);
        return $this;
    }
}
