<?php
declare(strict_types=1);

namespace Kingbes\Ui;

/**
 * 复选框控件。
 */
class Checkbox extends Control
{
    /**
     * 创建复选框。
     *
     * @param string $text 复选框文本
     */
    public function __construct(string $text)
    {
        $this->handle = static::platform()->checkboxCreate($text);
    }

    /**
     * 获取复选框文本。
     *
     * @return string 文本
     */
    public function getText(): string
    {
        return static::platform()->checkboxGetText($this->handle);
    }

    /**
     * 设置复选框文本。
     *
     * @param string $text 文本
     * @return static 当前实例（支持链式调用）
     */
    public function setText(string $text): static
    {
        static::platform()->checkboxSetText($this->handle, $text);
        return $this;
    }

    /**
     * 查询复选框是否选中。
     *
     * @return bool 是否选中
     */
    public function isChecked(): bool
    {
        return static::platform()->checkboxIsChecked($this->handle);
    }

    /**
     * 设置复选框选中状态。
     *
     * @param bool $checked 是否选中
     * @return static 当前实例（支持链式调用）
     */
    public function setChecked(bool $checked): static
    {
        static::platform()->checkboxSetChecked($this->handle, $checked);
        return $this;
    }

    /**
     * 注册复选框状态切换回调。
     *
     * @param \Closure $cb 回调函数
     * @return static 当前实例（支持链式调用）
     */
    public function onToggled(\Closure $cb): static
    {
        static::platform()->checkboxOnToggled($this->handle, $cb);
        return $this;
    }
}
