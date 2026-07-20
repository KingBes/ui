<?php
declare(strict_types=1);

namespace Kingbes\Ui;

/**
 * 搜索输入框控件。
 *
 * 语义上表示用于搜索的单行输入框；在无原生搜索样式的平台上
 * 退化为普通单行输入框。
 */
class SearchEntry extends Control
{
    /**
     * 创建搜索输入框。
     */
    public function __construct()
    {
        $this->handle = static::platform()->searchEntryCreate();
    }

    /**
     * 获取搜索文本。
     *
     * @return string 文本
     */
    public function getText(): string
    {
        return static::platform()->searchEntryGetText($this->handle);
    }

    /**
     * 设置搜索文本。
     *
     * @param string $text 文本
     * @return static 当前实例（支持链式调用）
     */
    public function setText(string $text): static
    {
        static::platform()->searchEntrySetText($this->handle, $text);
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
        static::platform()->searchEntryOnChanged($this->handle, $cb);
        return $this;
    }
}
