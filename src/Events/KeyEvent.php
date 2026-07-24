<?php
declare(strict_types=1);

namespace Kingbes\Ui\Events;

/**
 * 键盘事件值对象。
 *
 * $keyCode 为平台虚拟键码（Win32 VK_* / GTK GdkKeyval / Cocoa keyCode），
 * 上层可通过常量比较识别按键。$modifiers 为修饰键位掩码。
 */
final class KeyEvent
{
    /** 修饰键位掩码常量（与 MouseEvent 对齐） */
    public const MODIFIER_NONE   = 0;
    public const MODIFIER_SHIFT  = 1;
    public const MODIFIER_CTRL   = 2;
    public const MODIFIER_ALT    = 4;
    public const MODIFIER_SUPER  = 8;

    public function __construct(
        public readonly int $keyCode,
        public readonly int $modifiers = self::MODIFIER_NONE
    ) {}

    /**
     * 是否按下了指定修饰键。
     */
    public function hasModifier(int $flag): bool
    {
        return ($this->modifiers & $flag) === $flag;
    }

    public function isShiftDown(): bool
    {
        return $this->hasModifier(self::MODIFIER_SHIFT);
    }

    public function isCtrlDown(): bool
    {
        return $this->hasModifier(self::MODIFIER_CTRL);
    }

    public function isAltDown(): bool
    {
        return $this->hasModifier(self::MODIFIER_ALT);
    }
}
