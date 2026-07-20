<?php
declare(strict_types=1);

namespace Kingbes\Ui\Platform\Windows;

use Kingbes\Ui\Exception\UiException;
use Kingbes\Ui\Platform\Platform;
use Kingbes\Phpc\Library;
use Kingbes\Phpc\SafeCall;
use Kingbes\Phpc\Struct;
use Kingbes\Phpc\Pointer;
use Kingbes\Phpc\TypeCast;
use FFI\CData;

/**
 * Windows 平台后端。
 *
 * 通过 FFI 调用 user32.dll 与 kernel32.dll 实现跨平台 GUI 原始操作。
 *
 * 实现要点：
 *   - WNDCLASSA 注册时绑定一个 PHP 闭包作为 WindowProc，由 FFI 自动转为
 *     C 函数指针。闭包作为实例属性 $this->wndProc 保存，防止 PHP GC 回收
 *     导致窗口过程崩溃。
 *   - 所有控件回调、窗口回调、定时器回调存入实例属性数组，防止 GC。
 *   - Box 在 Windows 上无原生对应，使用 int[1] CData 作为占位 handle，
 *     在 PHP 端维护布局状态（horizontal / children / padded / parentHwnd），
 *     通过 MoveWindow 手动布局子控件。
 *
 * 句柄类型在签名中标注为 mixed，实际为 \FFI\CData（HWND / HMENU 等）。
 */
class WindowsPlatform extends Platform
{
    /** @var \FFI|null user32.dll FFI 实例 */
    private ?\FFI $user32 = null;

    /** @var \FFI|null kernel32.dll FFI 实例 */
    private ?\FFI $kernel32 = null;

    /** @var \FFI|null comctl32.dll FFI 实例（用于 InitCommonControlsEx） */
    private ?\FFI $comctl32 = null;

    /** @var \Closure WindowProc 闭包（必须作为实例属性保持引用，防 GC） */
    private \Closure $wndProc;

    /** @var array<string, \Closure> 控件回调：hwndInt($h).':type' → Closure */
    private array $closures = [];

    /** @var array<string, \Closure> 窗口回调：hwndInt($h).':close'/:resize → Closure */
    private array $windowCallbacks = [];

    /** @var array<int, array> box 状态：hwndInt($h) → 状态数组 */
    private array $boxes = [];

    /** @var array<int, \Closure> timer id → Closure */
    private array $timers = [];

    /** @var int 定时器自增 ID */
    private static int $nextTimerId = 1;

    /** @var array<int, array> Tab 状态：hwndInt($h) → ['handle' => HWND, 'children' => [...]] */
    private array $tabPages = [];

    /** @var array<int, mixed> Group 子控件：hwndInt($h) → child HWND */
    private array $groupChild = [];

    /** @var array<int, bool> Group 边距状态：hwndInt($h) → bool */
    private array $groupMargined = [];

    /** @var array<int, array> Form 状态：spl_object_id($h) → 状态数组 */
    private array $forms = [];

    /** @var array<int, array> Grid 状态：spl_object_id($h) → 状态数组 */
    private array $grids = [];

    /** @var array<int, array> RadioButtons 状态：spl_object_id($h) → ['handle', 'children', 'parentHwnd'] */
    private array $radioButtons = [];

    /** @var array<int, int> RadioButtons 子控件→父占位 handle：hwndInt(child) → spl_object_id(placeholder) */
    private array $radioChildToParent = [];

    /** @var array<int, array> Spinbox 范围表：hwndInt($h) → ['min' => int, 'max' => int] */
    private array $spinboxRanges = [];

    /**
     * 加载 user32.dll 与 kernel32.dll 并声明 C 头。
     *
     * 注意：FFI cdef 不支持 #define，常量在 PHP 代码中以整数直接使用。
     *
     * @throws \Kingbes\Phpc\Exception\LibraryNotPermittedException 库未在白名单
     */
    private function loadLibraries(): void
    {
        Library::permit('user32.dll');
        Library::permit('kernel32.dll');
        Library::permit('comctl32.dll');

        $this->kernel32 = Library::load('kernel32.dll', <<<C
typedef void* HMODULE;
typedef const char* LPCSTR;
HMODULE GetModuleHandleA(LPCSTR lpModuleName);
C);

        $this->comctl32 = Library::load('comctl32.dll', <<<C
typedef unsigned long DWORD;
typedef struct tagINITCOMMONCONTROLSEX {
    DWORD dwSize;
    DWORD dwICC;
} INITCOMMONCONTROLSEX;
int InitCommonControlsEx(const INITCOMMONCONTROLSEX *picce);
C);

        $this->user32 = Library::load('user32.dll', <<<C
typedef unsigned long DWORD;
typedef unsigned int UINT;
typedef unsigned long long UINT_PTR;
typedef const char* LPCSTR;
typedef void* HWND;
typedef void* HINSTANCE;
typedef void* HMENU;
typedef void* HBRUSH;
typedef void* HICON;
typedef void* HCURSOR;
typedef long LONG;
/* Win64 LLP64: long=4 字节，但 LONG_PTR/LPARAM/WPARAM 必须是指针大小（8 字节）
 * 因此用 long long 而非 long，否则 64 位指针会被截断 */
typedef long long LONG_PTR;
typedef unsigned long long WPARAM;
typedef long long LPARAM;
typedef unsigned short ATOM;
typedef void* HGDIOBJ;
typedef long long LRESULT;
typedef int BOOL;

typedef struct tagWNDCLASSA {
    UINT        style;
    LONG_PTR (*lpfnWndProc)(HWND, UINT, WPARAM, LPARAM);
    int         cbClsExtra;
    int         cbWndExtra;
    HINSTANCE   hInstance;
    HICON       hIcon;
    HCURSOR     hCursor;
    HBRUSH      hbrBackground;
    LPCSTR      lpszMenuName;
    LPCSTR      lpszClassName;
} WNDCLASSA;

typedef struct tagMSG {
    HWND    hwnd;
    UINT    message;
    WPARAM  wParam;
    LPARAM  lParam;
    DWORD   time;
    long    pt_x;
    long    pt_y;
} MSG;

typedef struct tagRECT {
    long left;
    long top;
    long right;
    long bottom;
} RECT;

/* Tab 控件项结构（comctl32 SysTabControl32） */
typedef struct tagTCITEMA {
    UINT    mask;
    DWORD   dwState;
    DWORD   dwStateMask;
    char*   pszText;
    int     cchTextMax;
    int     iImage;
    LPARAM  lParam;
} TCITEMA;

/* WM_NOTIFY 通知消息头结构 */
typedef struct tagNMHDR {
    HWND     hwndFrom;
    UINT_PTR idFrom;
    UINT     code;
} NMHDR;

/* long long → void* 联合体，用于把 LPARAM（PHP int）还原为指针。
 * Win64 上 long 只有 4 字节，无法容纳 8 字节指针，必须用 long long。 */
typedef union _INT_TO_PTR {
    long long i;
    void*     p;
} INT_TO_PTR;

/* DateTimePicker 用的 SYSTEMTIME 结构 */
typedef unsigned short WORD;
typedef struct _SYSTEMTIME {
    WORD wYear;
    WORD wMonth;
    WORD wDayOfWeek;
    WORD wDay;
    WORD wHour;
    WORD wMinute;
    WORD wSecond;
    WORD wMilliseconds;
} SYSTEMTIME;

ATOM    RegisterClassA(const WNDCLASSA *lpWndClass);
HWND    CreateWindowExA(DWORD dwExStyle, LPCSTR lpClassName, LPCSTR lpWindowName,
                        DWORD dwStyle, int X, int Y, int nWidth, int nHeight,
                        HWND hWndParent, HMENU hMenu, HINSTANCE hInstance, void* lpParam);
BOOL    ShowWindow(HWND hWnd, int nCmdShow);
BOOL    UpdateWindow(HWND hWnd);
BOOL    GetMessageA(MSG *lpMsg, HWND hWnd, UINT wMsgFilterMin, UINT wMsgFilterMax);
BOOL    PeekMessageA(MSG *lpMsg, HWND hWnd, UINT wMsgFilterMin, UINT wMsgFilterMax, UINT uRemoveMsg);
BOOL    TranslateMessage(const MSG *lpMsg);
LONG_PTR DispatchMessageA(const MSG *lpmsg);
LONG_PTR DefWindowProcA(HWND hWnd, UINT Msg, WPARAM wParam, LPARAM lParam);
LONG_PTR SendMessageA(HWND hWnd, UINT Msg, WPARAM wParam, LPARAM lParam);
void    PostQuitMessage(int nExitCode);
BOOL    InvalidateRect(HWND hWnd, const RECT* lpRect, BOOL bErase);
BOOL    DestroyWindow(HWND hWnd);
BOOL    EnableWindow(HWND hWnd, BOOL bEnable);
BOOL    SetWindowTextA(HWND hWnd, LPCSTR lpString);
int     GetWindowTextA(HWND hWnd, char* lpString, int nMaxCount);
int     GetWindowTextLengthA(HWND hWnd);
BOOL    GetWindowRect(HWND hWnd, RECT* lpRect);
BOOL    GetClientRect(HWND hWnd, RECT* lpRect);
BOOL    MoveWindow(HWND hWnd, int X, int Y, int nWidth, int nHeight, BOOL bRepaint);
HWND    SetParent(HWND hWndChild, HWND hWndNewParent);
LONG_PTR SetWindowLongPtrA(HWND hWnd, int nIndex, LONG_PTR dwNewLong);
LONG_PTR GetWindowLongPtrA(HWND hWnd, int nIndex);
UINT    SetTimer(HWND hWnd, UINT_PTR nIDEvent, UINT uElapse, void* lpTimerFunc);
BOOL    KillTimer(HWND hWnd, UINT_PTR nIDEvent);
LRESULT CallWindowProcA(LONG_PTR lpPrevWndFunc, HWND hWnd, UINT Msg, WPARAM wParam, LPARAM lParam);
C);
    }

    /* ==============================================================
     * 生命周期
     * ============================================================ */

    /**
     * 初始化后端：加载 FFI 库，注册窗口类，绑定 WindowProc 闭包。
     *
     * @throws UiException RegisterClassA 失败
     */
    public function init(): void
    {
        if ($this->user32 !== null) {
            return; // 已初始化
        }
        $this->loadLibraries();

        // 注册 comctl32 通用控件类（SysTabControl32 等）
        $icc = Struct::make($this->comctl32, 'INITCOMMONCONTROLSEX');
        $icc->set('dwSize', 8);   // sizeof(INITCOMMONCONTROLSEX) = 2 * DWORD = 8
        $icc->set('dwICC', 0x00FF); // ICC_STANDARD 类（含 ICC_TAB_CLASSES=0x0008 等）
        $iccRaw = $icc->raw();
        SafeCall::invoke($this->comctl32, 'InitCommonControlsEx', [\FFI::addr($iccRaw)]);

        // 定义 WindowProc 闭包并保存为实例属性（防 GC，否则窗口过程会崩溃）
        $this->wndProc = function ($hwnd, $msg, $wParam, $lParam): int {
            switch ($msg) {
                case 0x0002: // WM_DESTROY
                    // 顶层窗口销毁时退出消息循环。
                    // 注意：多窗口场景下任一窗口销毁都会退出，当前库定位为简单 GUI，
                    // 不做"最后一个窗口才退出"的判断。
                    $this->user32->PostQuitMessage(0);
                    return 0;
                case 0x0010: // WM_CLOSE
                    $key = $this->hwndInt($hwnd) . ':close';
                    if (isset($this->windowCallbacks[$key])) {
                        $allow = (bool)($this->windowCallbacks[$key])($hwnd);
                        if (!$allow) {
                            return 0;
                        }
                    }
                    return $this->user32->DefWindowProcA($hwnd, $msg, $wParam, $lParam);
                case 0x0005: // WM_SIZE
                    $key = $this->hwndInt($hwnd) . ':resize';
                    if (isset($this->windowCallbacks[$key])) {
                        ($this->windowCallbacks[$key])($hwnd);
                    }
                    $this->layoutBoxesInWindow($hwnd);
                    $this->layoutFormsInWindow($hwnd);
                    $this->layoutGridsInWindow($hwnd);
                    $this->layoutAllTabPages();
                    return 0;
                case 0x004E: // WM_NOTIFY
                    // lParam 指向 NMHDR 结构。LPARAM 在 FFI 中以 long（PHP int）传入，
                    // 用 INT_TO_PTR 联合体把整数值还原为 void*，再 cast 为 NMHDR*。
                    $caster = $this->user32->new('INT_TO_PTR');
                    $caster->i = (int)$lParam;
                    $nmhdr = $this->user32->cast('NMHDR*', $caster->p);
                    $code = (int)$nmhdr->code & 0xFFFFFFFF;
                    // TCN_SELCHANGE = (UINT)-551 = 0xFFFFFDD9，Tab 切换通知
                    if ($code === 0xFFFFFDD9) {
                        $tabHwnd = $nmhdr->hwndFrom;
                        $this->switchTabPage($tabHwnd);
                    }
                    return 0;
                case 0x0111: // WM_COMMAND
                    $hiword = ((int)$wParam >> 16) & 0xFFFF;
                    // lParam 是子控件 HWND，FFI 会自动转为 int（非 null 时表示有控件源）
                    $ckey = $this->hwndInt($lParam);
                    if ($ckey !== 0) {
                        if ($hiword === 0) {  // BN_CLICKED
                            // 优先检查是否为 RadioButtons 子控件
                            if (isset($this->radioChildToParent[$ckey])) {
                                $parentKey = $this->radioChildToParent[$ckey];
                                $closureKey = $parentKey . ':selected';
                                if (isset($this->closures[$closureKey])) {
                                    $placeholder = $this->radioButtons[$parentKey]['handle'] ?? null;
                                    ($this->closures[$closureKey])($placeholder);
                                }
                                return 0;
                            }
                            if (isset($this->closures[$ckey . ':clicked'])) {
                                ($this->closures[$ckey . ':clicked'])($lParam);
                            }
                            if (isset($this->closures[$ckey . ':toggled'])) {
                                ($this->closures[$ckey . ':toggled'])($lParam);
                            }
                        } elseif ($hiword === 0x0300) {  // EN_CHANGE
                            if (isset($this->closures[$ckey . ':changed'])) {
                                ($this->closures[$ckey . ':changed'])($lParam);
                            }
                        } elseif ($hiword === 1) {  // CBN_SELCHANGE
                            if (isset($this->closures[$ckey . ':selected'])) {
                                ($this->closures[$ckey . ':selected'])($lParam);
                            }
                            if (isset($this->closures[$ckey . ':ecombChanged'])) {
                                ($this->closures[$ckey . ':ecombChanged'])($lParam);
                            }
                        } elseif ($hiword === 5) {  // CBN_EDITCHANGE
                            if (isset($this->closures[$ckey . ':ecombChanged'])) {
                                ($this->closures[$ckey . ':ecombChanged'])($lParam);
                            }
                        }
                    }
                    return 0;
                case 0x0114: // WM_HSCROLL
                case 0x0115: // WM_VSCROLL
                    // 滑块（TRACKBAR）通知：lParam 为控件 HWND（标准滚动条时为 0）
                    $skey = $this->hwndInt($lParam);
                    if ($skey !== 0 && isset($this->closures[$skey . ':sliderChanged'])) {
                        ($this->closures[$skey . ':sliderChanged'])($lParam);
                    }
                    return 0;
                case 0x0113: // WM_TIMER
                    $tid = (int)$wParam;
                    if (isset($this->timers[$tid])) {
                        $cb = $this->timers[$tid];
                        $continue = (bool)$cb();
                        if (!$continue) {
                            $this->user32->KillTimer(null, $tid);
                            unset($this->timers[$tid]);
                        }
                    }
                    return 0;
            }
            return $this->user32->DefWindowProcA($hwnd, $msg, $wParam, $lParam);
        };

        // 注册窗口类
        $wc = Struct::make($this->user32, 'WNDCLASSA');
        $wc->set('style', 0x0003);             // CS_HREDRAW | CS_VREDRAW
        $wc->set('lpfnWndProc', $this->wndProc);
        $wc->set('cbClsExtra', 0);
        $wc->set('cbWndExtra', 0);
        $wc->set('hInstance', null);
        $wc->set('hIcon', null);
        $wc->set('hCursor', null);
        $wc->set('hbrBackground', null);
        $wc->set('lpszMenuName', null);

        // 准备类名字符串：分配 char[]，填字节，取首元素地址
        $className = "PhpUiWindow";
        $classNameArr = $this->user32->new('char[' . (strlen($className) + 1) . ']');
        for ($i = 0; $i < strlen($className); $i++) {
            $classNameArr[$i] = $className[$i];
        }
        $classNameArr[strlen($className)] = "\0";
        $wc->set('lpszClassName', \FFI::addr($classNameArr[0]));

        $wcRaw = $wc->raw();
        $atom = SafeCall::invoke($this->user32, 'RegisterClassA', [\FFI::addr($wcRaw)]);
        if ($atom == 0) {
            throw new UiException("RegisterClassA failed");
        }
    }

    /**
     * 释放后端资源。Windows 无显式 uninit 调用，仅清理 PHP 端注册表。
     */
    public function uninit(): void
    {
        // Windows 无显式 uninit 调用
    }

    /**
     * 进入阻塞主消息循环。
     *
     * 持续 GetMessageA / TranslateMessage / DispatchMessageA，
     * 直至收到 WM_QUIT（GetMessageA 返回 <= 0）。
     */
    public function main(): void
    {
        $msg = $this->user32->new('MSG');
        $msgAddr = \FFI::addr($msg);
        while (true) {
            $r = SafeCall::invoke($this->user32, 'GetMessageA', [$msgAddr, null, 0, 0]);
            if ($r <= 0) {
                break;
            }
            SafeCall::invoke($this->user32, 'TranslateMessage', [$msgAddr]);
            SafeCall::invoke($this->user32, 'DispatchMessageA', [$msgAddr]);
        }
    }

    /**
     * 执行一次事件循环迭代（非阻塞）。
     *
     * @param bool $wait 是否阻塞等待（实现中以 usleep 让出 CPU，未真正阻塞）
     * @return bool 是否仍有事件需要处理（收到 WM_QUIT 时返回 false）
     */
    public function mainStep(bool $wait = false): bool
    {
        $msg = $this->user32->new('MSG');
        $msgAddr = \FFI::addr($msg);
        $has = SafeCall::invoke($this->user32, 'PeekMessageA', [$msgAddr, null, 0, 0, 1 /*PM_REMOVE*/]);
        if ($has) {
            if ((int)$msg->message === 0x0012) {  // WM_QUIT
                return false;
            }
            SafeCall::invoke($this->user32, 'TranslateMessage', [$msgAddr]);
            SafeCall::invoke($this->user32, 'DispatchMessageA', [$msgAddr]);
        } else {
            usleep(10000);
        }
        return true;
    }

    /**
     * 退出主循环：投递 WM_QUIT 消息。
     */
    public function quit(): void
    {
        SafeCall::invoke($this->user32, 'PostQuitMessage', [0]);
    }

    /**
     * 注册定时器。
     *
     * Windows SetTimer 通过 WM_TIMER 触发回调，回调返回 false 时自动 KillTimer。
     *
     * @param int      $ms 间隔毫秒数
     * @param \Closure $cb 回调函数，返回 false 停止定时器
     */
    public function timer(int $ms, \Closure $cb): void
    {
        $id = self::$nextTimerId++;
        $this->timers[$id] = $cb;
        SafeCall::invoke($this->user32, 'SetTimer', [null, $id, $ms, null]);
    }

    /* ==============================================================
     * 窗口
     * ============================================================ */

    /**
     * 创建顶层窗口。
     *
     * WS_OVERLAPPEDWINDOW = 0x00CF0000
     * CW_USEDEFAULT = 0x80000000，在 PHP 64 位 int 中为 2147483648（越界 int32），
     * 改用 -2147483648（PHP_INT_MIN）表示。
     */
    public function windowCreate(string $title, int $w, int $h): mixed
    {
        $hwnd = SafeCall::expectNotNull($this->user32, 'CreateWindowExA', [
            0, "PhpUiWindow", $this->toAnsi($title), 0x00CF0000,
            -2147483648, -2147483648, $w, $h,
            null, null, null, null
        ]);
        return $hwnd;
    }

    public function windowSetTitle(mixed $h, string $t): void
    {
        SafeCall::invoke($this->user32, 'SetWindowTextA', [$h, $this->toAnsi($t)]);
    }

    public function windowSetSize(mixed $h, int $w, int $height): void
    {
        // 保持当前左上角位置不变
        $rect = $this->user32->new('RECT');
        SafeCall::invoke($this->user32, 'GetWindowRect', [$h, \FFI::addr($rect)]);
        SafeCall::invoke($this->user32, 'MoveWindow', [$h, $rect->left, $rect->top, $w, $height, 1]);
    }

    public function windowSetPosition(mixed $h, int $x, int $y): void
    {
        // 保持当前尺寸不变
        $rect = $this->user32->new('RECT');
        SafeCall::invoke($this->user32, 'GetWindowRect', [$h, \FFI::addr($rect)]);
        $width = $rect->right - $rect->left;
        $height = $rect->bottom - $rect->top;
        SafeCall::invoke($this->user32, 'MoveWindow', [$h, $x, $y, $width, $height, 1]);
    }

    public function windowGetPosition(mixed $h): array
    {
        $rect = $this->user32->new('RECT');
        SafeCall::invoke($this->user32, 'GetWindowRect', [$h, \FFI::addr($rect)]);
        return ['x' => (int)$rect->left, 'y' => (int)$rect->top];
    }

    /**
     * 设置窗口的子控件。
     *
     * 若 $child 是 Box / Form / Grid（出现在对应 PHP 端注册表中），记录其
     * parentHwnd 为该窗口并立即触发布局；否则（普通控件 HWND，含 Tab / Group）
     * 直接 placeChild 到该窗口。
     */
    public function windowSetChild(mixed $h, mixed $child): void
    {
        $childKey = $this->hwndInt($child);
        if (isset($this->boxes[$childKey])) {
            $this->boxes[$childKey]['parentHwnd'] = $h;
            $this->layoutBox($child);
        } elseif (isset($this->forms[$childKey])) {
            $this->forms[$childKey]['parentHwnd'] = $h;
            $this->layoutForm($child);
        } elseif (isset($this->grids[$childKey])) {
            $this->grids[$childKey]['parentHwnd'] = $h;
            $this->layoutGrid($child);
        } elseif (isset($this->radioButtons[$childKey])) {
            $this->radioButtons[$childKey]['parentHwnd'] = $h;
            $this->layoutRadioButtons($child);
        } else {
            // 普通控件（含 Tab / Group HWND）：让控件填满父窗口 client 区
            $rect = $this->user32->new('RECT');
            SafeCall::invoke($this->user32, 'GetClientRect', [$h, \FFI::addr($rect)]);
            $this->placeChild(
                $child, 0, 0,
                (int)($rect->right - $rect->left),
                (int)($rect->bottom - $rect->top),
                $h
            );
        }
    }

    public function windowShow(mixed $h): void
    {
        SafeCall::invoke($this->user32, 'ShowWindow', [$h, 5 /*SW_SHOW*/]);
    }

    public function windowHide(mixed $h): void
    {
        SafeCall::invoke($this->user32, 'ShowWindow', [$h, 0 /*SW_HIDE*/]);
    }

    public function windowOnClosing(mixed $h, \Closure $cb): void
    {
        $this->windowCallbacks[$this->hwndInt($h) . ':close'] = $cb;
    }

    public function windowOnResize(mixed $h, \Closure $cb): void
    {
        $this->windowCallbacks[$this->hwndInt($h) . ':resize'] = $cb;
    }

    public function windowDestroy(mixed $h): void
    {
        SafeCall::invoke($this->user32, 'DestroyWindow', [$h]);
        $key = $this->hwndInt($h);
        unset($this->windowCallbacks[$key . ':close']);
        unset($this->windowCallbacks[$key . ':resize']);
    }

    /* ==============================================================
     * 通用控件
     * ============================================================ */

    public function controlShow(mixed $h): void
    {
        SafeCall::invoke($this->user32, 'ShowWindow', [$h, 5 /*SW_SHOW*/]);
    }

    public function controlHide(mixed $h): void
    {
        SafeCall::invoke($this->user32, 'ShowWindow', [$h, 0 /*SW_HIDE*/]);
    }

    public function controlEnable(mixed $h): void
    {
        SafeCall::invoke($this->user32, 'EnableWindow', [$h, 1]);
    }

    public function controlDisable(mixed $h): void
    {
        SafeCall::invoke($this->user32, 'EnableWindow', [$h, 0]);
    }

    public function controlDestroy(mixed $h): void
    {
        $objKey = spl_object_id($h);
        $intKey = $this->hwndInt($h);

        // Form / Grid / RadioButtons 是 PHP 端造的占位 handle（int[1]），无 HWND 可销毁；
        // 仅从注册表移除并清理闭包。
        $isForm = isset($this->forms[$objKey]);
        $isGrid = isset($this->grids[$objKey]);
        $isRadio = isset($this->radioButtons[$objKey]);

        // RadioButtons：清理子控件的 radioChildToParent 映射
        if ($isRadio) {
            foreach ($this->radioButtons[$objKey]['children'] ?? [] as $child) {
                $childIntKey = $this->hwndInt($child);
                unset($this->radioChildToParent[$childIntKey]);
            }
        }

        // 清理布局容器注册表
        unset($this->tabPages[$intKey]);
        unset($this->groupChild[$intKey]);
        unset($this->groupMargined[$intKey]);
        unset($this->forms[$objKey]);
        unset($this->grids[$objKey]);
        unset($this->radioButtons[$objKey]);

        if (!$isForm && !$isGrid && !$isRadio) {
            SafeCall::invoke($this->user32, 'DestroyWindow', [$h]);
        }
        $this->cleanupClosures($h);
    }

    /**
     * 清理注册表中以 hwndInt($h).':' 为前缀的所有闭包条目。
     * 在 controlDestroy 时调用，避免控件已销毁但闭包仍滞留注册表。
     */
    protected function cleanupClosures(mixed $h): void
    {
        $prefix = $this->hwndInt($h) . ':';
        foreach (array_keys($this->closures) as $k) {
            if (str_starts_with($k, $prefix)) {
                unset($this->closures[$k]);
            }
        }
    }

    /**
     * 把 HWND（FFI\CData 指针）转为整数值，用作注册表 key。
     *
     * 必要性：WindowProc 收到的 lParam 在 WM_COMMAND 中是子控件 HWND，
     * FFI 会自动把 HWND 指针解引用为整数值（PHP int），无法用 spl_object_id
     * 反查回原始 CData 对象。因此注册表 key 统一用 HWND 的整数值，
     * 这样 WM_COMMAND 中的 (int)$lParam 可以直接匹配回 $closures 表。
     *
     * Box handle 是 PHP 端造的 int[1] 数组 CData（非指针），不走此路径，
     * 在 boxes 表中用 spl_object_id 即可。
     *
     * @param mixed $h 控件句柄（CData 指针 或 int）
     */
    private function hwndInt(mixed $h): int
    {
        if ($h instanceof CData) {
            // 仅对指针类型 cast 为整数；数组类型（如 Box 的 int[1]）回退到 spl_object_id
            $kind = \FFI::typeof($h)->getKind();
            // FFI\Type::KIND_POINTER = 9
            if ($kind !== 9 /* POINTER */) {
                return spl_object_id($h);
            }
            return (int) TypeCast::cast($h, 'intptr_t')->cdata;
        }
        return (int) $h;
    }

    /* ==============================================================
     * Button
     * ============================================================ */

    /**
     * 创建按钮。
     *
     * 创建时用 WS_POPUP(0x80000000) 而非 WS_CHILD|WS_VISIBLE：因为子控件在
     * 创建时还未确定父窗口，传 hWndParent=NULL 与 WS_CHILD 会导致 CreateWindowExA
     * 返回 NULL。WS_POPUP 可在没有父窗口的情况下创建不可见窗口，待 placeChild
     * 时再 SetParent 并将样式改回 WS_CHILD|WS_VISIBLE。
     */
    public function buttonCreate(string $text): mixed
    {
        return SafeCall::expectNotNull($this->user32, 'CreateWindowExA', [
            0, "BUTTON", $this->toAnsi($text), 0x80000000,
            0, 0, 100, 30,
            null, null, null, null
        ]);
    }

    public function buttonGetText(mixed $h): string
    {
        return $this->getWindowWindowText($h);
    }

    public function buttonSetText(mixed $h, string $t): void
    {
        SafeCall::invoke($this->user32, 'SetWindowTextA', [$h, $this->toAnsi($t)]);
    }

    public function buttonOnClicked(mixed $h, \Closure $cb): void
    {
        $this->closures[$this->hwndInt($h) . ':clicked'] = $cb;
    }

    /* ==============================================================
     * Label
     * ============================================================ */

    public function labelCreate(string $text): mixed
    {
        // WS_POPUP（见 buttonCreate 注释）；SS_ETCHED 等额外样式在 placeChild 时由
        // 样式合并保留原额外位
        return SafeCall::expectNotNull($this->user32, 'CreateWindowExA', [
            0, "STATIC", $this->toAnsi($text), 0x80000000,
            0, 0, 100, 25,
            null, null, null, null
        ]);
    }

    public function labelGetText(mixed $h): string
    {
        return $this->getWindowWindowText($h);
    }

    public function labelSetText(mixed $h, string $t): void
    {
        SafeCall::invoke($this->user32, 'SetWindowTextA', [$h, $this->toAnsi($t)]);
    }

    /* ==============================================================
     * Entry
     * ============================================================ */

    /**
     * 创建单行输入框。
     *
     * WS_POPUP | ES_AUTOHSCROLL(0x0001)；WS_CHILD|WS_VISIBLE 在 placeChild 时加。
     */
    public function entryCreate(): mixed
    {
        return SafeCall::expectNotNull($this->user32, 'CreateWindowExA', [
            0, "EDIT", "", 0x80000000 | 0x0001,
            0, 0, 100, 25,
            null, null, null, null
        ]);
    }

    public function entryGetText(mixed $h): string
    {
        return $this->getWindowWindowText($h);
    }

    public function entrySetText(mixed $h, string $t): void
    {
        SafeCall::invoke($this->user32, 'SetWindowTextA', [$h, $this->toAnsi($t)]);
    }

    public function entryOnChanged(mixed $h, \Closure $cb): void
    {
        $this->closures[$this->hwndInt($h) . ':changed'] = $cb;
    }

    /**
     * 设置输入框只读状态。
     *
     * EM_SETREADONLY = 0x00CF，wParam=1 启用只读，0 取消。
     */
    public function entrySetReadOnly(mixed $h, bool $ro): void
    {
        SafeCall::invoke($this->user32, 'SendMessageA', [$h, 0x00CF, $ro ? 1 : 0, 0]);
    }

    /* ==============================================================
     * Checkbox
     * ============================================================ */

    /**
     * 创建复选框。
     *
     * WS_POPUP | BS_AUTOCHECKBOX(0x0003)；WS_CHILD|WS_VISIBLE 在 placeChild 时加。
     */
    public function checkboxCreate(string $text): mixed
    {
        return SafeCall::expectNotNull($this->user32, 'CreateWindowExA', [
            0, "BUTTON", $this->toAnsi($text), 0x80000000 | 0x0003,
            0, 0, 100, 25,
            null, null, null, null
        ]);
    }

    public function checkboxGetText(mixed $h): string
    {
        return $this->getWindowWindowText($h);
    }

    public function checkboxSetText(mixed $h, string $t): void
    {
        SafeCall::invoke($this->user32, 'SetWindowTextA', [$h, $this->toAnsi($t)]);
    }

    /**
     * 查询复选框是否选中。
     *
     * BM_GETCHECK = 0x00F0，返回 1（BST_CHECKED）表示选中。
     */
    public function checkboxIsChecked(mixed $h): bool
    {
        $r = SafeCall::invoke($this->user32, 'SendMessageA', [$h, 0x00F0, 0, 0]);
        return ((int)$r) === 1;
    }

    /**
     * 设置复选框选中状态。
     *
     * BM_SETCHECK = 0x00F1，wParam=1 选中，0 取消。
     */
    public function checkboxSetChecked(mixed $h, bool $c): void
    {
        SafeCall::invoke($this->user32, 'SendMessageA', [$h, 0x00F1, $c ? 1 : 0, 0]);
    }

    public function checkboxOnToggled(mixed $h, \Closure $cb): void
    {
        $this->closures[$this->hwndInt($h) . ':toggled'] = $cb;
    }

    /* ==============================================================
     * Box
     * ============================================================ */

    /**
     * 创建容器盒子。
     *
     * Windows 无原生 Box 容器。返回一个 int[1] CData 作为占位 handle，
     * 在 PHP 端维护布局状态（horizontal / children / padded / parentHwnd）。
     */
    public function boxCreate(bool $horizontal): mixed
    {
        $handle = $this->user32->new('int[1]');
        $key = spl_object_id($handle);
        $this->boxes[$key] = [
            'handle' => $handle,  // 自引用，便于 layoutBoxesInWindow 反查
            'horizontal' => $horizontal,
            'children' => [],
            'padded' => false,
            'parentHwnd' => null,
        ];
        return $handle;
    }

    public function boxAppend(mixed $h, mixed $child, bool $stretchy): void
    {
        $key = spl_object_id($h);
        if (!isset($this->boxes[$key])) {
            throw new UiException("Invalid box handle");
        }
        $this->boxes[$key]['children'][] = ['handle' => $child, 'stretchy' => $stretchy];
        $this->layoutBox($h);
    }

    /**
     * 按索引从盒子移除子控件。
     *
     * 仅从 PHP 端 children 数组移除，不调用 DestroyWindow（由用户自行销毁）。
     */
    public function boxRemove(mixed $h, int $index): void
    {
        $key = spl_object_id($h);
        if (!isset($this->boxes[$key]['children'][$index])) {
            throw new UiException("Invalid box child index: $index");
        }
        array_splice($this->boxes[$key]['children'], $index, 1);
        $this->layoutBox($h);
    }

    public function boxSetPadded(mixed $h, bool $p): void
    {
        $key = spl_object_id($h);
        if (!isset($this->boxes[$key])) {
            throw new UiException("Invalid box handle");
        }
        $this->boxes[$key]['padded'] = $p;
        $this->layoutBox($h);
    }

    /**
     * 计算并应用 Box 内子控件的布局。
     *
     * 水平 Box：子控件横向排列，stretchy 子控件均分剩余宽度。
     * 垂直 Box：子控件纵向排列，stretchy 子控件均分剩余高度。
     * 嵌套 Box 通过 isHwnd 判断后递归 layoutBox（其位置由父 Box 决定，
     * 但 Windows 无原生容器，嵌套 Box 内部子控件直接 SetParent 到顶层窗口）。
     */
    private function layoutBox(mixed $h): void
    {
        $key = spl_object_id($h);
        $box = $this->boxes[$key] ?? null;
        if (!$box || empty($box['children']) || $box['parentHwnd'] === null) {
            return;
        }

        $parent = $box['parentHwnd'];
        $rect = $this->user32->new('RECT');
        SafeCall::invoke($this->user32, 'GetClientRect', [$parent, \FFI::addr($rect)]);
        $w = $rect->right - $rect->left;
        $hgt = $rect->bottom - $rect->top;

        $padded = $box['padded'] ? 4 : 0;
        $n = count($box['children']);
        $stretchyCount = count(array_filter($box['children'], fn($c) => $c['stretchy']));
        $nonStretchy = $n - $stretchyCount;

        if ($box['horizontal']) {
            $stretchyW = $stretchyCount > 0
                ? ($w - ($n - 1) * $padded - $nonStretchy * 80) / $stretchyCount
                : 0;
            $x = 0;
            foreach ($box['children'] as $c) {
                $cw = $c['stretchy'] ? (int)$stretchyW : 80;
                $this->placeChild($c['handle'], $x, 0, $cw, $hgt, $parent);
                $x += $cw + $padded;
            }
        } else {
            $stretchyH = $stretchyCount > 0
                ? ($hgt - ($n - 1) * $padded - $nonStretchy * 25) / $stretchyCount
                : 0;
            $y = 0;
            foreach ($box['children'] as $c) {
                $ch = $c['stretchy'] ? (int)$stretchyH : 25;
                $this->placeChild($c['handle'], 0, $y, $w, $ch, $parent);
                $y += $ch + $padded;
            }
        }
    }

    /**
     * 放置单个子控件。
     *
     * 若 child 是 Box / Form / Grid（出现在对应 PHP 端注册表中），将其 parentHwnd
     * 设为外层窗口并递归调对应布局函数；否则（HWND 控件，含 Tab / Group）调用
     * SetParent 确保父子关系正确（WS_CHILD 控件必须 parent 才能显示），再用
     * MoveWindow 定位。定位后若是 Tab / Group 容器，额外触发其内部子控件重排。
     */
    private function placeChild(mixed $child, int $x, int $y, int $w, int $h, mixed $parent): void
    {
        $childKey = spl_object_id($child);
        if (isset($this->boxes[$childKey])) {
            // 嵌套 Box：递归布局其内部
            $this->boxes[$childKey]['parentHwnd'] = $parent;
            $this->layoutBox($child);
            return;
        }
        if (isset($this->forms[$childKey])) {
            // 嵌套 Form：递归布局其内部
            $this->forms[$childKey]['parentHwnd'] = $parent;
            $this->layoutForm($child);
            return;
        }
        if (isset($this->grids[$childKey])) {
            // 嵌套 Grid：递归布局其内部
            $this->grids[$childKey]['parentHwnd'] = $parent;
            $this->layoutGrid($child);
            return;
        }
        if (isset($this->radioButtons[$childKey])) {
            // 嵌套 RadioButtons：递归布局其内部
            $this->radioButtons[$childKey]['parentHwnd'] = $parent;
            $this->layoutRadioButtons($child);
            return;
        }
        if ($child instanceof CData) {
            SafeCall::invoke($this->user32, 'SetParent', [$child, $parent]);
            // 控件创建时用 WS_POPUP，这里改回 WS_CHILD|WS_VISIBLE，并保留原额外样式
            // （BS_AUTOCHECKBOX / ES_AUTOHSCROLL / SS_ETCHEDHORZ / WS_CLIPSIBLINGS 等）
            $oldStyle = (int) SafeCall::invoke(
                $this->user32, 'GetWindowLongPtrA', [$child, -16 /*GWL_STYLE*/]
            );
            $newStyle = ($oldStyle & ~0x80000000 /*~WS_POPUP*/) | 0x50000000 /*WS_CHILD|WS_VISIBLE*/;
            SafeCall::invoke(
                $this->user32, 'SetWindowLongPtrA', [$child, -16, $newStyle]
            );
            SafeCall::invoke($this->user32, 'MoveWindow', [$child, $x, $y, $w, $h, 1]);

            // Tab 容器：定位后重排当前页子控件到 Tab 显示区
            $intKey = $this->hwndInt($child);
            if (isset($this->tabPages[$intKey])) {
                $this->layoutTabPage($child);
            }
            // Group 容器：定位后重排子控件到 Group 内部
            if (isset($this->groupChild[$intKey])) {
                $this->layoutGroupChild($child);
            }
        }
    }

    /**
     * 遍历所有 Box，找出 parentHwnd 为 $hwnd 的 Box 并重新布局。
     *
     * 在 WM_SIZE 时调用，确保窗口尺寸变化后子 Box 内控件自动重新排列。
     */
    private function layoutBoxesInWindow(mixed $hwnd): void
    {
        foreach ($this->boxes as $box) {
            if ($box['parentHwnd'] === $hwnd) {
                $this->layoutBox($box['handle']);
            }
        }
    }

    /* ==============================================================
     * Separator
     * ============================================================ */

    /**
     * 创建分隔线。
     *
     * WS_POPUP + 水平 SS_ETCHEDHORZ(0x0010) / 垂直 SS_ETCHEDVERT(0x0011)；
     * WS_CHILD|WS_VISIBLE 在 placeChild 时加。
     */
    public function separatorCreate(bool $horizontal): mixed
    {
        $style = 0x80000000;
        $style |= $horizontal ? 0x0010 : 0x0011;
        return SafeCall::expectNotNull($this->user32, 'CreateWindowExA', [
            0, "STATIC", "", $style,
            0, 0, 100, 2,
            null, null, null, null
        ]);
    }

    /* ==============================================================
     * 辅助方法
     * ============================================================ */

    /**
     * 通用：读取控件文本（GetWindowTextLengthA + GetWindowTextA）。
     *
     * 用于 Button / Label / Entry / Checkbox 的 GetText 实现。
     */
    private function getWindowWindowText(mixed $h): string
    {
        $len = SafeCall::invoke($this->user32, 'GetWindowTextLengthA', [$h]);
        $len = (int)$len;
        if ($len <= 0) {
            return '';
        }
        $buf = $this->user32->new('char[' . ($len + 1) . ']');
        SafeCall::invoke($this->user32, 'GetWindowTextA', [$h, $buf, $len + 1]);
        // A 系列 API 返回 ANSI 编码字节，转回 UTF-8
        return $this->fromAnsi(TypeCast::fromString($buf));
    }

    /**
     * UTF-8 → ANSI(GBK) 编码转换。
     *
     * Windows A 系列 API（CreateWindowExA / SetWindowTextA / GetWindowTextA）
     * 按系统 ANSI 代码页解释字节（中文 Windows 默认 CP936/GBK）。
     * PHP 源字符串是 UTF-8，需转换后才能正确显示中文。
     *
     * 优先用 mb_convert_encoding（需 mbstring 扩展），失败回退到 iconv，
     * 再失败回退到原字符串（非中文 Windows 上可能不需要转换）。
     */
    private function toAnsi(string $utf8): string
    {
        if (function_exists('mb_convert_encoding')) {
            $conv = @mb_convert_encoding($utf8, 'GBK', 'UTF-8');
            if ($conv !== false && $conv !== '') {
                return $conv;
            }
        }
        if (function_exists('iconv')) {
            $conv = @iconv('UTF-8', 'GBK//IGNORE', $utf8);
            if ($conv !== false) {
                return $conv;
            }
        }
        return $utf8;
    }

    /**
     * ANSI(GBK) → UTF-8 编码转换（toAnsi 的逆操作）。
     */
    private function fromAnsi(string $ansi): string
    {
        if (function_exists('mb_convert_encoding')) {
            $conv = @mb_convert_encoding($ansi, 'UTF-8', 'GBK');
            if ($conv !== false && $conv !== '') {
                return $conv;
            }
        }
        if (function_exists('iconv')) {
            $conv = @iconv('GBK', 'UTF-8//IGNORE', $ansi);
            if ($conv !== false) {
                return $conv;
            }
        }
        return $ansi;
    }

    /* ==============================================================
     * Tab（comctl32 SysTabControl32）
     * ============================================================
     *
     * Windows 原生 Tab 控件本身不管理子控件显示，需要在 PHP 端维护
     * $tabPages[hwndInt] = ['handle' => HWND, 'children' => [...]]，
     * 并在 TCN_SELCHANGE 通知（WM_NOTIFY）时切换子控件可见性。
     *
     * TCM_* 消息常量（来自 Windows SDK commctrl.h，TCM_FIRST = 0x1300）：
     *   TCM_GETITEMCOUNT = 0x1304, TCM_INSERTITEMA = 0x1307,
     *   TCM_DELETEITEM = 0x1308, TCM_DELETEALLITEMS = 0x1309,
     *   TCM_GETITEMRECT = 0x130A, TCM_GETCURSEL = 0x130B,
     *   TCM_SETCURSEL = 0x130C
     * TCIF_TEXT = 0x1, TCN_SELCHANGE = (UINT)-551 = 0xFFFFFDD9
     */

    /**
     * 创建多页签容器。
     *
     * 创建时用 WS_POPUP|WS_CLIPSIBLINGS（与现有控件一致），
     * placeChild 时改为 WS_CHILD|WS_VISIBLE|WS_CLIPSIBLINGS(0x54000000)。
     */
    public function tabCreate(): mixed
    {
        return SafeCall::expectNotNull($this->user32, 'CreateWindowExA', [
            0, "SysTabControl32", "", 0x80000000 | 0x04000000,
            0, 0, 100, 100,
            null, null, null, null
        ]);
    }

    public function tabAppend(mixed $h, string $name, mixed $child): void
    {
        $key = $this->hwndInt($h);
        if (!isset($this->tabPages[$key])) {
            $this->tabPages[$key] = ['handle' => $h, 'children' => []];
        }
        $index = count($this->tabPages[$key]['children']);
        $this->insertTabItem($h, $name, $index);
        $this->reparentTo($child, $h);
        $this->tabPages[$key]['children'][] = $child;
        $this->switchTabPage($h);
    }

    public function tabInsertAt(mixed $h, string $name, int $index, mixed $child): void
    {
        $key = $this->hwndInt($h);
        if (!isset($this->tabPages[$key])) {
            $this->tabPages[$key] = ['handle' => $h, 'children' => []];
        }
        $this->insertTabItem($h, $name, $index);
        $this->reparentTo($child, $h);
        // 在指定位置插入子控件
        $children = $this->tabPages[$key]['children'];
        array_splice($children, $index, 0, [$child]);
        $this->tabPages[$key]['children'] = $children;
        $this->switchTabPage($h);
    }

    public function tabDelete(mixed $h, int $index): void
    {
        $key = $this->hwndInt($h);
        SafeCall::invoke($this->user32, 'SendMessageA', [
            $h, 0x1308 /*TCM_DELETEITEM*/, $index, 0
        ]);
        if (isset($this->tabPages[$key]['children'][$index])) {
            $children = $this->tabPages[$key]['children'];
            array_splice($children, $index, 1);
            $this->tabPages[$key]['children'] = $children;
        }
        $this->switchTabPage($h);
    }

    public function tabNumPages(mixed $h): int
    {
        return (int) SafeCall::invoke($this->user32, 'SendMessageA', [
            $h, 0x1304 /*TCM_GETITEMCOUNT*/, 0, 0
        ]);
    }

    public function tabGetSelected(mixed $h): int
    {
        return (int) SafeCall::invoke($this->user32, 'SendMessageA', [
            $h, 0x130B /*TCM_GETCURSEL*/, 0, 0
        ]);
    }

    public function tabSetSelected(mixed $h, int $index): void
    {
        SafeCall::invoke($this->user32, 'SendMessageA', [
            $h, 0x130C /*TCM_SETCURSEL*/, $index, 0
        ]);
        $this->switchTabPage($h);
    }

    /**
     * Windows 原生 Tab 无 margined 概念，库内统一不支持。
     */
    public function tabGetMargined(mixed $h, int $index): bool
    {
        return false;
    }

    public function tabSetMargined(mixed $h, int $index, bool $m): void
    {
        // no-op：Windows 原生 Tab 无 margined 概念
    }

    /**
     * 向 Tab 控件插入一个页签项（TCITEMA + TCM_INSERTITEMA）。
     */
    private function insertTabItem(mixed $h, string $name, int $index): void
    {
        $ansiName = $this->toAnsi($name);
        $len = strlen($ansiName);
        $buf = $this->user32->new('char[' . ($len + 1) . ']');
        for ($i = 0; $i < $len; $i++) {
            $buf[$i] = $ansiName[$i];
        }
        $buf[$len] = "\0";

        $ti = Struct::make($this->user32, 'TCITEMA');
        $ti->set('mask', 0x1); // TCIF_TEXT
        $ti->set('pszText', \FFI::addr($buf[0]));
        $ti->set('cchTextMax', $len + 1);
        $tiRaw = $ti->raw();
        // LPARAM 在 Win64 是 8 字节，可容纳指针。
        // 但 PHP FFI 不会自动把 CData 指针转为 long long 标量，
        // 必须先 cast 指针为 LPARAM（long long）取其整数值再传递。
        $lParam = (int) $this->user32->cast('LPARAM', \FFI::addr($tiRaw))->cdata;
        SafeCall::invoke($this->user32, 'SendMessageA', [
            $h, 0x1307 /*TCM_INSERTITEMA*/, $index, $lParam
        ]);
    }

    /**
     * 切换 Tab 当前页的子控件可见性，并重排当前页子控件到显示区。
     * 在 tabAppend / tabInsertAt / tabDelete / tabSetSelected / TCN_SELCHANGE 时调用。
     */
    private function switchTabPage(mixed $h): void
    {
        $key = $this->hwndInt($h);
        if (!isset($this->tabPages[$key])) {
            return;
        }
        $current = (int) SafeCall::invoke($this->user32, 'SendMessageA', [
            $h, 0x130B /*TCM_GETCURSEL*/, 0, 0
        ]);
        foreach ($this->tabPages[$key]['children'] as $i => $child) {
            $cmd = ($i === $current) ? 5 /*SW_SHOW*/ : 0 /*SW_HIDE*/;
            if ($this->isContainerHandle($child)) {
                // 占位 handle：递归切换其内部子控件可见性
                $this->setContainerChildrenVisible($child, $cmd);
            } else {
                SafeCall::invoke($this->user32, 'ShowWindow', [$child, $cmd]);
            }
        }
        $this->layoutTabPage($h);
    }

    /**
     * 把当前选中页的子控件 MoveWindow 到 Tab 显示区。
     * 显示区 = Tab 客户区减去顶部 30px 的 tab 头高度。
     * 在 placeChild（Tab 被定位后）和 WM_SIZE 时调用。
     */
    private function layoutTabPage(mixed $h): void
    {
        $key = $this->hwndInt($h);
        if (!isset($this->tabPages[$key])) {
            return;
        }
        $current = (int) SafeCall::invoke($this->user32, 'SendMessageA', [
            $h, 0x130B /*TCM_GETCURSEL*/, 0, 0
        ]);
        if ($current < 0 || !isset($this->tabPages[$key]['children'][$current])) {
            return;
        }
        $child = $this->tabPages[$key]['children'][$current];
        $rect = $this->user32->new('RECT');
        SafeCall::invoke($this->user32, 'GetClientRect', [$h, \FFI::addr($rect)]);
        // 显示区：左右各留 2px，顶部留 30px（tab 头），底部留 2px
        $x = 2;
        $y = 30;
        $w = (int)($rect->right - $rect->left) - 4;
        $hgt = (int)($rect->bottom - $rect->top) - 32;
        if ($w < 0) $w = 0;
        if ($hgt < 0) $hgt = 0;
        // 用 placeChild 统一处理占位 handle 与 HWND 控件
        $this->placeChild($child, $x, $y, $w, $hgt, $h);
    }

    /**
     * 遍历所有 Tab 控件，重排当前页子控件（WM_SIZE 时调用）。
     */
    private function layoutAllTabPages(): void
    {
        foreach ($this->tabPages as $page) {
            $this->layoutTabPage($page['handle']);
        }
    }

    /* ==============================================================
     * Group（BS_GROUPBOX 系统类）
     * ============================================================ */

    /**
     * 创建带标题的容器组。
     *
     * BS_GROUPBOX = 0x7，创建时用 WS_POPUP（与现有控件一致）。
     */
    public function groupCreate(string $title): mixed
    {
        return SafeCall::expectNotNull($this->user32, 'CreateWindowExA', [
            0, "BUTTON", $this->toAnsi($title), 0x80000000 | 0x7,
            0, 0, 100, 100,
            null, null, null, null
        ]);
    }

    public function groupGetTitle(mixed $h): string
    {
        return $this->getWindowWindowText($h);
    }

    public function groupSetTitle(mixed $h, string $t): void
    {
        SafeCall::invoke($this->user32, 'SetWindowTextA', [$h, $this->toAnsi($t)]);
    }

    /**
     * 设置容器组的子控件（替换已有子控件）。
     *
     * SetParent(child, group) 后 MoveWindow 让 child 填充 Group 内部
     * （减去顶部 ~20px 标题高度）。WM_SIZE 时 placeChild 会重排。
     */
    public function groupSetChild(mixed $h, mixed $child): void
    {
        $key = $this->hwndInt($h);
        $this->groupChild[$key] = $child;
        $this->reparentTo($child, $h);
        $this->layoutGroupChild($h);
    }

    public function groupGetMargined(mixed $h): bool
    {
        return $this->groupMargined[$this->hwndInt($h)] ?? false;
    }

    public function groupSetMargined(mixed $h, bool $m): void
    {
        $this->groupMargined[$this->hwndInt($h)] = $m;
        $this->layoutGroupChild($h);
    }

    /**
     * 把 Group 的子控件 MoveWindow 到 Group 内部。
     * 内部 = Group 客户区减去顶部 20px（标题高度），margined 启用时四周留 8px。
     */
    private function layoutGroupChild(mixed $h): void
    {
        $key = $this->hwndInt($h);
        if (!isset($this->groupChild[$key])) {
            return;
        }
        $child = $this->groupChild[$key];
        $rect = $this->user32->new('RECT');
        SafeCall::invoke($this->user32, 'GetClientRect', [$h, \FFI::addr($rect)]);
        $margined = $this->groupMargined[$key] ?? false;
        $pad = $margined ? 8 : 2;
        $x = $pad;
        $y = 20;
        $w = (int)($rect->right - $rect->left) - 2 * $pad;
        $hgt = (int)($rect->bottom - $rect->top) - 20 - $pad;
        if ($w < 0) $w = 0;
        if ($hgt < 0) $hgt = 0;
        // 用 placeChild 统一处理占位 handle 与 HWND 控件
        $this->placeChild($child, $x, $y, $w, $hgt, $h);
    }

    /* ==============================================================
     * Form（PHP 端自实现，类似 Box）
     * ============================================================ *
     *
     * Form 是垂直布局：每行一个 label + 一个控件（label 在左侧 80px，
     * 控件在右侧填充）。状态用 $this->forms[spl_object_id($h)] 跟踪。
     */

    public function formCreate(): mixed
    {
        $handle = $this->user32->new('int[1]');
        $key = spl_object_id($handle);
        $this->forms[$key] = [
            'handle' => $handle,
            'children' => [],
            'padded' => false,
            'parentHwnd' => null,
        ];
        return $handle;
    }

    public function formAppend(mixed $h, string $label, mixed $child, bool $stretchy): void
    {
        $key = spl_object_id($h);
        if (!isset($this->forms[$key])) {
            throw new UiException("Invalid form handle");
        }
        // Form 内部自动创建 Label 控件作为行标签
        $labelHandle = $this->labelCreate($label);
        $this->forms[$key]['children'][] = [
            'labelHandle' => $labelHandle,
            'handle' => $child,
            'stretchy' => $stretchy,
        ];
        $this->layoutForm($h);
    }

    public function formDelete(mixed $h, int $index): void
    {
        $key = spl_object_id($h);
        if (!isset($this->forms[$key]['children'][$index])) {
            throw new UiException("Invalid form child index: $index");
        }
        // 销毁内部 Label 控件
        $labelHandle = $this->forms[$key]['children'][$index]['labelHandle'];
        SafeCall::invoke($this->user32, 'DestroyWindow', [$labelHandle]);
        $children = $this->forms[$key]['children'];
        array_splice($children, $index, 1);
        $this->forms[$key]['children'] = $children;
        $this->layoutForm($h);
    }

    public function formNumChildren(mixed $h): int
    {
        $key = spl_object_id($h);
        return isset($this->forms[$key]) ? count($this->forms[$key]['children']) : 0;
    }

    public function formGetPadded(mixed $h): bool
    {
        $key = spl_object_id($h);
        return $this->forms[$key]['padded'] ?? false;
    }

    public function formSetPadded(mixed $h, bool $p): void
    {
        $key = spl_object_id($h);
        if (!isset($this->forms[$key])) {
            throw new UiException("Invalid form handle");
        }
        $this->forms[$key]['padded'] = $p;
        $this->layoutForm($h);
    }

    /**
     * 计算并应用 Form 内子控件的布局。
     *
     * 每行：label 占左侧 80px，child 占剩余宽度。
     * stretchy 行均分剩余高度，非 stretchy 行 25px。
     * padded 启用时行间留 4px。
     */
    private function layoutForm(mixed $h): void
    {
        $key = spl_object_id($h);
        $form = $this->forms[$key] ?? null;
        if (!$form || empty($form['children']) || $form['parentHwnd'] === null) {
            return;
        }
        $parent = $form['parentHwnd'];
        $rect = $this->user32->new('RECT');
        SafeCall::invoke($this->user32, 'GetClientRect', [$parent, \FFI::addr($rect)]);
        $w = (int)($rect->right - $rect->left);
        $hgt = (int)($rect->bottom - $rect->top);

        $padded = $form['padded'] ? 4 : 0;
        $labelW = 80;
        $n = count($form['children']);
        $stretchyCount = count(array_filter(
            $form['children'], fn($c) => $c['stretchy']
        ));
        $nonStretchy = $n - $stretchyCount;
        $stretchyH = $stretchyCount > 0
            ? ($hgt - ($n - 1) * $padded - $nonStretchy * 25) / $stretchyCount
            : 0;

        $y = 0;
        foreach ($form['children'] as $c) {
            $ch = $c['stretchy'] ? (int)$stretchyH : 25;
            // label 占左侧 80px
            $this->placeChild($c['labelHandle'], 0, $y, $labelW, $ch, $parent);
            // child 占剩余宽度
            $childX = $labelW + $padded;
            $childW = $w - $labelW - $padded;
            if ($childW < 0) $childW = 0;
            $this->placeChild($c['handle'], $childX, $y, $childW, $ch, $parent);
            $y += $ch + $padded;
        }
    }

    /**
     * 遍历所有 Form，找出 parentHwnd 为 $hwnd 的 Form 并重新布局。
     */
    private function layoutFormsInWindow(mixed $hwnd): void
    {
        $hwndInt = $this->hwndInt($hwnd);
        foreach ($this->forms as $form) {
            if ($form['parentHwnd'] !== null
                && $this->hwndInt($form['parentHwnd']) === $hwndInt
            ) {
                $this->layoutForm($form['handle']);
            }
        }
    }

    /* ==============================================================
     * Grid（PHP 端自实现，类似 Form）
     * ============================================================ *
     *
     * Grid 是真正的二维网格：每个子控件占 left/top 起的 xspan×yspan 单元格。
     * 状态用 $this->grids[spl_object_id($h)] 跟踪。
     *
     * uiAlign: Fill=0, Start=1, Center=2, End=3
     * uiAt:    Leading=0, Top=1, Trailing=2, Bottom=3
     */

    public function gridCreate(): mixed
    {
        $handle = $this->user32->new('int[1]');
        $key = spl_object_id($handle);
        $this->grids[$key] = [
            'handle' => $handle,
            'cells' => [],
            'padded' => false,
            'parentHwnd' => null,
        ];
        return $handle;
    }

    public function gridAppend(
        mixed $h,
        mixed $child,
        int $left,
        int $top,
        int $xspan,
        int $yspan,
        bool $hexpand,
        int $halign,
        bool $vexpand,
        int $valign
    ): void {
        $key = spl_object_id($h);
        if (!isset($this->grids[$key])) {
            throw new UiException("Invalid grid handle");
        }
        $this->grids[$key]['cells'][] = [
            'handle' => $child,
            'left' => $left,
            'top' => $top,
            'xspan' => max(1, $xspan),
            'yspan' => max(1, $yspan),
            'hexpand' => $hexpand,
            'halign' => $halign,
            'vexpand' => $vexpand,
            'valign' => $valign,
        ];
        $this->layoutGrid($h);
    }

    public function gridInsertAt(
        mixed $h,
        mixed $child,
        mixed $existing,
        int $at,
        int $xspan,
        int $yspan,
        bool $hexpand,
        int $halign,
        bool $vexpand,
        int $valign
    ): void {
        $key = spl_object_id($h);
        if (!isset($this->grids[$key])) {
            throw new UiException("Invalid grid handle");
        }
        // 查找参照控件的 cell
        $existingKey = $this->hwndInt($existing);
        $existingCell = null;
        foreach ($this->grids[$key]['cells'] as $cell) {
            if ($this->hwndInt($cell['handle']) === $existingKey) {
                $existingCell = $cell;
                break;
            }
        }
        if ($existingCell === null) {
            throw new UiException("Existing cell not found in grid");
        }
        // 根据 $at 方向计算新 cell 位置
        $xspan = max(1, $xspan);
        $yspan = max(1, $yspan);
        switch ($at) {
            case 0: // Leading（左）
                $left = $existingCell['left'] - $xspan;
                $top = $existingCell['top'];
                break;
            case 1: // Top（上）
                $left = $existingCell['left'];
                $top = $existingCell['top'] - $yspan;
                break;
            case 2: // Trailing（右）
                $left = $existingCell['left'] + $existingCell['xspan'];
                $top = $existingCell['top'];
                break;
            case 3: // Bottom（下）
                $left = $existingCell['left'];
                $top = $existingCell['top'] + $existingCell['yspan'];
                break;
            default:
                $left = 0;
                $top = 0;
        }
        $this->grids[$key]['cells'][] = [
            'handle' => $child,
            'left' => $left,
            'top' => $top,
            'xspan' => $xspan,
            'yspan' => $yspan,
            'hexpand' => $hexpand,
            'halign' => $halign,
            'vexpand' => $vexpand,
            'valign' => $valign,
        ];
        $this->layoutGrid($h);
    }

    public function gridGetPadded(mixed $h): bool
    {
        $key = spl_object_id($h);
        return $this->grids[$key]['padded'] ?? false;
    }

    public function gridSetPadded(mixed $h, bool $p): void
    {
        $key = spl_object_id($h);
        if (!isset($this->grids[$key])) {
            throw new UiException("Invalid grid handle");
        }
        $this->grids[$key]['padded'] = $p;
        $this->layoutGrid($h);
    }

    /**
     * 计算并应用 Grid 内子控件的布局。
     *
     * 列数 = max(left + xspan)，行数 = max(top + yspan)。
     * 每列宽 = (总宽 - (cols-1)*padded) / cols，
     * 每行高 = (总高 - (rows-1)*padded) / rows。
     * 单元格 = left/top 起的 xspan×ysspan 区域。
     */
    private function layoutGrid(mixed $h): void
    {
        $key = spl_object_id($h);
        $grid = $this->grids[$key] ?? null;
        if (!$grid || empty($grid['cells']) || $grid['parentHwnd'] === null) {
            return;
        }
        $parent = $grid['parentHwnd'];
        $rect = $this->user32->new('RECT');
        SafeCall::invoke($this->user32, 'GetClientRect', [$parent, \FFI::addr($rect)]);
        $w = (int)($rect->right - $rect->left);
        $hgt = (int)($rect->bottom - $rect->top);

        $padded = $grid['padded'] ? 4 : 0;
        // 计算网格列数与行数
        $cols = 1;
        $rows = 1;
        foreach ($grid['cells'] as $cell) {
            $cols = max($cols, $cell['left'] + $cell['xspan']);
            $rows = max($rows, $cell['top'] + $cell['yspan']);
        }
        $colW = $cols > 0 ? ($w - ($cols - 1) * $padded) / $cols : 0;
        $rowH = $rows > 0 ? ($hgt - ($rows - 1) * $padded) / $rows : 0;

        foreach ($grid['cells'] as $cell) {
            $x = (int)($cell['left'] * ($colW + $padded));
            $y = (int)($cell['top'] * ($rowH + $padded));
            $cw = (int)($cell['xspan'] * $colW + ($cell['xspan'] - 1) * $padded);
            $ch = (int)($cell['yspan'] * $rowH + ($cell['yspan'] - 1) * $padded);
            if ($cw < 0) $cw = 0;
            if ($ch < 0) $ch = 0;
            $this->placeChild($cell['handle'], $x, $y, $cw, $ch, $parent);
        }
    }

    /**
     * 遍历所有 Grid，找出 parentHwnd 为 $hwnd 的 Grid 并重新布局。
     */
    private function layoutGridsInWindow(mixed $hwnd): void
    {
        $hwndInt = $this->hwndInt($hwnd);
        foreach ($this->grids as $grid) {
            if ($grid['parentHwnd'] !== null
                && $this->hwndInt($grid['parentHwnd']) === $hwndInt
            ) {
                $this->layoutGrid($grid['handle']);
            }
        }
    }

    /* ==============================================================
     * 共用辅助：reparentTo
     * ============================================================ */

    /**
     * 把子控件 reparent 到指定父窗口并切换为 WS_CHILD|WS_VISIBLE 样式。
     *
     * 用于 Tab 页子控件 reparent 到 Tab 控件、Group 子控件 reparent 到 Group。
     * 与 placeChild 中的 reparent 逻辑一致，但不调 MoveWindow（位置由
     * layoutTabPage / layoutGroupChild 单独设置）。
     */
    private function reparentTo(mixed $child, mixed $parent): void
    {
        $childKey = spl_object_id($child);
        // PHP 端占位 handle（Box/Form/Grid/RadioButtons）：只设置 parentHwnd 并触发布局
        if (isset($this->boxes[$childKey])) {
            $this->boxes[$childKey]['parentHwnd'] = $parent;
            $this->layoutBox($child);
            return;
        }
        if (isset($this->forms[$childKey])) {
            $this->forms[$childKey]['parentHwnd'] = $parent;
            $this->layoutForm($child);
            return;
        }
        if (isset($this->grids[$childKey])) {
            $this->grids[$childKey]['parentHwnd'] = $parent;
            $this->layoutGrid($child);
            return;
        }
        if (isset($this->radioButtons[$childKey])) {
            $this->radioButtons[$childKey]['parentHwnd'] = $parent;
            $this->layoutRadioButtons($child);
            return;
        }
        // 普通 HWND 控件：SetParent + 改样式
        SafeCall::invoke($this->user32, 'SetParent', [$child, $parent]);
        $oldStyle = (int) SafeCall::invoke(
            $this->user32, 'GetWindowLongPtrA', [$child, -16 /*GWL_STYLE*/]
        );
        $newStyle = ($oldStyle & ~0x80000000 /*~WS_POPUP*/) | 0x50000000 /*WS_CHILD|WS_VISIBLE*/;
        SafeCall::invoke(
            $this->user32, 'SetWindowLongPtrA', [$child, -16, $newStyle]
        );
    }

    /**
     * 切换容器（Box/Form/Grid）内部所有直接子控件的可见性。
     * 用于 Tab 切换页时，对占位 handle 的子控件批量显示/隐藏。
     */
    private function setContainerChildrenVisible(mixed $container, int $cmd): void
    {
        $key = spl_object_id($container);
        $children = [];
        if (isset($this->boxes[$key]['children'])) {
            foreach ($this->boxes[$key]['children'] as $c) {
                $children[] = $c['handle'];
            }
        } elseif (isset($this->forms[$key]['children'])) {
            foreach ($this->forms[$key]['children'] as $c) {
                $children[] = $c['handle'];
            }
        } elseif (isset($this->grids[$key]['cells'])) {
            foreach ($this->grids[$key]['cells'] as $c) {
                $children[] = $c['handle'];
            }
        } elseif (isset($this->radioButtons[$key]['children'])) {
            foreach ($this->radioButtons[$key]['children'] as $c) {
                $children[] = $c;
            }
        }
        foreach ($children as $child) {
            // 嵌套容器：递归处理其内部子控件
            if (isset($this->boxes[spl_object_id($child)])
                || isset($this->forms[spl_object_id($child)])
                || isset($this->grids[spl_object_id($child)])
                || isset($this->radioButtons[spl_object_id($child)])) {
                $this->setContainerChildrenVisible($child, $cmd);
            } else {
                SafeCall::invoke($this->user32, 'ShowWindow', [$child, $cmd]);
            }
        }
    }

    /**
     * 判断 handle 是否为 PHP 端占位 handle（Box/Form/Grid/RadioButtons 容器）。
     */
    private function isContainerHandle(mixed $h): bool
    {
        $key = spl_object_id($h);
        return isset($this->boxes[$key])
            || isset($this->forms[$key])
            || isset($this->grids[$key])
            || isset($this->radioButtons[$key]);
    }

    /* ==============================================================
     * Spinbox（数值微调框：EDIT + ES_NUMBER）
     * ============================================================ *
     *
     * 简化实现：用 ES_NUMBER 的 EDIT 控件，无 +/- 按钮。用户输入数字，
     * onChanged 在 EN_CHANGE 时触发。getValue/setValue 用 GetWindowTextA。
     * min/max 仅作为软约束（setValue 时 clamp，超出范围不报错）。
     */

    /**
     * 创建数值微调框。
     * ES_NUMBER = 0x2000，仅允许输入数字。ES_AUTOHSCROLL = 0x0001。
     */
    public function spinboxCreate(int $min, int $max): mixed
    {
        $h = SafeCall::expectNotNull($this->user32, 'CreateWindowExA', [
            0, "EDIT", "0", 0x80000000 | 0x2000 | 0x0001,
            0, 0, 100, 25,
            null, null, null, null
        ]);
        // 用属性表存储 min/max（HWND 上无法直接挂自定义数据，用 PHP 数组）
        $this->spinboxRanges[$this->hwndInt($h)] = ['min' => $min, 'max' => $max];
        return $h;
    }

    public function spinboxGetValue(mixed $h): int
    {
        $text = $this->getWindowWindowText($h);
        $val = (int)$text;
        $range = $this->spinboxRanges[$this->hwndInt($h)] ?? null;
        if ($range !== null) {
            $val = max($range['min'], min($range['max'], $val));
        }
        return $val;
    }

    public function spinboxSetValue(mixed $h, int $v): void
    {
        $range = $this->spinboxRanges[$this->hwndInt($h)] ?? null;
        if ($range !== null) {
            $v = max($range['min'], min($range['max'], $v));
        }
        SafeCall::invoke($this->user32, 'SetWindowTextA', [$h, $this->toAnsi((string)$v)]);
    }

    public function spinboxOnChanged(mixed $h, \Closure $cb): void
    {
        $this->closures[$this->hwndInt($h) . ':changed'] = $cb;
    }

    /* ==============================================================
     * Slider（TRACKBAR：msctls_trackbar32）
     * ============================================================ *
     *
     * TBM_* 消息（TBM_FIRST = 0x0400）：
     *   TBM_GETPOS=0x0400, TBM_SETPOS=0x0405, TBM_SETRANGEMIN=0x0407,
     *   TBM_SETRANGEMAX=0x0408
     * 滑块变化通过 WM_HSCROLL/WM_VSCROLL 通知（在 WndProc 中处理）。
     */

    public function sliderCreate(int $min, int $max): mixed
    {
        $h = SafeCall::expectNotNull($this->user32, 'CreateWindowExA', [
            0, "msctls_trackbar32", "", 0x80000000,
            0, 0, 200, 30,
            null, null, null, null
        ]);
        SafeCall::invoke($this->user32, 'SendMessageA', [$h, 0x0407 /*TBM_SETRANGEMIN*/, 0, $min]);
        SafeCall::invoke($this->user32, 'SendMessageA', [$h, 0x0408 /*TBM_SETRANGEMAX*/, 0, $max]);
        return $h;
    }

    public function sliderGetValue(mixed $h): int
    {
        return (int) SafeCall::invoke($this->user32, 'SendMessageA', [
            $h, 0x0400 /*TBM_GETPOS*/, 0, 0
        ]);
    }

    public function sliderSetValue(mixed $h, int $v): void
    {
        SafeCall::invoke($this->user32, 'SendMessageA', [
            $h, 0x0405 /*TBM_SETPOS*/, 1 /*redraw*/, $v
        ]);
    }

    public function sliderOnChanged(mixed $h, \Closure $cb): void
    {
        $this->closures[$this->hwndInt($h) . ':sliderChanged'] = $cb;
    }

    /* ==============================================================
     * ProgressBar（msctls_progress32）
     * ============================================================ *
     *
     * PBM_* 消息（CCM_FIRST = 0x2000, PBM_FIRST = CCM_FIRST+0x00）：
     *   PBM_SETPOS=0x2002, PBM_GETPOS=0x2008, PBM_SETRANGE32=0x2006,
     *   PBM_SETMARQUEE=0x2009
     * 库内固定范围 0-100，-1 表示不确定动画。
     */

    public function progressBarCreate(): mixed
    {
        $h = SafeCall::expectNotNull($this->user32, 'CreateWindowExA', [
            0, "msctls_progress32", "", 0x80000000,
            0, 0, 200, 20,
            null, null, null, null
        ]);
        SafeCall::invoke($this->user32, 'SendMessageA', [$h, 0x2006 /*PBM_SETRANGE32*/, 0, 100]);
        return $h;
    }

    public function progressBarGetValue(mixed $h): int
    {
        return (int) SafeCall::invoke($this->user32, 'SendMessageA', [
            $h, 0x2008 /*PBM_GETPOS*/, 0, 0
        ]);
    }

    public function progressBarSetValue(mixed $h, int $v): void
    {
        if ($v < 0) {
            // 不确定动画：PBS_MARQUEE=0x08 样式需在创建时设置，此处简化为 0
            SafeCall::invoke($this->user32, 'SendMessageA', [
                $h, 0x2009 /*PBM_SETMARQUEE*/, 1, 30
            ]);
        } else {
            SafeCall::invoke($this->user32, 'SendMessageA', [
                $h, 0x2002 /*PBM_SETPOS*/, $v, 0
            ]);
        }
    }

    /* ==============================================================
     * Combobox（COMBOBOX + CBS_DROPDOWNLIST，不可编辑）
     * ============================================================ *
     *
     * CB_* 消息：
     *   CB_ADDSTRING=0x0143, CB_INSERTSTRING=0x014A, CB_DELETESTRING=0x0144,
     *   CB_RESETCONTENT=0x014B, CB_GETCOUNT=0x0146, CB_GETCURSEL=0x0147,
     *   CB_SETCURSEL=0x014E
     * CBN_SELCHANGE=1 在 WM_COMMAND 的 wParam 高位。
     */

    /**
     * CBS_DROPDOWNLIST = 0x0003，CBS_HASSTRINGS = 0x0200。
     */
    public function comboboxCreate(): mixed
    {
        return SafeCall::expectNotNull($this->user32, 'CreateWindowExA', [
            0, "COMBOBOX", "", 0x80000000 | 0x0003 | 0x0200 | 0x0001 /*WS_VSCROLL*/,
            0, 0, 120, 200,
            null, null, null, null
        ]);
    }

    public function comboboxAppend(mixed $h, string $name): void
    {
        $ansi = $this->toAnsi($name);
        $buf = $this->user32->new('char[' . (strlen($ansi) + 1) . ']');
        for ($i = 0; $i < strlen($ansi); $i++) {
            $buf[$i] = $ansi[$i];
        }
        $buf[strlen($ansi)] = "\0";
        // CData 指针须先 cast 为 LPARAM 取整数值再传，否则触发
        // "Object of class FFI\CData could not be converted to int" 警告
        $lParam = (int) $this->user32->cast('LPARAM', \FFI::addr($buf))->cdata;
        SafeCall::invoke($this->user32, 'SendMessageA', [
            $h, 0x0143 /*CB_ADDSTRING*/, 0, $lParam
        ]);
    }

    public function comboboxInsertAt(mixed $h, string $name, int $index): void
    {
        $ansi = $this->toAnsi($name);
        $buf = $this->user32->new('char[' . (strlen($ansi) + 1) . ']');
        for ($i = 0; $i < strlen($ansi); $i++) {
            $buf[$i] = $ansi[$i];
        }
        $buf[strlen($ansi)] = "\0";
        // 同 comboboxAppend：先 cast 为 LPARAM 取整数值
        $lParam = (int) $this->user32->cast('LPARAM', \FFI::addr($buf))->cdata;
        SafeCall::invoke($this->user32, 'SendMessageA', [
            $h, 0x014A /*CB_INSERTSTRING*/, $index, $lParam
        ]);
    }

    public function comboboxDelete(mixed $h, int $index): void
    {
        SafeCall::invoke($this->user32, 'SendMessageA', [
            $h, 0x0144 /*CB_DELETESTRING*/, $index, 0
        ]);
    }

    public function comboboxClear(mixed $h): void
    {
        SafeCall::invoke($this->user32, 'SendMessageA', [
            $h, 0x014B /*CB_RESETCONTENT*/, 0, 0
        ]);
    }

    public function comboboxNumItems(mixed $h): int
    {
        return (int) SafeCall::invoke($this->user32, 'SendMessageA', [
            $h, 0x0146 /*CB_GETCOUNT*/, 0, 0
        ]);
    }

    public function comboboxGetSelected(mixed $h): int
    {
        $r = (int) SafeCall::invoke($this->user32, 'SendMessageA', [
            $h, 0x0147 /*CB_GETCURSEL*/, 0, 0
        ]);
        return $r;  // CB_ERR = -1 表示无选中
    }

    public function comboboxSetSelected(mixed $h, int $index): void
    {
        SafeCall::invoke($this->user32, 'SendMessageA', [
            $h, 0x014E /*CB_SETCURSEL*/, $index, 0
        ]);
    }

    public function comboboxOnSelected(mixed $h, \Closure $cb): void
    {
        $this->closures[$this->hwndInt($h) . ':selected'] = $cb;
    }

    /* ==============================================================
     * EditableCombobox（COMBOBOX + CBS_DROPDOWN，可编辑）
     * ============================================================ *
     *
     * 与 Combobox 共用 CB_* 消息，但文本通过 WM_GETTEXT/WM_SETTEXT 操作。
     * CBS_DROPDOWN = 0x0002（带编辑框）。
     */

    public function editableComboboxCreate(): mixed
    {
        return SafeCall::expectNotNull($this->user32, 'CreateWindowExA', [
            0, "COMBOBOX", "", 0x80000000 | 0x0002 | 0x0200 | 0x0001 /*WS_VSCROLL*/,
            0, 0, 120, 200,
            null, null, null, null
        ]);
    }

    public function editableComboboxAppend(mixed $h, string $name): void
    {
        $this->comboboxAppend($h, $name);
    }

    public function editableComboboxInsertAt(mixed $h, string $name, int $index): void
    {
        $this->comboboxInsertAt($h, $name, $index);
    }

    public function editableComboboxDelete(mixed $h, int $index): void
    {
        $this->comboboxDelete($h, $index);
    }

    public function editableComboboxClear(mixed $h): void
    {
        $this->comboboxClear($h);
    }

    public function editableComboboxNumItems(mixed $h): int
    {
        return $this->comboboxNumItems($h);
    }

    public function editableComboboxGetSelected(mixed $h): int
    {
        return $this->comboboxGetSelected($h);
    }

    public function editableComboboxSetSelected(mixed $h, int $index): void
    {
        $this->comboboxSetSelected($h, $index);
    }

    public function editableComboboxSetText(mixed $h, string $t): void
    {
        SafeCall::invoke($this->user32, 'SetWindowTextA', [$h, $this->toAnsi($t)]);
    }

    public function editableComboboxGetText(mixed $h): string
    {
        return $this->getWindowWindowText($h);
    }

    public function editableComboboxOnChanged(mixed $h, \Closure $cb): void
    {
        $this->closures[$this->hwndInt($h) . ':ecombChanged'] = $cb;
    }

    /* ==============================================================
     * RadioButtons（PHP 端容器 + 多个 BS_AUTORADIOBUTTON）
     * ============================================================ *
     *
     * RadioButtons 是 PHP 端占位 handle（int[1]），维护子控件列表。
     * 子控件用 BS_AUTORADIOBUTTON(0x9) + WS_GROUP(0x00020000) 在首项上
     * 实现自动互斥。BN_CLICKED 时通过 radioChildToParent 反查父占位并
     * 触发 :selected 回调。
     */

    public function radioButtonsCreate(): mixed
    {
        $handle = $this->user32->new('int[1]');
        $key = spl_object_id($handle);
        $this->radioButtons[$key] = [
            'handle' => $handle,
            'children' => [],
            'parentHwnd' => null,
        ];
        return $handle;
    }

    public function radioButtonsAppend(mixed $h, string $text): void
    {
        $key = spl_object_id($h);
        if (!isset($this->radioButtons[$key])) {
            throw new UiException("Invalid radioButtons handle");
        }
        $style = 0x80000000 | 0x9 /*BS_AUTORADIOBUTTON*/;
        // 首个子控件加 WS_GROUP 启动新组
        if (empty($this->radioButtons[$key]['children'])) {
            $style |= 0x00020000 /*WS_GROUP*/;
        }
        $child = SafeCall::expectNotNull($this->user32, 'CreateWindowExA', [
            0, "BUTTON", $this->toAnsi($text), $style,
            0, 0, 200, 25,
            null, null, null, null
        ]);
        $this->radioButtons[$key]['children'][] = $child;
        // 建立 child → 父占位 反查映射
        $this->radioChildToParent[$this->hwndInt($child)] = $key;
        $this->layoutRadioButtons($h);
    }

    public function radioButtonsGetSelected(mixed $h): int
    {
        $key = spl_object_id($h);
        if (!isset($this->radioButtons[$key])) {
            return -1;
        }
        foreach ($this->radioButtons[$key]['children'] as $i => $child) {
            $r = SafeCall::invoke($this->user32, 'SendMessageA', [
                $child, 0x00F0 /*BM_GETCHECK*/, 0, 0
            ]);
            if ((int)$r === 1 /*BST_CHECKED*/) {
                return $i;
            }
        }
        return -1;
    }

    public function radioButtonsSetSelected(mixed $h, int $index): void
    {
        $key = spl_object_id($h);
        if (!isset($this->radioButtons[$key]['children'][$index])) {
            return;
        }
        // BS_AUTORADIOBUTTON 在 SetCheck 时会自动取消同组其他项的选中
        $child = $this->radioButtons[$key]['children'][$index];
        SafeCall::invoke($this->user32, 'SendMessageA', [
            $child, 0x00F1 /*BM_SETCHECK*/, 1 /*BST_CHECKED*/, 0
        ]);
    }

    public function radioButtonsOnSelected(mixed $h, \Closure $cb): void
    {
        $this->closures[spl_object_id($h) . ':selected'] = $cb;
    }

    /**
     * 垂直布局 RadioButtons 子控件（类似 VBox）。
     */
    private function layoutRadioButtons(mixed $h): void
    {
        $key = spl_object_id($h);
        $rb = $this->radioButtons[$key] ?? null;
        if (!$rb || empty($rb['children']) || $rb['parentHwnd'] === null) {
            return;
        }
        $parent = $rb['parentHwnd'];
        $rect = $this->user32->new('RECT');
        SafeCall::invoke($this->user32, 'GetClientRect', [$parent, \FFI::addr($rect)]);
        $w = (int)($rect->right - $rect->left);
        $hgt = (int)($rect->bottom - $rect->top);
        $n = count($rb['children']);
        $itemH = $n > 0 ? intdiv($hgt, $n) : 25;
        $y = 0;
        foreach ($rb['children'] as $child) {
            $this->placeChild($child, 0, $y, $w, $itemH, $parent);
            $y += $itemH;
        }
    }

    /* ==============================================================
     * MultilineEntry（EDIT + ES_MULTILINE）
     * ============================================================ *
     *
     * ES_MULTILINE = 0x0004, ES_AUTOVSCROLL = 0x0040, ES_WANTRETURN = 0x1000,
     * WS_VSCROLL = 0x00200000。
     * 文本通过 WM_GETTEXT/WM_SETTEXT，追加通过 EM_REPLACESEL。
     */

    public function multilineEntryCreate(): mixed
    {
        return SafeCall::expectNotNull($this->user32, 'CreateWindowExA', [
            0, "EDIT", "", 0x80000000 | 0x0004 | 0x0040 | 0x1000 | 0x00200000,
            0, 0, 300, 150,
            null, null, null, null
        ]);
    }

    public function multilineEntryGetText(mixed $h): string
    {
        return $this->getWindowWindowText($h);
    }

    public function multilineEntrySetText(mixed $h, string $t): void
    {
        SafeCall::invoke($this->user32, 'SetWindowTextA', [$h, $this->toAnsi($t)]);
    }

    /**
     * 追加文本：EM_SETSEL=-1 将光标移到末尾，EM_REPLACESEL 替换选区（即追加）。
     */
    public function multilineEntryAppend(mixed $h, string $t): void
    {
        SafeCall::invoke($this->user32, 'SendMessageA', [$h, 0x00B1 /*EM_SETSEL*/, -1, -1]);
        $ansi = $this->toAnsi($t);
        $buf = $this->user32->new('char[' . (strlen($ansi) + 1) . ']');
        for ($i = 0; $i < strlen($ansi); $i++) {
            $buf[$i] = $ansi[$i];
        }
        $buf[strlen($ansi)] = "\0";
        // CData 指针须先 cast 为 LPARAM 取整数值再传
        $lParam = (int) $this->user32->cast('LPARAM', \FFI::addr($buf))->cdata;
        SafeCall::invoke($this->user32, 'SendMessageA', [
            $h, 0x00C2 /*EM_REPLACESEL*/, 0, $lParam
        ]);
    }

    public function multilineEntryOnChanged(mixed $h, \Closure $cb): void
    {
        $this->closures[$this->hwndInt($h) . ':changed'] = $cb;
    }

    public function multilineEntrySetReadOnly(mixed $h, bool $ro): void
    {
        SafeCall::invoke($this->user32, 'SendMessageA', [$h, 0x00CF /*EM_SETREADONLY*/, $ro ? 1 : 0, 0]);
    }

    /* ==============================================================
     * PasswordEntry（EDIT + ES_PASSWORD）
     * ============================================================ */

    /**
     * ES_PASSWORD = 0x0020，ES_AUTOHSCROLL = 0x0001。
     */
    public function passwordEntryCreate(): mixed
    {
        return SafeCall::expectNotNull($this->user32, 'CreateWindowExA', [
            0, "EDIT", "", 0x80000000 | 0x0020 | 0x0001,
            0, 0, 100, 25,
            null, null, null, null
        ]);
    }

    public function passwordEntryGetText(mixed $h): string
    {
        return $this->getWindowWindowText($h);
    }

    public function passwordEntrySetText(mixed $h, string $t): void
    {
        SafeCall::invoke($this->user32, 'SetWindowTextA', [$h, $this->toAnsi($t)]);
    }

    public function passwordEntryOnChanged(mixed $h, \Closure $cb): void
    {
        $this->closures[$this->hwndInt($h) . ':changed'] = $cb;
    }

    /* ==============================================================
     * SearchEntry（EDIT，无原生搜索图标）
     * ============================================================ *
     *
     * Windows 标准 EDIT 无原生搜索图标，仅作为普通输入框。可通过 EM_SETCUEBANNER
     * 设置占位提示文本（"搜索..."）。
     */

    public function searchEntryCreate(): mixed
    {
        $h = SafeCall::expectNotNull($this->user32, 'CreateWindowExA', [
            0, "EDIT", "", 0x80000000 | 0x0001,
            0, 0, 150, 25,
            null, null, null, null
        ]);
        // EM_SETCUEBANNER = 0x1501，lParam 为提示文本（UTF-16 在 A 系列中不支持，
        // 此处用 ANSI 提示，简化为不设置提示）
        return $h;
    }

    public function searchEntryGetText(mixed $h): string
    {
        return $this->getWindowWindowText($h);
    }

    public function searchEntrySetText(mixed $h, string $t): void
    {
        SafeCall::invoke($this->user32, 'SetWindowTextA', [$h, $this->toAnsi($t)]);
    }

    public function searchEntryOnChanged(mixed $h, \Closure $cb): void
    {
        $this->closures[$this->hwndInt($h) . ':changed'] = $cb;
    }

    /* ==============================================================
     * DateTimePicker（SysDateTimePick32）
     * ============================================================ *
     *
     * DTM_GETSYSTEMTIME = 0x1001, DTM_SETSYSTEMTIME = 0x1002。
     * GDT_VALID = 0 表示有效时间。返回 SYSTEMTIME 结构，需转 Unix 时间戳。
     */

    public function dateTimePickerCreate(): mixed
    {
        return SafeCall::expectNotNull($this->user32, 'CreateWindowExA', [
            0, "SysDateTimePick32", "", 0x80000000 | 0x0004 /*DTS_SHORTFORMAT*/,
            0, 0, 200, 25,
            null, null, null, null
        ]);
    }

    public function dateTimePickerGetTime(mixed $h): int
    {
        $st = $this->user32->new('SYSTEMTIME');
        $r = SafeCall::invoke($this->user32, 'SendMessageA', [
            $h, 0x1001 /*DTM_GETSYSTEMTIME*/, 0, \FFI::addr($st)
        ]);
        if ((int)$r !== 0 /*GDT_VALID*/) {
            return time();
        }
        return mktime(
            (int)$st->wHour, (int)$st->wMinute, (int)$st->wSecond,
            (int)$st->wMonth, (int)$st->wDay, (int)$st->wYear
        );
    }

    public function dateTimePickerSetTime(mixed $h, int $t): void
    {
        $arr = getdate($t);
        $st = $this->user32->new('SYSTEMTIME');
        $st->wYear = $arr['year'];
        $st->wMonth = $arr['mon'];
        $st->wDay = $arr['mday'];
        $st->wHour = $arr['hours'];
        $st->wMinute = $arr['minutes'];
        $st->wSecond = $arr['seconds'];
        $st->wDayOfWeek = $arr['wday'];
        $st->wMilliseconds = 0;
        SafeCall::invoke($this->user32, 'SendMessageA', [
            $h, 0x1002 /*DTM_SETSYSTEMTIME*/, 0 /*GDT_VALID*/, \FFI::addr($st)
        ]);
    }
}
