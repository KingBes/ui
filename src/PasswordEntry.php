<?php
declare(strict_types=1);

namespace Kingbes\Ui;

/**
 * 密码输入框控件。
 *
 * 输入字符以掩码显示，但程序读取的仍是明文。
 */
class PasswordEntry extends Control
{
    /**
     * 创建密码输入框。
     */
    public function __construct()
    {
        $this->handle = static::platform()->passwordEntryCreate();
    }

    /**
     * 获取密码文本。
     *
     * @return string 文本
     */
    public function getText(): string
    {
        return static::platform()->passwordEntryGetText($this->handle);
    }

    /**
     * 设置密码文本。
     *
     * @param string $text 文本
     * @return static 当前实例（支持链式调用）
     */
    public function setText(string $text): static
    {
        static::platform()->passwordEntrySetText($this->handle, $text);
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
        static::platform()->passwordEntryOnChanged($this->handle, $cb);
        return $this;
    }
}
