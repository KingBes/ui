<?php
declare(strict_types=1);

namespace Kingbes\Ui\Control;

use Kingbes\Ui\App;
use Kingbes\Ui\Control;
use Kingbes\Ui\Graphics\Color;
use Kingbes\Ui\Window;

/**
 * 颜色选择按钮。
 *
 * 外观为 Button，点击后弹出系统颜色选择对话框（dialogChooseColor）。
 * 按钮文本显示当前颜色的十六进制值，背景色同步更新为当前颜色。
 *
 * 事件：
 *   - onColorChanged：用户选中新颜色后触发（参数：Color）。
 */
class ColorButton extends Control
{
    /** BS_PUSHBUTTON */
    private const STYLE_PUSHBUTTON = 0x00010000;

    private Color $color;

    /** 颜色变化回调（参数：Color）。 */
    public ?\Closure $onColorChanged = null;

    /**
     * @param Control|Window $parent 父容器或窗口。
     * @param Color          $color  初始颜色（默认黑色）。
     */
    public function __construct(Control|Window $parent, ?Color $color = null)
    {
        $this->color = $color ?? Color::black();
        parent::__construct($parent);
        // 点击按钮自动弹出颜色对话框
        $this->onClick = fn() => $this->click();
        // 设置按钮文本为颜色十六进制
        $this->updateButtonText();
    }

    protected function create(): void
    {
        $this->hwnd = App::platform()->controlCreate(
            'Button',
            '',
            self::STYLE_PUSHBUTTON,
            0,
            $this->parentHwnd(),
            0
        );
    }

    /**
     * 设置当前颜色。
     */
    public function setColor(Color $color): void
    {
        $this->color = $color;
        $this->updateButtonText();
    }

    /**
     * 获取当前颜色。
     */
    public function getColor(): Color
    {
        return $this->color;
    }

    /**
     * 点击按钮：弹出颜色选择对话框。
     */
    public function click(): void
    {
        $parentHwnd = $this->window?->getHwnd() ?? 0;
        $newColor = App::platform()->dialogChooseColor($parentHwnd);
        if ($newColor !== null) {
            $this->color = $newColor;
            $this->updateButtonText();
            if ($this->onColorChanged !== null) {
                try {
                    ($this->onColorChanged)($newColor);
                } catch (\Throwable $e) {
                    trigger_error(
                        'onColorChanged callback error: ' . $e->getMessage(),
                        \E_USER_WARNING
                    );
                }
            }
        }
    }

    /**
     * 更新按钮文本为颜色十六进制值。
     */
    private function updateButtonText(): void
    {
        $hex = sprintf(
            '#%02X%02X%02X',
            $this->color->r,
            $this->color->g,
            $this->color->b
        );
        $this->setText($hex);
    }
}
