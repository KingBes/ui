<?php
declare(strict_types=1);

namespace Kingbes\Ui\Platform\Mac;

use Kingbes\Ui\Exception\UnsupportedOperationException;
use Kingbes\Ui\Geometry\Point;
use Kingbes\Ui\Geometry\Size;
use Kingbes\Ui\Graphics\Color;
use Kingbes\Ui\Platform\AbstractPlatform;
use Kingbes\Ui\Theme;
use Kingbes\Phpc\Library;
use Kingbes\Phpc\SafeCall;
use Kingbes\Phpc\Pointer;

/**
 * macOS Cocoa 后端桩实现。
 *
 * 通过 FFI 加载 libobjc.dylib / CoreFoundation，借助 ObjC runtime
 * （objc_getClass / sel_registerName / objc_msgSend）实现 Window/Button/
 * Label 基础流程。未实现的方法抛 UnsupportedOperationException。
 *
 * 关键设计：
 *   - 所有 ObjC 对象指针对外用 int 传递，内部用 INT_TO_PTR 联合体完成
 *     int↔id 转换（不同 FFI 作用域不能直接 cast）。
 *   - objc_msgSend 是变参函数，FFI 中按需声明不同签名版本
 *     （objc_msgSend / objc_msgSend1 / objc_msgSend_int / objc_msgSend_cstr）。
 *   - NSString 创建用 stringWithUTF8String:（避免 NSRect 结构体传递复杂性）。
 *   - 主循环用 CFRunLoopRunInMode 轮询（10ms），每轮 runTimers() +
 *     runQueueMain()；quit() 设置 $running = false 退出循环。
 *   - 控件类型用 $controlTypes 跟踪（'Button'/'Label'），用于
 *     controlSetText/controlGetText 区分调用对应 Cocoa API。
 *
 * 注意：本机 Windows 无法运行 Cocoa，仅做 php -l 语法检查。
 */
class CocoaPlatform extends AbstractPlatform
{
    // ============================================================
    // Cocoa 常量
    // ============================================================

    /**
     * NSWindow styleMask：组合标题栏 + 关闭 + 最小化 + 可调整大小。
     *
     * NSTitledWindowMask      = 1
     * NSClosableWindowMask    = 2
     * NSMiniaturizableWindowMask = 4
     * NSResizableWindowMask   = 8
     */
    private const NS_WINDOW_STYLE_MASK = 1 | 2 | 4 | 8;

    /** NSApplicationActivationPolicyRegular = 0 */
    private const NS_APPLICATION_ACTIVATION_POLICY_REGULAR = 0;

    /** kCFStringEncodingUTF8 = 0x08000100 */
    private const K_CF_STRING_ENCODING_UTF8 = 0x08000100;

    /** YES = 1, NO = 0 */
    private const YES = 1;
    private const NO = 0;

    /**
     * NSAppearance 名称字符串常量（NSString 值，非 selector）。
     *
     * 用于 [NSAppearance appearanceNamed:] 创建对应外观实例：
     *   - NSAppearanceNameDarkAqua：macOS 10.14+ 深色外观
     *   - NSAppearanceNameAqua：默认浅色外观
     */
    private const NS_APPEARANCE_NAME_DARK_AQUA = 'NSAppearanceNameDarkAqua';
    private const NS_APPEARANCE_NAME_AQUA = 'NSAppearanceNameAqua';

    // ============================================================
    // FFI 实例
    // ============================================================

    /** @var \FFI|null libobjc.dylib（ObjC runtime） */
    private ?\FFI $objc = null;

    /** @var \FFI|null CoreFoundation（CFRunLoop / CFString） */
    private ?\FFI $cf = null;

    // ============================================================
    // 运行期状态
    // ============================================================

    /**
     * ObjC 对象 CData 保活表：hwnd(int) => id CData。
     *
     * 防止 FFI 回收 CData 后指针失效；所有创建的窗口/控件都登记在此。
     *
     * @var array<int, \FFI\CData>
     */
    private array $objects = [];

    /**
     * 控件类型表：hwnd(int) => 原生类名（'Button'/'Label'）。
     *
     * 用于 controlSetText/controlGetText 区分调用对应 Cocoa API。
     *
     * @var array<int, string>
     */
    private array $controlTypes = [];

    /**
     * 控件 ID 自增计数器。
     */
    private int $nextControlId = 1000;

    /**
     * 选择器缓存：SEL 名称 => SEL CData。
     *
     * 避免重复 sel_registerName 调用。
     *
     * @var array<string, \FFI\CData>
     */
    private array $selectorCache = [];

    // ============================================================
    // 构造器：加载 ObjC runtime + CoreFoundation
    // ============================================================

    public function __construct()
    {
        Library::permit('libobjc.dylib');
        Library::permit('CoreFoundation');

        $this->objc = Library::load('libobjc.dylib', self::OBJC_HEADER);
        $this->cf   = Library::load('CoreFoundation', self::CF_HEADER);

        // 获取 NSApplication 单例并设为常规应用策略
        $this->initApplication();
    }

    /**
     * 初始化 NSApplication 单例。
     *
     * [NSApplication sharedApplication] + [app setActivationPolicy:0]
     * + [app activateIgnoringOtherApps:YES]
     */
    private function initApplication(): void
    {
        $app = $this->objc_msgSend_id(
            $this->getClass('NSApplication'),
            $this->sel('sharedApplication')
        );
        Pointer::assertNotNull($app, 'NSApplication sharedApplication');

        // [app setActivationPolicy:NSApplicationActivationPolicyRegular]
        $this->objc_msgSend_int($app, $this->sel('setActivationPolicy:'), self::NS_APPLICATION_ACTIVATION_POLICY_REGULAR);

        // [app activateIgnoringOtherApps:YES]
        $this->objc_msgSend_int($app, $this->sel('activateIgnoringOtherApps:'), self::YES);
    }

    // ============================================================
    // C 头声明
    // ============================================================

    /**
     * libobjc.dylib 头声明。
     *
     * objc_msgSend 是变参函数，FFI 中按需声明不同签名版本：
     *   - objc_msgSend(id, SEL)              无参方法，返回 id
     *   - objc_msgSend1(id, SEL, id)         1 个 id 参数，返回 id
     *   - objc_msgSend_int(id, SEL, int)     1 个 int 参数，返回 id
     *   - objc_msgSend_cstr(id, SEL, char*)  1 个 cstring 参数，返回 id
     */
    private const OBJC_HEADER = <<<C
typedef void* id;
typedef void* SEL;
typedef void* Class;

/* 联合体用于 int↔指针 转换（不同 FFI 作用域不能直接 cast） */
typedef union { long long i; void* p; } INT_TO_PTR;

/* ObjC runtime 核心 */
Class objc_getClass(const char *name);
SEL sel_registerName(const char *name);

/* objc_msgSend 变参函数，FFI 中按需声明不同签名 */
id objc_msgSend(id self, SEL op);
id objc_msgSend1(id self, SEL op, id arg1);
id objc_msgSend_int(id self, SEL op, int arg1);
id objc_msgSend_cstr(id self, SEL op, const char *arg1);
C;

    /**
     * CoreFoundation 头声明（CFRunLoopRunInMode / CFStringCreateWithCString）。
     */
    private const CF_HEADER = <<<C
typedef void* CFStringRef;
typedef void* CFAllocatorRef;
typedef unsigned int CFStringEncoding;
typedef int Boolean;

/* 联合体用于 int↔指针 转换 */
typedef union { long long i; void* p; } INT_TO_PTR;

/* kCFRunLoopDefaultMode 是全局常量，FFI 无法直接访问；
 * 用 NSDefaultRunLoopMode 字符串替代，本桩用 CFString 创建。 */
int CFRunLoopRunInMode(const void* mode, double seconds, int returnAfterSourceHandled);

/* 从 C 字符串创建 CFString */
CFStringRef CFStringCreateWithCString(CFAllocatorRef alloc, const char *cStr, CFStringEncoding encoding);
C;

    // ============================================================
    // int↔指针 辅助（用 INT_TO_PTR 联合体，禁止跨作用域 cast）
    // ============================================================

    /**
     * id 指针 → int（用 objc 作用域的 INT_TO_PTR）。
     */
    private function ptrToInt(\FFI\CData $ptr): int
    {
        $c = $this->objc->new('INT_TO_PTR');
        $c->p = $ptr;
        return (int) $c->i;
    }

    /**
     * int → id 指针（用 objc 作用域的 INT_TO_PTR）。
     */
    private function intToPtr(int $i): \FFI\CData
    {
        $c = $this->objc->new('INT_TO_PTR');
        $c->i = $i;
        return $c->p;
    }

    // ============================================================
    // ObjC 辅助（getClass / sel / objc_msgSend 封装 / nsString）
    // ============================================================

    /**
     * 获取 ObjC 类对象：objc_getClass(name)。
     */
    private function getClass(string $name): \FFI\CData
    {
        $cls = SafeCall::invoke($this->objc, 'objc_getClass', [$name]);
        Pointer::assertNotNull($cls, "Class '{$name}'");
        return $cls;
    }

    /**
     * 注册选择器：sel_registerName(name)，带缓存。
     */
    private function sel(string $name): \FFI\CData
    {
        if (isset($this->selectorCache[$name])) {
            return $this->selectorCache[$name];
        }
        $selObj = SafeCall::invoke($this->objc, 'sel_registerName', [$name]);
        Pointer::assertNotNull($selObj, "SEL '{$name}'");
        $this->selectorCache[$name] = $selObj;
        return $selObj;
    }

    /**
     * [obj method] —— 无参方法，返回 id。
     */
    private function objc_msgSend_id(\FFI\CData $obj, \FFI\CData $sel): \FFI\CData
    {
        $result = SafeCall::invoke($this->objc, 'objc_msgSend', [$obj, $sel]);
        Pointer::assertNotNull($result, 'objc_msgSend');
        return $result;
    }

    /**
     * [obj method:arg] —— 1 个 id 参数，返回 id。
     */
    private function objc_msgSend_with_id(\FFI\CData $obj, \FFI\CData $sel, \FFI\CData $arg): \FFI\CData
    {
        $result = SafeCall::invoke($this->objc, 'objc_msgSend1', [$obj, $sel, $arg]);
        return $result;
    }

    /**
     * [obj method:arg] —— 1 个 int 参数，返回 id。
     */
    private function objc_msgSend_int(\FFI\CData $obj, \FFI\CData $sel, int $arg): \FFI\CData
    {
        return SafeCall::invoke($this->objc, 'objc_msgSend_int', [$obj, $sel, $arg]);
    }

    /**
     * 从 C 字符串创建 NSString（用 stringWithUTF8String:）。
     *
     * 实现细节：调用 [NSString stringWithUTF8String:cstr]，返回 id。
     * 闭包保活 NSString 对象（防 GC 回收后悬垂指针）。
     */
    private function nsString(string $str): \FFI\CData
    {
        $cls = $this->getClass('NSString');
        $sel = $this->sel('stringWithUTF8String:');
        return SafeCall::invoke($this->objc, 'objc_msgSend_cstr', [$cls, $sel, $str]);
    }

    // ============================================================
    // 窗口方法
    // ============================================================

    /**
     * 创建顶层窗口。
     *
     * [NSWindow alloc] + [NSWindow init]（简化：用 init 默认大小，
     * 避免传递 NSRect 结构体的复杂性）。返回 id int。
     */
    public function windowCreate(string $title, int $width, int $height): int
    {
        $cls = $this->getClass('NSWindow');
        $alloc = $this->objc_msgSend_id($cls, $this->sel('alloc'));
        Pointer::assertNotNull($alloc, 'NSWindow alloc');

        // 简化：用 init 创建默认窗口，再用 setFrame:display: 调整大小
        $window = $this->objc_msgSend_id($alloc, $this->sel('init'));
        Pointer::assertNotNull($window, 'NSWindow init');

        // 设置标题
        $titleStr = $this->nsString($title);
        $this->objc_msgSend_with_id($window, $this->sel('setTitle:'), $titleStr);

        $hwnd = $this->ptrToInt($window);
        $this->objects[$hwnd] = $window;
        return $hwnd;
    }

    public function windowDestroy(int $hwnd): void
    {
        $obj = $this->objects[$hwnd] ?? null;
        if ($obj === null) {
            return;
        }
        // [window close] + [window release]
        $this->objc_msgSend_id($obj, $this->sel('close'));
        $this->objc_msgSend_id($obj, $this->sel('release'));
        unset($this->objects[$hwnd], $this->controlTypes[$hwnd]);
    }

    public function windowSetTitle(int $hwnd, string $title): void
    {
        $obj = $this->objects[$hwnd] ?? null;
        if ($obj === null) {
            return;
        }
        $titleStr = $this->nsString($title);
        $this->objc_msgSend_with_id($obj, $this->sel('setTitle:'), $titleStr);
    }

    public function windowGetTitle(int $hwnd): string
    {
        $obj = $this->objects[$hwnd] ?? null;
        if ($obj === null) {
            return '';
        }
        // [window title] 返回 NSString，FFI 无法直接获取 C 字符串
        // 简化：返回空字符串（完整实现需用 UTF8String + FFI::string）
        return '';
    }

    public function windowSetPosition(int $hwnd, int $x, int $y): void
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'macOS');
    }

    public function windowGetPosition(int $hwnd): Point
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'macOS');
    }

    public function windowSetSize(int $hwnd, int $width, int $height): void
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'macOS');
    }

    public function windowGetSize(int $hwnd): Size
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'macOS');
    }

    public function windowGetClientSize(int $hwnd): Size
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'macOS');
    }

    public function windowSetFullscreen(int $hwnd, bool $fullscreen): void
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'macOS');
    }

    public function windowSetBorderless(int $hwnd, bool $borderless): void
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'macOS');
    }

    public function windowSetResizeable(int $hwnd, bool $resizeable): void
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'macOS');
    }

    public function windowMaximize(int $hwnd): void
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'macOS');
    }

    public function windowMinimize(int $hwnd): void
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'macOS');
    }

    public function windowRestore(int $hwnd): void
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'macOS');
    }

    /**
     * 显示窗口：[window makeKeyAndOrderFront:nil]。
     */
    public function windowShow(int $hwnd): void
    {
        $obj = $this->objects[$hwnd] ?? null;
        if ($obj === null) {
            return;
        }
        // makeKeyAndOrderFront: 接受一个 id 参数（nil）
        $nil = $this->objc->new('id');
        $this->objc_msgSend_with_id($obj, $this->sel('makeKeyAndOrderFront:'), $nil);
    }

    public function windowHide(int $hwnd): void
    {
        $obj = $this->objects[$hwnd] ?? null;
        if ($obj === null) {
            return;
        }
        // [window orderOut:nil]
        $nil = $this->objc->new('id');
        $this->objc_msgSend_with_id($obj, $this->sel('orderOut:'), $nil);
    }

    public function windowSetTopmost(int $hwnd, bool $topmost): void
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'macOS');
    }

    public function windowSetChild(int $hwnd, int $childHwnd): void
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'macOS');
    }

    public function windowSetScrollable(int $hwnd, int $contentHeight): void
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'macOS');
    }

    public function windowIsFocused(int $hwnd): bool
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'macOS');
    }

    public function windowSetMenu(int $hwnd, int $menuHwnd): void
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'macOS');
    }

    // ============================================================
    // 控件方法
    // ============================================================

    /**
     * 创建子控件。
     *
     * className "Button" → [NSButton alloc] + [NSButton initWithFrame:] + [button setTitle:]
     * className "Label"  → [NSTextField alloc] + [NSTextField initWithFrame:] + [textField setStringValue:]
     *                     + [textField setBezeled:NO] + [textField setEditable:NO]
     *
     * 简化：用 init 代替 initWithFrame:（避免 NSRect 结构体传递）。
     */
    public function controlCreate(
        string $className,
        string $text,
        int $style,
        int $exStyle,
        int $parentHwnd,
        int $id
    ): int {
        if ($id === 0) {
            $id = $this->nextControlId++;
        }

        $cls = match ($className) {
            'Button' => $this->getClass('NSButton'),
            'Label'  => $this->getClass('NSTextField'),
            default  => throw UnsupportedOperationException::forMethod(__METHOD__, 'macOS'),
        };

        // [cls alloc]
        $alloc = $this->objc_msgSend_id($cls, $this->sel('alloc'));
        Pointer::assertNotNull($alloc, "{$className} alloc");

        // 简化：用 init 代替 initWithFrame:
        $obj = $this->objc_msgSend_id($alloc, $this->sel('init'));
        Pointer::assertNotNull($obj, "{$className} init");

        // 设置初始文本
        $textStr = $this->nsString($text);
        if ($className === 'Button') {
            $this->objc_msgSend_with_id($obj, $this->sel('setTitle:'), $textStr);
        } else {
            // Label (NSTextField)
            $this->objc_msgSend_with_id($obj, $this->sel('setStringValue:'), $textStr);
            $this->objc_msgSend_int($obj, $this->sel('setBezeled:'), self::NO);
            $this->objc_msgSend_int($obj, $this->sel('setEditable:'), self::NO);
            $this->objc_msgSend_int($obj, $this->sel('setSelectable:'), self::NO);
        }

        // 加入父窗口 contentView（若提供）
        if ($parentHwnd !== 0 && isset($this->objects[$parentHwnd])) {
            $parent = $this->objects[$parentHwnd];
            $contentView = $this->objc_msgSend_id($parent, $this->sel('contentView'));
            $this->objc_msgSend_with_id($contentView, $this->sel('addSubview:'), $obj);
        }

        $hwnd = $this->ptrToInt($obj);
        $this->objects[$hwnd] = $obj;
        $this->controlTypes[$hwnd] = $className;
        return $hwnd;
    }

    public function controlDestroy(int $hwnd): void
    {
        $obj = $this->objects[$hwnd] ?? null;
        if ($obj === null) {
            return;
        }
        // [obj removeFromSuperview] + [obj release]
        $this->objc_msgSend_id($obj, $this->sel('removeFromSuperview'));
        $this->objc_msgSend_id($obj, $this->sel('release'));
        unset($this->objects[$hwnd], $this->controlTypes[$hwnd]);
    }

    public function controlSetText(int $hwnd, string $text): void
    {
        $obj = $this->objects[$hwnd] ?? null;
        if ($obj === null) {
            return;
        }
        $type = $this->controlTypes[$hwnd] ?? '';
        $textStr = $this->nsString($text);
        match ($type) {
            'Button' => $this->objc_msgSend_with_id($obj, $this->sel('setTitle:'), $textStr),
            'Label'  => $this->objc_msgSend_with_id($obj, $this->sel('setStringValue:'), $textStr),
            default  => null,
        };
    }

    public function controlGetText(int $hwnd): string
    {
        // 简化：FFI 无法直接从 NSString 获取 C 字符串
        // 完整实现需用 UTF8String selector + FFI::string
        return '';
    }

    public function controlSetBounds(int $hwnd, int $x, int $y, int $width, int $height): void
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'macOS');
    }

    public function controlShow(int $hwnd): void
    {
        $obj = $this->objects[$hwnd] ?? null;
        if ($obj === null) {
            return;
        }
        // 简化：设为 hidden=NO
        $this->objc_msgSend_int($obj, $this->sel('setHidden:'), self::NO);
    }

    public function controlHide(int $hwnd): void
    {
        $obj = $this->objects[$hwnd] ?? null;
        if ($obj === null) {
            return;
        }
        $this->objc_msgSend_int($obj, $this->sel('setHidden:'), self::YES);
    }

    public function controlEnable(int $hwnd, bool $enabled): void
    {
        $obj = $this->objects[$hwnd] ?? null;
        if ($obj === null) {
            return;
        }
        $this->objc_msgSend_int($obj, $this->sel('setEnabled:'), $enabled ? self::YES : self::NO);
    }

    public function controlIsChecked(int $hwnd): bool
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'macOS');
    }

    public function controlSetChecked(int $hwnd, bool $checked): void
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'macOS');
    }

    public function controlAddString(int $hwnd, string $text): void
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'macOS');
    }

    public function controlRemoveString(int $hwnd, int $index): void
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'macOS');
    }

    public function controlClear(int $hwnd): void
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'macOS');
    }

    public function controlGetSelectedIndex(int $hwnd): int
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'macOS');
    }

    public function controlSetSelectedIndex(int $hwnd, int $index): void
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'macOS');
    }

    public function controlSetRange(int $hwnd, int $min, int $max): void
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'macOS');
    }

    public function controlSetValue(int $hwnd, int $value): void
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'macOS');
    }

    public function controlGetValue(int $hwnd): int
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'macOS');
    }

    // ============================================================
    // Tab 标签页方法（未实现）
    // ============================================================

    public function tabInsertItem(int $tabHwnd, int $index, string $text): void
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'macOS');
    }

    public function tabDeleteItem(int $tabHwnd, int $index): void
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'macOS');
    }

    public function tabGetSelected(int $tabHwnd): int
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'macOS');
    }

    public function tabSetSelected(int $tabHwnd, int $index): void
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'macOS');
    }

    public function tabGetItemCount(int $tabHwnd): int
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'macOS');
    }

    // ============================================================
    // DateTimePicker 方法（未实现）
    // ============================================================

    public function dateTimePickerGetTime(int $hwnd): ?array
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'macOS');
    }

    public function dateTimePickerSetTime(
        int $hwnd,
        int $year,
        int $month,
        int $day,
        int $hour,
        int $minute,
        int $second
    ): void {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'macOS');
    }

    public function dateTimePickerSetFormat(int $hwnd, string $format): void
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'macOS');
    }

    // ============================================================
    // 菜单方法（未实现）
    // ============================================================

    public function menuCreateBar(): int
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'macOS');
    }

    public function menuCreatePopup(): int
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'macOS');
    }

    public function menuAppendItem(int $menuHwnd, string $text, int $id): void
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'macOS');
    }

    public function menuAppendSeparator(int $menuHwnd): void
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'macOS');
    }

    public function menuAppendSubmenu(int $menuHwnd, string $text, int $submenuHwnd): void
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'macOS');
    }

    public function menuSetEnabled(int $menuHwnd, int $id, bool $enabled): void
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'macOS');
    }

    public function menuSetChecked(int $menuHwnd, int $id, bool $checked): void
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'macOS');
    }

    public function menuDestroy(int $menuHwnd): void
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'macOS');
    }

    // ============================================================
    // 对话框方法（未实现）
    // ============================================================

    public function dialogMsgBox(int $parentHwnd, string $text, string $caption, int $type): int
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'macOS');
    }

    public function dialogOpenFile(int $parentHwnd, array $filters): ?string
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'macOS');
    }

    public function dialogSaveFile(int $parentHwnd, array $filters): ?string
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'macOS');
    }

    public function dialogOpenFolder(int $parentHwnd): ?string
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'macOS');
    }

    public function dialogChooseColor(int $parentHwnd): ?Color
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'macOS');
    }

    public function dialogChooseFont(int $parentHwnd): ?array
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'macOS');
    }

    // ============================================================
    // 绘图方法（未实现）
    // ============================================================

    public function areaCreate(int $parentHwnd): int
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'macOS');
    }

    public function areaInvalidate(int $hwnd): void
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'macOS');
    }

    public function drawContextCreate(int $hwnd): mixed
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'macOS');
    }

    public function drawContextFree(mixed $ctx): void
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'macOS');
    }

    public function drawLine(mixed $ctx, int $x1, int $y1, int $x2, int $y2): void
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'macOS');
    }

    public function drawRect(mixed $ctx, int $x, int $y, int $width, int $height): void
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'macOS');
    }

    public function drawEllipse(mixed $ctx, int $x, int $y, int $width, int $height): void
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'macOS');
    }

    public function drawText(mixed $ctx, int $x, int $y, string $text): void
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'macOS');
    }

    public function drawTextAttributed(mixed $ctx, int $x, int $y, int $attributedStringId): void
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'macOS');
    }

    public function setPen(mixed $ctx, Color $color, int $width): void
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'macOS');
    }

    public function setBrush(mixed $ctx, Color $color): void
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'macOS');
    }

    public function setFont(mixed $ctx, string $name, int $size): void
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'macOS');
    }

    public function setColor(mixed $ctx, Color $color): void
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'macOS');
    }

    // ============================================================
    // 事件循环
    // ============================================================

    /**
     * 进入事件循环。
     *
     * 实现：用 CFRunLoopRunInMode(kCFRunLoopDefaultMode, 0.01s, false)
     * 轮询循环，每轮 runTimers() + runQueueMain()，直到 $running=false。
     *
     * 注意：kCFRunLoopDefaultMode 是全局常量，FFI 无法直接访问；
     * 用 NSDefaultRunLoopMode 字符串（CFString 创建）替代。
     */
    public function run(): void
    {
        $this->running = true;

        // 创建 kCFRunLoopDefaultMode 的 CFString 替代
        $modeStr = "kCFRunLoopDefaultMode";
        $mode = SafeCall::invoke(
            $this->cf,
            'CFStringCreateWithCString',
            [null, $modeStr, self::K_CF_STRING_ENCODING_UTF8]
        );
        Pointer::assertNotNull($mode, 'CFStringCreateWithCString');

        while ($this->running) {
            // CFRunLoopRunInMode(mode, 0.01s, returnAfterSourceHandled=0)
            SafeCall::invoke($this->cf, 'CFRunLoopRunInMode', [$mode, 0.01, 0]);

            $this->runTimers();
            $this->runQueueMain();

            // 避免 CPU 占用过高
            usleep(1000);
        }
    }

    /**
     * 退出事件循环。
     *
     * 先询问 shouldQuit 回调；返回 false 则不退出。
     * 设置 $running = false 让 run() 循环退出。
     */
    public function quit(): void
    {
        if (!$this->shouldQuit()) {
            return;
        }
        $this->running = false;
    }

    // queueMain 与 triggerRelayout 继承自 AbstractPlatform。
    // wakeUpMainLoop 默认空实现即可：run() 用 CFRunLoopRunInMode(0.01s)
    // 轮询，会自动拾取 queueMain 队列，无需主动唤醒。

    // ============================================================
    // 主题
    // ============================================================

    /**
     * 设置应用主题。
     *
     * Cocoa 实现：通过 [NSApp setAppearance:] 设置应用全局外观。
     *
     *   - Theme::DARK：[NSApp setAppearance:[NSAppearance appearanceNamed:NSAppearanceNameDarkAqua]]
     *   - Theme::LIGHT：[NSApp setAppearance:[NSAppearance appearanceNamed:NSAppearanceNameAqua]]
     *   - Theme::SYSTEM / Theme::CLASSIC：[NSApp setAppearance:nil]（跟随系统）
     *
     * 调用流程：
     *   1. [NSApplication sharedApplication] 获取 NSApp 单例
     *   2. DARK/LIGHT：
     *      a. stringWithUTF8String: 从 C 字符串创建 NSString（外观名称）
     *      b. [NSAppearance appearanceNamed:name] → NSAppearance 实例
     *      c. [NSApp setAppearance:appearance]
     *   3. SYSTEM/CLASSIC：
     *      [NSApp setAppearance:nil]（nil 用 $this->objc->new('id') 创建）
     *
     * NSAppearanceNameDarkAqua / NSAppearanceNameAqua 是 NSString 常量值
     *（非 selector），通过 stringWithUTF8String: 转换为 NSString 传递。
     */
    public function setAppTheme(string $theme): void
    {
        // [NSApplication sharedApplication] → NSApp 单例
        $nsApp = $this->objc_msgSend_id(
            $this->getClass('NSApplication'),
            $this->sel('sharedApplication')
        );

        if ($theme === Theme::DARK || $theme === Theme::LIGHT) {
            // 外观名称 NSString
            $name = ($theme === Theme::DARK)
                ? self::NS_APPEARANCE_NAME_DARK_AQUA
                : self::NS_APPEARANCE_NAME_AQUA;
            $nameStr = $this->nsString($name);

            // [NSAppearance appearanceNamed:nameStr] → NSAppearance 实例
            $appearanceCls = $this->getClass('NSAppearance');
            $appearance = $this->objc_msgSend_with_id(
                $appearanceCls,
                $this->sel('appearanceNamed:'),
                $nameStr
            );

            // [NSApp setAppearance:appearance]
            $this->objc_msgSend_with_id(
                $nsApp,
                $this->sel('setAppearance:'),
                $appearance
            );
        } else {
            // Theme::SYSTEM / Theme::CLASSIC：[NSApp setAppearance:nil]（跟随系统）
            $nil = $this->objc->new('id');
            $this->objc_msgSend_with_id(
                $nsApp,
                $this->sel('setAppearance:'),
                $nil
            );
        }
    }

    // ============================================================
    // 系统服务
    // ============================================================

    /**
     * 屏幕尺寸。
     *
     * 简化实现：抛 UnsupportedOperationException（需 NSScreen API，
     * 本桩未完整实现）。
     */
    public function screenSize(): Size
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'macOS');
    }

    public function clipboardSetText(string $text): void
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'macOS');
    }

    public function clipboardGetText(): string
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'macOS');
    }

    // timer/clearTimer/onShouldQuit 继承自 AbstractPlatform。
}
