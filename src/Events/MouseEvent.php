<?php
declare(strict_types=1);

namespace Kingbes\Ui\Events;

/**
 * 鼠标事件值对象。
 *
 * $button 取值为 'left'/'right'/'middle'/'none'，使用本类常量。
 * $modifiers 为修饰键位掩码（MODIFIER_* 常量按位或）。
 */
final class MouseEvent
{
    /** 按键常量 */
    public const BUTTON_NONE   = 'none';
    public const BUTTON_LEFT   = 'left';
    public const BUTTON_RIGHT  = 'right';
    public const BUTTON_MIDDLE = 'middle';

    /** 修饰键位掩码常量 */
    public const MODIFIER_NONE   = 0;
    public const MODIFIER_SHIFT  = 1;
    public const MODIFIER_CTRL   = 2;
    public const MODIFIER_ALT    = 4;
    public const MODIFIER_SUPER  = 8;

    public function __construct(
        public readonly int $x,
        public readonly int $y,
        public readonly string $button = self::BUTTON_NONE,
        public readonly int $modifiers = self::MODIFIER_NONE
    ) {}

    /**
     * 是否按下了指定修饰键。
     */
    public function hasModifier(int $flag): bool
    {
        return ($this->modifiers & $flag) === $flag;
    }

    /**
     * 是否按下 Shift。
     */
    public function isShiftDown(): bool
    {
        return $this->hasModifier(self::MODIFIER_SHIFT);
    }

    /**
     * 是否按下 Ctrl。
     */
    public function isCtrlDown(): bool
    {
        return $this->hasModifier(self::MODIFIER_CTRL);
    }

    /**
     * 是否按下 Alt。
     */
    public function isAltDown(): bool
    {
        return $this->hasModifier(self::MODIFIER_ALT);
    }
}
