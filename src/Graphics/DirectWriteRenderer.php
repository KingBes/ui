<?php
declare(strict_types=1);

namespace Kingbes\Ui\Graphics;

use Kingbes\Ui\Platform\Windows\WindowsPlatform;
use Kingbes\Phpc\Library;

/**
 * DirectWrite + Direct2D 彩色 emoji 渲染器。
 *
 * GDI（TextOutW）和 GDI+ 1.0（GdipDrawString）均不支持彩色字体
 * （COLR/CPAL/CBDT/sbix 表），仅 DirectWrite 通过
 * ID2D1RenderTarget::DrawTextLayout 配合
 * D2D1_DRAW_TEXT_OPTIONS_ENABLE_COLOR_FONT 标志支持彩色 emoji。
 *
 * 本类通过 FFI 调用 COM 接口方法（vtable 结构体声明）实现：
 *   1. DWriteCreateFactory → IDWriteFactory
 *   2. D2D1CreateFactory → ID2D1Factory
 *   3. ID2D1Factory::CreateDCRenderTarget → ID2D1DCRenderTarget
 *   4. 每次渲染：BindDC → BeginDraw → CreateTextFormat →
 *      CreateTextLayout → CreateSolidColorBrush → DrawTextLayout →
 *      EndDraw → Release 临时对象
 *
 * COM 方法通过 vtable 结构体声明调用：
 *   $factory->lpVtbl->CreateTextFormat($factory, ...);
 * 未使用的方法用 void* 占位，仅声明实际调用的方法签名。
 *
 * 不可用时（旧版 Windows / DLL 缺失）回退到 GDI 单色 emoji。
 */
final class DirectWriteRenderer
{
    // ============================================================
    // Win32 / DirectWrite / Direct2D 常量
    // ============================================================

    private const S_OK = 0;

    // D2D1_FACTORY_TYPE
    private const D2D1_FACTORY_TYPE_SINGLE_THREADED = 0;

    // DWRITE_FACTORY_TYPE
    private const DWRITE_FACTORY_TYPE_SHARED = 0;

    // D2D1_RENDER_TARGET_TYPE
    private const D2D1_RENDER_TARGET_TYPE_DEFAULT = 0;

    // DXGI_FORMAT
    private const DXGI_FORMAT_UNKNOWN = 0;

    // D2D1_ALPHA_MODE
    private const D2D1_ALPHA_MODE_PREMULTIPLIED = 1;

    // D2D1_RENDER_TARGET_USAGE
    private const D2D1_RENDER_TARGET_USAGE_GDI_COMPATIBLE = 2;

    // D2D1_DRAW_TEXT_OPTIONS
    private const D2D1_DRAW_TEXT_OPTIONS_ENABLE_COLOR_FONT = 4;

    // DWRITE_FONT_WEIGHT / STYLE / STRETCH
    private const DWRITE_FONT_WEIGHT_NORMAL  = 400;
    private const DWRITE_FONT_STYLE_NORMAL   = 0;
    private const DWRITE_FONT_STRETCH_NORMAL = 5;

    // ============================================================
    // FFI 实例与 COM 对象
    // ============================================================

    private WindowsPlatform $platform;
    private \FFI $d2d1;
    private \FFI $dwrite;

    /** @var \FFI\CData|null ID2D1Factory* */
    private $d2dFactory = null;
    /** @var \FFI\CData|null IDWriteFactory* */
    private $dwFactory = null;
    /** @var \FFI\CData|null ID2D1DCRenderTarget* */
    private $renderTarget = null;

    private bool $initialized = false;

    // ============================================================
    // 构造与初始化
    // ============================================================

    public function __construct(WindowsPlatform $platform)
    {
        $this->platform = $platform;
        try {
            Library::permit('d2d1.dll');
            Library::permit('dwrite.dll');
            $this->d2d1 = Library::load('d2d1.dll', self::D2D1_HEADER);
            $this->dwrite = Library::load('dwrite.dll', self::DWRITE_HEADER);
            $this->initFactories();
            $this->initRenderTarget();
            $this->initialized = true;
        } catch (\Throwable $e) {
            trigger_error(
                'DirectWrite init failed, color emoji unavailable: '
                . $e->getMessage(),
                \E_USER_WARNING
            );
        }
    }

    public function isAvailable(): bool
    {
        return $this->initialized;
    }

    /**
     * 创建 ID2D1Factory 和 IDWriteFactory。
     */
    private function initFactories(): void
    {
        // --- IDWriteFactory ---
        $iidDWrite = $this->dwrite->new('IID');
        $iidDWrite->Data1 = 0xb859ee5a;
        $iidDWrite->Data2 = 0xd838;
        $iidDWrite->Data3 = 0x4b5b;
        $iidDWrite->Data4[0] = 0xa2;
        $iidDWrite->Data4[1] = 0xe8;
        $iidDWrite->Data4[2] = 0x1a;
        $iidDWrite->Data4[3] = 0xdc;
        $iidDWrite->Data4[4] = 0x7d;
        $iidDWrite->Data4[5] = 0x93;
        $iidDWrite->Data4[6] = 0xdb;
        $iidDWrite->Data4[7] = 0x48;

        $dwFactoryPtr = $this->dwrite->new('void*');
        try {
            $hr = (int) $this->dwrite->DWriteCreateFactory(
                self::DWRITE_FACTORY_TYPE_SHARED,
                \FFI::addr($iidDWrite),
                \FFI::addr($dwFactoryPtr)
            );
        } catch (\Throwable $e) {
            throw new \RuntimeException('DWriteCreateFactory call failed: ' . $e->getMessage(), 0, $e);
        }
        if ($hr !== self::S_OK) {
            throw new \RuntimeException(
                'DWriteCreateFactory failed: hr=' . $hr
            );
        }
        // 用 INT_TO_PTR 联合体检查指针是否为 null（不能直接 (int) cast CData）
        $dwCaster = $this->dwrite->new('INT_TO_PTR');
        $dwCaster->p = $dwFactoryPtr;
        $dwAddr = (int) $dwCaster->i;
        if ($dwAddr === 0) {
            throw new \RuntimeException('DWriteCreateFactory returned null factory');
        }
        $this->dwFactory = $this->dwrite->cast('IDWriteFactory*', $dwFactoryPtr);

        // --- ID2D1Factory ---
        $iidD2d = $this->d2d1->new('IID');
        $iidD2d->Data1 = 0x06152247;
        $iidD2d->Data2 = 0x6f50;
        $iidD2d->Data3 = 0x465a;
        $iidD2d->Data4[0] = 0x92;
        $iidD2d->Data4[1] = 0x45;
        $iidD2d->Data4[2] = 0x11;
        $iidD2d->Data4[3] = 0x8b;
        $iidD2d->Data4[4] = 0xfd;
        $iidD2d->Data4[5] = 0x3b;
        $iidD2d->Data4[6] = 0x60;
        $iidD2d->Data4[7] = 0x07;

        $d2dFactoryPtr = $this->d2d1->new('void*');
        try {
            $hr = (int) $this->d2d1->D2D1CreateFactory(
                self::D2D1_FACTORY_TYPE_SINGLE_THREADED,
                \FFI::addr($iidD2d),
                null,  // pFactoryOptions = NULL (debugLevel = none)
                \FFI::addr($d2dFactoryPtr)
            );
        } catch (\Throwable $e) {
            throw new \RuntimeException('D2D1CreateFactory call failed: ' . $e->getMessage(), 0, $e);
        }
        if ($hr !== self::S_OK) {
            throw new \RuntimeException(
                'D2D1CreateFactory failed: hr=' . $hr
            );
        }
        $d2dCaster = $this->d2d1->new('INT_TO_PTR');
        $d2dCaster->p = $d2dFactoryPtr;
        $d2dAddr = (int) $d2dCaster->i;
        if ($d2dAddr === 0) {
            throw new \RuntimeException('D2D1CreateFactory returned null factory');
        }
        $this->d2dFactory = $this->d2d1->cast('ID2D1Factory*', $d2dFactoryPtr);
    }

    /**
     * 创建 ID2D1DCRenderTarget（用于渲染到 GDI HDC）。
     */
    private function initRenderTarget(): void
    {
        $props = $this->d2d1->new('D2D1_RENDER_TARGET_PROPERTIES');
        $props->type = self::D2D1_RENDER_TARGET_TYPE_DEFAULT;
        $props->pixelFormat->format = self::DXGI_FORMAT_UNKNOWN;
        $props->pixelFormat->alphaMode = self::D2D1_ALPHA_MODE_PREMULTIPLIED;
        $props->dpiX = 0.0;  // 0 = 使用桌面 DPI
        $props->dpiY = 0.0;
        $props->usage = self::D2D1_RENDER_TARGET_USAGE_GDI_COMPATIBLE;
        $props->minLevel = 0;

        $rtPtr = $this->d2d1->new('void*');
        $hr = (int) $this->d2dFactory->lpVtbl->CreateDCRenderTarget(
            $this->d2dFactory,
            \FFI::addr($props),
            \FFI::addr($rtPtr)
        );
        if ($hr !== self::S_OK) {
            throw new \RuntimeException(
                'CreateDCRenderTarget failed: hr=' . $hr
            );
        }
        $this->renderTarget = $this->d2d1->cast(
            'ID2D1DCRenderTarget*',
            $rtPtr
        );
    }

    // ============================================================
    // 彩色 emoji 文本渲染
    // ============================================================

    /**
     * 用 DirectWrite 渲染彩色 emoji 文本。
     *
     * @param int    $hdcInt   GDI HDC int 值（来自 DrawContext）。
     * @param int    $x        起点 x（像素）。
     * @param int    $y        起点 y（像素）。
     * @param int    $width    可用宽度（像素）。
     * @param int    $height   可用高度（像素）。
     * @param string $text     UTF-8 文本（含 emoji）。
     * @param string $fontName 字体名（如 "Segoe UI Emoji"）。
     * @param int    $fontSize 字号（像素，近似按 96 DPI 转换为 DIP）。
     * @param Color  $color    文本颜色。
     */
    public function drawText(
        int $hdcInt,
        int $x,
        int $y,
        int $width,
        int $height,
        string $text,
        string $fontName,
        int $fontSize,
        Color $color
    ): void {
        if (!$this->initialized || $text === '') {
            return;
        }

        // 将 GDI HDC int 转为 d2d1 作用域的 HDC 指针
        $hdcPtr = $this->platform->intToPtrIn($this->d2d1, $hdcInt);

        // BindDC：绑定渲染目标到当前 HDC 的矩形区域
        $rect = $this->d2d1->new('RECT');
        $rect->left = $x;
        $rect->top = $y;
        $rect->right = $x + max($width, 1);
        $rect->bottom = $y + max($height, 1);

        $hr = (int) $this->renderTarget->lpVtbl->BindDC(
            $this->renderTarget,
            $hdcPtr,
            \FFI::addr($rect)
        );
        if ($hr !== self::S_OK) {
            return;
        }

        // BeginDraw
        $this->renderTarget->lpVtbl->BeginDraw($this->renderTarget);

        // 创建 IDWriteTextFormat
        $fontWide = $this->wideBuf($fontName);
        $localeWide = $this->wideBuf('en-US');
        $textFormatPtr = $this->dwrite->new('void*');
        $hr = (int) $this->dwFactory->lpVtbl->CreateTextFormat(
            $this->dwFactory,
            \FFI::addr($fontWide[0]),
            null,  // fontCollection = NULL (system)
            self::DWRITE_FONT_WEIGHT_NORMAL,
            self::DWRITE_FONT_STYLE_NORMAL,
            self::DWRITE_FONT_STRETCH_NORMAL,
            (float) $fontSize,
            \FFI::addr($localeWide[0]),
            \FFI::addr($textFormatPtr)
        );
        if ($hr !== self::S_OK) {
            $this->renderTarget->lpVtbl->EndDraw(
                $this->renderTarget,
                null,
                null
            );
            return;
        }
        $textFormat = $this->dwrite->cast(
            'IDWriteTextFormat*',
            $textFormatPtr
        );

        // 创建 IDWriteTextLayout
        $textWide = mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');
        $textLen = intdiv(strlen($textWide), 2);
        $textBuf = $this->dwrite->new('wchar_t[' . ($textLen + 1) . ']');
        for ($i = 0; $i < $textLen; $i++) {
            $textBuf[$i] = ord($textWide[$i * 2])
                | (ord($textWide[$i * 2 + 1]) << 8);
        }
        $textBuf[$textLen] = 0;

        $layoutPtr = $this->dwrite->new('void*');
        $hr = (int) $this->dwFactory->lpVtbl->CreateTextLayout(
            $this->dwFactory,
            \FFI::addr($textBuf[0]),
            $textLen,
            $textFormat,
            (float) max($width, 1),
            (float) max($height, 1),
            \FFI::addr($layoutPtr)
        );
        // 释放 TextFormat（CreateTextLayout 已完成引用）
        $this->releaseCom($this->dwrite, $textFormat);
        if ($hr !== self::S_OK) {
            $this->renderTarget->lpVtbl->EndDraw(
                $this->renderTarget,
                null,
                null
            );
            return;
        }
        $layout = $this->dwrite->cast(
            'IDWriteTextLayout*',
            $layoutPtr
        );

        // 创建 ID2D1SolidColorBrush
        $d2dColor = $this->d2d1->new('D2D1_COLOR_F');
        $d2dColor->r = $color->r / 255.0;
        $d2dColor->g = $color->g / 255.0;
        $d2dColor->b = $color->b / 255.0;
        $d2dColor->a = 1.0;

        $brushPtr = $this->d2d1->new('void*');
        $hr = (int) $this->renderTarget->lpVtbl->CreateSolidColorBrush(
            $this->renderTarget,
            \FFI::addr($d2dColor),
            null,  // brushProperties = NULL (默认 opacity=1, identity transform)
            \FFI::addr($brushPtr)
        );
        if ($hr !== self::S_OK) {
            $this->releaseCom($this->dwrite, $layout);
            $this->renderTarget->lpVtbl->EndDraw(
                $this->renderTarget,
                null,
                null
            );
            return;
        }
        // brushPtr 是 void*，其值 = COM 对象地址
        // DrawTextLayout 第 4 参数为 void*，releaseCom 通过 void** 解引用，
        // 无需 cast 到具体类型（ID2D1SolidColorBrush 未在 FFI 头中声明）

        // DrawTextLayout：启用彩色字体
        $origin = $this->d2d1->new('D2D1_POINT_2F');
        $origin->x = 0.0;
        $origin->y = 0.0;
        $this->renderTarget->lpVtbl->DrawTextLayout(
            $this->renderTarget,
            $origin,
            $layout,
            $brushPtr,
            self::D2D1_DRAW_TEXT_OPTIONS_ENABLE_COLOR_FONT
        );

        // EndDraw
        $this->renderTarget->lpVtbl->EndDraw(
            $this->renderTarget,
            null,
            null
        );

        // 释放临时 COM 对象
        $this->releaseCom($this->d2d1, $brushPtr);
        $this->releaseCom($this->dwrite, $layout);
    }

    // ============================================================
    // 辅助方法
    // ============================================================

    /**
     * 调用 COM 对象的 Release 方法（vtable 索引 2）。
     *
     * COM 对象内存布局：对象地址 → 第一个成员是 lpVtbl 指针 → vtable[2] = Release。
     * 因此需要两级解引用：
     *   1. *(void**)obj  → vtable 指针
     *   2. ((void**)vtable)[2]  → Release 函数指针
     *
     * @param \FFI        $ffi 创建该 COM 对象的 FFI 作用域（dwrite 或 d2d1）。
     * @param \FFI\CData  $obj COM 对象指针（其值 = COM 对象地址）。
     */
    private function releaseCom(\FFI $ffi, \FFI\CData $obj): void
    {
        try {
            // 将对象指针重解释为 void**，解引用得到 vtable 指针
            $objAsVtPtr = $ffi->cast('void**', $obj);
            $vtable = $objAsVtPtr[0];
            // vtable[2] = IUnknown::Release
            $vtAsFnPtr = $ffi->cast('void**', $vtable);
            $releaseFn = $vtAsFnPtr[2];
            // 转为函数指针并调用 Release(obj)
            $typedFn = $ffi->cast(
                'unsigned long (*)(void*)',
                $releaseFn
            );
            $typedFn($obj);
        } catch (\Throwable $e) {
            // 忽略释放错误
        }
    }

    /**
     * 在 dwrite 作用域创建 wchar_t[] 缓冲。
     */
    private function wideBuf(string $text): \FFI\CData
    {
        $wide = mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');
        $len = max(1, intdiv(strlen($wide), 2));
        $arr = $this->dwrite->new('wchar_t[' . ($len + 1) . ']', false);
        for ($i = 0; $i < $len; $i++) {
            $arr[$i] = ord($wide[$i * 2])
                | (ord($wide[$i * 2 + 1]) << 8);
        }
        $arr[$len] = 0;
        return $arr;
    }

    // ============================================================
    // FFI 头声明
    // ============================================================

    /**
     * Direct2D FFI 头。
     *
     * 声明了 ID2D1Factory 和 ID2D1DCRenderTarget 的 vtable，
     * 仅对实际调用的方法声明函数指针签名，其余用 void* 占位。
     */
    private const D2D1_HEADER = <<<C
typedef long HRESULT;
typedef unsigned long ULONG;

// 指针 ↔ 整数转换联合体（用于 ptrToInt 检查）
typedef union {
    long long i;
    void* p;
} INT_TO_PTR;

typedef struct {
    unsigned long  Data1;
    unsigned short Data2;
    unsigned short Data3;
    unsigned char  Data4[8];
} IID;

typedef struct {
    float r;
    float g;
    float b;
    float a;
} D2D1_COLOR_F;

typedef struct {
    float x;
    float y;
} D2D1_POINT_2F;

typedef struct {
    long left;
    long top;
    long right;
    long bottom;
} RECT;

typedef struct {
    int format;
    int alphaMode;
} D2D1_PIXEL_FORMAT;

typedef struct {
    int type;
    D2D1_PIXEL_FORMAT pixelFormat;
    float dpiX;
    float dpiY;
    int usage;
    int minLevel;
} D2D1_RENDER_TARGET_PROPERTIES;

// ID2D1Factory vtable（CreateDCRenderTarget 在索引 17）
typedef struct ID2D1FactoryVtbl {
    void* m00; void* m01; void* m02;  // IUnknown: QueryInterface, AddRef, Release
    void* m03; void* m04; void* m05;  // ReloadSystemMetrics, ClearResources, GetDesktopDpi
    void* m06; void* m07; void* m08;  // CreateRectangleGeometry, ...
    void* m09; void* m10; void* m11;  // CreateGeometryGroup, CreateTransformedGeometry, CreatePathGeometry
    void* m12; void* m13; void* m14;  // CreateStrokeStyle, CreateDrawingStateBlock, CreateWicBitmapRenderTarget
    void* m15; void* m16;             // CreateHwndRenderTarget, CreateDxgiSurfaceRenderTarget
    HRESULT (*CreateDCRenderTarget)(void*, const D2D1_RENDER_TARGET_PROPERTIES*, void**);
} ID2D1FactoryVtbl;

typedef struct ID2D1Factory {
    const ID2D1FactoryVtbl* lpVtbl;
} ID2D1Factory;

// ID2D1DCRenderTarget vtable（继承 ID2D1RenderTarget → ID2D1Resource → IUnknown）
// CreateSolidColorBrush=8, DrawTextLayout=27, BeginDraw=47, EndDraw=48, BindDC=57
typedef struct ID2D1DCRenderTargetVtbl {
    void* m00; void* m01; void* m02;  // IUnknown: 0-2
    void* m03;                         // ID2D1Resource::GetFactory=3
    void* m04; void* m05; void* m06; void* m07;  // CreateBitmap=4, CreateBitmapFromWicBitmap=5, CreateSharedBitmap=6, CreateBitmapBrush=7
    HRESULT (*CreateSolidColorBrush)(void*, const D2D1_COLOR_F*, const void*, void**);  // 8
    void* m09; void* m10; void* m11; void* m12; void* m13;  // 9-13
    void* m14; void* m15; void* m16; void* m17; void* m18;  // 14-18
    void* m19; void* m20; void* m21; void* m22; void* m23;  // 19-23
    void* m24; void* m25; void* m26;  // 24-26
    void (*DrawTextLayout)(void*, D2D1_POINT_2F, void*, void*, int);  // 27
    void* m28; void* m29; void* m30; void* m31; void* m32;  // 28-32
    void* m33; void* m34; void* m35; void* m36; void* m37;  // 33-37
    void* m38; void* m39; void* m40; void* m41; void* m42;  // 38-42
    void* m43; void* m44; void* m45; void* m46;             // 43-46
    void (*BeginDraw)(void*);                                // 47
    HRESULT (*EndDraw)(void*, void*, void*);                 // 48
    void* m49; void* m50; void* m51; void* m52; void* m53;  // 49-53
    void* m54; void* m55; void* m56;                         // 54-56
    HRESULT (*BindDC)(void*, const void*, const RECT*);      // 57
    void* m58;                                               // GetHDC=58
} ID2D1DCRenderTargetVtbl;

typedef struct ID2D1DCRenderTarget {
    const ID2D1DCRenderTargetVtbl* lpVtbl;
} ID2D1DCRenderTarget;

HRESULT D2D1CreateFactory(int factoryType, const IID* riid, const void* pFactoryOptions, void** ppIFactory);
C;

    /**
     * DirectWrite FFI 头。
     *
     * 声明 IDWriteFactory vtable，仅对实际调用的方法声明函数指针签名：
     *   - CreateTextFormat 在索引 14
     *   - CreateTextLayout 在索引 17
     * 其余方法用 void* 占位。
     * IDWriteTextFormat / IDWriteTextLayout 仅作为 DrawTextLayout 的入参，
     * 不直接调用其方法（Release 通过 IUnknown vtable 索引 2 统一处理），
     * 故用简单结构体声明。
     */
    private const DWRITE_HEADER = <<<C
typedef long HRESULT;
typedef unsigned long ULONG;
typedef unsigned short wchar_t;
typedef wchar_t WCHAR;
typedef const WCHAR* LPCWSTR;

// 指针 ↔ 整数转换联合体（用于 ptrToInt 检查）
typedef union {
    long long i;
    void* p;
} INT_TO_PTR;

typedef struct {
    unsigned long  Data1;
    unsigned short Data2;
    unsigned short Data3;
    unsigned char  Data4[8];
} IID;

// IDWriteFactory vtable
// IUnknown: 0 QueryInterface, 1 AddRef, 2 Release
// IDWriteFactory:
//   3  GetSystemFontCollection
//   4  CreateCustomFontCollection
//   5  RegisterFontCollectionLoader
//   6  UnregisterFontCollectionLoader
//   7  CreateFontFileReference
//   8  CreateCustomFontFileReference
//   9  CreateFontFace
//   10 CreateRenderingParams
//   11 CreateMonitorRenderingParams
//   12 CreateCustomRenderingParams
//   13 CreateGlyphRunAnalysis
//   14 CreateTextFormat
//   15 CreateTypography
//   16 GetGdiInterop
//   17 CreateTextLayout
typedef struct IDWriteFactoryVtbl {
    void* m00; void* m01; void* m02;  // IUnknown
    void* m03; void* m04; void* m05; void* m06;
    void* m07; void* m08; void* m09; void* m10;
    void* m11; void* m12; void* m13;
    HRESULT (*CreateTextFormat)(void*, const WCHAR*, void*, int, int, int, float, const WCHAR*, void**);  // 14
    void* m15; void* m16;
    HRESULT (*CreateTextLayout)(void*, const WCHAR*, unsigned int, void*, float, float, void**);  // 17
} IDWriteFactoryVtbl;

typedef struct IDWriteFactory {
    const IDWriteFactoryVtbl* lpVtbl;
} IDWriteFactory;

// IDWriteTextFormat / IDWriteTextLayout 仅作为不透明指针使用
// （DrawTextLayout 入参；Release 通过 IUnknown vtable 索引 2）
typedef struct IDWriteTextFormat {
    void* lpVtbl;
} IDWriteTextFormat;

typedef struct IDWriteTextLayout {
    void* lpVtbl;
} IDWriteTextLayout;

HRESULT DWriteCreateFactory(int factoryType, const IID* iid, void** factory);
C;
}

