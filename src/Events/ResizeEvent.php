<?php
declare(strict_types=1);

namespace Kingbes\Ui\Events;

/**
 * 窗口/控件尺寸变化事件值对象。
 */
final class ResizeEvent
{
    public function __construct(
        public readonly int $width,
        public readonly int $height
    ) {}

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
