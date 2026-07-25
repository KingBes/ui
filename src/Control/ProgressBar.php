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
 * 启用 setIndeterminate(true) 后进入 marquee 不确定状态（滚动动画）。
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
     * 设置当前进度值（确定状态下生效）。
     */
    public function setValue(int $value): void
    {
        App::platform()->controlSetValue($this->hwnd, $value);
    }

    /**
     * 设置范围（确定状态下生效）。
     */
    public function setRange(int $min, int $max): void
    {
        App::platform()->controlSetRange($this->hwnd, $min, $max);
    }

    /**
     * 启用/关闭不确定状态（marquee 滚动动画）。
     *
     * 启用后进度条以滚动动画表示"进行中但未知总量"。
     * 关闭后恢复为确定状态，需用 setValue 控制具体进度。
     *
     * @param bool $enabled true=启用滚动动画，false=恢复确定状态。
     */
    public function setIndeterminate(bool $enabled): void
    {
        App::platform()->progressBarSetMarquee($this->hwnd, $enabled);
    }
}
