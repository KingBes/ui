<?php
declare(strict_types=1);

namespace Kingbes\Ui\Control;

use Kingbes\Ui\App;
use Kingbes\Ui\Control;
use Kingbes\Ui\Window;

/**
 * 可编辑下拉框。
 *
 * 基于 Win32 "ComboBox" 类（CBS_DROPDOWN，可输入 + 下拉列表）。
 * 与 ComboBox（CBS_DROPDOWNLIST 只读）不同，用户可输入自定义文本。
 *
 * 事件：
 *   - onSelect：下拉列表选择项变化时触发（CBN_SELCHANGE）。
 *   - onChange：编辑框文本变化时触发（CBN_EDITCHANGE）。
 */
class EditableComboBox extends Control
{
    /** CBS_DROPDOWN：可编辑 + 下拉列表（CBS_SIMPLE=可编辑+始终显示列表） */
    private const CBS_DROPDOWN = 0x0002;
    private const WS_TABSTOP   = 0x00010000;

    /** 选择项变化回调（无参数）。 */
    public ?\Closure $onSelect = null;
    /** 编辑框文本变化回调（无参数）。 */
    public ?\Closure $onChange = null;

    /**
     * @param Control|Window $parent 父容器或窗口。
     */
    public function __construct(Control|Window $parent)
    {
        parent::__construct($parent);
    }

    protected function create(): void
    {
        $this->hwnd = App::platform()->controlCreate(
            'ComboBox',
            '',
            self::CBS_DROPDOWN | self::WS_TABSTOP,
            0,
            $this->parentHwnd(),
            0
        );
    }

    /**
     * 添加选项。
     */
    public function addItem(string $text): void
    {
        App::platform()->controlAddString($this->hwnd, $text);
    }

    /**
     * 移除指定索引的选项。
     */
    public function removeItem(int $index): void
    {
        App::platform()->controlRemoveString($this->hwnd, $index);
    }

    /**
     * 清空所有选项。
     */
    public function clear(): void
    {
        App::platform()->controlClear($this->hwnd);
    }

    /**
     * 选中指定索引的选项。
     */
    public function select(int $index): void
    {
        App::platform()->controlSetSelectedIndex($this->hwnd, $index);
    }

    /**
     * 获取当前选中项索引（-1 表示未选中）。
     */
    public function getSelectedIndex(): int
    {
        return App::platform()->controlGetSelectedIndex($this->hwnd);
    }

    /**
     * 获取编辑框文本（用户输入或选中项文本）。
     */
    public function getText(): string
    {
        return parent::getText();
    }

    /**
     * 设置编辑框文本。
     */
    public function setText(string $text): void
    {
        parent::setText($text);
    }
}
