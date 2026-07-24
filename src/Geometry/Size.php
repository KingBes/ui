<?php
declare(strict_types=1);

namespace Kingbes\Ui\Geometry;

/**
 * 二维尺寸值对象。
 *
 * 不可变；宽高均为非负整数（像素），调用方负责语义校验。
 */
final class Size
{
    public function __construct(
        public readonly int $width,
        public readonly int $height
    ) {}

    /**
     * 静态工厂。
     */
    public static function of(int $width, int $height): static
    {
        return new static($width, $height);
    }

    /**
     * 零尺寸 (0, 0)。
     */
    public static function zero(): static
    {
        return new static(0, 0);
    }

    /**
     * 返回 [width, height] 数组形式。
     *
     * @return array{0:int,1:int}
     */
    public function toArray(): array
    {
        return [$this->width, $this->height];
    }
}
