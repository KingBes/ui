<?php
declare(strict_types=1);

namespace Kingbes\Ui\Graphics;

/**
 * RGBA 颜色值对象。
 *
 * 不可变。各通道取值范围 0-255。
 * alpha 通道默认 0 表示完全不透明（与 Win32 GDI 习惯一致，
 * 因为 GDI 不直接支持 alpha 通道，0 表示无透明）。
 *
 * 注意：alpha 的语义为后续平台（如 GTK/Cocoa）保留；Windows 后端
 * 在需要 COLORREF 时仅使用 R/G/B 通道并通过 alpha=0 标识不透明。
 */
final class Color
{
    public function __construct(
        public readonly int $r,
        public readonly int $g,
        public readonly int $b,
        public readonly int $a = 0
    ) {}

    /**
     * 静态工厂：以 RGB 构造（alpha 默认 0）。
     */
    public static function rgb(int $r, int $g, int $b): static
    {
        return new static($r, $g, $b, 0);
    }

    /**
     * 静态工厂：以 RGBA 构造。
     */
    public static function rgba(int $r, int $g, int $b, int $a): static
    {
        return new static($r, $g, $b, $a);
    }

    /**
     * 从 Win32 COLORREF（0x00BBGGRR）构造。
     */
    public static function fromColorRef(int $colorRef): static
    {
        return new static(
            $colorRef & 0xFF,
            ($colorRef >> 8) & 0xFF,
            ($colorRef >> 16) & 0xFF,
            0
        );
    }

    /**
     * 转 Win32 COLORREF（0x00BBGGRR）。
     */
    public function toColorRef(): int
    {
        return ($this->r & 0xFF)
            | (($this->g & 0xFF) << 8)
            | (($this->b & 0xFF) << 16);
    }

    /**
     * 转 [r, g, b, a] 数组形式。
     *
     * @return array{0:int,1:int,2:int,3:int}
     */
    public function toArray(): array
    {
        return [$this->r, $this->g, $this->b, $this->a];
    }

    // ----------------------------------------------------------------
    // 常用颜色静态方法
    // ----------------------------------------------------------------

    public static function black(): static
    {
        return new static(0, 0, 0, 0);
    }

    public static function white(): static
    {
        return new static(255, 255, 255, 0);
    }

    public static function red(): static
    {
        return new static(255, 0, 0, 0);
    }

    public static function green(): static
    {
        return new static(0, 128, 0, 0);
    }

    public static function lime(): static
    {
        return new static(0, 255, 0, 0);
    }

    public static function blue(): static
    {
        return new static(0, 0, 255, 0);
    }

    public static function navy(): static
    {
        return new static(0, 0, 128, 0);
    }

    public static function yellow(): static
    {
        return new static(255, 255, 0, 0);
    }

    public static function cyan(): static
    {
        return new static(0, 255, 255, 0);
    }

    public static function magenta(): static
    {
        return new static(255, 0, 255, 0);
    }

    public static function gray(): static
    {
        return new static(128, 128, 128, 0);
    }

    public static function grey(): static
    {
        return new static(128, 128, 128, 0);
    }

    public static function silver(): static
    {
        return new static(192, 192, 192, 0);
    }

    public static function maroon(): static
    {
        return new static(128, 0, 0, 0);
    }

    public static function purple(): static
    {
        return new static(128, 0, 128, 0);
    }

    public static function olive(): static
    {
        return new static(128, 128, 0, 0);
    }

    public static function teal(): static
    {
        return new static(0, 128, 128, 0);
    }

    public static function orange(): static
    {
        return new static(255, 165, 0, 0);
    }

    public static function transparent(): static
    {
        return new static(0, 0, 0, 255);
    }
}
