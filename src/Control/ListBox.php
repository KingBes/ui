<?php
declare(strict_types=1);

namespace Kingbes\Ui\Control;

use Kingbes\Ui\App;
use Kingbes\Ui\Control;
use Kingbes\Ui\Window;

/**
 * 列表框控件。
 *
 * 基于 Win32 "ListBox" 类（LBS_STANDARD：含边框、垂直滚动条、
 * 双击/选择通知）。选择项变化时触发 onSelect 回调
 * （WM_COMMAND LBN_SELCHANGE）。
 */
class ListBox extends Control
{
    private const LBS_STANDARD = 0x00A00003;
    private const WS_TABSTOP    = 0x00010000;

    /** 选择项变化回调（无参数）。 */
    public ?\Closure $onSelect = null;

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
            'ListBox',
            '',
            self::LBS_STANDARD | self::WS_TABSTOP,
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
}
