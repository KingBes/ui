<?php
declare(strict_types=1);

namespace Kingbes\Ui\Graphics;

use Kingbes\Ui\App;

/**
 * 富文本（多段不同字体/颜色/大小的文本）。
 *
 * 由若干段（segment）组成，每段独立设置字体名、字号、颜色。
 * draw() 按段顺序调用 DrawContext::setFont/setColor/drawText，累加 x 偏移。
 *
 * 构造时向平台注册表申请唯一 ID（allocAttrStringId），供
 * platform->drawTextAttributed(ctx, x, y, id) 反查并绘制。
 *
 * measure() 为粗略估算（每字符宽度 ≈ size * 0.6），用于布局占位。
 */
class AttributedString
{
    /**
     * 平台分配的唯一 ID。
     */
    private int $id;

    /**
     * 文本段列表。
     *
     * @var list<array{text:string, font:string, size:int, color:Color}>
     */
    private array $segments = [];

    public function __construct()
    {
        $platform = App::platform();
        $this->id = $platform->allocAttrStringId();
        $platform->registerAttrString($this->id, $this);
    }

    /**
     * 追加一段文本。
     *
     * @param string    $text  段文本（支持中文/emoji）。
     * @param string    $font  字体名，默认 "Segoe UI"。
     * @param int       $size  字号（像素），默认 14。
     * @param Color|null $color 颜色，默认黑色。
     * @return $this 支持链式调用。
     */
    public function append(string $text, string $font = 'Segoe UI', int $size = 14, ?Color $color = null): self
    {
        $this->segments[] = [
            'text'  => $text,
            'font'  => $font,
            'size'  => max(1, $size),
            'color' => $color ?? Color::black(),
        ];
        return $this;
    }

    /**
     * 获取 ID。
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * 获取所有段。
     *
     * @return list<array{text:string, font:string, size:int, color:Color}>
     */
    public function getSegments(): array
    {
        return $this->segments;
    }

    /**
     * 粗略估算文本尺寸。
     *
     * 每字符宽度按 size * 0.6 估算（中文字符按全宽近似），高度取最大段字号。
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function measure(DrawContext $ctx): array
    {
        $width = 0;
        $height = 0;
        foreach ($this->segments as $seg) {
            $charCount = mb_strlen($seg['text']);
            $width += (int) ceil($seg['size'] * 0.6 * $charCount);
            $height = max($height, $seg['size']);
        }
        return [$width, $height];
    }

    /**
     * 按段绘制：每段独立 setFont/setColor/drawText，累加 x 偏移。
     *
     * @param DrawContext $ctx 绘图上下文。
     * @param int         $x   起点 x。
     * @param int         $y   起点 y。
     */
    public function draw(DrawContext $ctx, int $x, int $y): void
    {
        $cx = $x;
        foreach ($this->segments as $seg) {
            $ctx->setFont($seg['font'], $seg['size']);
            $ctx->setColor($seg['color']);
            $ctx->drawText($cx, $y, $seg['text']);
            $charCount = mb_strlen($seg['text']);
            $cx += (int) ceil($seg['size'] * 0.6 * $charCount);
        }
    }
}
