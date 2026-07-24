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
     */
    private \FFI\CData $hdcGdi;

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
     * 内部构造，由 WindowsPlatform::drawContextCreate 创建。
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

        // gdi32 作用域 HDC
        $this->hdcGdi = $platform->intToPtrIn($platform->getGdi32(), $hdcInt);

        // 创建 GDI+ Graphics：gdiplus 作用域 HDC → GdipCreateFromHDC
        $gp = $platform->getGdiplus();
        $hdcGp = $platform->intToPtrIn($gp, $hdcInt);
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
     * PS_SOLID=0。旧画笔压栈以便析构恢复。
     */
    public function setPen(Color $color, int $width = 1): void
    {
        $gdi = $this->platform->getGdi32();
        $pen = $gdi->CreatePen(0, max(1, $width), $color->toColorRef());
        $old = $gdi->SelectObject($this->hdcGdi, $pen);
        $this->gdiObjects[] = $pen;
        $this->oldObjects[] = $old;
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
        $wide = mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');
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
        $wide = mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');
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
        $wide = mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');
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
        $wide = mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');
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
}
