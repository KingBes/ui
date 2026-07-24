<?php
declare(strict_types=1);

namespace Kingbes\Ui\Geometry;

/**
 * 矩形值对象（左上角坐标 + 尺寸）。
 *
 * 不可变。语义遵循 Windows RECT 与 GTK GdkRectangle 的常见表示：
 * x/y 为左上角，width/height 为正向尺寸。
 */
final class Rect
{
    public function __construct(
        public readonly int $x,
        public readonly int $y,
        public readonly int $width,
        public readonly int $height
    ) {}

    /**
     * 静态工厂。
     */
    public static function of(int $x, int $y, int $width, int $height): static
    {
        return new static($x, $y, $width, $height);
    }

    /**
     * 从 Point + Size 构造。
     */
    public static function fromPointAndSize(Point $origin, Size $size): static
    {
        return new static($origin->x, $origin->y, $size->width, $size->height);
    }

    /**
     * 零矩形。
     */
    public static function zero(): static
    {
        return new static(0, 0, 0, 0);
    }

    /**
     * 右边界坐标 (x + width)。
     */
    public function right(): int
    {
        return $this->x + $this->width;
    }

    /**
     * 下边界坐标 (y + height)。
     */
    public function bottom(): int
    {
        return $this->y + $this->height;
    }

    /**
     * 左上角点。
     */
    public function origin(): Point
    {
        return new Point($this->x, $this->y);
    }

    /**
     * 尺寸。
     */
    public function size(): Size
    {
        return new Size($this->width, $this->height);
    }
}
