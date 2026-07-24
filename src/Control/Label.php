<?php
declare(strict_types=1);

namespace Kingbes\Ui\Control;

use Kingbes\Ui\App;
use Kingbes\Ui\Control;
use Kingbes\Ui\Window;

/**
 * 标签控件（静态文本）。
 *
 * 基于 Win32 "Static" 类。支持左对齐（默认）、居中、右对齐。
 */
class Label extends Control
{
    public const ALIGN_LEFT   = 0;
    public const ALIGN_CENTER = 1;
    public const ALIGN_RIGHT  = 2;

    /** SS_LEFT / SS_CENTER / SS_RIGHT */
    private const SS_LEFT   = 0x00000000;
    private const SS_CENTER = 0x00000001;
    private const SS_RIGHT  = 0x00000002;

    private string $text;
    private int $alignment;

    /**
     * @param Control|Window $parent    父容器或窗口。
     * @param string         $text      标签文本（支持中文/emoji）。
     * @param int            $alignment 对齐方式（ALIGN_LEFT/CENTER/RIGHT）。
     */
    public function __construct(
        Control|Window $parent,
        string $text = '',
        int $alignment = self::ALIGN_LEFT
    ) {
        $this->text = $text;
        $this->alignment = $alignment;
        parent::__construct($parent);
    }

    protected function create(): void
    {
        $style = match ($this->alignment) {
            self::ALIGN_CENTER => self::SS_CENTER,
            self::ALIGN_RIGHT  => self::SS_RIGHT,
            default            => self::SS_LEFT,
        };
        $this->hwnd = App::platform()->controlCreate(
            'Static',
            $this->text,
            $style,
            0,
            $this->parentHwnd(),
            0
        );
    }
}
