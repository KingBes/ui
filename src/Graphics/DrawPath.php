<?php
declare(strict_types=1);

namespace Kingbes\Ui\Graphics;

use Kingbes\Ui\Platform\Windows\WindowsPlatform;

/**
 * 路径对象（GDI+ GpPath 封装）。
 *
 * 用于构建矢量路径，配合 DrawContext::fillPath / strokePath 使用。
 * 支持 moveTo / lineTo / arcTo / bezierTo / quadTo / closeFigure。
 *
 * 填充规则：
 *   - FILL_ALTERNATE（默认）：奇偶规则，类似 Canvas evenodd
 *   - FILL_WINDING：非零规则，类似 Canvas nonzero
 *
 * 用法：
 *   $path = new DrawPath($platform, DrawPath::FILL_WINDING);
 *   $path->moveTo(10, 10);
 *   $path->lineTo(100, 10);
 *   $path->lineTo(100, 100);
 *   $path->closeFigure();
 *   $ctx->fillPath($path);
 *   $path->free();
 */
final class DrawPath
{
    /** 填充规则：奇偶（alternate） */
    public const FILL_ALTERNATE = 0;
    /** 填充规则：非零（winding） */
    public const FILL_WINDING = 1;

    private WindowsPlatform $platform;
    private \FFI\CData $path;
    private bool $freed = false;

    /** 当前画笔位置（moveTo / lineTo 等更新） */
    private float $curX = 0.0;
    private float $curY = 0.0;

    /** 是否已开始新 figure（moveTo 后为 true，首次添加线段后为 false） */
    private bool $figureStarted = false;

    /**
     * @param WindowsPlatform $platform  平台实例。
     * @param int             $fillMode  填充规则（FILL_ALTERNATE / FILL_WINDING）。
     */
    public function __construct(WindowsPlatform $platform, int $fillMode = self::FILL_ALTERNATE)
    {
        $this->platform = $platform;
        $gp = $platform->getGdiplus();
        $p = $gp->new('GpPath');
        $status = (int) $gp->GdipCreatePath($fillMode, \FFI::addr($p));
        if ($status !== 0) {
            throw new \RuntimeException('GdipCreatePath failed: ' . $status);
        }
        $this->path = $p;
    }

    /**
     * 移动到指定点（开始新的子路径）。
     */
    public function moveTo(float $x, float $y): void
    {
        $gp = $this->platform->getGdiplus();
        $gp->GdipStartPathFigure($this->path);
        $this->curX = $x;
        $this->curY = $y;
        $this->figureStarted = true;
    }

    /**
     * 从当前点画直线到指定点。
     */
    public function lineTo(float $x, float $y): void
    {
        $gp = $this->platform->getGdiplus();
        $gp->GdipAddPathLine($this->path, $this->curX, $this->curY, $x, $y);
        $this->curX = $x;
        $this->curY = $y;
        $this->figureStarted = false;
    }

    /**
     * 添加圆弧路径。
     *
     * 弧的外接矩形为 (x, y, width, height)，
     * 从 startAngle 度开始，扫掠 sweepAngle 度。
     * 角度以度为单位，顺时针方向为正。
     *
     * @param float $x           外接矩形左上角 x。
     * @param float $y           外接矩形左上角 y。
     * @param float $width       外接矩形宽度。
     * @param float $height      外接矩形高度。
     * @param float $startAngle  起始角度（度）。
     * @param float $sweepAngle  扫掠角度（度，正值顺时针）。
     */
    public function arcTo(float $x, float $y, float $width, float $height, float $startAngle, float $sweepAngle): void
    {
        $gp = $this->platform->getGdiplus();
        $gp->GdipAddPathArc($this->path, $x, $y, $width, $height, $startAngle, $sweepAngle);
        // GDI+ 弧的终点不容易计算，这里近似更新当前位置
        $this->figureStarted = false;
    }

    /**
     * 添加三次贝塞尔曲线。
     *
     * 从当前点到 (x3, y3)，控制点为 (x1, y1) 和 (x2, y2)。
     *
     * @param float $x1 控制点1 x。
     * @param float $y1 控制点1 y。
     * @param float $x2 控制点2 x。
     * @param float $y2 控制点2 y。
     * @param float $x3 终点 x。
     * @param float $y3 终点 y。
     */
    public function bezierTo(float $x1, float $y1, float $x2, float $y2, float $x3, float $y3): void
    {
        $gp = $this->platform->getGdiplus();
        $gp->GdipAddPathBezier(
            $this->path,
            $this->curX, $this->curY,  // 起点
            $x1, $y1,                   // 控制点1
            $x2, $y2,                   // 控制点2
            $x3, $y3                    // 终点
        );
        $this->curX = $x3;
        $this->curY = $y3;
        $this->figureStarted = false;
    }

    /**
     * 添加二次贝塞尔曲线。
     *
     * 从当前点到 (x2, y2)，控制点为 (x1, y1)。
     * 内部转换为三次贝塞尔实现。
     *
     * @param float $x1 控制点 x。
     * @param float $y1 控制点 y。
     * @param float $x2 终点 x。
     * @param float $y2 终点 y。
     */
    public function quadTo(float $x1, float $y1, float $x2, float $y2): void
    {
        // 二次贝塞尔转三次贝塞尔
        // P0 = current, P1 = quad control, P2 = quad end
        // Cubic: C0 = P0, C1 = P0 + 2/3*(P1-P0), C2 = P2 + 2/3*(P1-P2), C3 = P2
        $cx1 = $this->curX + 2.0 / 3.0 * ($x1 - $this->curX);
        $cy1 = $this->curY + 2.0 / 3.0 * ($y1 - $this->curY);
        $cx2 = $x2 + 2.0 / 3.0 * ($x1 - $x2);
        $cy2 = $y2 + 2.0 / 3.0 * ($y1 - $y2);
        $this->bezierTo($cx1, $cy1, $cx2, $cy2, $x2, $y2);
    }

    /**
     * 闭合当前子路径（从当前点画直线回到子路径起点）。
     */
    public function closeFigure(): void
    {
        $gp = $this->platform->getGdiplus();
        $gp->GdipClosePathFigure($this->path);
        $this->figureStarted = false;
    }

    /**
     * 获取底层 GpPath 指针（供 DrawContext 使用）。
     *
     * @internal
     */
    public function getGpPath(): \FFI\CData
    {
        return $this->path;
    }

    /**
     * 释放底层 GpPath 资源。幂等。
     */
    public function free(): void
    {
        if ($this->freed) {
            return;
        }
        $this->freed = true;
        $this->platform->getGdiplus()->GdipDeletePath($this->path);
    }

    public function __destruct()
    {
        $this->free();
    }
}
