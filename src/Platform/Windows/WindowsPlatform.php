<?php
declare(strict_types=1);

namespace Kingbes\Ui\Platform\Windows;

use Kingbes\Ui\Exception\UnsupportedOperationException;
use Kingbes\Ui\Geometry\Point;
use Kingbes\Ui\Geometry\Size;
use Kingbes\Ui\Graphics\Color;
use Kingbes\Ui\Graphics\DrawContext;
use Kingbes\Ui\Platform\AbstractPlatform;
use Kingbes\Ui\Control\Button;
use Kingbes\Ui\Control\Checkbox;
use Kingbes\Ui\Control\RadioBox;
use Kingbes\Ui\Control\Entry;
use Kingbes\Ui\Control\TextArea;
use Kingbes\Ui\Control\ComboBox;
use Kingbes\Ui\Control\ListBox;
use Kingbes\Ui\Control\Slider;
use Kingbes\Ui\Control\ProgressBar;
use Kingbes\Ui\Control\SpinBox;
use Kingbes\Ui\Events\KeyEvent;
use Kingbes\Ui\Events\MouseEvent;
use Kingbes\Ui\Events\ResizeEvent;
use Kingbes\Phpc\Library;

/**
 * Windows 后端平台实现（Unicode W 系列）。
 *
 * 通过 FFI 加载 6 个系统 DLL（user32/gdi32/kernel32/comctl32/comdlg32/shell32），
 * 以 PHP 闭包作为 WindowProc 响应窗口消息，提供窗口/事件循环/系统服务能力。
 *
 * 关键设计：
 *   - 所有文本 API 使用 Unicode W 系列（RegisterClassExW/CreateWindowExW/
 *     DefWindowProcW/PeekMessageW/SendMessageW/SetWindowTextW 等），支持中文与 emoji。
 *   - 字符串处理：PHP UTF-8 ↔ UTF-16LE（mb_convert_encoding），FFI 用 wchar_t[]。
 *   - 所有句柄对外用 int 传递，内部用 INT_TO_PTR 联合体完成 int↔HWND 转换。
 *   - WindowProc 闭包存于 $this->windowProc 属性防 GC 回收导致崩溃。
 *   - 主循环用 PeekMessageW 非阻塞轮询 + usleep(1000)，保证定时器/queueMain 执行。
 *   - 控件创建后用 CreateFontW 创建 "Segoe UI" 默认字体，SendMessageW(WM_SETFONT) 设置。
 *   - WM_COMMAND 通过 lParam(控件 HWND) 反查控件实例，分发到对应事件闭包。
 *   - WM_KEYDOWN/WM_KEYUP 在消息循环中拦截，分发到控件的 onKeyDown/onKeyUp。
 */
class WindowsPlatform extends AbstractPlatform
{
    // ============================================================
    // Win32 常量
    // ============================================================

    /** 窗口类名（注册一次） */
    private const WINDOW_CLASS_NAME = 'PhpUiWindow';

    // 窗口风格
    private const WS_OVERLAPPEDWINDOW = 0x00CF0000;
    private const WS_VISIBLE          = 0x10000000;
    private const WS_POPUP            = 0x80000000;
    private const WS_CAPTION          = 0x00C00000;
    private const WS_THICKFRAME       = 0x00040000;
    private const WS_VSCROLL          = 0x00200000;
    private const WS_CHILD            = 0x40000000;
    private const WS_CLIPCHILDREN     = 0x02000000;
    private const WS_BORDER           = 0x00800000;
    private const WS_TABSTOP          = 0x00010000;
    private const WS_GROUP            = 0x00020000;

    // 扩展样式
    private const WS_EX_CLIENTEDGE    = 0x00000200;

    // 类风格
    private const CS_HREDRAW = 0x0002;
    private const CS_VREDRAW = 0x0001;

    // GetWindowLongPtr 索引
    private const GWL_STYLE      = -16;
    private const GWLP_USERDATA  = -21;
    private const GWLP_WNDPROC    = -4;

    // ShowWindow 命令
    private const SW_HIDE      = 0;
    private const SW_SHOW      = 5;
    private const SW_MAXIMIZE  = 3;
    private const SW_MINIMIZE  = 6;
    private const SW_RESTORE   = 9;

    // SetWindowPos 标志
    private const SWP_NOSIZE       = 0x0001;
    private const SWP_NOMOVE       = 0x0002;
    private const SWP_NOZORDER     = 0x0004;
    private const SWP_FRAMECHANGED = 0x0020;

    // HWND 特殊句柄
    private const HWND_TOPMOST    = -1;
    private const HWND_NOTOPMOST  = -2;

    // 消息
    private const WM_CREATE      = 0x0001;
    private const WM_DESTROY     = 0x0002;
    private const WM_SIZE        = 0x0005;
    private const WM_PAINT       = 0x000F;
    private const WM_CLOSE       = 0x0010;
    private const WM_QUIT        = 0x0012;
    private const WM_SETFONT     = 0x0030;
    private const WM_COMMAND     = 0x0111;
    private const WM_NOTIFY      = 0x004E;
    private const WM_HSCROLL     = 0x0114;
    private const WM_VSCROLL     = 0x0115;
    private const WM_KEYDOWN     = 0x0100;
    private const WM_KEYUP       = 0x0101;
    private const WM_MOUSEMOVE   = 0x0200;
    private const WM_LBUTTONDOWN = 0x0201;
    private const WM_LBUTTONUP   = 0x0202;
    private const WM_RBUTTONDOWN = 0x0204;
    private const WM_RBUTTONUP   = 0x0205;
    private const WM_MBUTTONDOWN = 0x0207;
    private const WM_MBUTTONUP   = 0x0208;

    // PeekMessage
    private const PM_REMOVE = 1;

    // GetSystemMetrics
    private const SM_CXSCREEN = 0;
    private const SM_CYSCREEN = 1;

    // ScrollBar 类型（SetScrollInfo/GetScrollInfo 第二参数 nBar）
    private const SB_CTL  = 2;  // 控件滚动条（Slider/ListBox 等）
    private const SB_VERT = 1;  // 窗口垂直滚动条

    // 光标 / 颜色
    private const IDC_ARROW    = 32512;
    private const COLOR_WINDOW = 5;

    // 剪贴板
    private const CF_UNICODETEXT = 13;
    private const GMEM_MOVEABLE   = 0x0002;

    // CreateWindow 默认位置（带符号 32 位值）
    private const CW_USEDEFAULT = -2147483648;

    // ============================================================
    // 控件样式常量
    // ============================================================

    // Button 风格
    private const BS_PUSHBUTTON       = 0x00010000;
    private const BS_AUTOCHECKBOX     = 0x00000003;
    private const BS_AUTORADIOBUTTON  = 0x00000004;

    // Static 风格
    private const SS_LEFT             = 0x00000000;
    private const SS_CENTER           = 0x00000001;
    private const SS_RIGHT            = 0x00000002;

    // Edit 风格
    private const ES_AUTOHSCROLL      = 0x0080;
    private const ES_MULTILINE        = 0x0004;
    private const ES_AUTOVSCROLL      = 0x0040;
    private const ES_WANTRETURN       = 0x1000;
    private const ES_READONLY         = 0x0800;
    private const ES_PASSWORD         = 0x0020;

    // ComboBox 风格
    private const CBS_DROPDOWNLIST    = 0x0003;

    // ListBox 风格
    private const LBS_STANDARD         = 0x00A00003;

    // Trackbar 风格
    private const TBS_AUTOTICKS       = 0x0001;

    // UpDown 风格
    private const UDS_SETBUDDY         = 0x0002;
    private const UDS_ALIGNRIGHT       = 0x0004;
    private const UDS_ARROWKEYS        = 0x0020;
    private const UDS_AUTOBUDDY        = 0x0010;

    // ============================================================
    // 控件消息常量
    // ============================================================

    // Button 消息
    private const BM_GETCHECK  = 0x00F0;
    private const BM_SETCHECK  = 0x00F1;

    // 复选状态
    private const BST_UNCHECKED = 0;
    private const BST_CHECKED   = 1;

    // ComboBox 消息
    private const CB_ADDSTRING     = 0x0143;
    private const CB_DELETESTRING  = 0x0154;
    private const CB_RESETCONTENT  = 0x014B;
    private const CB_GETCURSEL     = 0x0147;
    private const CB_SETCURSEL     = 0x014E;

    // ListBox 消息
    private const LB_ADDSTRING     = 0x0180;
    private const LB_DELETESTRING  = 0x0182;
    private const LB_RESETCONTENT  = 0x0185;
    private const LB_GETCURSEL      = 0x0188;
    private const LB_SETCURSEL      = 0x0186;

    // Trackbar 消息
    private const TBM_SETRANGEMIN  = 0x041D;
    private const TBM_SETRANGEMAX  = 0x041E;
    private const TBM_GETPOS        = 0x0400;
    private const TBM_SETPOS        = 0x0405;

    // UpDown 消息
    private const UDM_SETRANGE      = 0x0465;
    private const UDM_GETPOS        = 0x0468;
    private const UDM_SETPOS        = 0x0467;

    // ProgressBar 消息
    private const PBM_SETRANGE     = 0x0401;
    private const PBM_SETPOS       = 0x0402;

    // WM_COMMAND 通知码
    private const BN_CLICKED     = 0;
    private const EN_CHANGE      = 0x0300;
    private const CBN_SELCHANGE  = 1;
    private const LBN_SELCHANGE  = 1;

    // WM_HSCROLL/WM_VSCROLL 通知码
    private const SB_LINEUP         = 0;
    private const SB_LINEDOWN       = 1;
    private const SB_PAGEUP         = 2;
    private const SB_PAGEDOWN       = 3;
    private const SB_THUMBPOSITION = 4;
    private const SB_THUMBTRACK     = 5;
    private const SB_TOP            = 6;
    private const SB_BOTTOM         = 7;
    private const SB_ENDSCROLL      = 8;

    // SCROLLINFO fMask
    private const SIF_RANGE           = 0x0001;
    private const SIF_PAGE           = 0x0002;
    private const SIF_POS             = 0x0004;
    private const SIF_TRACKPOS        = 0x0010;
    private const SIF_ALL             = 0x0017; // RANGE|PAGE|POS|TRACKPOS

    // 虚拟键码
    private const VK_RETURN = 0x0D;

    // ============================================================
    // 菜单标志常量（AppendMenuW / EnableMenuItem / CheckMenuItem）
    // ============================================================

    private const MF_STRING    = 0x00000000; // 菜单项为文本字符串
    private const MF_SEPARATOR = 0x00000800; // 分隔符
    private const MF_POPUP     = 0x00000010; // 弹出子菜单
    private const MF_BYCOMMAND = 0x00000000; // 按 ID 查找（默认）
    private const MF_ENABLED   = 0x00000000; // 启用
    private const MF_GRAYED    = 0x00000001; // 禁用（灰显）
    private const MF_CHECKED   = 0x00000008; // 勾选
    private const MF_UNCHECKED = 0x00000000; // 取消勾选

    // 菜单相关消息
    private const WM_INITMENUPOPUP = 0x0117;

    // ============================================================
    // FFI 实例
    // ============================================================

    /** @var \FFI|null user32 */
    private ?\FFI $user32 = null;
    /** @var \FFI|null gdi32 */
    private ?\FFI $gdi32 = null;
    /** @var \FFI|null kernel32 */
    private ?\FFI $kernel32 = null;
    /** @var \FFI|null comctl32 */
    private ?\FFI $comctl32 = null;
    /** @var \FFI|null comdlg32 */
    private ?\FFI $comdlg32 = null;
    /** @var \FFI|null shell32 */
    private ?\FFI $shell32 = null;
    /** @var \FFI|null ole32（CoTaskMemFree 用于释放 PIDL） */
    private ?\FFI $ole32 = null;
    /** @var \FFI|null gdiplus（GDI+ 彩色 emoji 渲染） */
    private ?\FFI $gdiplus = null;

    /** GDI+ 启动 token（CData 保活，进程退出时由 GdiplusShutdown 释放）。 */
    private $gdiplusToken = null;

    // ============================================================
    // 运行期状态
    // ============================================================

    /** WindowProc 闭包引用（防 GC 回收导致窗口崩溃）。 */
    private ?\Closure $windowProc = null;

    /** 窗口类是否已注册（进程内只注册一次）。 */
    private bool $classRegistered = false;

    /** 类名 wchar_t[] 缓冲（owned=false，进程内常驻防释放）。 */
    private $classNameBuf = null;

    /** 模态对话框守护标志。 */
    protected bool $inModalDialog = false;

    /** 全屏状态保存：hwnd(int) => [left, top, right, bottom, style] */
    private array $fullscreenState = [];

    /**
     * 窗口滚动状态：hwnd(int) => ['contentHeight' => int, 'scrollPos' => int]。
     *
     * windowSetScrollable 启用窗口垂直滚动时初始化；WM_VSCROLL 时更新
     * scrollPos；relayoutWindowNow 读取以偏移子容器位置（用 contentHeight
     * 作为容器高度，baseY = -scrollPos）。
     *
     * @var array<int, array{contentHeight:int, scrollPos:int}>
     */
    private array $windowScrollInfo = [];

    /** 控件 ID 自增计数器。 */
    private int $nextControlId = 1000;

    /** 控件类型表：hwnd(int) => 原生类名（用于区分 ComboBox/ListBox 消息）。 */
    private array $controlTypes = [];

    /** 默认字体 HFONT（CData，保持引用防 GC）。 */
    private $defaultFont = null;

    /** 默认字体 HFONT int 值（用于 WPARAM）。 */
    private int $defaultFontInt = 0;

    /**
     * 菜单 HMENU CData 保活表：menuHwnd(int) => HMENU CData。
     *
     * menuCreateBar/menuCreatePopup 返回的 HMENU CData 必须保活，
     * 否则 GC 回收后地址失效。EnableMenuItem/CheckMenuItem 等操作
     * 需从本表取出原始 CData 引用传入，不能用 intToHwnd 重新创建。
     *
     * @var array<int, \FFI\CData>
     */
    private array $menus = [];

    // ============================================================
    // 构造器：加载 6 个系统 DLL
    // ============================================================

    public function __construct()
    {
        Library::permit('user32.dll');
        Library::permit('gdi32.dll');
        Library::permit('kernel32.dll');
        Library::permit('comctl32.dll');
        Library::permit('comdlg32.dll');
        Library::permit('shell32.dll');
        Library::permit('ole32.dll');
        Library::permit('gdiplus.dll');

        $this->user32   = Library::load('user32.dll',   self::USER32_HEADER);
        $this->gdi32    = Library::load('gdi32.dll',    self::GDI32_HEADER);
        $this->kernel32 = Library::load('kernel32.dll', self::KERNEL32_HEADER);
        $this->comctl32 = Library::load('comctl32.dll', self::COMCTL32_HEADER);
        $this->comdlg32 = Library::load('comdlg32.dll', self::COMMDLG32_HEADER);
        $this->shell32  = Library::load('shell32.dll',  self::SHELL32_HEADER);
        $this->ole32    = Library::load('ole32.dll',    self::OLE32_HEADER);
        $this->gdiplus  = Library::load('gdiplus.dll',  self::GDIPLUS_HEADER);

        // 初始化 GDI+（彩色 emoji 渲染必需，否则所有 GDI+ 函数返回错误）
        $this->initGdiplus();
    }

    /**
     * 初始化 GDI+：GdiplusStartup，token 存 $this->gdiplusToken。
     *
     * SuppressBackgroundThread=0 表示使用调用线程，无需 GdiplusNotificationHook。
     */
    private function initGdiplus(): void
    {
        $input = $this->gdiplus->new('GdiplusStartupInput');
        $input->GdiplusVersion = 1;
        $input->DebugEventCallback = null;
        $input->SuppressBackgroundThread = 0;
        $input->SuppressExternalCodecs = 0;

        $this->gdiplusToken = $this->gdiplus->new('ULONG_PTR');
        $status = (int) $this->gdiplus->GdiplusStartup(
            \FFI::addr($this->gdiplusToken),
            \FFI::addr($input),
            null
        );
        if ($status !== 0) {
            throw new \RuntimeException(
                'GdiplusStartup failed with status ' . $status
                . ' (彩色 emoji 渲染不可用)'
            );
        }
    }

    // ============================================================
    // C 头声明
    // ============================================================

    /**
     * user32.dll 头声明（Unicode W 系列）。
     *
     * 注意：WPARAM/LPARAM/LRESULT/LONG_PTR/UINT_PTR 用 8 字节类型
     * （long long / unsigned long long），匹配 x64 调用约定；
     * DWORD/UINT/LONG 用 4 字节类型。字符串参数用 LPCWSTR（const wchar_t*）。
     */
    private const USER32_HEADER = <<<C
typedef void* HWND;
typedef void* HMENU;
typedef void* HDC;
typedef void* HINSTANCE;
typedef void* HBRUSH;
typedef void* HICON;
typedef void* HCURSOR;
typedef unsigned long DWORD;
typedef unsigned int UINT;
typedef int LONG;
typedef long long LONG_PTR;
typedef unsigned long long UINT_PTR;
typedef unsigned long long WPARAM;
typedef long long LPARAM;
typedef long long LRESULT;
typedef unsigned short ATOM;
// wchar_t 在 Windows 上为 16 位无符号整数（UTF-16LE 码元）。
// PHP FFI 的 C 解析器不包含标准头，需显式定义。
typedef unsigned short wchar_t;
typedef const char* LPCSTR;
typedef char* LPSTR;
typedef const wchar_t* LPCWSTR;
typedef wchar_t* LPWSTR;
typedef int BOOL;

typedef union { long long i; void* p; } INT_TO_PTR;

typedef struct tagPOINT { LONG x; LONG y; } POINT;
typedef struct tagRECT { LONG left; LONG top; LONG right; LONG bottom; } RECT;

typedef unsigned char BYTE;

typedef struct tagSCROLLINFO {
    UINT cbSize;
    UINT fMask;
    int  nMin;
    int  nMax;
    UINT nPage;
    int  nPos;
    int  nTrackPos;
} SCROLLINFO;
typedef SCROLLINFO* LPSCROLLINFO;

typedef struct tagPAINTSTRUCT {
    HDC  hdc;
    BOOL fErase;
    RECT rcPaint;
    BOOL fRestore;
    BOOL fIncUpdate;
    BYTE rgbReserved[32];
} PAINTSTRUCT;

typedef struct tagMSG {
    HWND    hwnd;
    UINT    message;
    WPARAM  wParam;
    LPARAM  lParam;
    DWORD   time;
    LONG    pt_x;
    LONG    pt_y;
} MSG;

typedef struct tagWNDCLASSEXW {
    UINT        cbSize;
    UINT        style;
    LRESULT (*lpfnWndProc)(HWND, UINT, WPARAM, LPARAM);
    int         cbClsExtra;
    int         cbWndExtra;
    HINSTANCE   hInstance;
    HICON       hIcon;
    HCURSOR     hCursor;
    HBRUSH      hbrBackground;
    LPCWSTR     lpszMenuName;
    LPCWSTR     lpszClassName;
    HICON       hIconSm;
} WNDCLASSEXW;

ATOM    RegisterClassExW(const WNDCLASSEXW *lpWndClass);
HWND    CreateWindowExW(DWORD dwExStyle, LPCWSTR lpClassName, LPCWSTR lpWindowName,
                        DWORD dwStyle, int X, int Y, int nWidth, int nHeight,
                        HWND hWndParent, HMENU hMenu, HINSTANCE hInstance, void* lpParam);
LRESULT DefWindowProcW(HWND hWnd, UINT Msg, WPARAM wParam, LPARAM lParam);
LRESULT DispatchMessageW(const MSG *lpmsg);
int     PeekMessageW(MSG *lpMsg, HWND hWnd, UINT wMsgFilterMin, UINT wMsgFilterMax, UINT wRemoveMsg);
int     TranslateMessage(const MSG *lpMsg);
int     PostQuitMessage(int nExitCode);
BOOL    PostMessageW(HWND hWnd, UINT Msg, WPARAM wParam, LPARAM lParam);
LRESULT SendMessageW(HWND hWnd, UINT Msg, WPARAM wParam, LPARAM lParam);
BOOL    DestroyWindow(HWND hWnd);
int     ShowWindow(HWND hWnd, int nCmdShow);
BOOL    UpdateWindow(HWND hWnd);
BOOL    SetWindowPos(HWND hWnd, HWND hWndInsertAfter, int X, int Y, int cx, int cy, UINT uFlags);
BOOL    SetWindowTextW(HWND hWnd, LPCWSTR lpString);
int     GetWindowTextW(HWND hWnd, LPWSTR lpString, int nMaxCount);
int     GetWindowTextLengthW(HWND hWnd);
BOOL    GetWindowRect(HWND hWnd, RECT *lpRect);
BOOL    GetClientRect(HWND hWnd, RECT *lpRect);
LONG_PTR GetWindowLongPtrW(HWND hWnd, int nIndex);
LONG_PTR SetWindowLongPtrW(HWND hWnd, int nIndex, LONG_PTR dwNewLong);
HWND    GetForegroundWindow(void);
HWND    SetParent(HWND hWndChild, HWND hWndNewParent);
BOOL    SetMenu(HWND hWnd, HMENU hMenu);
int     GetSystemMetrics(int nIndex);
HCURSOR LoadCursorW(HINSTANCE hInstance, UINT_PTR lpCursorName);
BOOL    InvalidateRect(HWND hWnd, const RECT *lpRect, BOOL bErase);
BOOL    OpenClipboard(HWND hWndNewOwner);
BOOL    EmptyClipboard(void);
void*   SetClipboardData(UINT uFormat, void* hMem);
BOOL    CloseClipboard(void);
void*   GetClipboardData(UINT uFormat);
int     MessageBoxW(HWND hWnd, LPCWSTR lpText, LPCWSTR lpCaption, UINT uType);
BOOL    EnableWindow(HWND hWnd, BOOL bEnable);
HMENU   CreateMenu(void);
HMENU   CreatePopupMenu(void);
BOOL    DestroyMenu(HMENU hMenu);
BOOL    AppendMenuW(HMENU hMenu, UINT uFlags, UINT_PTR uIDNewItem, LPCWSTR lpNewItem);
BOOL    InsertMenuW(HMENU hMenu, UINT uPosition, UINT uFlags, UINT_PTR uIDNewItem, LPCWSTR lpNewItem);
BOOL    CheckMenuItem(HMENU hMenu, UINT uIDCheckItem, UINT uCheck);
BOOL    EnableMenuItem(HMENU hMenu, UINT uIDEnableItem, UINT uEnable);
BOOL    DrawMenuBar(HWND hWnd);
HDC     BeginPaint(HWND hwnd, PAINTSTRUCT* lpPaint);
BOOL    EndPaint(HWND hwnd, PAINTSTRUCT* lpPaint);
int     SetScrollInfo(HWND hwnd, int nBar, LPSCROLLINFO lpsi, BOOL redraw);
BOOL    GetScrollInfo(HWND hwnd, int nBar, LPSCROLLINFO lpsi);
C;

    /**
     * gdi32.dll 头声明。
     */
    private const GDI32_HEADER = <<<C
typedef int BOOL;
typedef unsigned long DWORD;
typedef unsigned int UINT;
typedef int LONG;
typedef void* HDC;
typedef void* HGDIOBJ;
typedef void* HPEN;
typedef void* HBRUSH;
typedef void* HFONT;
typedef unsigned short wchar_t;
typedef const char* LPCSTR;
typedef const wchar_t* LPCWSTR;

typedef union { long long i; void* p; } INT_TO_PTR;

HPEN    CreatePen(int fnPenStyle, int nWidth, DWORD crColor);
HBRUSH  CreateSolidBrush(DWORD crColor);
HFONT   CreateFontW(int nHeight, int nWidth, int nEscapement, int nOrientation,
                    int fnWeight, DWORD fdwItalic, DWORD fdwUnderline,
                    DWORD fdwStrikeOut, DWORD fdwCharSet,
                    DWORD fdwOutputPrecision, DWORD fdwClipPrecision,
                    DWORD fdwQuality, DWORD fdwPitchAndFamily, LPCWSTR lpszFace);
HGDIOBJ SelectObject(HDC hdc, HGDIOBJ hgdiobj);
BOOL    DeleteObject(HGDIOBJ hObject);
BOOL    MoveToEx(HDC hdc, int X, int Y, void* lpPoint);
BOOL    LineTo(HDC hdc, int nXEnd, int nYEnd);
BOOL    Rectangle(HDC hdc, int nLeftRect, int nTopRect, int nRightRect, int nBottomRect);
BOOL    Ellipse(HDC hdc, int nLeftRect, int nTopRect, int nRightRect, int nBottomRect);
BOOL    TextOutW(HDC hdc, int nXStart, int nYStart, LPCWSTR lpString, int cbString);
DWORD   SetTextColor(HDC hdc, DWORD crColor);
int     SetBkMode(HDC hdc, int iBkMode);
C;

    /**
     * kernel32.dll 头声明。
     */
    private const KERNEL32_HEADER = <<<C
typedef void* HMODULE;
typedef const char* LPCSTR;
typedef unsigned long DWORD;
typedef unsigned int UINT;
typedef int BOOL;
typedef unsigned short wchar_t;
typedef const wchar_t* LPCWSTR;

HMODULE GetModuleHandleW(LPCWSTR lpModuleName);
void*   GetProcAddress(HMODULE hModule, const char* lpProcName);
void    ExitProcess(UINT uExitCode);

void*   GlobalAlloc(UINT uFlags, DWORD dwBytes);
char*   GlobalLock(void* hMem);
BOOL    GlobalUnlock(void* hMem);
void*   GlobalFree(void* hMem);
C;

    /**
     * comctl32.dll 头声明。
     */
    private const COMCTL32_HEADER = <<<C
typedef unsigned long DWORD;
typedef unsigned int UINT;

typedef struct tagINITCOMMONCONTROLSEX {
    DWORD dwSize;
    DWORD dwICC;
} INITCOMMONCONTROLSEX;

void InitCommonControlsEx(const INITCOMMONCONTROLSEX *piccs);
void InitCommonControls(void);
C;

    /**
     * comdlg32.dll 头声明（Unicode W 系列，批次 5 完整结构体）。
     *
     * OPENFILENAMEW / CHOOSECOLORW / LOGFONTW / CHOOSEFONTW 结构体在
     * x64 上的大小分别为 152 / 72 / 92 / 100 字节，由 FFI 自动对齐，
     * 通过 \FFI::sizeof() 获取实际大小填入 lStructSize。
     */
    private const COMMDLG32_HEADER = <<<C
typedef void* HWND;
typedef void* HINSTANCE;
typedef void* HDC;
typedef unsigned long DWORD;
typedef unsigned int UINT;
typedef int BOOL;
typedef int INT;
typedef int LONG;
typedef unsigned short WORD;
typedef unsigned char BYTE;
typedef long long LPARAM;
typedef unsigned short wchar_t;
typedef const wchar_t* LPCWSTR;
typedef wchar_t* LPWSTR;
typedef DWORD* LPDWORD;
typedef DWORD COLORREF;

typedef union { long long i; void* p; } INT_TO_PTR;

typedef struct tagOFNW {
    DWORD        lStructSize;
    HWND         hwndOwner;
    HINSTANCE    hInstance;
    LPCWSTR      lpstrFilter;
    LPWSTR       lpstrCustomFilter;
    DWORD        nMaxCustFilter;
    DWORD        nFilterIndex;
    LPWSTR       lpstrFile;
    DWORD        nMaxFile;
    LPWSTR       lpstrFileTitle;
    DWORD        nMaxFileTitle;
    LPCWSTR      lpstrInitialDir;
    LPCWSTR      lpstrTitle;
    DWORD        Flags;
    WORD         nFileOffset;
    WORD         nFileExtension;
    LPCWSTR      lpstrDefExt;
    LPARAM       lCustData;
    void*        lpfnHook;
    LPCWSTR      lpTemplateName;
    void*        pvReserved;
    DWORD        dwReserved;
    DWORD        FlagsEx;
} OPENFILENAMEW;

typedef struct {
    DWORD        lStructSize;
    HWND         hwndOwner;
    HWND         hInstance;
    DWORD        rgbResult;
    LPDWORD      lpCustColors;
    DWORD        Flags;
    LPARAM       lCustData;
    void*        lpfnHook;
    LPCWSTR      lpTemplateName;
} CHOOSECOLORW;

typedef struct tagLOGFONTW {
    LONG      lfHeight;
    LONG      lfWidth;
    LONG      lfEscapement;
    LONG      lfOrientation;
    LONG      lfWeight;
    BYTE      lfItalic;
    BYTE      lfUnderline;
    BYTE      lfStrikeOut;
    BYTE      lfCharSet;
    BYTE      lfOutPrecision;
    BYTE      lfClipPrecision;
    BYTE      lfQuality;
    BYTE      lfPitchAndFamily;
    wchar_t   lfFaceName[32];
} LOGFONTW;

typedef struct {
    DWORD       lStructSize;
    HWND        hwndOwner;
    HDC         hDC;
    LOGFONTW*   lpLogFont;
    INT         iPointSize;
    DWORD       Flags;
    COLORREF    rgbColors;
    LPARAM      lCustData;
    void*       lpfnHook;
    LPCWSTR     lpTemplateName;
    HINSTANCE   hInstance;
    LPWSTR      lpszStyle;
    WORD        nFontType;
    WORD        __alignment;
    INT         nSizeMin;
    INT         nSizeMax;
} CHOOSEFONTW;

BOOL GetOpenFileNameW(OPENFILENAMEW *lpofn);
BOOL GetSaveFileNameW(OPENFILENAMEW *lpofn);
BOOL ChooseColorW(CHOOSECOLORW *lpcc);
BOOL ChooseFontW(CHOOSEFONTW *lpcf);
C;

    /**
     * shell32.dll 头声明（Unicode W 系列，批次 5 完整结构体）。
     */
    private const SHELL32_HEADER = <<<C
typedef void* HWND;
typedef void* LPCITEMIDLIST;
typedef unsigned int UINT;
typedef int BOOL;
typedef unsigned short wchar_t;
typedef long long LPARAM;
typedef const wchar_t* LPCWSTR;
typedef wchar_t* LPWSTR;

typedef union { long long i; void* p; } INT_TO_PTR;

typedef struct _browseinfoW {
    HWND          hwndOwner;
    LPCITEMIDLIST pidlRoot;
    LPWSTR        pszDisplayName;
    LPCWSTR       lpszTitle;
    UINT          ulFlags;
    void*         lpfn;
    LPARAM        lParam;
    int           iImage;
} BROWSEINFOW;

void* SHBrowseForFolderW(BROWSEINFOW *lpbi);
BOOL SHGetPathFromIDListW(void* pidl, wchar_t* pszPath);
C;

    /**
     * ole32.dll 头声明（仅 CoTaskMemFree 用于释放 SHBrowseForFolderW 返回的 PIDL）。
     */
    private const OLE32_HEADER = <<<C
typedef void* LPVOID;

typedef union { long long i; void* p; } INT_TO_PTR;

void CoTaskMemFree(LPVOID pv);
C;

    /**
     * gdiplus.dll 头声明（彩色 emoji 渲染）。
     *
     * GDI+ 的 GdipDrawString 支持 Segoe UI Emoji 彩色字体，是彩色 emoji
     * 渲染的关键。所有 GpGraphics/GpFont/GpBrush/GpFontFamily 对象用完
     * 必须 Delete，否则内存泄漏。
     *
     * 注意：wchar_t 需在 gdiplus 作用域内显式 typedef（与 user32/gdi32
     * 作用域不互通）。HDC 跨作用域经 int 转换。
     */
    private const GDIPLUS_HEADER = <<<C
typedef int BOOL;
typedef unsigned int UINT;
typedef unsigned int UINT32;
typedef unsigned long DWORD;
typedef unsigned long ULONG_PTR;
typedef unsigned short wchar_t;
typedef void* HDC;
typedef void* GpGraphics;
typedef void* GpFont;
typedef void* GpFontFamily;
typedef void* GpBrush;
typedef void* GpSolidFill;
typedef void* GpStringFormat;
typedef int Status;

typedef union { long long i; void* p; } INT_TO_PTR;

typedef struct {
    UINT32 GdiplusVersion;
    void*  DebugEventCallback;
    BOOL   SuppressBackgroundThread;
    BOOL   SuppressExternalCodecs;
} GdiplusStartupInput;

typedef struct {
    float X;
    float Y;
    float Width;
    float Height;
} RectF;

Status  GdiplusStartup(ULONG_PTR* token, GdiplusStartupInput* input, void* output);
void    GdiplusShutdown(ULONG_PTR token);

Status  GdipCreateFromHDC(HDC hdc, GpGraphics** graphics);
Status  GdipDeleteGraphics(GpGraphics* graphics);

Status  GdipCreateFontFamilyFromName(const wchar_t* name, void* fontCollection, GpFontFamily** fontFamily);
Status  GdipDeleteFontFamily(GpFontFamily* fontFamily);
Status  GdipCreateFont(GpFontFamily* family, float emSize, int style, int unit, GpFont** font);
Status  GdipDeleteFont(GpFont* font);
Status  GdipCreateSolidFill(int argb, GpSolidFill** brush);
Status  GdipDeleteBrush(GpBrush* brush);
Status  GdipDrawString(GpGraphics* graphics, const wchar_t* string, int length, GpFont* font, RectF* layoutRect, GpStringFormat* format, GpBrush* brush);
Status  GdipMeasureString(GpGraphics* graphics, const wchar_t* string, int length, GpFont* font, RectF* layoutRect, GpStringFormat* format, RectF* boundingBox, int* codepointsFitted, int* linesFilled);
C;

    // ============================================================
    // int↔指针 辅助（用 INT_TO_PTR 联合体，禁止跨作用域 cast）
    // ============================================================

    private function ptrToInt(\FFI\CData $ptr): int
    {
        $c = $this->user32->new('INT_TO_PTR');
        $c->p = $ptr;
        return (int) $c->i;
    }

    private function hwndInt(\FFI\CData $hwnd): int
    {
        $c = $this->user32->new('INT_TO_PTR');
        $c->p = $hwnd;
        return (int) $c->i;
    }

    private function intToHwnd(int $i): \FFI\CData
    {
        $c = $this->user32->new('INT_TO_PTR');
        $c->i = $i;
        return $c->p;
    }

    private function hmenuToInt(\FFI\CData $hmenu): int
    {
        $c = $this->user32->new('INT_TO_PTR');
        $c->p = $hmenu;
        return (int) $c->i;
    }

    /** gdi32 指针 → int（gdi32 作用域内用 INT_TO_PTR） */
    private function gdiPtrToInt(\FFI\CData $ptr): int
    {
        $c = $this->gdi32->new('INT_TO_PTR');
        $c->p = $ptr;
        return (int) $c->i;
    }

    /**
     * 在指定 FFI 作用域将 int 转为 void* 指针（用于跨作用域结构体字段赋值）。
     *
     * 不同 FFI 作用域的 void* 类型不互通，因此在 comdlg32/shell32/ole32
     * 作用域内设置 HWND 字段时需用对应作用域的 INT_TO_PTR 转换。
     *
     * 公开访问：DrawContext 需将 HDC int 转为 gdi32/gdiplus 作用域指针。
     */
    public function intToPtrIn(\FFI $ffi, int $i): \FFI\CData
    {
        $c = $ffi->new('INT_TO_PTR');
        $c->i = $i;
        return $c->p;
    }

    /**
     * 在指定 FFI 作用域将 void* 指针转为 int（用于跨作用域指针传递）。
     */
    private function ptrToIntIn(\FFI $ffi, \FFI\CData $ptr): int
    {
        $c = $ffi->new('INT_TO_PTR');
        $c->p = $ptr;
        return (int) $c->i;
    }

    // ============================================================
    // 公开 FFI 作用域访问（供 DrawContext 使用）
    // ============================================================

    public function getUser32(): \FFI
    {
        return $this->user32;
    }

    public function getGdi32(): \FFI
    {
        return $this->gdi32;
    }

    public function getGdiplus(): \FFI
    {
        return $this->gdiplus;
    }

    // ============================================================
    // Unicode 编码辅助（UTF-8 ↔ UTF-16LE）
    // ============================================================

    /**
     * UTF-8 字符串 → wchar_t[] CData（含 \0 终止）。
     *
     * owned=false 确保缓冲在进程内常驻，避免 FFI 回收后悬空指针。
     * 调用方需通过 \FFI::addr($arr[0]) 获取 LPCWSTR 指针。
     */
    private function utf8ToWide(string $utf8): \FFI\CData
    {
        $wide = mb_convert_encoding($utf8, 'UTF-16LE', 'UTF-8');
        $len = intdiv(strlen($wide), 2);
        $arr = $this->user32->new('wchar_t[' . ($len + 1) . ']', false);
        for ($i = 0; $i < $len; $i++) {
            $arr[$i] = ord($wide[$i * 2]) | (ord($wide[$i * 2 + 1]) << 8);
        }
        $arr[$len] = 0;
        return $arr;
    }

    /**
     * wchar_t[] CData → UTF-8 字符串。
     */
    private function wideToUtf8(\FFI\CData $wideStr): string
    {
        $bytes = '';
        $i = 0;
        while (true) {
            $ch = $wideStr[$i];
            if ($ch == 0) {
                break;
            }
            $bytes .= chr($ch & 0xFF) . chr(($ch >> 8) & 0xFF);
            $i++;
        }
        return mb_convert_encoding($bytes, 'UTF-8', 'UTF-16LE');
    }

    /**
     * 在指定 FFI 作用域创建 wchar_t[] 缓冲（owned=false 持久化，防 GC）。
     *
     * 用于 comdlg32/shell32 作用域的结构体字段赋值——这些作用域的 wchar_t
     * 与 user32 作用域不互通，必须在本作用域内创建缓冲。
     *
     * @param \FFI  $ffi       目标 FFI 作用域（需含 wchar_t typedef）
     * @param string $utf8     UTF-8 字符串
     * @param int    $minChars 最小 wchar_t 数（不足时补 \0）
     */
    private function wideBufIn(\FFI $ffi, string $utf8, int $minChars = 0): \FFI\CData
    {
        $wide = mb_convert_encoding($utf8, 'UTF-16LE', 'UTF-8');
        $len = max(intdiv(strlen($wide), 2), $minChars);
        $arr = $ffi->new('wchar_t[' . ($len + 1) . ']', false);
        for ($i = 0; $i < $len; $i++) {
            $arr[$i] = ord($wide[$i * 2]) | (ord($wide[$i * 2 + 1]) << 8);
        }
        $arr[$len] = 0;
        return $arr;
    }

    /**
     * 在指定 FFI 作用域创建零初始化 wchar_t[] 缓冲（owned=false 持久化）。
     */
    private function wideBufZero(\FFI $ffi, int $chars): \FFI\CData
    {
        $arr = $ffi->new('wchar_t[' . $chars . ']', false);
        for ($i = 0; $i < $chars; $i++) {
            $arr[$i] = 0;
        }
        return $arr;
    }

    /**
     * 构建 OPENFILENAMEW 的 lpstrFilter 双 \0 结尾 UTF-16LE 缓冲。
     *
     * 输入格式：
     *   - "描述|过滤"  → "描述\0过滤\0"
     *   - "*.txt"     → "*.txt\0*.txt\0"（无 | 时用模式本身作为描述）
     * 多对串联，最后额外一个 \0 终止整个字符串。
     *
     * @param \FFI $ffi 目标 FFI 作用域（comdlg32）
     * @param array<int,string> $filters 过滤器列表
     */
    private function buildOpenFileNameFilter(\FFI $ffi, array $filters): \FFI\CData
    {
        $parts = [];
        foreach ($filters as $f) {
            if (str_contains($f, '|')) {
                [$desc, $pat] = explode('|', $f, 2);
                $parts[] = $desc;
                $parts[] = $pat;
            } else {
                // 无 "|"：用模式本身作为描述
                $parts[] = $f;
                $parts[] = $f;
            }
        }

        // 预计算总 wchar_t 数（含段间 \0 与终止 \0）
        $encoded = [];
        $totalChars = 1; // 终止 \0
        foreach ($parts as $p) {
            $wide = mb_convert_encoding($p, 'UTF-16LE', 'UTF-8');
            $encoded[] = $wide;
            $totalChars += intdiv(strlen($wide), 2) + 1; // 内容 + 段间 \0
        }

        $arr = $ffi->new('wchar_t[' . $totalChars . ']', false);
        $idx = 0;
        foreach ($encoded as $wide) {
            $len = intdiv(strlen($wide), 2);
            for ($i = 0; $i < $len; $i++) {
                $arr[$idx++] = ord($wide[$i * 2]) | (ord($wide[$i * 2 + 1]) << 8);
            }
            $arr[$idx++] = 0; // 段间 \0
        }
        $arr[$idx++] = 0; // 终止 \0
        return $arr;
    }

    // ============================================================
    // 默认字体（CreateFontW "Segoe UI" 支持中文回退）
    // ============================================================

    /**
     * 创建默认字体（单例，首次调用创建，后续返回缓存）。
     *
     * @param int $size 字号（磅），默认 14。
     * @return int HFONT 的 int 表示（用于 SendMessageW 的 WPARAM）。
     */
    private function createDefaultFont(int $size = 14): int
    {
        if ($this->defaultFontInt !== 0) {
            return $this->defaultFontInt;
        }
        $faceName = $this->utf8ToWide('Segoe UI');
        $height = -max(1, (int) round($size * 96 / 72));
        $font = $this->gdi32->CreateFontW(
            $height, 0, 0, 0,
            400,   // FW_NORMAL
            0, 0, 0,
            1,     // DEFAULT_CHARSET
            0, 0, 0,
            0,     // DEFAULT_PITCH | FF_DONTCARE
            \FFI::addr($faceName[0])
        );
        $this->defaultFont = $font;
        $this->defaultFontInt = $this->gdiPtrToInt($font);
        return $this->defaultFontInt;
    }

    /**
     * 为指定窗口/控件设置默认字体。
     */
    private function applyDefaultFont(\FFI\CData $hwnd): void
    {
        $fontInt = $this->createDefaultFont();
        $this->user32->SendMessageW($hwnd, self::WM_SETFONT, $fontInt, 1);
    }

    // ============================================================
    // Task 6: 窗口类注册与窗口创建（WNDCLASSEXW）
    // ============================================================

    /**
     * 注册窗口类 PhpUiWindow（仅注册一次）。
     */
    private function registerWindowClass(): void
    {
        if ($this->classRegistered) {
            return;
        }

        // 初始化通用控件（Trackbar/UpDown/ProgressBar 等）
        $icc = $this->comctl32->new('INITCOMMONCONTROLSEX');
        $icc->dwSize = 8;
        $icc->dwICC = 0x003F; // ICC_ALL_CLASSES
        $this->comctl32->InitCommonControlsEx(\FFI::addr($icc));

        // WindowProc 闭包
        $this->windowProc = function ($hwnd, $msg, $wParam, $lParam): int {
            return $this->dispatchWindowProc($hwnd, (int) $msg, (int) $wParam, (int) $lParam);
        };

        // 类名 wchar_t[]（owned=false 常驻进程）
        $this->classNameBuf = $this->utf8ToWide(self::WINDOW_CLASS_NAME);
        $classNamePtr = \FFI::addr($this->classNameBuf[0]);

        $wc = $this->user32->new('WNDCLASSEXW');
        $wc->cbSize = \FFI::sizeof($wc);
        $wc->style = self::CS_HREDRAW | self::CS_VREDRAW;
        $wc->lpfnWndProc = $this->windowProc;
        $wc->cbClsExtra = 0;
        $wc->cbWndExtra = 0;
        $wc->hInstance = null;
        $wc->hIcon = null;
        $wc->hCursor = $this->user32->LoadCursorW(null, self::IDC_ARROW);
        $bgC = $this->user32->new('INT_TO_PTR');
        $bgC->i = self::COLOR_WINDOW + 1;
        $wc->hbrBackground = $bgC->p;
        $wc->lpszMenuName = null;
        $wc->lpszClassName = $classNamePtr;
        $wc->hIconSm = null;

        $atom = $this->user32->RegisterClassExW(\FFI::addr($wc));
        if ($atom == 0) {
            throw new \RuntimeException(
                'RegisterClassExW failed for class "' . self::WINDOW_CLASS_NAME . '"'
            );
        }

        $this->classRegistered = true;
    }

    /**
     * WindowProc 消息分发。
     */
    private function dispatchWindowProc(\FFI\CData $hwnd, int $msg, int $wParam, int $lParam): int
    {
        // 模态守护
        if ($this->inModalDialog) {
            return (int) $this->user32->DefWindowProcW($hwnd, $msg, $wParam, $lParam);
        }

        $hwndInt = $this->hwndInt($hwnd);
        $isWindow = isset($this->windows[$hwndInt]);

        if ($isWindow) {
            switch ($msg) {
                case self::WM_SIZE:
                    $this->relayoutWindowNow($hwndInt);
                    // 同步滚动条 page 大小（客户区高度变化后更新 SCROLLINFO）
                    if (isset($this->windowScrollInfo[$hwndInt])) {
                        $client = $this->windowGetClientSize($hwndInt);
                        $this->applyScrollInfo($hwndInt, $client->height);
                    }
                    $window = $this->windows[$hwndInt] ?? null;
                    if ($window !== null && $window->onResize !== null) {
                        $w = $lParam & 0xFFFF;
                        $h = ($lParam >> 16) & 0xFFFF;
                        try {
                            ($window->onResize)(new ResizeEvent($w, $h));
                        } catch (\Throwable $e) {
                            trigger_error(
                                'onResize callback error: ' . $e->getMessage(),
                                \E_USER_WARNING
                            );
                        }
                    }
                    return 0;

                case self::WM_CLOSE:
                    $window = $this->windows[$hwndInt] ?? null;
                    $prevent = false;
                    if ($window !== null && $window->onClose !== null) {
                        try {
                            $ret = ($window->onClose)();
                            if ($ret === false) {
                                $prevent = true;
                            }
                        } catch (\Throwable $e) {
                            trigger_error(
                                'onClose callback error: ' . $e->getMessage(),
                                \E_USER_WARNING
                            );
                        }
                    }
                    if ($prevent) {
                        return 0;
                    }
                    return (int) $this->user32->DefWindowProcW($hwnd, $msg, $wParam, $lParam);

                case self::WM_DESTROY:
                    $this->unregisterWindow($hwndInt);
                    unset($this->windowScrollInfo[$hwndInt]);
                    if ($this->windows === []) {
                        $this->user32->PostQuitMessage(0);
                    }
                    return 0;

                case self::WM_COMMAND:
                    return $this->dispatchWmCommand($hwndInt, $wParam, $lParam);

                case self::WM_HSCROLL:
                case self::WM_VSCROLL:
                    return $this->dispatchScroll($hwndInt, $msg, $wParam, $lParam);

                case self::WM_NOTIFY:
                    return (int) $this->user32->DefWindowProcW($hwnd, $msg, $wParam, $lParam);

                case self::WM_PAINT:
                    return (int) $this->user32->DefWindowProcW($hwnd, $msg, $wParam, $lParam);

                case self::WM_MOUSEMOVE:
                case self::WM_LBUTTONDOWN:
                case self::WM_LBUTTONUP:
                case self::WM_RBUTTONDOWN:
                case self::WM_RBUTTONUP:
                case self::WM_MBUTTONDOWN:
                case self::WM_MBUTTONUP:
                case self::WM_KEYDOWN:
                case self::WM_KEYUP:
                    return (int) $this->user32->DefWindowProcW($hwnd, $msg, $wParam, $lParam);

                default:
                    return (int) $this->user32->DefWindowProcW($hwnd, $msg, $wParam, $lParam);
            }
        }

        // 控件分支：Area 控件（PhpUiWindow 类的 WS_CHILD 子窗口）在此处理
        // WM_PAINT/鼠标消息；其他控件走 DefWindowProcW 默认处理。
        if (($this->controlTypes[$hwndInt] ?? '') === 'Area') {
            return $this->dispatchAreaMessage($hwnd, $hwndInt, $msg, $wParam, $lParam);
        }

        // Container 控件（PhpUiWindow 类的 WS_CHILD 子窗口，如 HBox/VBox/Grid/Form）
        // 作为按钮等子控件的父窗口，会收到子控件发来的 WM_COMMAND 通知。
        // 必须在此分发到对应控件的 onClick/onChange/onSelect 回调，否则按钮点击无效。
        // 注意：lParam 是控件 HWND，用 $this->controls 反查控件实例。
        if ($msg === self::WM_COMMAND && $lParam !== 0) {
            return $this->dispatchWmCommand($hwndInt, $wParam, $lParam);
        }

        return (int) $this->user32->DefWindowProcW($hwnd, $msg, $wParam, $lParam);
    }

    /**
     * Area 控件消息分发：WM_PAINT 触发 onDraw，鼠标消息触发对应回调。
     */
    private function dispatchAreaMessage(\FFI\CData $hwnd, int $hwndInt, int $msg, int $wParam, int $lParam): int
    {
        $area = $this->controls[$hwndInt] ?? null;

        switch ($msg) {
            case self::WM_PAINT:
                $ctx = $this->drawContextCreate($hwndInt);
                try {
                    if ($area !== null && $area->onDraw !== null) {
                        try {
                            ($area->onDraw)($ctx);
                        } catch (\Throwable $e) {
                            trigger_error(
                                'onDraw callback error: ' . $e->getMessage(),
                                \E_USER_WARNING
                            );
                        }
                    }
                } finally {
                    $this->drawContextFree($ctx);
                }
                return 0;

            case self::WM_MOUSEMOVE:
            case self::WM_LBUTTONDOWN:
            case self::WM_LBUTTONUP:
            case self::WM_RBUTTONDOWN:
            case self::WM_RBUTTONUP:
            case self::WM_MBUTTONDOWN:
            case self::WM_MBUTTONUP:
                $this->dispatchAreaMouse($area, $msg, $wParam, $lParam);
                return 0;

            default:
                return (int) $this->user32->DefWindowProcW($hwnd, $msg, $wParam, $lParam);
        }
    }

    /**
     * Area 鼠标消息分发。
     *
     * lParam 低字=x 高字=y（signed short，需符号扩展）。
     * wParam 的 MK_LBUTTON=0x0001/MK_RBUTTON=0x0002/MK_MBUTTON=0x0010。
     */
    private function dispatchAreaMouse(?object $area, int $msg, int $wParam, int $lParam): void
    {
        if ($area === null) {
            return;
        }
        // 符号扩展 signed short → int
        $x = $lParam & 0xFFFF;
        if ($x >= 0x8000) {
            $x -= 0x10000;
        }
        $y = ($lParam >> 16) & 0xFFFF;
        if ($y >= 0x8000) {
            $y -= 0x10000;
        }

        // 修饰键：MK_SHIFT=0x0004, MK_CONTROL=0x0008
        $modifiers = MouseEvent::MODIFIER_NONE;
        if ($wParam & 0x0004) {
            $modifiers |= MouseEvent::MODIFIER_SHIFT;
        }
        if ($wParam & 0x0008) {
            $modifiers |= MouseEvent::MODIFIER_CTRL;
        }

        $button = MouseEvent::BUTTON_NONE;
        $callback = null;

        switch ($msg) {
            case self::WM_LBUTTONDOWN:
                $button = MouseEvent::BUTTON_LEFT;
                $callback = $area->onMouseDown ?? null;
                break;
            case self::WM_RBUTTONDOWN:
                $button = MouseEvent::BUTTON_RIGHT;
                $callback = $area->onMouseDown ?? null;
                break;
            case self::WM_MBUTTONDOWN:
                $button = MouseEvent::BUTTON_MIDDLE;
                $callback = $area->onMouseDown ?? null;
                break;
            case self::WM_LBUTTONUP:
                $button = MouseEvent::BUTTON_LEFT;
                $callback = $area->onMouseUp ?? null;
                break;
            case self::WM_RBUTTONUP:
                $button = MouseEvent::BUTTON_RIGHT;
                $callback = $area->onMouseUp ?? null;
                break;
            case self::WM_MBUTTONUP:
                $button = MouseEvent::BUTTON_MIDDLE;
                $callback = $area->onMouseUp ?? null;
                break;
            case self::WM_MOUSEMOVE:
                // 从 wParam 推断当前按下的按键
                if ($wParam & 0x0001) {
                    $button = MouseEvent::BUTTON_LEFT;
                } elseif ($wParam & 0x0002) {
                    $button = MouseEvent::BUTTON_RIGHT;
                } elseif ($wParam & 0x0010) {
                    $button = MouseEvent::BUTTON_MIDDLE;
                }
                $callback = $area->onMouseMove ?? null;
                break;
        }

        if ($callback === null) {
            return;
        }
        try {
            $callback(new MouseEvent($x, $y, $button, $modifiers));
        } catch (\Throwable $e) {
            trigger_error(
                'mouse callback error: ' . $e->getMessage(),
                \E_USER_WARNING
            );
        }
    }

    /**
     * WM_COMMAND 分发：
     *   - lParam != 0 → 控件通知（通过 lParam 反查控件实例）
     *   - lParam == 0 → 菜单命令，LOWORD(wParam) = 菜单项 ID
     *     遍历窗口 Menu 的 items，findItemById 匹配，调 onClick。
     */
    private function dispatchWmCommand(int $hwndInt, int $wParam, int $lParam): int
    {
        if ($lParam === 0) {
            // 菜单/加速键命令
            $menuItemId = $wParam & 0xFFFF; // LOWORD(wParam)
            $window = $this->windows[$hwndInt] ?? null;
            if ($window !== null && method_exists($window, 'getMenu')) {
                $menu = $window->getMenu();
                if ($menu !== null) {
                    $item = $menu->findItemById($menuItemId);
                    if ($item !== null && $item->onClick !== null) {
                        try {
                            ($item->onClick)();
                        } catch (\Throwable $e) {
                            trigger_error(
                                'menu onClick callback error: ' . $e->getMessage(),
                                \E_USER_WARNING
                            );
                        }
                    }
                }
            }
            return 0;
        }
        $notification = ($wParam >> 16) & 0xFFFF;
        $control = $this->controls[$lParam] ?? null;
        if ($control === null) {
            return 0;
        }

        if ($notification === self::BN_CLICKED) {
            // RadioBox 点击时手动选中并取消同组其他项。
            // BS_AUTORADIOBUTTON 在 Container（子窗口）父下自动切换不稳定，
            // 此处显式调用 setChecked(true) 触发 uncheckSiblings 逻辑。
            if ($control instanceof \Kingbes\Ui\Control\RadioBox) {
                $control->setChecked(true);
            }
            if ($control->onClick !== null) {
                try {
                    ($control->onClick)();
                } catch (\Throwable $e) {
                    trigger_error(
                        'onClick callback error: ' . $e->getMessage(),
                        \E_USER_WARNING
                    );
                }
            }
        } elseif ($notification === self::EN_CHANGE) {
            if (property_exists($control, 'onChange') && $control->onChange !== null) {
                try {
                    ($control->onChange)();
                } catch (\Throwable $e) {
                    trigger_error(
                        'onChange callback error: ' . $e->getMessage(),
                        \E_USER_WARNING
                    );
                }
            }
        } elseif ($notification === self::CBN_SELCHANGE || $notification === self::LBN_SELCHANGE) {
            if (property_exists($control, 'onSelect') && $control->onSelect !== null) {
                try {
                    ($control->onSelect)();
                } catch (\Throwable $e) {
                    trigger_error(
                        'onSelect callback error: ' . $e->getMessage(),
                        \E_USER_WARNING
                    );
                }
            }
        }
        return 0;
    }

    /**
     * WM_HSCROLL/WM_VSCROLL 分发。
     *
     *   - lParam != 0：控件滚动条（Slider 等），分发到对应控件的 onChanged。
     *   - lParam == 0 且 WM_VSCROLL：窗口垂直滚动条，更新 scrollPos 并重新布局。
     *     SB_THUMBTRACK/SB_THUMBPOSITION 的滚动位置在 HIWORD(wParam)（16 位有符号）。
     *
     * 注意：lParam==0 也可能来自 Slider 的标准滚动条，但 Slider 使用 SB_CTL
     * 类型且其消息 lParam 非 0，故此处 lParam==0 一律按窗口滚动条处理。
     *
     * @param int $hwndInt 接收消息的窗口句柄。
     */
    private function dispatchScroll(int $hwndInt, int $msg, int $wParam, int $lParam): int
    {
        // 窗口垂直滚动条
        if ($lParam === 0 && $msg === self::WM_VSCROLL) {
            return $this->handleWindowVScroll($hwndInt, $wParam);
        }
        if ($lParam === 0) {
            return 0; // 其他标准滚动条（如 WM_HSCROLL 窗口水平条），忽略
        }
        // 控件滚动条：Slider onChanged
        $notification = $wParam & 0xFFFF;
        $control = $this->controls[$lParam] ?? null;
        if ($control !== null && property_exists($control, 'onChanged') && $control->onChanged !== null) {
            try {
                ($control->onChanged)();
            } catch (\Throwable $e) {
                trigger_error(
                    'onChanged callback error: ' . $e->getMessage(),
                    \E_USER_WARNING
                );
            }
        }
        return 0;
    }

    /**
     * 处理窗口垂直滚动条 WM_VSCROLL：更新 scrollPos 并触发重布局。
     *
     * SB_THUMBTRACK/SB_THUMBPOSITION 的目标位置在 HIWORD(wParam)，需
     * 符号扩展为有符号 16 位整数。
     *
     * @param int $hwndInt 窗口句柄。
     * @param int $wParam  WM_VSCROLL 的 wParam。
     * @return int 始终返回 0（已处理）。
     */
    private function handleWindowVScroll(int $hwndInt, int $wParam): int
    {
        $info = $this->windowScrollInfo[$hwndInt] ?? null;
        if ($info === null) {
            return 0;
        }

        $notification = $wParam & 0xFFFF;
        $client = $this->windowGetClientSize($hwndInt);
        $clientH = $client->height;
        $page = max(1, $clientH);
        $maxPos = max(0, $info['contentHeight'] - $clientH);
        $oldPos = $info['scrollPos'];
        $newPos = $oldPos;

        switch ($notification) {
            case self::SB_LINEUP:
                $newPos = $oldPos - 16;
                break;
            case self::SB_LINEDOWN:
                $newPos = $oldPos + 16;
                break;
            case self::SB_PAGEUP:
                $newPos = $oldPos - $page;
                break;
            case self::SB_PAGEDOWN:
                $newPos = $oldPos + $page;
                break;
            case self::SB_THUMBTRACK:
            case self::SB_THUMBPOSITION:
                // HIWORD(wParam) 为 16 位有符号整数
                $newPos = ($wParam >> 16) & 0xFFFF;
                if ($newPos >= 0x8000) {
                    $newPos -= 0x10000;
                }
                break;
            case self::SB_TOP:
                $newPos = 0;
                break;
            case self::SB_BOTTOM:
                $newPos = $maxPos;
                break;
            case self::SB_ENDSCROLL:
            default:
                return 0; // 不处理
        }

        // 钳位到 [0, maxPos]
        $newPos = max(0, min($newPos, $maxPos));
        if ($newPos === $oldPos) {
            return 0;
        }

        $info['scrollPos'] = $newPos;
        $this->windowScrollInfo[$hwndInt] = $info;
        $this->applyScrollInfo($hwndInt, $clientH);
        $this->triggerRelayout($hwndInt);
        return 0;
    }

    // ============================================================
    // 窗口方法
    // ============================================================

    public function windowCreate(string $title, int $width, int $height): int
    {
        $this->registerWindowClass();

        $classNameBuf = $this->utf8ToWide(self::WINDOW_CLASS_NAME);
        $titleBuf = $this->utf8ToWide($title);

        $hwnd = $this->user32->CreateWindowExW(
            0,
            \FFI::addr($classNameBuf[0]),
            \FFI::addr($titleBuf[0]),
            self::WS_OVERLAPPEDWINDOW,
            self::CW_USEDEFAULT,
            self::CW_USEDEFAULT,
            $width,
            $height,
            null, null, null, null
        );
        if ($hwnd === null || \FFI::isNull($hwnd)) {
            throw new \RuntimeException('CreateWindowExW failed');
        }

        $hwndInt = $this->hwndInt($hwnd);
        $this->user32->SetWindowLongPtrW($hwnd, self::GWLP_USERDATA, $hwndInt);

        // 设置默认字体
        $this->applyDefaultFont($hwnd);

        return $hwndInt;
    }

    public function windowDestroy(int $hwnd): void
    {
        $this->user32->DestroyWindow($this->intToHwnd($hwnd));
    }

    public function windowSetTitle(int $hwnd, string $title): void
    {
        $buf = $this->utf8ToWide($title);
        $this->user32->SetWindowTextW($this->intToHwnd($hwnd), \FFI::addr($buf[0]));
    }

    public function windowGetTitle(int $hwnd): string
    {
        $h = $this->intToHwnd($hwnd);
        $len = (int) $this->user32->GetWindowTextLengthW($h);
        if ($len <= 0) {
            return '';
        }
        $buf = $this->user32->new('wchar_t[' . ($len + 1) . ']');
        $this->user32->GetWindowTextW($h, $buf, $len + 1);
        return $this->wideToUtf8($buf);
    }

    public function windowSetPosition(int $hwnd, int $x, int $y): void
    {
        $this->user32->SetWindowPos(
            $this->intToHwnd($hwnd), null,
            $x, $y, 0, 0,
            self::SWP_NOSIZE | self::SWP_NOZORDER
        );
    }

    public function windowGetPosition(int $hwnd): Point
    {
        $rect = $this->user32->new('RECT');
        $this->user32->GetWindowRect($this->intToHwnd($hwnd), \FFI::addr($rect));
        return Point::of((int) $rect->left, (int) $rect->top);
    }

    public function windowSetSize(int $hwnd, int $width, int $height): void
    {
        $this->user32->SetWindowPos(
            $this->intToHwnd($hwnd), null,
            0, 0, $width, $height,
            self::SWP_NOMOVE | self::SWP_NOZORDER
        );
    }

    public function windowGetSize(int $hwnd): Size
    {
        $rect = $this->user32->new('RECT');
        $this->user32->GetWindowRect($this->intToHwnd($hwnd), \FFI::addr($rect));
        return Size::of((int) $rect->right - (int) $rect->left, (int) $rect->bottom - (int) $rect->top);
    }

    public function windowGetClientSize(int $hwnd): Size
    {
        $rect = $this->user32->new('RECT');
        $this->user32->GetClientRect($this->intToHwnd($hwnd), \FFI::addr($rect));
        return Size::of((int) $rect->right, (int) $rect->bottom);
    }

    public function windowSetFullscreen(int $hwnd, bool $fullscreen): void
    {
        $h = $this->intToHwnd($hwnd);
        if ($fullscreen) {
            if (isset($this->fullscreenState[$hwnd])) {
                return;
            }
            $rect = $this->user32->new('RECT');
            $this->user32->GetWindowRect($h, \FFI::addr($rect));
            $style = (int) $this->user32->GetWindowLongPtrW($h, self::GWL_STYLE);
            $this->fullscreenState[$hwnd] = [
                (int) $rect->left, (int) $rect->top,
                (int) $rect->right, (int) $rect->bottom,
                $style,
            ];
            $this->user32->SetWindowLongPtrW($h, self::GWL_STYLE, self::WS_POPUP | self::WS_VISIBLE);
            $sw = (int) $this->user32->GetSystemMetrics(self::SM_CXSCREEN);
            $sh = (int) $this->user32->GetSystemMetrics(self::SM_CYSCREEN);
            $this->user32->SetWindowPos(
                $h, null, 0, 0, $sw, $sh,
                self::SWP_FRAMECHANGED | self::SWP_NOZORDER
            );
        } else {
            $state = $this->fullscreenState[$hwnd] ?? null;
            if ($state === null) {
                return;
            }
            unset($this->fullscreenState[$hwnd]);
            $this->user32->SetWindowLongPtrW($h, self::GWL_STYLE, $state[4]);
            $w = $state[2] - $state[0];
            $hgt = $state[3] - $state[1];
            $this->user32->SetWindowPos(
                $h, null, $state[0], $state[1], $w, $hgt,
                self::SWP_FRAMECHANGED | self::SWP_NOZORDER
            );
        }
    }

    public function windowSetBorderless(int $hwnd, bool $borderless): void
    {
        $h = $this->intToHwnd($hwnd);
        $style = (int) $this->user32->GetWindowLongPtrW($h, self::GWL_STYLE);
        if ($borderless) {
            $style &= ~(self::WS_CAPTION | self::WS_THICKFRAME);
        } else {
            $style |= (self::WS_CAPTION | self::WS_THICKFRAME);
        }
        $this->user32->SetWindowLongPtrW($h, self::GWL_STYLE, $style);
        $this->user32->SetWindowPos(
            $h, null, 0, 0, 0, 0,
            self::SWP_FRAMECHANGED | self::SWP_NOMOVE | self::SWP_NOSIZE | self::SWP_NOZORDER
        );
        $this->triggerRelayout($hwnd);
    }

    public function windowSetResizeable(int $hwnd, bool $resizeable): void
    {
        $h = $this->intToHwnd($hwnd);
        $style = (int) $this->user32->GetWindowLongPtrW($h, self::GWL_STYLE);
        if ($resizeable) {
            $style |= self::WS_THICKFRAME;
        } else {
            $style &= ~self::WS_THICKFRAME;
        }
        $this->user32->SetWindowLongPtrW($h, self::GWL_STYLE, $style);
        $this->user32->SetWindowPos(
            $h, null, 0, 0, 0, 0,
            self::SWP_FRAMECHANGED | self::SWP_NOMOVE | self::SWP_NOSIZE | self::SWP_NOZORDER
        );
    }

    public function windowMaximize(int $hwnd): void
    {
        $this->user32->ShowWindow($this->intToHwnd($hwnd), self::SW_MAXIMIZE);
    }

    public function windowMinimize(int $hwnd): void
    {
        $this->user32->ShowWindow($this->intToHwnd($hwnd), self::SW_MINIMIZE);
    }

    public function windowRestore(int $hwnd): void
    {
        $this->user32->ShowWindow($this->intToHwnd($hwnd), self::SW_RESTORE);
    }

    public function windowShow(int $hwnd): void
    {
        $this->user32->ShowWindow($this->intToHwnd($hwnd), self::SW_SHOW);
        $this->user32->UpdateWindow($this->intToHwnd($hwnd));
    }

    public function windowHide(int $hwnd): void
    {
        $this->user32->ShowWindow($this->intToHwnd($hwnd), self::SW_HIDE);
    }

    public function windowSetTopmost(int $hwnd, bool $topmost): void
    {
        $insertAfter = $topmost ? self::HWND_TOPMOST : self::HWND_NOTOPMOST;
        $this->user32->SetWindowPos(
            $this->intToHwnd($hwnd),
            $this->intToHwnd($insertAfter),
            0, 0, 0, 0,
            self::SWP_NOMOVE | self::SWP_NOSIZE
        );
    }

    public function windowSetChild(int $hwnd, int $childHwnd): void
    {
        $this->user32->SetParent($this->intToHwnd($childHwnd), $this->intToHwnd($hwnd));
    }

    public function windowSetScrollable(int $hwnd, int $contentHeight): void
    {
        $h = $this->intToHwnd($hwnd);
        // 1. 添加 WS_VSCROLL 样式
        $style = (int) $this->user32->GetWindowLongPtrW($h, self::GWL_STYLE);
        $style |= self::WS_VSCROLL;
        $this->user32->SetWindowLongPtrW($h, self::GWL_STYLE, $style);
        $this->user32->SetWindowPos(
            $h, null, 0, 0, 0, 0,
            self::SWP_FRAMECHANGED | self::SWP_NOMOVE | self::SWP_NOSIZE | self::SWP_NOZORDER
        );

        // 2. 记录滚动状态
        $client = $this->windowGetClientSize($hwnd);
        $this->windowScrollInfo[$hwnd] = [
            'contentHeight' => max(0, $contentHeight),
            'scrollPos'     => 0,
        ];

        // 3. 设置 SCROLLINFO（range=[0, contentHeight], page=clientHeight）
        $this->applyScrollInfo($hwnd, $client->height);

        // 4. 异步触发重布局（用 contentHeight + scrollPos 偏移）
        $this->triggerRelayout($hwnd);
    }

    /**
     * 应用 SCROLLINFO 到窗口垂直滚动条。
     *
     * fMask = SIF_RANGE | SIF_PAGE | SIF_POS：设置范围、页面大小、当前位置。
     * nMax 取 contentHeight；nPage 取 clientHeight（一页可见高度）。
     * 当 contentHeight <= clientHeight 时 Windows 自动隐藏滚动条。
     *
     * @param int $hwnd          窗口句柄。
     * @param int $clientHeight  客户区高度（用于 nPage）。
     */
    private function applyScrollInfo(int $hwnd, int $clientHeight): void
    {
        $info = $this->windowScrollInfo[$hwnd] ?? null;
        if ($info === null) {
            return;
        }
        $si = $this->user32->new('SCROLLINFO');
        $si->cbSize = \FFI::sizeof($si);
        $si->fMask = self::SIF_RANGE | self::SIF_PAGE | self::SIF_POS;
        $si->nMin = 0;
        $si->nMax = $info['contentHeight'];
        $si->nPage = max(0, $clientHeight);
        $si->nPos = $info['scrollPos'];
        $si->nTrackPos = 0;
        $this->user32->SetScrollInfo(
            $this->intToHwnd($hwnd),
            self::SB_VERT,
            \FFI::addr($si),
            1 // redraw
        );
    }

    /**
     * 立即对窗口的顶层容器执行重布局（同步）。
     *
     * 覆盖 AbstractPlatform 实现：当窗口启用了滚动条（windowScrollInfo 中
     * 存在该 hwnd）时，将顶层容器的位置设为 (0, -scrollPos)，高度设为
     * contentHeight，使子控件随滚动条偏移；否则退化为父类默认行为
     * （容器位置 (0,0)，使用客户区尺寸）。
     *
     * 不使用 ScrollWindowEx：避免 WM_SIZE 重布局时丢失滚动位置。
     */
    protected function relayoutWindowNow(int $hwnd): void
    {
        $window = $this->windows[$hwnd] ?? null;
        if ($window === null) {
            return;
        }
        if (!method_exists($window, 'getChildContainer')) {
            return;
        }
        $container = $window->getChildContainer();
        if ($container === null) {
            return;
        }
        if (!method_exists($container, 'layout')) {
            return;
        }
        $size = $this->windowGetClientSize($hwnd);
        $info = $this->windowScrollInfo[$hwnd] ?? null;

        // 关键：先设置顶层 Container 自身的位置和尺寸到 Window 客户区。
        // controlCreate 创建 Container 时初始尺寸为 0x0，若不显式设置，
        // 子控件虽在 layout 中被 setBounds 到 Container 客户区坐标，
        // 但 Container 客户区为 0x0 会裁剪掉所有子控件，导致窗口空白。
        $containerHwnd = $container->getHwnd();
        if ($containerHwnd !== 0) {
            if ($info === null) {
                $this->controlSetBounds(
                    $containerHwnd,
                    0,
                    0,
                    $size->width,
                    $size->height
                );
            } else {
                // 有滚动：将容器自身位置偏移到 (0, -scrollPos)，高度设为 contentHeight；
                // layout() 在容器内部坐标系内排布子控件（0, 0, w, contentHeight）。
                $this->controlSetBounds(
                    $containerHwnd,
                    0,
                    -$info['scrollPos'],
                    $size->width,
                    $info['contentHeight']
                );
            }
        }

        if ($info === null) {
            // 无滚动：默认布局
            $container->layout(0, 0, $size->width, $size->height);
            return;
        }
        $container->layout(0, 0, $size->width, $info['contentHeight']);
    }

    public function windowIsFocused(int $hwnd): bool
    {
        $fg = $this->user32->GetForegroundWindow();
        if ($fg === null) {
            return false;
        }
        return $this->hwndInt($fg) === $hwnd;
    }

    public function windowSetMenu(int $hwnd, int $menuHwnd): void
    {
        $this->user32->SetMenu($this->intToHwnd($hwnd), $this->intToHwnd($menuHwnd));
        $this->user32->SetWindowPos(
            $this->intToHwnd($hwnd), null,
            0, 0, 0, 0,
            self::SWP_FRAMECHANGED | self::SWP_NOMOVE | self::SWP_NOSIZE | self::SWP_NOZORDER
        );
        // 刷新菜单栏显示
        $this->user32->DrawMenuBar($this->intToHwnd($hwnd));
        // SWP_NOSIZE 抑制 WM_SIZE，需异步触发重布局以适应菜单栏占用的客户区变化
        $this->triggerRelayout($hwnd);
    }

    // ============================================================
    // Task 7: 事件循环与 queueMain
    // ============================================================

    public function run(): void
    {
        $this->running = true;
        $msg = $this->user32->new('MSG');
        $msgAddr = \FFI::addr($msg);

        while ($this->running) {
            $has = (int) $this->user32->PeekMessageW($msgAddr, null, 0, 0, self::PM_REMOVE);
            if ($has) {
                $msgId = (int) $msg->message;
                if ($msgId === self::WM_QUIT) {
                    break;
                }

                // 拦截控件键盘消息，分发到 onKeyDown/onKeyUp
                if ($msgId === self::WM_KEYDOWN || $msgId === self::WM_KEYUP) {
                    $this->dispatchControlKey($msg, $msgId);
                }

                $this->user32->TranslateMessage($msgAddr);
                $this->user32->DispatchMessageW($msgAddr);
            } else {
                $this->runTimers();
                $this->runQueueMain();
                usleep(1000);
            }
        }

        $this->running = false;
    }

    /**
     * 在消息循环中拦截 WM_KEYDOWN/WM_KEYUP，分发到控件的键盘回调。
     */
    private function dispatchControlKey(\FFI\CData $msg, int $msgId): void
    {
        $hwndInt = $this->hwndInt($msg->hwnd);
        $control = $this->controls[$hwndInt] ?? null;
        if ($control === null) {
            return;
        }
        $keyCode = (int) $msg->wParam;

        if ($msgId === self::WM_KEYDOWN) {
            if ($control->onKeyDown !== null) {
                try {
                    ($control->onKeyDown)(new KeyEvent($keyCode));
                } catch (\Throwable $e) {
                    trigger_error(
                        'onKeyDown callback error: ' . $e->getMessage(),
                        \E_USER_WARNING
                    );
                }
            }
            // Entry onEnter (VK_RETURN)
            if ($keyCode === self::VK_RETURN && $control instanceof Entry) {
                if ($control->onEnter !== null) {
                    try {
                        ($control->onEnter)();
                    } catch (\Throwable $e) {
                        trigger_error(
                            'onEnter callback error: ' . $e->getMessage(),
                            \E_USER_WARNING
                        );
                    }
                }
            }
        } else { // WM_KEYUP
            if ($control->onKeyUp !== null) {
                try {
                    ($control->onKeyUp)(new KeyEvent($keyCode));
                } catch (\Throwable $e) {
                    trigger_error(
                        'onKeyUp callback error: ' . $e->getMessage(),
                        \E_USER_WARNING
                    );
                }
            }
        }
    }

    public function quit(): void
    {
        if (!$this->shouldQuit()) {
            return;
        }
        $this->user32->PostQuitMessage(0);
    }

    // ============================================================
    // 系统服务
    // ============================================================

    public function screenSize(): Size
    {
        $w = (int) $this->user32->GetSystemMetrics(self::SM_CXSCREEN);
        $h = (int) $this->user32->GetSystemMetrics(self::SM_CYSCREEN);
        return Size::of($w, $h);
    }

    public function clipboardSetText(string $text): void
    {
        $wide = mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');
        $byteCount = strlen($wide) + 2; // 含 \0\0 终止

        $hMem = $this->kernel32->GlobalAlloc(self::GMEM_MOVEABLE, $byteCount);
        if ($hMem === null) {
            return;
        }
        $ptr = $this->kernel32->GlobalLock($hMem);
        if ($ptr !== null) {
            if (strlen($wide) > 0) {
                \FFI::memcpy($ptr, $wide, strlen($wide));
            }
            $ptr[strlen($wide)] = "\0";
            $ptr[strlen($wide) + 1] = "\0";
            $this->kernel32->GlobalUnlock($hMem);
        }

        if (!$this->user32->OpenClipboard(null)) {
            $this->kernel32->GlobalFree($hMem);
            return;
        }
        $this->user32->EmptyClipboard();
        $this->user32->SetClipboardData(self::CF_UNICODETEXT, $hMem);
        $this->user32->CloseClipboard();
    }

    public function clipboardGetText(): string
    {
        if (!$this->user32->OpenClipboard(null)) {
            return '';
        }
        $data = $this->user32->GetClipboardData(self::CF_UNICODETEXT);
        $this->user32->CloseClipboard();

        if ($data === null || \FFI::isNull($data)) {
            return '';
        }
        $ptr = $this->kernel32->GlobalLock($data);
        if ($ptr === null) {
            return '';
        }
        // 读取 wchar_t 数据直到双 \0
        $bytes = '';
        $i = 0;
        while (true) {
            $b1 = $ptr[$i];
            $b2 = $ptr[$i + 1];
            if ($b1 === "\0" && $b2 === "\0") {
                break;
            }
            $bytes .= $b1 . $b2;
            $i += 2;
            if ($i > 200000) {
                break;
            }
        }
        $this->kernel32->GlobalUnlock($data);
        return mb_convert_encoding($bytes, 'UTF-8', 'UTF-16LE');
    }

    // ============================================================
    // Task 9: 控件创建通用机制
    // ============================================================

    public function controlCreate(string $className, string $text, int $style, int $exStyle, int $parentHwnd, int $id): int
    {
        $this->registerWindowClass();

        // 分配控件 ID
        if ($id === 0) {
            $id = $this->nextControlId++;
        }

        // 'Container' → 用已注册的 PhpUiWindow 类
        if ($className === 'Container') {
            $classBuf = $this->utf8ToWide(self::WINDOW_CLASS_NAME);
            $fullStyle = self::WS_CHILD | self::WS_VISIBLE | self::WS_CLIPCHILDREN | $style;
        } else {
            $classBuf = $this->utf8ToWide($className);
            $fullStyle = self::WS_CHILD | self::WS_VISIBLE | $style;
        }

        $textBuf = $this->utf8ToWide($text);
        $parent = $parentHwnd !== 0 ? $this->intToHwnd($parentHwnd) : null;
        $menu = $this->intToHwnd($id);

        // ComboBox 下拉列表高度由控件初始高度决定，创建时高度为 0 会导致
        // 下拉列表无法展开。CBS_DROPDOWNLIST 默认下拉约 8 项，此处给 200 像素。
        $initHeight = 0;
        $initWidth = 0;
        if ($className === 'ComboBox') {
            $initHeight = 200;
        }

        $hwnd = $this->user32->CreateWindowExW(
            $exStyle,
            \FFI::addr($classBuf[0]),
            $text === '' ? null : \FFI::addr($textBuf[0]),
            $fullStyle,
            0, 0, $initWidth, $initHeight,
            $parent,
            $menu,
            null, null
        );
        if ($hwnd === null || \FFI::isNull($hwnd)) {
            throw new \RuntimeException('CreateWindowExW failed for class "' . $className . '"');
        }

        // 设置默认字体
        $this->applyDefaultFont($hwnd);

        $hwndInt = $this->hwndInt($hwnd);
        $this->controlTypes[$hwndInt] = $className;

        return $hwndInt;
    }

    public function controlDestroy(int $hwnd): void
    {
        $this->user32->DestroyWindow($this->intToHwnd($hwnd));
        unset($this->controlTypes[$hwnd]);
    }

    public function controlSetText(int $hwnd, string $text): void
    {
        $buf = $this->utf8ToWide($text);
        $this->user32->SetWindowTextW($this->intToHwnd($hwnd), \FFI::addr($buf[0]));
    }

    public function controlGetText(int $hwnd): string
    {
        $h = $this->intToHwnd($hwnd);
        $len = (int) $this->user32->GetWindowTextLengthW($h);
        if ($len <= 0) {
            return '';
        }
        $buf = $this->user32->new('wchar_t[' . ($len + 1) . ']');
        $this->user32->GetWindowTextW($h, $buf, $len + 1);
        return $this->wideToUtf8($buf);
    }

    public function controlSetBounds(int $hwnd, int $x, int $y, int $width, int $height): void
    {
        $this->user32->SetWindowPos(
            $this->intToHwnd($hwnd), null,
            $x, $y, $width, $height,
            self::SWP_NOZORDER
        );
    }

    public function controlShow(int $hwnd): void
    {
        $this->user32->ShowWindow($this->intToHwnd($hwnd), self::SW_SHOW);
    }

    public function controlHide(int $hwnd): void
    {
        $this->user32->ShowWindow($this->intToHwnd($hwnd), self::SW_HIDE);
    }

    public function controlEnable(int $hwnd, bool $enabled): void
    {
        $this->user32->EnableWindow($this->intToHwnd($hwnd), $enabled ? 1 : 0);
    }

    public function controlIsChecked(int $hwnd): bool
    {
        $result = (int) $this->user32->SendMessageW(
            $this->intToHwnd($hwnd), self::BM_GETCHECK, 0, 0
        );
        return $result === self::BST_CHECKED;
    }

    public function controlSetChecked(int $hwnd, bool $checked): void
    {
        $this->user32->SendMessageW(
            $this->intToHwnd($hwnd), self::BM_SETCHECK,
            $checked ? self::BST_CHECKED : self::BST_UNCHECKED, 0
        );
    }

    public function controlAddString(int $hwnd, string $text): void
    {
        $buf = $this->utf8ToWide($text);
        $h = $this->intToHwnd($hwnd);
        $ptr = $this->ptrToInt(\FFI::addr($buf[0]));
        $type = $this->controlTypes[$hwnd] ?? '';
        $msg = ($type === 'ComboBox') ? self::CB_ADDSTRING : self::LB_ADDSTRING;
        $this->user32->SendMessageW($h, $msg, 0, $ptr);
    }

    public function controlRemoveString(int $hwnd, int $index): void
    {
        $h = $this->intToHwnd($hwnd);
        $type = $this->controlTypes[$hwnd] ?? '';
        $msg = ($type === 'ComboBox') ? self::CB_DELETESTRING : self::LB_DELETESTRING;
        $this->user32->SendMessageW($h, $msg, $index, 0);
    }

    public function controlClear(int $hwnd): void
    {
        $h = $this->intToHwnd($hwnd);
        $type = $this->controlTypes[$hwnd] ?? '';
        $msg = ($type === 'ComboBox') ? self::CB_RESETCONTENT : self::LB_RESETCONTENT;
        $this->user32->SendMessageW($h, $msg, 0, 0);
    }

    public function controlGetSelectedIndex(int $hwnd): int
    {
        $h = $this->intToHwnd($hwnd);
        $type = $this->controlTypes[$hwnd] ?? '';
        $msg = ($type === 'ComboBox') ? self::CB_GETCURSEL : self::LB_GETCURSEL;
        return (int) $this->user32->SendMessageW($h, $msg, 0, 0);
    }

    public function controlSetSelectedIndex(int $hwnd, int $index): void
    {
        $h = $this->intToHwnd($hwnd);
        $type = $this->controlTypes[$hwnd] ?? '';
        $msg = ($type === 'ComboBox') ? self::CB_SETCURSEL : self::LB_SETCURSEL;
        $this->user32->SendMessageW($h, $msg, $index, 0);
    }

    public function controlSetRange(int $hwnd, int $min, int $max): void
    {
        $h = $this->intToHwnd($hwnd);
        $type = $this->controlTypes[$hwnd] ?? '';
        if ($type === 'msctls_trackbar32') {
            $this->user32->SendMessageW($h, self::TBM_SETRANGEMIN, 1, $min);
            $this->user32->SendMessageW($h, self::TBM_SETRANGEMAX, 1, $max);
        } elseif ($type === 'msctls_progress32') {
            // PBM_SETRANGE: lParam = MAKELONG(min, max)
            $lParam = ($max << 16) | ($min & 0xFFFF);
            $this->user32->SendMessageW($h, self::PBM_SETRANGE, 0, $lParam);
        } elseif ($type === 'msctls_updown32') {
            // UDM_SETRANGE: lParam = MAKELONG(max, min)
            $lParamUD = ($min << 16) | ($max & 0xFFFF);
            $this->user32->SendMessageW($h, self::UDM_SETRANGE, 0, $lParamUD);
        }
    }

    public function controlSetValue(int $hwnd, int $value): void
    {
        $h = $this->intToHwnd($hwnd);
        $type = $this->controlTypes[$hwnd] ?? '';
        if ($type === 'msctls_trackbar32') {
            // Trackbar: wParam=1 表示重绘
            $this->user32->SendMessageW($h, self::TBM_SETPOS, 1, $value);
        } elseif ($type === 'msctls_progress32') {
            $this->user32->SendMessageW($h, self::PBM_SETPOS, $value, 0);
        } elseif ($type === 'msctls_updown32') {
            // UpDown: 低字为位置值
            $this->user32->SendMessageW($h, self::UDM_SETPOS, 0, $value & 0xFFFF);
        }
    }

    public function controlGetValue(int $hwnd): int
    {
        $h = $this->intToHwnd($hwnd);
        $type = $this->controlTypes[$hwnd] ?? '';
        if ($type === 'msctls_trackbar32') {
            return (int) $this->user32->SendMessageW($h, self::TBM_GETPOS, 0, 0);
        }
        if ($type === 'msctls_updown32') {
            $result = (int) $this->user32->SendMessageW($h, self::UDM_GETPOS, 0, 0);
            return $result & 0xFFFF;
        }
        // ProgressBar 无 GETPOS（旧版）；返回 0
        return 0;
    }

    // ============================================================
    // 以下方法由后续批次实现
    // ============================================================

    public function menuCreateBar(): int
    {
        $hmenu = $this->user32->CreateMenu();
        if ($hmenu === null || \FFI::isNull($hmenu)) {
            throw new \RuntimeException('CreateMenu failed');
        }
        $id = $this->hmenuToInt($hmenu);
        // 保活 HMENU CData，防止 GC 回收后地址失效
        $this->menus[$id] = $hmenu;
        return $id;
    }

    public function menuCreatePopup(): int
    {
        $hmenu = $this->user32->CreatePopupMenu();
        if ($hmenu === null || \FFI::isNull($hmenu)) {
            throw new \RuntimeException('CreatePopupMenu failed');
        }
        $id = $this->hmenuToInt($hmenu);
        // 保活 HMENU CData
        $this->menus[$id] = $hmenu;
        return $id;
    }

    public function menuAppendItem(int $menuHwnd, string $text, int $id): void
    {
        $hmenu = $this->menus[$menuHwnd]
            ?? throw new \RuntimeException("Unknown menu handle: {$menuHwnd}");
        $textBuf = $this->utf8ToWide($text);
        $this->user32->AppendMenuW(
            $hmenu,
            self::MF_STRING,
            $id,
            \FFI::addr($textBuf[0])
        );
    }

    public function menuAppendSeparator(int $menuHwnd): void
    {
        $hmenu = $this->menus[$menuHwnd]
            ?? throw new \RuntimeException("Unknown menu handle: {$menuHwnd}");
        $this->user32->AppendMenuW(
            $hmenu,
            self::MF_SEPARATOR,
            0,
            null
        );
    }

    public function menuAppendSubmenu(int $menuHwnd, string $text, int $submenuHwnd): void
    {
        $hmenu = $this->menus[$menuHwnd]
            ?? throw new \RuntimeException("Unknown menu handle: {$menuHwnd}");
        // 确认子菜单 CData 存在（保活校验）
        if (!isset($this->menus[$submenuHwnd])) {
            throw new \RuntimeException("Unknown submenu handle: {$submenuHwnd}");
        }
        $textBuf = $this->utf8ToWide($text);
        // MF_POPUP 时 uIDNewItem 是子菜单 HMENU 的 int 值（UINT_PTR 接收）
        $this->user32->AppendMenuW(
            $hmenu,
            self::MF_STRING | self::MF_POPUP,
            $submenuHwnd,
            \FFI::addr($textBuf[0])
        );
    }

    public function menuSetEnabled(int $menuHwnd, int $id, bool $enabled): void
    {
        $hmenu = $this->menus[$menuHwnd]
            ?? throw new \RuntimeException("Unknown menu handle: {$menuHwnd}");
        // MF_BYCOMMAND=0 | MF_ENABLED=0 / MF_GRAYED=1
        $this->user32->EnableMenuItem(
            $hmenu,
            $id,
            self::MF_BYCOMMAND | ($enabled ? self::MF_ENABLED : self::MF_GRAYED)
        );
    }

    public function menuSetChecked(int $menuHwnd, int $id, bool $checked): void
    {
        $hmenu = $this->menus[$menuHwnd]
            ?? throw new \RuntimeException("Unknown menu handle: {$menuHwnd}");
        // MF_BYCOMMAND=0 | MF_CHECKED=0x8 / MF_UNCHECKED=0
        $this->user32->CheckMenuItem(
            $hmenu,
            $id,
            self::MF_BYCOMMAND | ($checked ? self::MF_CHECKED : self::MF_UNCHECKED)
        );
    }

    public function menuDestroy(int $menuHwnd): void
    {
        $hmenu = $this->menus[$menuHwnd] ?? null;
        if ($hmenu === null) {
            return;
        }
        $this->user32->DestroyMenu($hmenu);
        unset($this->menus[$menuHwnd]);
    }

    // ============================================================
    // 对话框方法（批次 5：Unicode W 系列，inModalDialog 守护）
    // ============================================================

    /**
     * 消息框（MessageBoxW，支持中文）。
     *
     * inModalDialog=true 期间，WindowProc 对所有消息调 DefWindowProcW
     * 默认处理（允许窗口重绘，不卡死），调用后恢复 false。
     *
     * type 常量：MB_OK=0x0000, MB_OKCANCEL=0x0001, MB_YESNO=0x0004,
     *           MB_ICONERROR=0x10, MB_ICONWARNING=0x30,
     *           MB_ICONINFORMATION=0x40, MB_ICONQUESTION=0x20
     * 返回值：IDOK=1, IDCANCEL=2, IDYES=6, IDNO=7
     */
    public function dialogMsgBox(int $parentHwnd, string $text, string $caption, int $type): int
    {
        $textBuf = $this->utf8ToWide($text);
        $captionBuf = $this->utf8ToWide($caption);
        $hwnd = $parentHwnd !== 0 ? $this->intToHwnd($parentHwnd) : null;

        $this->inModalDialog = true;
        try {
            $result = (int) $this->user32->MessageBoxW(
                $hwnd,
                \FFI::addr($textBuf[0]),
                \FFI::addr($captionBuf[0]),
                $type
            );
        } finally {
            $this->inModalDialog = false;
        }
        return $result;
    }

    /**
     * 打开文件对话框（GetOpenFileNameW）。
     *
     * OPENFILENAMEW 结构体大小由 FFI sizeof 获取（x64 = 152 字节）。
     * 过滤器缓冲为双 \0 结尾 UTF-16LE。lpstrFile 分配 wchar_t[260]。
     * Flags: OFN_EXPLORER=0x00080000 | OFN_FILEMUSTEXIST=0x00001000。
     */
    public function dialogOpenFile(int $parentHwnd, array $filters): ?string
    {
        return $this->openSaveFileName($parentHwnd, $filters, true);
    }

    /**
     * 保存文件对话框（GetSaveFileNameW）。
     * Flags: OFN_EXPLORER=0x00080000 | OFN_OVERWRITEPROMPT=0x00000002。
     */
    public function dialogSaveFile(int $parentHwnd, array $filters): ?string
    {
        return $this->openSaveFileName($parentHwnd, $filters, false);
    }

    /**
     * GetOpenFileNameW / GetSaveFileNameW 共用实现。
     */
    private function openSaveFileName(int $parentHwnd, array $filters, bool $open): ?string
    {
        $filterBuf = $this->buildOpenFileNameFilter($this->comdlg32, $filters);
        $fileBuf = $this->wideBufZero($this->comdlg32, 260);

        $ofn = $this->comdlg32->new('OPENFILENAMEW');
        $ofn->lStructSize = \FFI::sizeof($ofn);
        if ($parentHwnd !== 0) {
            $ofn->hwndOwner = $this->intToPtrIn($this->comdlg32, $parentHwnd);
        }
        $ofn->lpstrFilter = \FFI::addr($filterBuf[0]);
        $ofn->lpstrFile = \FFI::addr($fileBuf[0]);
        $ofn->nMaxFile = 260;
        // OFN_EXPLORER=0x00080000
        // 打开: OFN_FILEMUSTEXIST=0x00001000
        // 保存: OFN_OVERWRITEPROMPT=0x00000002
        $ofn->Flags = $open ? (0x00080000 | 0x00001000) : (0x00080000 | 0x00000002);

        $this->inModalDialog = true;
        try {
            $ok = $open
                ? (int) $this->comdlg32->GetOpenFileNameW(\FFI::addr($ofn))
                : (int) $this->comdlg32->GetSaveFileNameW(\FFI::addr($ofn));
        } finally {
            $this->inModalDialog = false;
        }

        if ($ok === 0) {
            return null; // 取消或失败
        }
        return $this->wideToUtf8($fileBuf);
    }

    /**
     * 打开文件夹对话框（SHBrowseForFolderW + SHGetPathFromIDListW）。
     *
     * BROWSEINFOW.ulFlags: BIF_RETURNONLYFSDIRS=0x0001 | BIF_NEWDIALOGSTYLE=0x0040
     * SHBrowseForFolderW 返回 PIDL，SHGetPathFromIDListW(pidl, buffer) 获取路径。
     * PIDL 需 CoTaskMemFree 释放（ole32.dll，跨作用域经 int 转换）。
     */
    public function dialogOpenFolder(int $parentHwnd): ?string
    {
        $titleBuf = $this->wideBufIn($this->shell32, '选择文件夹');
        $displayBuf = $this->wideBufZero($this->shell32, 260);

        $bi = $this->shell32->new('BROWSEINFOW');
        if ($parentHwnd !== 0) {
            $bi->hwndOwner = $this->intToPtrIn($this->shell32, $parentHwnd);
        }
        $bi->pidlRoot = null;
        $bi->pszDisplayName = \FFI::addr($displayBuf[0]);
        $bi->lpszTitle = \FFI::addr($titleBuf[0]);
        $bi->ulFlags = 0x0001 | 0x0040; // BIF_RETURNONLYFSDIRS | BIF_NEWDIALOGSTYLE
        $bi->lpfn = null;
        $bi->lParam = 0;
        $bi->iImage = 0;

        $this->inModalDialog = true;
        try {
            $pidl = $this->shell32->SHBrowseForFolderW(\FFI::addr($bi));
        } finally {
            $this->inModalDialog = false;
        }

        if ($pidl === null || \FFI::isNull($pidl)) {
            return null; // 用户取消
        }

        $pathBuf = $this->wideBufZero($this->shell32, 260);
        $ok = (int) $this->shell32->SHGetPathFromIDListW($pidl, $pathBuf);

        // 释放 PIDL：shell32 作用域 → int → ole32 作用域 void*
        $pidlInt = $this->ptrToIntIn($this->shell32, $pidl);
        $pidlOle = $this->intToPtrIn($this->ole32, $pidlInt);
        $this->ole32->CoTaskMemFree($pidlOle);

        if ($ok === 0) {
            return null;
        }
        return $this->wideToUtf8($pathBuf);
    }

    /**
     * 颜色对话框（ChooseColorW）。
     *
     * CHOOSECOLORW.lStructSize 由 FFI sizeof 获取（x64 = 72 字节）。
     * lpCustColors 指向 DWORD[16] 持久存储（owned=false 防 GC）。
     * Flags: CC_FULLOPEN=0x0002 | CC_RGBINIT=0x0001。
     * 返回 Color::fromColorRef(rgbResult) 或 null（取消）。
     */
    public function dialogChooseColor(int $parentHwnd): ?Color
    {
        // 自定义颜色数组（DWORD[16]，owned=false 持久化防 GC）
        $custColors = $this->comdlg32->new('DWORD[16]', false);
        for ($i = 0; $i < 16; $i++) {
            $custColors[$i] = 0xFFFFFF; // 白色
        }

        $cc = $this->comdlg32->new('CHOOSECOLORW');
        $cc->lStructSize = \FFI::sizeof($cc);
        if ($parentHwnd !== 0) {
            $cc->hwndOwner = $this->intToPtrIn($this->comdlg32, $parentHwnd);
        }
        $cc->hInstance = null;
        $cc->rgbResult = 0; // 初始黑色
        $cc->lpCustColors = \FFI::addr($custColors[0]);
        $cc->Flags = 0x0002 | 0x0001; // CC_FULLOPEN | CC_RGBINIT
        $cc->lCustData = 0;
        $cc->lpfnHook = null;
        $cc->lpTemplateName = null;

        $this->inModalDialog = true;
        try {
            $ok = (int) $this->comdlg32->ChooseColorW(\FFI::addr($cc));
        } finally {
            $this->inModalDialog = false;
        }

        if ($ok === 0) {
            return null; // 取消
        }
        return Color::fromColorRef((int) $cc->rgbResult);
    }

    /**
     * 字体对话框（ChooseFontW）。
     *
     * 分配 LOGFONTW，填入默认值（lfHeight=-14, lfFaceName="Segoe UI"）。
     * Flags: CF_SCREENFONTS=0x0001 | CF_INITTOLOGFONTSTRUCT=0x0040 | CF_EFFECTS=0x0100。
     * 返回 ['name' => string, 'size' => int, 'color' => Color] 或 null（取消）。
     * iPointSize 单位为 1/10 磅，size = iPointSize / 10。
     */
    public function dialogChooseFont(int $parentHwnd): ?array
    {
        // LOGFONTW 默认值
        $lf = $this->comdlg32->new('LOGFONTW');
        $lf->lfHeight = -14;
        $lf->lfWidth = 0;
        $lf->lfEscapement = 0;
        $lf->lfOrientation = 0;
        $lf->lfWeight = 400; // FW_NORMAL
        $lf->lfItalic = 0;
        $lf->lfUnderline = 0;
        $lf->lfStrikeOut = 0;
        $lf->lfCharSet = 1; // DEFAULT_CHARSET
        $lf->lfOutPrecision = 0;
        $lf->lfClipPrecision = 0;
        $lf->lfQuality = 0;
        $lf->lfPitchAndFamily = 0;
        // lfFaceName = "Segoe UI"（LF_FACESIZE=32）
        $faceWide = mb_convert_encoding('Segoe UI', 'UTF-16LE', 'UTF-8');
        $faceChars = intdiv(strlen($faceWide), 2);
        for ($i = 0; $i < 32; $i++) {
            $lf->lfFaceName[$i] = $i < $faceChars
                ? (ord($faceWide[$i * 2]) | (ord($faceWide[$i * 2 + 1]) << 8))
                : 0;
        }

        $cf = $this->comdlg32->new('CHOOSEFONTW');
        $cf->lStructSize = \FFI::sizeof($cf);
        if ($parentHwnd !== 0) {
            $cf->hwndOwner = $this->intToPtrIn($this->comdlg32, $parentHwnd);
        }
        $cf->hDC = null;
        $cf->lpLogFont = \FFI::addr($lf);
        $cf->iPointSize = 0;
        $cf->Flags = 0x0001 | 0x0040 | 0x0100; // CF_SCREENFONTS | CF_INITTOLOGFONTSTRUCT | CF_EFFECTS
        $cf->rgbColors = 0; // 黑色
        $cf->lCustData = 0;
        $cf->lpfnHook = null;
        $cf->lpTemplateName = null;
        $cf->hInstance = null;
        $cf->lpszStyle = null;
        $cf->nFontType = 0;
        $cf->__alignment = 0;
        $cf->nSizeMin = 0;
        $cf->nSizeMax = 0;

        $this->inModalDialog = true;
        try {
            $ok = (int) $this->comdlg32->ChooseFontW(\FFI::addr($cf));
        } finally {
            $this->inModalDialog = false;
        }

        if ($ok === 0) {
            return null; // 取消
        }

        // 读取字体名（wchar_t[32] → UTF-8）
        $bytes = '';
        for ($i = 0; $i < 32; $i++) {
            $ch = $lf->lfFaceName[$i];
            if ($ch == 0) {
                break;
            }
            $bytes .= chr($ch & 0xFF) . chr(($ch >> 8) & 0xFF);
        }
        $fontName = mb_convert_encoding($bytes, 'UTF-8', 'UTF-16LE');

        // iPointSize 单位为 1/10 磅
        $fontSize = (int) round((int) $cf->iPointSize / 10);
        $fontColor = Color::fromColorRef((int) $cf->rgbColors);

        return [
            'name' => $fontName,
            'size' => $fontSize,
            'color' => $fontColor,
        ];
    }

    public function areaCreate(int $parentHwnd): int
    {
        $this->registerWindowClass();

        $classBuf = $this->utf8ToWide(self::WINDOW_CLASS_NAME);
        $parent = $parentHwnd !== 0 ? $this->intToHwnd($parentHwnd) : null;

        // Area 复用 PhpUiWindow 类（已注册），作为 WS_CHILD 子窗口。
        // WindowProc 通过 $this->controlTypes 表区分 Area（'Area'）。
        $hwnd = $this->user32->CreateWindowExW(
            0,
            \FFI::addr($classBuf[0]),
            null,
            self::WS_CHILD | self::WS_VISIBLE,
            0, 0, 0, 0,
            $parent,
            null, null, null
        );
        if ($hwnd === null || \FFI::isNull($hwnd)) {
            throw new \RuntimeException('CreateWindowExW failed for Area');
        }

        $this->applyDefaultFont($hwnd);

        $hwndInt = $this->hwndInt($hwnd);
        $this->controlTypes[$hwndInt] = 'Area';
        return $hwndInt;
    }

    public function areaInvalidate(int $hwnd): void
    {
        $this->user32->InvalidateRect($this->intToHwnd($hwnd), null, 1);
    }

    /**
     * 创建绘图上下文（BeginPaint + GDI+ Graphics）。
     *
     * 由 WindowProc WM_PAINT 调用，用户通过 Area onDraw 回调拿到 DrawContext。
     * PAINTSTRUCT CData 保活到 drawContextFree（EndPaint 配对）。
     */
    public function drawContextCreate(int $hwnd): mixed
    {
        $h = $this->intToHwnd($hwnd);
        $ps = $this->user32->new('PAINTSTRUCT');
        $this->user32->BeginPaint($h, \FFI::addr($ps));
        // HDC 跨作用域：user32 void* → int → DrawContext 内部按需转 gdi32/gdiplus
        $hdcInt = $this->ptrToInt($ps->hdc);
        return new DrawContext($this, $ps, $h, $hdcInt);
    }

    /**
     * 释放绘图上下文（恢复 GDI 对象栈 + GDI+ 释放 + EndPaint）。
     */
    public function drawContextFree(mixed $ctx): void
    {
        if ($ctx instanceof DrawContext) {
            $ctx->free();
        }
    }

    // ----------------------------------------------------------------
    // 绘图委托方法（转发到 DrawContext 对象方法）
    // ----------------------------------------------------------------

    public function drawLine(mixed $ctx, int $x1, int $y1, int $x2, int $y2): void
    {
        if ($ctx instanceof DrawContext) {
            $ctx->drawLine($x1, $y1, $x2, $y2);
        }
    }

    public function drawRect(mixed $ctx, int $x, int $y, int $width, int $height): void
    {
        if ($ctx instanceof DrawContext) {
            $ctx->drawRect($x, $y, $width, $height);
        }
    }

    public function drawEllipse(mixed $ctx, int $x, int $y, int $width, int $height): void
    {
        if ($ctx instanceof DrawContext) {
            $ctx->drawEllipse($x, $y, $width, $height);
        }
    }

    public function drawText(mixed $ctx, int $x, int $y, string $text): void
    {
        if ($ctx instanceof DrawContext) {
            $ctx->drawText($x, $y, $text);
        }
    }

    public function drawTextAttributed(mixed $ctx, int $x, int $y, int $attributedStringId): void
    {
        if ($ctx instanceof DrawContext) {
            $ctx->drawTextAttributed($x, $y, $attributedStringId);
        }
    }

    public function setPen(mixed $ctx, Color $color, int $width): void
    {
        if ($ctx instanceof DrawContext) {
            $ctx->setPen($color, $width);
        }
    }

    public function setBrush(mixed $ctx, Color $color): void
    {
        if ($ctx instanceof DrawContext) {
            $ctx->setBrush($color);
        }
    }

    public function setFont(mixed $ctx, string $name, int $size): void
    {
        if ($ctx instanceof DrawContext) {
            $ctx->setFont($name, $size);
        }
    }

    public function setColor(mixed $ctx, Color $color): void
    {
        if ($ctx instanceof DrawContext) {
            $ctx->setColor($color);
        }
    }
}
