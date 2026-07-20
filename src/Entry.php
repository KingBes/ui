<?php
declare(strict_types=1);

namespace Kingbes\Ui;

/**
 * 单行文本输入框控件。
 */
class Entry extends Control
{
    /**
     * 创建输入框。
     */
    public function __construct()
    {
        $this->handle = static::platform()->entryCreate();
    }

    /**
     * 获取输入框文本。
     *
     * @return string 文本
     */
    public function getText(): string
    {
        return static::platform()->entryGetText($this->handle);
    }

    /**
     * 设置输入框文本。
     *
     * @param string $text 文本
     * @return static 当前实例（支持链式调用）
     */
    public function setText(string $text): static
    {
        static::platform()->entrySetText($this->handle, $text);
        return $this;
    }

    /**
     * 注册输入框内容变化回调。
     *
     * @param \Closure $cb 回调函数
     * @return static 当前实例（支持链式调用）
     */
    public function onChanged(\Closure $cb): static
    {
        static::platform()->entryOnChanged($this->handle, $cb);
        return $this;
    }

    /**
     * 设置输入框只读状态。
     *
     * @param bool $readOnly 是否只读
     * @return static 当前实例（支持链式调用）
     */
    public function setReadOnly(bool $readOnly): static
    {
        static::platform()->entrySetReadOnly($this->handle, $readOnly);
        return $this;
    }
}
