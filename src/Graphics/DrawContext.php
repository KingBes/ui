<?php
declare(strict_types=1);

namespace Kingbes\Ui\Graphics;

use Kingbes\Ui\Platform\Windows\WindowsPlatform;

/**
 * 绘图上下文（Windows 后端）。
 *
 * 包装 BeginPaint 返回的 HDC，同时持有：
 *   - GDI 对象栈：setPen/setBrush 创建的 HPEN/HBRUSH，析构时恢复 SelectObject
 *     并 DeleteObject，避免 GDI 资源泄漏。
 *   - GDI+ Graphics：从 HDC 创建（GdipCreateFromHDC），用于彩色 emoji 文本
 *     渲染（GdipDrawString 支持 Segoe UI Emoji 彩色字体）。
 *   - GDI+ Font / SolidFill / FontFamily：当前文本字体与画笔，析构时释放。
 *
 * 生命周期由 WindowsPlatform 管理：
 *   drawContextCreate(hwnd) → BeginPaint + GdipCreateFromHDC + new DrawContext
 *   → Area onDraw(ctx) → drawContextFree(ctx) → ctx->free()
 *     → 恢复 GDI 对象 + GdipDeleteGraphics + GdipDeleteFont/Brush/Family + EndPaint
 *
 * 注意：
 *   - HDC 跨 FFI 作用域：PAINTSTRUCT.hdc 在 user32 作用域，GDI 函数在 gdi32
 *     作用域、GDI+ 在 gdiplus 作用域，三者 void* 不互通，经 int 转换。
 *   - wchar_t 在 gdiplus 作用域需独立创建缓冲（与 user32 作用域不互通）。
 */
final class DrawContext
{
    /**
     * 平台实例（提供 FFI 作用域与辅助方法访问）。
     */
    private WindowsPlatform $platform;

    /**
     * PAINTSTRUCT CData（user32 作用域，保活到 EndPaint）。
     */
    private \FFI\CData $ps;

    /**
     * HWND CData（user32 作用域，EndPaint 使用）。
     */
    private \FFI\CData $hwnd;

    /**
     * HDC int 值（跨作用域传递的中间表示）。
     */
    private int $hdcInt;

    /**
     * gdi32 作用域的 HDC 指针（GDI 绘图使用）。
     *
     * 双缓冲启用后指向内存 DC（memDc），所有 GDI 绘制先写入内存
     * 位图，free() 时一次性 BitBlt 到屏幕 DC。
     */
    private \FFI\CData $hdcGdi;

    /**
     * 屏幕 DC（gdi32 作用域，BeginPaint 返回的原始 DC）。
     *
     * 双缓冲 BitBlt 的目标。内存 DC 创建时也以此为兼容参照。
     */
    private \FFI\CData $screenHdcGdi;

    /**
     * 内存兼容 DC（gdi32 作用域），用于离屏绘制。
     */
    private ?\FFI\CData $memDc = null;

    /**
     * 内存位图（gdi32 作用域），选入 memDc 作为绘制表面。
     */
    private ?\FFI\CData $memBitmap = null;

    /**
     * SelectObject(memDc, memBitmap) 返回的旧 1x1 单色位图，需在 free 时恢复。
     */
    private ?\FFI\CData $oldBitmap = null;

    /**
     * 双缓冲位图尺寸（客户区宽高，BitBlt 拷贝范围）。
     */
    private int $bufWidth = 0;

    /**
     * 双缓冲位图尺寸（客户区高，BitBlt 拷贝范围）。
     */
    private int $bufHeight = 0;

    /**
     * GDI+ Graphics（gdiplus 作用域）。
     */
    private \FFI\CData $graphics;

    /**
     * 当前 GDI+ Font（null 表示未设置，drawText 时按需创建默认）。
     */
    private ?\FFI\CData $gdiFont = null;

    /**
     * 当前 GDI+ SolidFill 画笔。
     */
    private ?\FFI\CData $gdiBrush = null;

    /**
     * 当前 GDI+ FontFamily（创建 Font 的依赖，需独立释放）。
     */
    private ?\FFI\CData $fontFamily = null;

    /**
     * 当前字体名（用于判断是否为 emoji 字体，决定渲染路径）。
     */
    private string $fontName = 'Segoe UI';

    /**
     * 当前字号（GDI 文本渲染用）。
     */
    private int $fontSize = 14;

    /**
     * GDI HFONT（emoji 文本渲染用，owned by gdiObjects 析构时释放）。
     */
    private $gdiHfont = null;

    /**
     * 已创建的 GDI 对象（HPEN/HBRUSH），析构时 DeleteObject。
     *
     * @var list<\FFI\CData>
     */
    private array $gdiObjects = [];

    /**
     * SelectObject 返回的旧对象，析构时按相反顺序恢复。
     *
     * @var list<\FFI\CData>
     */
    private array $oldObjects = [];

    /**
     * 当前文本颜色（同时驱动 GDI+ SolidFill 的 ARGB）。
     */
    private Color $textColor;

    /**
     * 当前 GDI+ 画笔（用于 stroke 操作）。null 表示按需创建。
     */
    private ?\FFI\CData $gdiPen = null;

    /**
     * 当前画笔颜色（用于按需创建 GDI+ Pen）。
     */
    private Color $penColor;

    /**
     * 当前画笔宽度。
     */
    private float $penWidth = 1.0;

    /**
     * 当前渐变画笔（null 表示使用纯色画刷）。
     */
    private ?GradientBrush $gradientBrush = null;

    /**
     * 释放标志（防止 free() 重复执行）。
     */
    private bool $freed = false;

    /**
     * DirectWrite 彩色 emoji 渲染器（惰性初始化，仅 emoji 字体路径使用）。
     */
    private static ?DirectWriteRenderer $dwRenderer = null;

    /**
     * DirectWrite 初始化结果缓存（避免每次 drawText 都重新尝试）。
     */
    private static ?bool $dwTried = null;

    /**
     * 获取 GDI+ Graphics CData（gdiplus 作用域）。
     *
     * 仅供 WindowsPlatform 内部使用（如 Area 滚动时应用世界坐标变换）。
     */
    public function getGraphics(): \FFI\CData
    {
        return $this->graphics;
    }

    /**
     * 获取 Area 客户区宽度（像素）。
     *
     * onDraw 回调可用此值代替硬编码尺寸，确保背景/网格等能铺满
     * 整个绘图区域，避免窗口放大后出现黑边（双缓冲内存位图未覆盖区域）。
     */
    public function getWidth(): int
    {
        return $this->bufWidth;
    }

    /**
     * 获取 Area 客户区高度（像素）。
     */
    public function getHeight(): int
    {
        return $this->bufHeight;
    }

    /**
     * 内部构造，由 WindowsPlatform::drawContextCreate 创建。
     *
     * 双缓冲流程：
     *   1. 保存 BeginPaint 返回的屏幕 DC（screenHdcGdi）。
     *   2. GetClientRect 获取客户区尺寸。
     *   3. CreateCompatibleDC(screenHdc) → 内存 DC。
     *   4. CreateCompatibleBitmap(screenHdc, w, h) → 等大位图。
     *   5. SelectObject(memDc, memBitmap) 关联位图与内存 DC。
     *   6. 后续 GDI/GDI+ 绘制全部基于 memDc（hdcGdi 指向 memDc）。
     *   7. free() 时 BitBlt 一次性把 memDc 拷贝到 screenHdcGdi。
     *
     * 客户区为 0x0 时（窗口尚未布局）跳过双缓冲，直接用屏幕 DC，
     * 避免 CreateCompatibleBitmap(0,0) 失败。
     *
     * @param WindowsPlatform $platform 平台实例。
     * @param \FFI\CData      $ps       PAINTSTRUCT（user32 作用域，已由 BeginPaint 填充）。
     * @param \FFI\CData      $hwnd     HWND（user32 作用域，EndPaint 使用）。
     * @param int             $hdcInt   HDC int 值（已由平台从 ps.hdc 转换）。
     */
    public function __construct(WindowsPlatform $platform, \FFI\CData $ps, \FFI\CData $hwnd, int $hdcInt)
    {
        $this->platform = $platform;
        $this->ps = $ps;
        $this->hwnd = $hwnd;
        $this->hdcInt = $hdcInt;
        $this->textColor = Color::black();
        $this->penColor = Color::black();

        $gdi = $platform->getGdi32();
        // 屏幕 DC（gdi32 作用域），双缓冲的兼容参照与 BitBlt 目标
        $this->screenHdcGdi = $platform->intToPtrIn($gdi, $hdcInt);

        // 默认 hdcGdi 指向屏幕 DC（客户区为 0 时回退路径）
        $this->hdcGdi = $this->screenHdcGdi;

        // 获取客户区尺寸，用于创建等大内存位图
        $rect = $platform->getUser32()->new('RECT');
        $platform->getUser32()->GetClientRect($hwnd, \FFI::addr($rect));
        $w = (int) ($rect->right - $rect->left);
        $h = (int) ($rect->bottom - $rect->top);

        // 始终记录客户区尺寸，供 getWidth/getHeight 暴露给 onDraw 回调
        $this->bufWidth = $w;
        $this->bufHeight = $h;

        if ($w > 0 && $h > 0) {
            // 创建内存 DC 与等大位图
            $memDc = $gdi->CreateCompatibleDC($this->screenHdcGdi);
            $memBmp = $gdi->CreateCompatibleBitmap($this->screenHdcGdi, $w, $h);
            $oldBmp = $gdi->SelectObject($memDc, $memBmp);

            $this->memDc = $memDc;
            $this->memBitmap = $memBmp;
            $this->oldBitmap = $oldBmp;
            // 后续 GDI 绘制全部走内存 DC
            $this->hdcGdi = $memDc;
        }

        // 创建 GDI+ Graphics：基于 hdcGdi（内存 DC 或屏幕 DC）
        $gp = $platform->getGdiplus();
        $hdcGpInt = $platform->ptrToIntIn($gdi, $this->hdcGdi);
        $hdcGp = $platform->intToPtrIn($gp, $hdcGpInt);
        $g = $gp->new('GpGraphics');
        $status = (int) $gp->GdipCreateFromHDC($hdcGp, \FFI::addr($g));
        if ($status !== 0) {
            throw new \RuntimeException(
                'GdipCreateFromHDC failed with status ' . $status
            );
        }
        $this->graphics = $g;
    }

    // ============================================================
    // GDI 绘图方法（线条/矩形/椭圆）
    // ============================================================

    /**
     * 设置画笔（线条颜色/宽度）。
     *
     * 同时创建 GDI 画笔（drawLine/drawRect 等）和 GDI+ 画笔（strokePath 等）。
     * PS_SOLID=0。旧 GDI 画笔压栈以便析构恢复。
     */
    public function setPen(Color $color, int $width = 1): void
    {
        $gdi = $this->platform->getGdi32();
        $pen = $gdi->CreatePen(0, max(1, $width), $color->toColorRef());
        $old = $gdi->SelectObject($this->hdcGdi, $pen);
        $this->gdiObjects[] = $pen;
        $this->oldObjects[] = $old;

        // 同步 GDI+ 画笔
        $this->penColor = $color;
        $this->penWidth = (float) max(1, $width);
        $this->ensureGdiPen();
    }

    /**
     * 设置画刷（填充颜色）。
     *
     * 旧画刷压栈以便析构恢复。
     */
    public function setBrush(Color $color): void
    {
        $gdi = $this->platform->getGdi32();
        $brush = $gdi->CreateSolidBrush($color->toColorRef());
        $old = $gdi->SelectObject($this->hdcGdi, $brush);
        $this->gdiObjects[] = $brush;
        $this->oldObjects[] = $old;
    }

    /**
     * 画直线（使用当前画笔）。
     */
    public function drawLine(int $x1, int $y1, int $x2, int $y2): void
    {
        $gdi = $this->platform->getGdi32();
        $gdi->MoveToEx($this->hdcGdi, $x1, $y1, null);
        $gdi->LineTo($this->hdcGdi, $x2, $y2);
    }

    /**
     * 画矩形边框（使用当前画笔）。
     *
     * GDI Rectangle 用左上+右下坐标，此处转交 w/h → right/bottom。
     */
    public function drawRect(int $x, int $y, int $w, int $h): void
    {
        $this->platform->getGdi32()->Rectangle(
            $this->hdcGdi,
            $x, $y, $x + $w, $y + $h
        );
    }

    /**
     * 画椭圆（使用当前画笔/画刷）。
     */
    public function drawEllipse(int $x, int $y, int $w, int $h): void
    {
        $this->platform->getGdi32()->Ellipse(
            $this->hdcGdi,
            $x, $y, $x + $w, $y + $h
        );
    }

    // ============================================================
    // GDI+ 文本渲染（彩色 emoji + 中文）
    // ============================================================

    /**
     * 设置文本字体（创建 GDI+ Font）。
     *
     * 重复调用会先释放旧的 Font/FontFamily 再创建新的。
     *
     * @param string $name 字体名（如 "Segoe UI"、"Segoe UI Emoji"）。
     * @param int    $size 字号（像素，UnitPixel）。
     */
    public function setFont(string $name, int $size): void
    {
        $gp = $this->platform->getGdiplus();

        // 记录字体名与字号（emoji 文本渲染路径判断用）
        $this->fontName = $name;
        $this->fontSize = $size;

        // 释放旧字体/字族
        if ($this->gdiFont !== null) {
            $gp->GdipDeleteFont($this->gdiFont);
            $this->gdiFont = null;
        }
        if ($this->fontFamily !== null) {
            $gp->GdipDeleteFontFamily($this->fontFamily);
            $this->fontFamily = null;
        }

        // 创建字族（wchar_t 须在 gdiplus 作用域创建）
        $nameBuf = $this->wideBufGp($name);
        $family = $gp->new('GpFontFamily');
        $status = (int) $gp->GdipCreateFontFamilyFromName(
            \FFI::addr($nameBuf[0]),
            null,
            \FFI::addr($family)
        );
        if ($status !== 0) {
            // 字体不存在时回退到默认（GenericSansSerif）
            trigger_error(
                'GdipCreateFontFamilyFromName failed for "' . $name . '" (status ' . $status . ')',
                \E_USER_WARNING
            );
            return;
        }
        $this->fontFamily = $family;

        // 创建字体：style=Regular(0), unit=Pixel(2)
        $font = $gp->new('GpFont');
        $status = (int) $gp->GdipCreateFont(
            $family,
            (float) max(1, $size),
            0,   // FontStyleRegular
            2,   // UnitPixel
            \FFI::addr($font)
        );
        if ($status !== 0) {
            trigger_error(
                'GdipCreateFont failed (status ' . $status . ')',
                \E_USER_WARNING
            );
            return;
        }
        $this->gdiFont = $font;
    }

    /**
     * 设置文本颜色（创建 GDI+ SolidFill 画笔）。
     *
     * ARGB = 0xFFRRGGBB（alpha=255 不透明）。重复调用先释放旧画笔。
     */
    public function setColor(Color $color): void
    {
        $gp = $this->platform->getGdiplus();
        if ($this->gdiBrush !== null) {
            $gp->GdipDeleteBrush($this->gdiBrush);
            $this->gdiBrush = null;
        }

        // ARGB 32 位（带 alpha=0xFF），转为有符号 int32 避免溢出
        $argb = (0xFF << 24)
            | (($color->r & 0xFF) << 16)
            | (($color->g & 0xFF) << 8)
            | ($color->b & 0xFF);
        if ($argb > 0x7FFFFFFF) {
            $argb -= 0x100000000;
        }

        $brush = $gp->new('GpSolidFill');
        $status = (int) $gp->GdipCreateSolidFill($argb, \FFI::addr($brush));
        if ($status !== 0) {
            trigger_error(
                'GdipCreateSolidFill failed (status ' . $status . ')',
                \E_USER_WARNING
            );
            return;
        }
        $this->gdiBrush = $brush;
        $this->textColor = $color;
    }

    /**
     * 绘制文本。
     *
     * 渲染路径选择：
     *   - 若当前字体名包含 "Emoji"（如 Segoe UI Emoji）：用 GDI TextOutW。
     *     GDI 在 Windows 8+ 通过字体回退支持彩色 emoji 渲染，
     *     而 GDI+ 1.0 的 GdipDrawString 只渲染单色字形。
     *   - 其他字体：用 GDI+ GdipDrawString（抗锯齿质量更好）。
     *
     * @param int    $x    起点 x（像素）。
     * @param int    $y    起点 y（像素）。
     * @param string $text UTF-8 文本（含 emoji）。
     */
    public function drawText(int $x, int $y, string $text): void
    {
        if ($text === '') {
            return;
        }
        // 未设置字体/颜色时用默认
        if ($this->gdiFont === null) {
            $this->setFont('Segoe UI', 14);
        }
        if ($this->gdiBrush === null) {
            $this->setColor($this->textColor);
        }

        // emoji 字体优先走 DirectWrite 彩色渲染
        // GDI TextOutW 在部分系统只能渲染单色字形，DirectWrite 通过
        // D2D1_DRAW_TEXT_OPTIONS_ENABLE_COLOR_FONT 支持彩色 emoji。
        if (stripos($this->fontName, 'Emoji') !== false) {
            $dw = $this->getDwRenderer();
            if ($dw !== null) {
                // DirectWrite 字号用像素值近似（96 DPI 下 1px ≈ 0.75pt）
                $dipSize = max(1.0, (float) $this->fontSize);
                $dw->drawText(
                    $this->hdcInt,
                    $x,
                    $y,
                    10000,
                    1000,
                    $text,
                    $this->fontName,
                    (int) round($dipSize),
                    $this->textColor
                );
                return;
            }
            // DirectWrite 不可用，回退到 GDI TextOutW
            $this->drawTextGdi($x, $y, $text);
            return;
        }

        if ($this->gdiFont === null || $this->gdiBrush === null) {
            return;
        }

        $gp = $this->platform->getGdiplus();

        // UTF-8 → UTF-16LE → gdiplus 作用域 wchar_t[]
        $wide = WindowsPlatform::conv($text, 'UTF-16LE', 'UTF-8');
        $len = intdiv(strlen($wide), 2);
        if ($len === 0) {
            return;
        }
        $arr = $gp->new('wchar_t[' . ($len + 1) . ']');
        for ($i = 0; $i < $len; $i++) {
            $arr[$i] = ord($wide[$i * 2]) | (ord($wide[$i * 2 + 1]) << 8);
        }
        $arr[$len] = 0;

        // layoutRect: RectF，宽度设大值确保文本完整绘制
        $rect = $gp->new('RectF');
        $rect->X = (float) $x;
        $rect->Y = (float) $y;
        $rect->Width = 10000.0;
        $rect->Height = 1000.0;

        $gp->GdipDrawString(
            $this->graphics,
            \FFI::addr($arr[0]),
            $len,
            $this->gdiFont,
            \FFI::addr($rect),
            null,  // StringFormat=null 使用默认
            $this->gdiBrush
        );
    }

    /**
     * 用 GDI TextOutW 绘制文本（彩色 emoji 渲染路径）。
     *
     * 创建 HFONT（CreateFontW）→ SelectObject 到 HDC → SetTextColor/
     * SetBkMode(TRANSPARENT) → TextOutW → 恢复旧字体 → DeleteObject。
     * HFONT 加入 gdiObjects 在 free() 时统一释放。
     */
    private function drawTextGdi(int $x, int $y, string $text): void
    {
        $gdi = $this->platform->getGdi32();

        // 创建 HFONT（负高度表示点大小映射，96 DPI）
        $h = -max(1, (int) round($this->fontSize * 96 / 72));
        $nameBuf = $this->wideBufGdi($this->fontName);
        $hfont = $gdi->CreateFontW(
            $h, 0, 0, 0,
            400,   // FW_NORMAL
            0, 0, 0,
            1,     // DEFAULT_CHARSET
            0, 0, 0, 0,
            \FFI::addr($nameBuf[0])
        );
        $oldFont = $gdi->SelectObject($this->hdcGdi, $hfont);
        $this->gdiObjects[] = $hfont;
        $this->oldObjects[] = $oldFont;

        // 设置文本颜色与透明背景
        $gdi->SetTextColor($this->hdcGdi, $this->textColor->toColorRef());
        $gdi->SetBkMode($this->hdcGdi, 1); // TRANSPARENT=1

        // UTF-8 → UTF-16LE → gdi32 作用域 wchar_t[]
        $wide = WindowsPlatform::conv($text, 'UTF-16LE', 'UTF-8');
        $len = intdiv(strlen($wide), 2);
        if ($len === 0) {
            return;
        }
        $buf = $gdi->new('wchar_t[' . ($len + 1) . ']');
        for ($i = 0; $i < $len; $i++) {
            $buf[$i] = ord($wide[$i * 2]) | (ord($wide[$i * 2 + 1]) << 8);
        }
        $buf[$len] = 0;

        $gdi->TextOutW($this->hdcGdi, $x, $y, \FFI::addr($buf[0]), $len);
    }

    /**
     * 在 gdi32 作用域创建 wchar_t[] 缓冲（owned=false 持久化）。
     */
    private function wideBufGdi(string $text): \FFI\CData
    {
        $gdi = $this->platform->getGdi32();
        $wide = WindowsPlatform::conv($text, 'UTF-16LE', 'UTF-8');
        $len = max(1, intdiv(strlen($wide), 2));
        $arr = $gdi->new('wchar_t[' . ($len + 1) . ']', false);
        for ($i = 0; $i < $len; $i++) {
            $arr[$i] = ord($wide[$i * 2]) | (ord($wide[$i * 2 + 1]) << 8);
        }
        $arr[$len] = 0;
        return $arr;
    }

    /**
     * 绘制富文本（按 AttributedString ID 查找并分段绘制）。
     *
     * @param int $x                起点 x。
     * @param int $y                起点 y。
     * @param int $attributedStringId 富文本对象 ID（由 AttributedString 构造时分配）。
     */
    public function drawTextAttributed(int $x, int $y, int $attributedStringId): void
    {
        $str = $this->platform->getAttrString($attributedStringId);
        if ($str !== null) {
            $str->draw($this, $x, $y);
        }
    }

    // ============================================================
    // 路径系统（#8）/ fill-stroke 分离（#9）/ 曲线圆弧（#11 #12）
    // ============================================================

    /**
     * 创建路径对象。
     *
     * @param int $fillMode 填充规则（DrawPath::FILL_ALTERNATE / FILL_WINDING）。
     */
    public function createPath(int $fillMode = DrawPath::FILL_ALTERNATE): DrawPath
    {
        return new DrawPath($this->platform, $fillMode);
    }

    /**
     * 创建线性渐变画笔。
     *
     * @param float $x1     起点 x。
     * @param float $y1     起点 y。
     * @param float $x2     终点 x。
     * @param float $y2     终点 y。
     * @param Color $color1 起点颜色。
     * @param Color $color2 终点颜色。
     */
    public function createGradientBrush(
        float $x1, float $y1,
        float $x2, float $y2,
        Color $color1, Color $color2
    ): GradientBrush {
        return new GradientBrush($this->platform, $x1, $y1, $x2, $y2, $color1, $color2);
    }

    /**
     * 填充矩形（仅填充，无边框）。
     *
     * 使用当前画刷（setColor 设置的纯色画刷或 setGradientBrush 设置的渐变画刷）。
     */
    public function fillRect(int $x, int $y, int $w, int $h): void
    {
        $this->ensureFillBrush();
        $this->platform->getGdiplus()->GdipFillRectangle(
            $this->graphics, $this->getActiveFillBrush(),
            (float) $x, (float) $y, (float) $w, (float) $h
        );
    }

    /**
     * 描边矩形（仅边框，不填充）。
     *
     * 使用当前画笔（setPen 设置）。
     */
    public function strokeRect(int $x, int $y, int $w, int $h): void
    {
        $this->ensureGdiPen();
        $this->platform->getGdiplus()->GdipDrawRectangle(
            $this->graphics, $this->gdiPen,
            (float) $x, (float) $y, (float) $w, (float) $h
        );
    }

    /**
     * 填充椭圆（仅填充，无边框）。
     */
    public function fillEllipse(int $x, int $y, int $w, int $h): void
    {
        $this->ensureFillBrush();
        $this->platform->getGdiplus()->GdipFillEllipse(
            $this->graphics, $this->getActiveFillBrush(),
            (float) $x, (float) $y, (float) $w, (float) $h
        );
    }

    /**
     * 描边椭圆（仅边框，不填充）。
     */
    public function strokeEllipse(int $x, int $y, int $w, int $h): void
    {
        $this->ensureGdiPen();
        $this->platform->getGdiplus()->GdipDrawEllipse(
            $this->graphics, $this->gdiPen,
            (float) $x, (float) $y, (float) $w, (float) $h
        );
    }

    /**
     * 填充路径。
     *
     * 使用当前画刷填充闭合路径区域。
     *
     * @param DrawPath $path 路径对象。
     */
    public function fillPath(DrawPath $path): void
    {
        $this->ensureFillBrush();
        $this->platform->getGdiplus()->GdipFillPath(
            $this->graphics, $this->getActiveFillBrush(), $path->getGpPath()
        );
    }

    /**
     * 描边路径。
     *
     * 使用当前画笔沿路径绘制线条。
     *
     * @param DrawPath $path 路径对象。
     */
    public function strokePath(DrawPath $path): void
    {
        $this->ensureGdiPen();
        $this->platform->getGdiplus()->GdipDrawPath(
            $this->graphics, $this->gdiPen, $path->getGpPath()
        );
    }

    /**
     * 绘制三次贝塞尔曲线。
     *
     * @param int $x1 起点 x。  @param int $y1 起点 y。
     * @param int $x2 控制点1 x。 @param int $y2 控制点1 y。
     * @param int $x3 控制点2 x。 @param int $y3 控制点2 y。
     * @param int $x4 终点 x。  @param int $y4 终点 y。
     */
    public function drawBezier(
        int $x1, int $y1, int $x2, int $y2, int $x3, int $y3, int $x4, int $y4
    ): void {
        $this->ensureGdiPen();
        $this->platform->getGdiplus()->GdipDrawBezier(
            $this->graphics, $this->gdiPen,
            (float) $x1, (float) $y1, (float) $x2, (float) $y2,
            (float) $x3, (float) $y3, (float) $x4, (float) $y4
        );
    }

    /**
     * 绘制圆弧。
     *
     * @param int   $x           外接矩形左上角 x。
     * @param int   $y           外接矩形左上角 y。
     * @param int   $width       外接矩形宽度。
     * @param int   $height      外接矩形高度。
     * @param float $startAngle  起始角度（度）。
     * @param float $sweepAngle  扫掠角度（度，正值顺时针）。
     */
    public function drawArc(
        int $x, int $y, int $width, int $height,
        float $startAngle, float $sweepAngle
    ): void {
        $this->ensureGdiPen();
        $this->platform->getGdiplus()->GdipDrawArc(
            $this->graphics, $this->gdiPen,
            (float) $x, (float) $y, (float) $width, (float) $height,
            $startAngle, $sweepAngle
        );
    }

    // ============================================================
    // 变换矩阵（#13）
    // ============================================================

    /**
     * 平移变换。
     *
     * 后续绘制操作的原点偏移 (dx, dy)。
     *
     * @param float $dx x 方向偏移。
     * @param float $dy y 方向偏移。
     */
    public function translate(float $dx, float $dy): void
    {
        // MatrixOrderPrepend=0：新变换在已有变换之前应用（后指定的先生效）
        $this->platform->getGdiplus()->GdipTranslateWorldTransform(
            $this->graphics, $dx, $dy, 0
        );
    }

    /**
     * 缩放变换。
     *
     * @param float $sx x 方向缩放比例。
     * @param float $sy y 方向缩放比例。
     */
    public function scale(float $sx, float $sy): void
    {
        $this->platform->getGdiplus()->GdipScaleWorldTransform(
            $this->graphics, $sx, $sy, 0
        );
    }

    /**
     * 旋转变换。
     *
     * @param float $angle 旋转角度（度，正值顺时针）。
     */
    public function rotate(float $angle): void
    {
        $this->platform->getGdiplus()->GdipRotateWorldTransform(
            $this->graphics, $angle, 0
        );
    }

    /**
     * 保存当前图形状态（变换、裁剪等）。
     *
     * 可与 restore() 配对实现状态栈。
     *
     * @return int 状态 ID，传给 restore() 恢复。
     */
    public function save(): int
    {
        $gp = $this->platform->getGdiplus();
        $state = $gp->new('unsigned int');
        $gp->GdipSaveGraphics($this->graphics, \FFI::addr($state));
        return (int) $state->cdata;
    }

    /**
     * 恢复之前保存的图形状态。
     *
     * @param int $state save() 返回的状态 ID。
     */
    public function restore(int $state): void
    {
        $this->platform->getGdiplus()->GdipRestoreGraphics(
            $this->graphics, $state
        );
    }

    // ============================================================
    // 裁剪（#14）
    // ============================================================

    /**
     * 设置路径裁剪区域。
     *
     * 后续绘制操作仅在路径区域内可见。
     *
     * @param DrawPath $path 裁剪路径。
     */
    public function setClipPath(DrawPath $path): void
    {
        // CombineModeReplace=0
        $this->platform->getGdiplus()->GdipSetClipPath(
            $this->graphics, $path->getGpPath(), 0
        );
    }

    /**
     * 设置矩形裁剪区域。
     *
     * @param int $x 矩形左上角 x。
     * @param int $y 矩形左上角 y。
     * @param int $w 矩形宽度。
     * @param int $h 矩形高度。
     */
    public function setClipRect(int $x, int $y, int $w, int $h): void
    {
        // CombineModeReplace=0
        $this->platform->getGdiplus()->GdipSetClipRect(
            $this->graphics, (float) $x, (float) $y, (float) $w, (float) $h, 0
        );
    }

    /**
     * 重置裁剪区域（移除所有裁剪限制）。
     */
    public function resetClip(): void
    {
        $this->platform->getGdiplus()->GdipResetClip($this->graphics);
    }

    // ============================================================
    // 渐变画笔（#10）
    // ============================================================

    /**
     * 设置渐变画笔（后续 fill 操作使用此画笔）。
     *
     * 传 null 恢复为纯色画刷。
     *
     * @param GradientBrush|null $brush 渐变画笔，或 null 恢复纯色。
     */
    public function setGradientBrush(?GradientBrush $brush): void
    {
        $this->gradientBrush = $brush;
    }

    // ============================================================
    // 图像绘制（#23）
    // ============================================================

    /**
     * 绘制图像（原始尺寸）。
     *
     * 在 (x, y) 处按图像自身像素尺寸绘制，不缩放。
     *
     * @param Image $image 图像对象。
     * @param int   $x     目标左上角 x。
     * @param int   $y     目标左上角 y。
     */
    public function drawImage(Image $image, int $x, int $y): void
    {
        $this->platform->getGdiplus()->GdipDrawImage(
            $this->graphics,
            $image->getGpImage(),
            (float) $x,
            (float) $y
        );
    }

    /**
     * 绘制图像（缩放到指定尺寸）。
     *
     * 将图像整体缩放绘制到 (x, y, w, h) 矩形内。
     *
     * @param Image $image 图像对象。
     * @param int   $x     目标左上角 x。
     * @param int   $y     目标左上角 y。
     * @param int   $w     目标宽度。
     * @param int   $h     目标高度。
     */
    public function drawImageScaled(Image $image, int $x, int $y, int $w, int $h): void
    {
        $this->platform->getGdiplus()->GdipDrawImageRect(
            $this->graphics,
            $image->getGpImage(),
            (float) $x,
            (float) $y,
            (float) $w,
            (float) $h
        );
    }

    /**
     * 绘制图像（裁剪 + 缩放）。
     *
     * 从图像源矩形 (sx, sy, sw, sh) 取出内容，绘制到目标矩形 (dx, dy, dw, dh)。
     * 源/目标尺寸可不同，会自动缩放。源矩形超出图像范围的部分会被裁剪。
     *
     * @param Image $image 图像对象。
     * @param int   $dx    目标左上角 x。
     * @param int   $dy    目标左上角 y。
     * @param int   $dw    目标宽度。
     * @param int   $dh    目标高度。
     * @param int   $sx    源左上角 x（图像坐标系）。
     * @param int   $sy    源左上角 y（图像坐标系）。
     * @param int   $sw    源宽度。
     * @param int   $sh    源高度。
     */
    public function drawImageCropped(
        Image $image,
        int $dx, int $dy, int $dw, int $dh,
        int $sx, int $sy, int $sw, int $sh
    ): void {
        // srcUnit=2 表示 UnitPixel（与 Graphics 像素坐标系一致）
        // imageAttributes=null / callback=null / callbackData=null
        $this->platform->getGdiplus()->GdipDrawImageRectRect(
            $this->graphics,
            $image->getGpImage(),
            (float) $dx, (float) $dy, (float) $dw, (float) $dh,
            (float) $sx, (float) $sy, (float) $sw, (float) $sh,
            2, null, null, null
        );
    }

    // ============================================================
    // 释放
    // ============================================================

    /**
     * 释放绘图上下文（恢复 GDI 对象栈 + GDI+ 释放 + EndPaint）。
     *
     * 幂等：重复调用无副作用。由 WindowsPlatform::drawContextFree 调用，
     * 也会在析构时自动触发。
     */
    public function free(): void
    {
        if ($this->freed) {
            return;
        }
        $this->freed = true;

        $gdi = $this->platform->getGdi32();
        // 恢复 GDI 对象（逆序）并删除创建的对象
        for ($i = count($this->gdiObjects) - 1; $i >= 0; $i--) {
            if (isset($this->oldObjects[$i])) {
                $gdi->SelectObject($this->hdcGdi, $this->oldObjects[$i]);
            }
            if (isset($this->gdiObjects[$i])) {
                $gdi->DeleteObject($this->gdiObjects[$i]);
            }
        }
        $this->gdiObjects = [];
        $this->oldObjects = [];

        // GDI+ 释放
        $gp = $this->platform->getGdiplus();
        if ($this->gdiPen !== null) {
            $gp->GdipDeletePen($this->gdiPen);
            $this->gdiPen = null;
        }
        if ($this->gdiFont !== null) {
            $gp->GdipDeleteFont($this->gdiFont);
            $this->gdiFont = null;
        }
        if ($this->gdiBrush !== null) {
            $gp->GdipDeleteBrush($this->gdiBrush);
            $this->gdiBrush = null;
        }
        if ($this->fontFamily !== null) {
            $gp->GdipDeleteFontFamily($this->fontFamily);
            $this->fontFamily = null;
        }
        $gp->GdipDeleteGraphics($this->graphics);

        // 双缓冲：把内存 DC 一次性 BitBlt 到屏幕 DC，再释放内存资源。
        // 顺序：GDI+ Graphics 已删除 → BitBlt（memDc 已无 GDI+ 引用）→
        //       恢复 memDc 旧位图 → DeleteObject(memBitmap) → DeleteDC(memDc)
        if ($this->memDc !== null) {
            // SRCCOPY = 0x00CC0020
            $gdi->BitBlt(
                $this->screenHdcGdi,
                0, 0, $this->bufWidth, $this->bufHeight,
                $this->memDc,
                0, 0,
                0x00CC0020
            );
            // 恢复 memDc 的默认 1x1 单色位图，再释放用户位图与 DC
            if ($this->oldBitmap !== null) {
                $gdi->SelectObject($this->memDc, $this->oldBitmap);
            }
            if ($this->memBitmap !== null) {
                $gdi->DeleteObject($this->memBitmap);
            }
            $gdi->DeleteDC($this->memDc);
            $this->memDc = null;
            $this->memBitmap = null;
            $this->oldBitmap = null;
        }

        // EndPaint 配对 BeginPaint
        $this->platform->getUser32()->EndPaint($this->hwnd, \FFI::addr($this->ps));
    }

    /**
     * 析构：确保资源释放（兜底，正常流程由 drawContextFree 主动调用）。
     */
    public function __destruct()
    {
        $this->free();
    }

    // ============================================================
    // 内部辅助
    // ============================================================

    /**
     * 在 gdiplus 作用域创建 wchar_t[] 缓冲（owned=false 持久化）。
     *
     * gdiplus 作用域的 wchar_t 与 user32/gdi32 不互通，必须在本地创建。
     */
    private function wideBufGp(string $text): \FFI\CData
    {
        $gp = $this->platform->getGdiplus();
        $wide = WindowsPlatform::conv($text, 'UTF-16LE', 'UTF-8');
        $len = max(1, intdiv(strlen($wide), 2));
        $arr = $gp->new('wchar_t[' . ($len + 1) . ']', false);
        for ($i = 0; $i < $len; $i++) {
            $arr[$i] = ord($wide[$i * 2]) | (ord($wide[$i * 2 + 1]) << 8);
        }
        $arr[$len] = 0;
        return $arr;
    }

    /**
     * 获取 DirectWrite 渲染器单例（惰性初始化）。
     *
     * DirectWrite 彩色 emoji 渲染需要通过 COM vtable 调用多参数方法，
     * 但 PHP FFI 调用 vtable 函数指针（2+ 参数）时会触发访问违规崩溃。
     * 因此暂时禁用 DirectWrite 路径，emoji 回退到 GDI 单色渲染。
     *
     * DirectWriteRenderer 类保留以供未来 PHP FFI 修复后启用。
     */
    private function getDwRenderer(): ?DirectWriteRenderer
    {
        return null;
    }

    /**
     * 确保 GDI+ 画笔已创建（按需创建）。
     */
    private function ensureGdiPen(): void
    {
        if ($this->gdiPen !== null) {
            return;
        }
        $gp = $this->platform->getGdiplus();
        $argb = self::colorToArgb($this->penColor);
        $pen = $gp->new('GpPen');
        $status = (int) $gp->GdipCreatePen1(
            $argb, $this->penWidth, 2, \FFI::addr($pen)
        );
        if ($status !== 0) {
            trigger_error('GdipCreatePen1 failed: ' . $status, \E_USER_WARNING);
            return;
        }
        $this->gdiPen = $pen;
    }

    /**
     * 确保填充画刷可用（纯色或渐变）。
     */
    private function ensureFillBrush(): void
    {
        if ($this->gradientBrush !== null) {
            return;
        }
        if ($this->gdiBrush === null) {
            $this->setColor($this->textColor);
        }
    }

    /**
     * 获取当前激活的填充画刷（渐变优先，否则纯色）。
     */
    private function getActiveFillBrush(): \FFI\CData
    {
        if ($this->gradientBrush !== null) {
            return $this->gradientBrush->getBrush();
        }
        return $this->gdiBrush;
    }

    /**
     * Color 转 GDI+ ARGB（int32 有符号）。
     */
    private static function colorToArgb(Color $c): int
    {
        $argb = (0xFF << 24)
            | (($c->r & 0xFF) << 16)
            | (($c->g & 0xFF) << 8)
            | ($c->b & 0xFF);
        if ($argb > 0x7FFFFFFF) {
            $argb -= 0x100000000;
        }
        return $argb;
    }
}
