<?php
declare(strict_types=1);

namespace Kingbes\Ui\Control;

use Kingbes\Ui\App;
use Kingbes\Ui\Control;
use Kingbes\Ui\Graphics\Color;
use Kingbes\Ui\Window;

/**
 * 字体选择按钮。
 *
 * 外观为 Button，点击后弹出系统字体选择对话框（dialogChooseFont）。
 * 按钮文本显示当前字体名和字号。
 *
 * 字体信息包含 name（字体名）、size（字号磅）、color（字体颜色）。
 *
 * 事件：
 *   - onFontChanged：用户选择新字体后触发（参数：array{name:string,size:int,color:Color}）。
 */
class FontButton extends Control
{
    /** BS_PUSHBUTTON */
    private const STYLE_PUSHBUTTON = 0x00010000;

    /** @var array{name:string,size:int,color:Color} */
    private array $font;

    /** 字体变化回调（参数：array{name:string,size:int,color:Color}）。 */
    public ?\Closure $onFontChanged = null;

    /**
     * @param Control|Window $parent 父容器或窗口。
     * @param array{name:string,size:int,color:Color}|null $font 初始字体（默认 Segoe UI 14pt 黑色）。
     */
    public function __construct(Control|Window $parent, ?array $font = null)
    {
        $this->font = $font ?? [
            'name' => 'Segoe UI',
            'size' => 14,
            'color' => Color::black(),
        ];
        parent::__construct($parent);
        $this->onClick = fn() => $this->click();
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
     * 设置当前字体。
     *
     * @param array{name:string,size:int,color:Color} $font
     */
    public function setFont(array $font): void
    {
        $this->font = $font;
        $this->updateButtonText();
    }

    /**
     * 获取当前字体。
     *
     * @return array{name:string,size:int,color:Color}
     */
    public function getFont(): array
    {
        return $this->font;
    }

    /**
     * 点击按钮：弹字体选择对话框。
     */
    public function click(): void
    {
        $parentHwnd = $this->window?->getHwnd() ?? 0;
        $newFont = App::platform()->dialogChooseFont($parentHwnd);
        if ($newFont !== null) {
            $this->font = $newFont;
            $this->updateButtonText();
            if ($this->onFontChanged !== null) {
                try {
                    ($this->onFontChanged)($newFont);
                } catch (\Throwable $e) {
                    trigger_error(
                        'onFontChanged callback error: ' . $e->getMessage(),
                        \E_USER_WARNING
                    );
                }
            }
        }
    }

    /**
     * 更新按钮文本为字体名 + 字号。
     */
    private function updateButtonText(): void
    {
        $this->setText($this->font['name'] . ' ' . $this->font['size'] . 'pt');
    }
}
