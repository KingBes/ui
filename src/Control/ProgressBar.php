<?php
declare(strict_types=1);

namespace Kingbes\Ui\Control;

use Kingbes\Ui\App;
use Kingbes\Ui\Control;
use Kingbes\Ui\Window;

/**
 * 进度条控件。
 *
 * 基于 Win32 "msctls_progress32" 类。通过 setValue/setRange 控制进度。
 */
class ProgressBar extends Control
{
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
            'msctls_progress32',
            '',
            0,
            0,
            $this->parentHwnd(),
            0
        );
    }

    /**
     * 设置当前进度值。
     */
    public function setValue(int $value): void
    {
        App::platform()->controlSetValue($this->hwnd, $value);
    }

    /**
     * 设置范围。
     */
    public function setRange(int $min, int $max): void
    {
        App::platform()->controlSetRange($this->hwnd, $min, $max);
    }
}
