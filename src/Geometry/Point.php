<?php
declare(strict_types=1);

namespace Kingbes\Ui\Geometry;

/**
 * 二维点值对象。
 *
 * 不可变；坐标用整数表示，与原生 GUI API（像素坐标）对齐。
 */
final class Point
{
    public function __construct(
        public readonly int $x,
        public readonly int $y
    ) {}

    /**
     * 静态工厂。
     */
    public static function of(int $x, int $y): static
    {
        return new static($x, $y);
    }

    /**
     * 原点 (0, 0)。
     */
    public static function zero(): static
    {
        return new static(0, 0);
    }

    /**
     * 返回 [x, y] 数组形式。
     *
     * @return array{0:int,1:int}
     */
    public function toArray(): array
    {
        return [$this->x, $this->y];
    }
}
