<?php
declare(strict_types=1);

namespace Kingbes\Ui;

/**
 * 标签控件（静态文本）。
 */
class Label extends Control
{
    /**
     * 创建标签。
     *
     * @param string $text 标签文本
     */
    public function __construct(string $text)
    {
        $this->handle = static::platform()->labelCreate($text);
    }

    /**
     * 获取标签文本。
     *
     * @return string 标签文本
     */
    public function getText(): string
    {
        return static::platform()->labelGetText($this->handle);
    }

    /**
     * 设置标签文本。
     *
     * @param string $text 文本
     * @return static 当前实例（支持链式调用）
     */
    public function setText(string $text): static
    {
        static::platform()->labelSetText($this->handle, $text);
        return $this;
    }
}
