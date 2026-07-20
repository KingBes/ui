<?php
declare(strict_types=1);

namespace Kingbes\Ui\Platform\Macos;

use Kingbes\Ui\Exception\UiException;
use Kingbes\Ui\Platform\Platform;
use Kingbes\Phpc\Library;
use Kingbes\Phpc\SafeCall;
use Kingbes\Phpc\Pointer;
use FFI\CData;

/**
 * macOS Cocoa 平台后端。
 *
 * 通过 FFI 调用 libobjc.dylib 的 ObjC runtime 实现 Cocoa GUI 原始操作。
 * 每一个 ObjC 方法调用对应一次 objc_msgSend；选择器（SEL）通过
 * sel_registerName 注册并缓存。
 *
 * 回调桥接策略：
 *   - 运行时动态创建 PhpUiTarget 类（继承 NSObject），为其添加 invoke: 方法
 *   - invoke: 的 IMP 由 PHP 闭包充当（FFI 自动将闭包转为函数指针）
 *   - 闭包必须存入 $this->closures 注册表防止 PHP GC 回收，否则 ObjC
 *     触发悬垂闭包会段错误
 *
 * 已知限制：
 *   - windowGetPosition 返回 [0,0]：取 NSWindow.frame 需 objc_msgSend_stret，
 *     PHP FFI 不直接支持结构体返回，此处简化处理
 *   - layoutBox 使用固定 100x100 默认尺寸：读取 [view frame] 同样需要 stret，
 *     真实场景需在 windowSetChild 时根据父窗口尺寸触发 layoutBox
 *
 * 句柄类型在签名中标注为 mixed，实际为 \FFI\CData（id、NSWindow、NSView 等指针）。
 */
class CocoaPlatform extends Platform
{
    /** @var \FFI|null ObjC runtime FFI 实例 */
    private ?\FFI $objc = null;

    /** @var CData|null NSApplication 单例 */
    private ?CData $nsApp = null;

    /** @var CData|null 缓存的常用 ObjC Class 对象 */
    private ?CData $clsNSApplication = null;
    private ?CData $clsNSWindow = null;
    private ?CData $clsNSView = null;
    private ?CData $clsNSButton = null;
    private ?CData $clsNSTextField = null;
    private ?CData $clsNSBox = null;
    private ?CData $clsNSString = null;
    private ?CData $clsNSDate = null;
    private ?CData $clsNSRunLoop = null;
    private ?CData $clsNSNotificationCenter = null;
    private ?CData $clsNSTabView = null;
    private ?CData $clsNSTabViewItem = null;
    private ?CData $clsNSObject = null;

    /** @var CData|null 动态注册的 PhpUiTarget 类对象 */
    private ?CData $clsPhpUiTarget = null;

    /** @var CData|null 单例 helper target 实例，用于按钮等控件的 setTarget: */
    private ?CData $helperTarget = null;

    /** @var array<string, CData> 缓存的 SEL：选择器名 → CData(SEL) */
    private array $sels = [];

    /** @var array<string, \Closure> 闭包注册表：spl_object_id(target).':action' → Closure */
    private array $closures = [];

    /** @var array<int, array> Box 状态：spl_object_id(view) → ['horizontal','children','padded'] */
    private array $boxes = [];

    /** @var array<int, array> Form 状态：spl_object_id(view) → ['children','padded'] */
    private array $forms = [];

    /** @var array<int, array> Grid 状态：spl_object_id(view) → ['cells','padded'] */
    private array $grids = [];

    /** @var array<int, mixed> 定时器注册表：timer id → [cb, timer CData] */
    private array $timers = [];

    /** @var int 定时器内部计数器 */
    private static int $nextTimerId = 1;

    /* ==============================================================
     * 初始化与 FFI 加载
     * ============================================================ */

    /**
     * 初始化 Cocoa 后端：加载 libobjc FFI、缓存常用 Class、创建 helper target、
     * 获取 NSApplication 单例。
     *
     * @throws UiException 当 FFI 加载或 ObjC 类获取失败时
     */
    public function init(): void
    {
        if ($this->objc !== null) {
            return; // 已初始化
        }

        Library::permit('libobjc.dylib');

        $header = <<<C
typedef void* id;
typedef void* Class;
typedef void* SEL;
typedef long NSInteger;
typedef unsigned long NSUInteger;
typedef long BOOL;
typedef struct { double x, y, width, height; } NSRect;
typedef struct { double x, y; } NSPoint;
typedef struct { double width, height; } NSSize;

/* PHP 闭包转 IMP 的函数指针类型：void (id, SEL, id) */
typedef void (*PhpUiIMP)(id, SEL, id);

id objc_getClass(const char *name);
SEL sel_registerName(const char *name);
id objc_msgSend(id self, SEL op, ...);
Class objc_allocateClassPair(Class superclass, const char *name, size_t extraBytes);
void objc_registerClassPair(Class cls);
BOOL class_addMethod(Class cls, SEL name, PhpUiIMP imp, const char *types);
id class_createInstance(Class cls, size_t extraBytes);
Class object_getClass(id obj);
C;

        try {
            $this->objc = Library::load('libobjc.dylib', $header);
        } catch (\Throwable $e) {
            throw new UiException("Failed to load libobjc.dylib: " . $e->getMessage(), 0, $e);
        }

        // 缓存常用 ObjC Class
        $this->clsNSObject = SafeCall::expectNotNull($this->objc, 'objc_getClass', ["NSObject"]);
        $this->clsNSApplication = SafeCall::expectNotNull($this->objc, 'objc_getClass', ["NSApplication"]);
        $this->clsNSWindow = SafeCall::expectNotNull($this->objc, 'objc_getClass', ["NSWindow"]);
        $this->clsNSView = SafeCall::expectNotNull($this->objc, 'objc_getClass', ["NSView"]);
        $this->clsNSButton = SafeCall::expectNotNull($this->objc, 'objc_getClass', ["NSButton"]);
        $this->clsNSTextField = SafeCall::expectNotNull($this->objc, 'objc_getClass', ["NSTextField"]);
        $this->clsNSBox = SafeCall::expectNotNull($this->objc, 'objc_getClass', ["NSBox"]);
        $this->clsNSString = SafeCall::expectNotNull($this->objc, 'objc_getClass', ["NSString"]);
        $this->clsNSDate = SafeCall::expectNotNull($this->objc, 'objc_getClass', ["NSDate"]);
        $this->clsNSRunLoop = SafeCall::expectNotNull($this->objc, 'objc_getClass', ["NSRunLoop"]);
        $this->clsNSNotificationCenter = SafeCall::expectNotNull($this->objc, 'objc_getClass', ["NSNotificationCenter"]);
        $this->clsNSTabView = SafeCall::expectNotNull($this->objc, 'objc_getClass', ["NSTabView"]);
        $this->clsNSTabViewItem = SafeCall::expectNotNull($this->objc, 'objc_getClass', ["NSTabViewItem"]);

        // 创建 helper target 类（用于 setTarget/setAction 回调桥接）
        $this->createHelperTargetClass();

        // [NSApplication sharedApplication]
        $this->nsApp = $this->msg($this->clsNSApplication, 'sharedApplication');
        // [NSApp finishLaunching]
        $this->msg($this->nsApp, 'finishLaunching');
    }

    /**
     * 创建 helper target 类 PhpUiTarget。
     *
     * ObjC runtime 的 class_addMethod 接受 IMP（函数指针）。PHP FFI 可将闭包
     * 自动转为函数指针（参考 phpc 示例 16 把闭包作为 WindowProc）。
     *
     * 策略：
     *   - 以 NSObject 为父类动态注册 PhpUiTarget
     *   - 添加 invoke: 方法，IMP 签名 "v@:@" 即 void (id, SEL, id)
     *   - 若类已存在（多次运行同进程），直接获取已注册的类
     *   - 创建单例 helperTarget 实例供按钮等控件复用
     */
    private function createHelperTargetClass(): void
    {
        $targetCls = SafeCall::invoke($this->objc, 'objc_allocateClassPair', [
            $this->clsNSObject, "PhpUiTarget", 0,
        ]);

        if ($targetCls === null || Pointer::isNull($targetCls)) {
            // 类已存在（同进程多次运行），获取已注册的类
            $targetCls = SafeCall::expectNotNull($this->objc, 'objc_getClass', ["PhpUiTarget"]);
        } else {
            // 注册 invoke: 方法：IMP 为 PHP 闭包
            $selInvoke = $this->sel('invoke:');
            $imp = function ($self, $cmd, $sender) {
                $key = spl_object_id($sender) . ':action';
                if (isset($this->closures[$key])) {
                    ($this->closures[$key])($sender);
                }
            };
            // IMP 签名 "v@:@" 表示 void (id self, SEL cmd, id arg)
            SafeCall::invoke($this->objc, 'class_addMethod', [
                $targetCls, $selInvoke, $imp, "v@:@",
            ]);
            SafeCall::invoke($this->objc, 'objc_registerClassPair', [$targetCls]);
        }

        $this->clsPhpUiTarget = $targetCls;

        // 创建单例 helper 实例：[PhpUiTarget alloc] init]
        $this->helperTarget = $this->msg(
            $this->msg($this->clsPhpUiTarget, 'alloc'),
            'init'
        );
    }

    /* ==============================================================
     * ObjC runtime helpers
     * ============================================================ */

    /**
     * 注册并缓存选择器。
     *
     * @param string $name 选择器名（如 "sharedApplication"）
     * @return CData SEL 对象
     */
    private function sel(string $name): CData
    {
        return $this->sels[$name] ??= SafeCall::invoke(
            $this->objc, 'sel_registerName', [$name]
        );
    }

    /**
     * 发送 ObjC 消息：objc_msgSend(self, sel, ...args)。
     *
     * @param mixed           $obj  接收者（id/Class）
     * @param CData|string    $sel  选择器（CData 或字符串名）
     * @param array<int, mixed> $args 变参列表
     * @return mixed 返回值（通常为 CData id）
     */
    private function msg(mixed $obj, CData|string $sel, array $args = []): mixed
    {
        if (is_string($sel)) {
            $sel = $this->sel($sel);
        }
        return SafeCall::invoke(
            $this->objc, 'objc_msgSend',
            array_merge([$obj, $sel], $args)
        );
    }

    /**
     * 从 PHP 字符串创建 NSString。
     *
     * @param string $s PHP 字符串
     * @return CData NSString 实例
     */
    private function makeNSString(string $s): CData
    {
        // [NSString stringWithCString:encoding:]，NSUTF8StringEncoding = 4
        return $this->msg($this->clsNSString, 'stringWithCString:encoding:', [$s, 4]);
    }

    /**
     * 将 NSString 转为 PHP 字符串。
     *
     * @param CData $str NSString 实例
     * @return string PHP 字符串
     */
    private function nsStringToPhp(CData $str): string
    {
        $cstr = $this->msg($str, 'UTF8String');
        if ($cstr === null || ($cstr instanceof CData && Pointer::isNull($cstr))) {
            return '';
        }
        return \FFI::string($cstr);
    }

    /**
     * 创建 NSRect 结构体。
     *
     * @param float $x      X 坐标
     * @param float $y      Y 坐标
     * @param float $width  宽度
     * @param float $height 高度
     * @return CData NSRect
     */
    private function makeRect(float $x, float $y, float $width, float $height): CData
    {
        $rect = $this->objc->new('NSRect');
        $rect->x = $x;
        $rect->y = $y;
        $rect->width = $width;
        $rect->height = $height;
        return $rect;
    }

    /**
     * 创建 NSSize 结构体。
     *
     * @param float $width  宽度
     * @param float $height 高度
     * @return CData NSSize
     */
    private function makeSize(float $width, float $height): CData
    {
        $size = $this->objc->new('NSSize');
        $size->width = $width;
        $size->height = $height;
        return $size;
    }

    /**
     * 创建 NSPoint 结构体。
     *
     * @param float $x X 坐标
     * @param float $y Y 坐标
     * @return CData NSPoint
     */
    private function makePoint(float $x, float $y): CData
    {
        $point = $this->objc->new('NSPoint');
        $point->x = $x;
        $point->y = $y;
        return $point;
    }

    /**
     * 将 objc_msgSend 返回的 id 解释为整数（用于 state 等整数返回值）。
     *
     * @param mixed $val objc_msgSend 返回值
     * @return int 整数值
     */
    private function asInt(mixed $val): int
    {
        if ($val === null) {
            return 0;
        }
        if ($val instanceof CData) {
            if (\FFI::isNull($val)) {
                return 0;
            }
            return (int) \FFI::cast('intptr_t', $val);
        }
        return (int) $val;
    }

    /* ==============================================================
     * 生命周期
     * ============================================================ */

    /**
     * 进入 Cocoa 主事件循环（阻塞直至所有窗口关闭或调用 quit）。
     */
    public function main(): void
    {
        $this->msg($this->nsApp, 'run');
    }

    /**
     * 执行一次事件循环迭代。
     *
     * 用 NSRunLoop runUntilDate: 实现非阻塞/可阻塞的单步迭代。
     *
     * @param bool $wait 是否阻塞等待事件
     * @return bool 是否仍有事件需要处理
     */
    public function mainStep(bool $wait = false): bool
    {
        $rl = $this->msg($this->clsNSRunLoop, 'currentRunLoop');
        $interval = $wait ? 0.1 : 0.001;
        $date = $this->msg($this->clsNSDate, 'dateWithTimeIntervalSinceNow:', [$interval]);
        $this->msg($rl, 'runUntilDate:', [$date]);
        return true;
    }

    /**
     * 退出主事件循环。
     */
    public function quit(): void
    {
        $this->msg($this->nsApp, 'terminate:', [null]);
    }

    /**
     * 释放后端资源。Cocoa 无显式 uninit，仅清理 PHP 端注册表引用。
     */
    public function uninit(): void
    {
        // 清理 PHP 端引用，防止闭包悬垂
        $this->closures = [];
        $this->boxes = [];
        $this->forms = [];
        $this->grids = [];
        $this->timers = [];
        $this->sels = [];
        $this->helperTarget = null;
        $this->nsApp = null;
        $this->clsPhpUiTarget = null;
        $this->objc = null;
    }

    /**
     * 注册定时器。
     *
     * 用 NSTimer scheduledTimerWithTimeInterval:target:selector:userInfo:repeats:
     * 实现。每个 timer 使用独立的 helper target 实例，闭包存入 $this->closures
     * 注册表防 GC。
     *
     * 用户闭包返回 bool，true 继续 false 停止（停止时从注册表移除）。
     *
     * @param int      $ms 间隔毫秒数
     * @param \Closure $cb  回调函数
     */
    public function timer(int $ms, \Closure $cb): void
    {
        $id = self::$nextTimerId++;
        $this->timers[$id] = $cb;

        // 创建独立 target，闭包存到 closures 表
        $target = $this->msg($this->msg($this->clsPhpUiTarget, 'alloc'), 'init');
        $this->closures[spl_object_id($target) . ':action'] = function () use ($cb, $id) {
            $continue = (bool) $cb();
            if (!$continue) {
                unset($this->timers[$id]);
                // 简化：不主动 invalidate timer，依赖 GC
            }
        };

        $clsNSTimer = SafeCall::expectNotNull($this->objc, 'objc_getClass', ["NSTimer"]);
        // [NSTimer scheduledTimerWithTimeInterval:target:selector:userInfo:repeats:]
        $interval = $ms / 1000.0;
        $selInvoke = $this->sel('invoke:');
        $timer = $this->msg($clsNSTimer, 'scheduledTimerWithTimeInterval:target:selector:userInfo:repeats:', [
            $interval, $target, $selInvoke, null, 1, // repeats = YES
        ]);
        // timer 也需保持引用防 GC
        $this->timers[$id . ':timer'] = $timer;
    }

    /* ==============================================================
     * 窗口
     * ============================================================ */

    /**
     * 创建顶层窗口。
     *
     * @param string $title 窗口标题
     * @param int    $w     宽度
     * @param int    $h     高度
     * @return mixed 窗口句柄
     */
    public function windowCreate(string $title, int $w, int $h): mixed
    {
        $win = $this->msg($this->clsNSWindow, 'alloc');
        $rect = $this->makeRect(0.0, 0.0, (float) $w, (float) $h);
        // styleMask: NSTitledWindowMask(1) | NSClosableWindowMask(2)
        //            | NSMiniaturizableWindowMask(4) | NSResizableWindowMask(8) = 15
        // backing: NSBackingStoreBuffered = 2
        // defer: NO = 0
        $win = $this->msg($win, 'initWithContentRect:styleMask:backing:defer:', [
            $rect, 15, 2, 0,
        ]);
        $this->msg($win, 'setTitle:', [$this->makeNSString($title)]);
        return $win;
    }

    public function windowSetTitle(mixed $h, string $t): void
    {
        $this->msg($h, 'setTitle:', [$this->makeNSString($t)]);
    }

    public function windowSetSize(mixed $h, int $w, int $height): void
    {
        $size = $this->makeSize((float) $w, (float) $height);
        $this->msg($h, 'setContentSize:', [$size]);
    }

    public function windowSetPosition(mixed $h, int $x, int $y): void
    {
        $point = $this->makePoint((float) $x, (float) $y);
        $this->msg($h, 'setFrameOrigin:', [$point]);
    }

    /**
     * 获取窗口位置。
     *
     * 简化实现：取 NSWindow.frame 需要 objc_msgSend_stret（结构体返回），
     * PHP FFI 不直接支持。此处返回 [0,0]。
     *
     * @param mixed $h 窗口句柄
     * @return array 形如 ['x' => int, 'y' => int]
     */
    public function windowGetPosition(mixed $h): array
    {
        // stret 限制：无法通过 FFI 直接获取 NSWindow.frame
        return ['x' => 0, 'y' => 0];
    }

    public function windowSetChild(mixed $h, mixed $child): void
    {
        $this->msg($h, 'setContentView:', [$child]);
        // 若 child 是 Box（NSView），触发 layoutBox
        $key = spl_object_id($child);
        if (isset($this->boxes[$key])) {
            $this->layoutBox($child);
        }
    }

    public function windowShow(mixed $h): void
    {
        $this->msg($h, 'makeKeyAndOrderFront:', [null]);
    }

    public function windowHide(mixed $h): void
    {
        $this->msg($h, 'orderOut:', [null]);
    }

    /**
     * 注册窗口关闭回调。
     *
     * 通过 NSNotificationCenter 监听 NSWindowWillCloseNotification。
     * libui 语义：闭包返回 true 允许关闭；Cocoa 无阻止关闭机制，直接调用 cb。
     *
     * @param mixed    $h  窗口句柄
     * @param \Closure $cb 回调函数
     */
    public function windowOnClosing(mixed $h, \Closure $cb): void
    {
        $nc = $this->msg($this->clsNSNotificationCenter, 'defaultCenter');
        $target = $this->msg($this->msg($this->clsPhpUiTarget, 'alloc'), 'init');
        $this->closures[spl_object_id($target) . ':action'] = function () use ($cb, $h) {
            $cb($h);
        };
        $name = $this->makeNSString('NSWindowWillCloseNotification');
        $this->msg($nc, 'addObserver:selector:name:object:', [
            $target, $this->sel('invoke:'), $name, $h,
        ]);
    }

    /**
     * 注册窗口尺寸变化回调。
     *
     * 通过 NSNotificationCenter 监听 NSWindowDidResizeNotification。
     *
     * @param mixed    $h  窗口句柄
     * @param \Closure $cb 回调函数
     */
    public function windowOnResize(mixed $h, \Closure $cb): void
    {
        $nc = $this->msg($this->clsNSNotificationCenter, 'defaultCenter');
        $target = $this->msg($this->msg($this->clsPhpUiTarget, 'alloc'), 'init');
        $this->closures[spl_object_id($target) . ':action'] = function () use ($cb, $h) {
            $cb($h);
        };
        $name = $this->makeNSString('NSWindowDidResizeNotification');
        $this->msg($nc, 'addObserver:selector:name:object:', [
            $target, $this->sel('invoke:'), $name, $h,
        ]);
    }

    public function windowDestroy(mixed $h): void
    {
        $this->msg($h, 'close');
        $this->msg($h, 'release');
    }

    /* ==============================================================
     * 通用控件
     * ============================================================ */

    public function controlShow(mixed $h): void
    {
        $this->msg($h, 'setHidden:', [0]); // NO
    }

    public function controlHide(mixed $h): void
    {
        $this->msg($h, 'setHidden:', [1]); // YES
    }

    public function controlEnable(mixed $h): void
    {
        $this->msg($h, 'setEnabled:', [1]); // YES
    }

    public function controlDisable(mixed $h): void
    {
        $this->msg($h, 'setEnabled:', [0]); // NO
    }

    public function controlDestroy(mixed $h): void
    {
        $this->msg($h, 'release');
        // 清理以 spl_object_id($h).':' 为前缀的闭包条目
        // （button/checkbox 的 :action 用 control id 作为 key，可被清理；
        //  windowOnClosing/windowOnResize/entryOnChanged 使用独立 helper target 的 id，
        //  无法通过 control id 清理，属已知简化。）
        $prefix = spl_object_id($h) . ':';
        foreach (array_keys($this->closures) as $k) {
            if (str_starts_with($k, $prefix)) {
                unset($this->closures[$k]);
            }
        }
        // 清理 Form/Grid 注册表（若 $h 是 form/grid 容器）
        $key = spl_object_id($h);
        if (isset($this->forms[$key])) {
            // 释放 Form 内部创建的 label 控件（child 由调用方管理）
            foreach ($this->forms[$key]['children'] as $c) {
                $this->msg($c['label'], 'release');
            }
            unset($this->forms[$key]);
        }
        unset($this->grids[$key]);
    }

    /* ==============================================================
     * Button
     * ============================================================ */

    public function buttonCreate(string $text): mixed
    {
        $btn = $this->msg($this->clsNSButton, 'alloc');
        $rect = $this->makeRect(0.0, 0.0, 100.0, 30.0);
        $btn = $this->msg($btn, 'initWithFrame:', [$rect]);
        $this->msg($btn, 'setTitle:', [$this->makeNSString($text)]);
        return $btn;
    }

    public function buttonGetText(mixed $h): string
    {
        $title = $this->msg($h, 'title');
        if ($title === null || !($title instanceof CData) || Pointer::isNull($title)) {
            return '';
        }
        return $this->nsStringToPhp($title);
    }

    public function buttonSetText(mixed $h, string $t): void
    {
        $this->msg($h, 'setTitle:', [$this->makeNSString($t)]);
    }

    /**
     * 注册按钮点击回调。
     *
     * 使用单例 helperTarget 作为 setTarget:，setAction: 设为 invoke:。
     * 闭包以 spl_object_id(handle) 为键存入注册表。
     *
     * @param mixed    $h  按钮句柄
     * @param \Closure $cb 回调函数
     */
    public function buttonOnClicked(mixed $h, \Closure $cb): void
    {
        $this->msg($h, 'setTarget:', [$this->helperTarget]);
        $this->msg($h, 'setAction:', [$this->sel('invoke:')]);
        $this->closures[spl_object_id($h) . ':action'] = $cb;
    }

    /* ==============================================================
     * Label
     * ============================================================ */

    /**
     * 创建标签。
     *
     * 用 NSTextField 模拟：设置不可编辑、无边框、无背景、不可选择。
     *
     * @param string $text 标签文本
     * @return mixed 标签句柄
     */
    public function labelCreate(string $text): mixed
    {
        $label = $this->msg($this->clsNSTextField, 'alloc');
        $rect = $this->makeRect(0.0, 0.0, 100.0, 20.0);
        $label = $this->msg($label, 'initWithFrame:', [$rect]);
        $this->msg($label, 'setEditable:', [0]);
        $this->msg($label, 'setBezeled:', [0]);
        $this->msg($label, 'setDrawsBackground:', [0]);
        $this->msg($label, 'setSelectable:', [0]);
        $this->msg($label, 'setStringValue:', [$this->makeNSString($text)]);
        return $label;
    }

    public function labelGetText(mixed $h): string
    {
        $val = $this->msg($h, 'stringValue');
        if ($val === null || !($val instanceof CData) || Pointer::isNull($val)) {
            return '';
        }
        return $this->nsStringToPhp($val);
    }

    public function labelSetText(mixed $h, string $t): void
    {
        $this->msg($h, 'setStringValue:', [$this->makeNSString($t)]);
    }

    /* ==============================================================
     * Entry
     * ============================================================ */

    /**
     * 创建单行输入框。
     *
     * 用 NSTextField（不调 setEditable:NO 即可编辑）。
     *
     * @return mixed 输入框句柄
     */
    public function entryCreate(): mixed
    {
        $field = $this->msg($this->clsNSTextField, 'alloc');
        $rect = $this->makeRect(0.0, 0.0, 100.0, 25.0);
        $field = $this->msg($field, 'initWithFrame:', [$rect]);
        return $field;
    }

    public function entryGetText(mixed $h): string
    {
        $val = $this->msg($h, 'stringValue');
        if ($val === null || !($val instanceof CData) || Pointer::isNull($val)) {
            return '';
        }
        return $this->nsStringToPhp($val);
    }

    public function entrySetText(mixed $h, string $t): void
    {
        $this->msg($h, 'setStringValue:', [$this->makeNSString($t)]);
    }

    /**
     * 注册输入框内容变化回调。
     *
     * 通过 NSNotificationCenter 监听 NSControlTextDidChangeNotification。
     *
     * @param mixed    $h  输入框句柄
     * @param \Closure $cb 回调函数
     */
    public function entryOnChanged(mixed $h, \Closure $cb): void
    {
        $nc = $this->msg($this->clsNSNotificationCenter, 'defaultCenter');
        $target = $this->msg($this->msg($this->clsPhpUiTarget, 'alloc'), 'init');
        $this->closures[spl_object_id($target) . ':action'] = function () use ($cb, $h) {
            $cb($h);
        };
        $name = $this->makeNSString('NSControlTextDidChangeNotification');
        $this->msg($nc, 'addObserver:selector:name:object:', [
            $target, $this->sel('invoke:'), $name, $h,
        ]);
    }

    public function entrySetReadOnly(mixed $h, bool $ro): void
    {
        // setEditable: true 表示可编辑，ro 时传 0
        $this->msg($h, 'setEditable:', [$ro ? 0 : 1]);
    }

    /* ==============================================================
     * Checkbox
     * ============================================================ */

    /**
     * 创建复选框。
     *
     * 用 NSButton + setButtonType: NSSwitchButton (3)。
     *
     * @param string $text 复选框文本
     * @return mixed 复选框句柄
     */
    public function checkboxCreate(string $text): mixed
    {
        $btn = $this->msg($this->clsNSButton, 'alloc');
        $rect = $this->makeRect(0.0, 0.0, 100.0, 25.0);
        $btn = $this->msg($btn, 'initWithFrame:', [$rect]);
        // NSSwitchButton = 3
        $this->msg($btn, 'setButtonType:', [3]);
        $this->msg($btn, 'setTitle:', [$this->makeNSString($text)]);
        return $btn;
    }

    public function checkboxGetText(mixed $h): string
    {
        $title = $this->msg($h, 'title');
        if ($title === null || !($title instanceof CData) || Pointer::isNull($title)) {
            return '';
        }
        return $this->nsStringToPhp($title);
    }

    public function checkboxSetText(mixed $h, string $t): void
    {
        $this->msg($h, 'setTitle:', [$this->makeNSString($t)]);
    }

    /**
     * 查询复选框是否选中。
     *
     * [btn state] 返回 NSInteger，0 = NSOffState，1 = NSOnState。
     * 因 objc_msgSend 声明返回 id，需将指针解释为整数。
     *
     * @param mixed $h 复选框句柄
     * @return bool 是否选中
     */
    public function checkboxIsChecked(mixed $h): bool
    {
        $state = $this->msg($h, 'state');
        return $this->asInt($state) !== 0;
    }

    public function checkboxSetChecked(mixed $h, bool $c): void
    {
        $this->msg($h, 'setState:', [$c ? 1 : 0]);
    }

    /**
     * 注册复选框状态切换回调。
     *
     * 复选框点击即触发 action（同 Button）。
     *
     * @param mixed    $h  复选框句柄
     * @param \Closure $cb 回调函数
     */
    public function checkboxOnToggled(mixed $h, \Closure $cb): void
    {
        $this->msg($h, 'setTarget:', [$this->helperTarget]);
        $this->msg($h, 'setAction:', [$this->sel('invoke:')]);
        $this->closures[spl_object_id($h) . ':action'] = $cb;
    }

    /* ==============================================================
     * Box
     * ============================================================ */

    /**
     * 创建容器盒子。
     *
     * 用 NSView 作为容器，PHP 端维护子控件列表与布局状态。
     *
     * @param bool $horizontal true 为水平布局，false 为垂直布局
     * @return mixed 盒子句柄
     */
    public function boxCreate(bool $horizontal): mixed
    {
        $view = $this->msg($this->clsNSView, 'alloc');
        $rect = $this->makeRect(0.0, 0.0, 100.0, 100.0);
        $view = $this->msg($view, 'initWithFrame:', [$rect]);
        $this->boxes[spl_object_id($view)] = [
            'horizontal' => $horizontal,
            'children'   => [],
            'padded'     => false,
        ];
        return $view;
    }

    public function boxAppend(mixed $h, mixed $child, bool $stretchy): void
    {
        $key = spl_object_id($h);
        if (!isset($this->boxes[$key])) {
            throw new UiException("boxAppend: invalid box handle (spl_object_id=$key)");
        }
        $this->boxes[$key]['children'][] = [
            'handle'   => $child,
            'stretchy' => $stretchy,
        ];
        $this->msg($h, 'addSubview:', [$child]);
        $this->layoutBox($h);
    }

    /**
     * 从盒子移除指定索引位置的子控件。
     *
     * @param mixed $h     盒子句柄
     * @param int   $index 子控件索引
     * @throws UiException box 未跟踪或索引越界
     */
    public function boxRemove(mixed $h, int $index): void
    {
        $key = spl_object_id($h);
        if (!isset($this->boxes[$key])) {
            throw new UiException("boxRemove: box not tracked (spl_object_id=$key)");
        }
        $children = $this->boxes[$key]['children'];
        if (!array_key_exists($index, $children)) {
            throw new UiException("boxRemove: invalid index $index");
        }
        $child = $children[$index]['handle'];
        $this->msg($child, 'removeFromSuperview');
        // 保持索引连续
        array_splice($this->boxes[$key]['children'], $index, 1);
        $this->layoutBox($h);
    }

    public function boxSetPadded(mixed $h, bool $p): void
    {
        $key = spl_object_id($h);
        if (!isset($this->boxes[$key])) {
            throw new UiException("boxSetPadded: box not tracked (spl_object_id=$key)");
        }
        $this->boxes[$key]['padded'] = $p;
        $this->layoutBox($h);
    }

    /**
     * 重新布局 Box 子控件。
     *
     * 简化限制：因读取 [view frame] 需 objc_msgSend_stret（PHP FFI 不直接支持），
     * 此处使用固定默认尺寸 100x100。stretchy 子项均分剩余空间，非 stretchy
     * 子项水平布局固定 80 宽、垂直布局固定 25 高。
     *
     * 真实场景中需在 windowSetChild 时根据父窗口尺寸触发 layoutBox。
     *
     * @param mixed $h 盒子句柄
     */
    private function layoutBox(mixed $h): void
    {
        $key = spl_object_id($h);
        $box = $this->boxes[$key] ?? null;
        if (!$box || empty($box['children'])) {
            return;
        }

        $w = 100;
        $hgt = 100;
        $padded = $box['padded'] ? 4 : 0;
        $n = count($box['children']);
        $stretchyCount = count(array_filter(
            $box['children'],
            fn ($c) => $c['stretchy']
        ));
        $nonStretchy = $n - $stretchyCount;

        $selSetFrame = $this->sel('setFrame:');

        if ($box['horizontal']) {
            $stretchyW = $stretchyCount > 0
                ? ($w - ($n - 1) * $padded - $nonStretchy * 80) / $stretchyCount
                : 0;
            $x = 0.0;
            foreach ($box['children'] as $c) {
                $cw = $c['stretchy'] ? (float) $stretchyW : 80.0;
                $rect = $this->makeRect($x, 0.0, $cw, (float) $hgt);
                $this->msg($c['handle'], $selSetFrame, [$rect]);
                $x += $cw + $padded;
            }
        } else {
            $stretchyH = $stretchyCount > 0
                ? ($hgt - ($n - 1) * $padded - $nonStretchy * 25) / $stretchyCount
                : 0;
            $y = 0.0;
            foreach ($box['children'] as $c) {
                $ch = $c['stretchy'] ? (float) $stretchyH : 25.0;
                $rect = $this->makeRect(0.0, $y, (float) $w, $ch);
                $this->msg($c['handle'], $selSetFrame, [$rect]);
                $y += $ch + $padded;
            }
        }
    }

    /* ==============================================================
     * Separator
     * ============================================================ */

    /**
     * 创建分隔线。
     *
     * 用 NSBox + setBoxType: NSBoxSeparator (2)。
     *
     * @param bool $horizontal true 为水平分隔线，false 为垂直分隔线
     * @return mixed 分隔线句柄
     */
    public function separatorCreate(bool $horizontal): mixed
    {
        $box = $this->msg($this->clsNSBox, 'alloc');
        if ($horizontal) {
            $rect = $this->makeRect(0.0, 0.0, 100.0, 2.0);
        } else {
            $rect = $this->makeRect(0.0, 0.0, 2.0, 100.0);
        }
        $box = $this->msg($box, 'initWithFrame:', [$rect]);
        // NSBoxSeparator = 2
        $this->msg($box, 'setBoxType:', [2]);
        return $box;
    }

    /* ==============================================================
     * Tab
     * ============================================================ */

    /**
     * 创建多页签容器。
     *
     * 用 NSTabView + NSTabViewItem 实现。
     * tabViewType:0 = NSTopTabsBezelBorder（顶部带边框的标签栏）。
     *
     * @return mixed 页签容器句柄
     */
    public function tabCreate(): mixed
    {
        $tab = $this->msg($this->clsNSTabView, 'alloc');
        $rect = $this->makeRect(0.0, 0.0, 100.0, 100.0);
        $tab = $this->msg($tab, 'initWithFrame:', [$rect]);
        // NSTopTabsBezelBorder = 0
        $this->msg($tab, 'setTabViewType:', [0]);
        return $tab;
    }

    /**
     * 在末尾追加一页。
     *
     * 创建 NSTabViewItem，设置 label 和 view，然后 addTabViewItem:。
     *
     * @param mixed  $h     页签容器句柄
     * @param string $name  页签标题
     * @param mixed  $child 子控件句柄
     */
    public function tabAppend(mixed $h, string $name, mixed $child): void
    {
        $item = $this->msg($this->clsNSTabViewItem, 'alloc');
        $item = $this->msg($item, 'initWithIdentifier:', [null]);
        $this->msg($item, 'setLabel:', [$this->makeNSString($name)]);
        $this->msg($item, 'setView:', [$child]);
        $this->msg($h, 'addTabViewItem:', [$item]);
    }

    /**
     * 在指定位置插入一页。
     *
     * @param mixed  $h     页签容器句柄
     * @param string $name  页签标题
     * @param int    $index 插入位置索引
     * @param mixed  $child 子控件句柄
     */
    public function tabInsertAt(mixed $h, string $name, int $index, mixed $child): void
    {
        $item = $this->msg($this->clsNSTabViewItem, 'alloc');
        $item = $this->msg($item, 'initWithIdentifier:', [null]);
        $this->msg($item, 'setLabel:', [$this->makeNSString($name)]);
        $this->msg($item, 'setView:', [$child]);
        $this->msg($h, 'insertTabViewItem:atIndex:', [$item, $index]);
    }

    /**
     * 删除指定位置的页签。
     *
     * @param mixed $h     页签容器句柄
     * @param int   $index 页签索引
     */
    public function tabDelete(mixed $h, int $index): void
    {
        $item = $this->msg($h, 'tabViewItemAtIndex:', [$index]);
        if ($item === null || ($item instanceof CData && Pointer::isNull($item))) {
            return;
        }
        $this->msg($h, 'removeTabViewItem:', [$item]);
    }

    /**
     * 获取页签数量。
     *
     * @param mixed $h 页签容器句柄
     * @return int 页数
     */
    public function tabNumPages(mixed $h): int
    {
        $n = $this->msg($h, 'numberOfTabViewItems');
        return $this->asInt($n);
    }

    /**
     * 获取当前选中的页签索引。
     *
     * @param mixed $h 页签容器句柄
     * @return int 当前选中页索引，-1 表示无选中页
     */
    public function tabGetSelected(mixed $h): int
    {
        $item = $this->msg($h, 'selectedTabViewItem');
        if ($item === null || ($item instanceof CData && Pointer::isNull($item))) {
            return -1;
        }
        $idx = $this->msg($h, 'indexOfTabViewItem:', [$item]);
        $idxInt = $this->asInt($idx);
        // NSNotFound 检查（值为 NSIntegerMax，远超正常索引范围）
        return $idxInt > 1000000 ? -1 : $idxInt;
    }

    /**
     * 设置当前选中的页签。
     *
     * @param mixed $h     页签容器句柄
     * @param int   $index 要选中的页签索引
     */
    public function tabSetSelected(mixed $h, int $index): void
    {
        $this->msg($h, 'selectTabViewItemAtIndex:', [$index]);
    }

    /**
     * 查询指定页是否启用边距。
     *
     * 简化实现：Cocoa NSTabViewItem 无直接 margined 属性，统一返回 false。
     *
     * @param mixed $h     页签容器句柄
     * @param int   $index 页签索引
     * @return bool 是否启用边距
     */
    public function tabGetMargined(mixed $h, int $index): bool
    {
        return false;
    }

    /**
     * 设置指定页的边距启用状态。
     *
     * 简化实现：no-op，Cocoa NSTabViewItem 无直接 margined 属性。
     *
     * @param mixed $h     页签容器句柄
     * @param int   $index 页签索引
     * @param bool  $m     是否启用边距
     */
    public function tabSetMargined(mixed $h, int $index, bool $m): void
    {
        // no-op：Cocoa 无对应 API
    }

    /* ==============================================================
     * Group
     * ============================================================ */

    /**
     * 创建带标题的容器组。
     *
     * 用 NSBox（NSBoxPrimary=0 类型，带标题边框）。
     *
     * @param string $title 组标题
     * @return mixed 容器组句柄
     */
    public function groupCreate(string $title): mixed
    {
        $box = $this->msg($this->clsNSBox, 'alloc');
        $rect = $this->makeRect(0.0, 0.0, 100.0, 100.0);
        $box = $this->msg($box, 'initWithFrame:', [$rect]);
        // NSBoxPrimary = 0（带标题边框）
        $this->msg($box, 'setBoxType:', [0]);
        $this->msg($box, 'setTitle:', [$this->makeNSString($title)]);
        return $box;
    }

    /**
     * 获取容器组标题。
     *
     * @param mixed $h 容器组句柄
     * @return string 标题
     */
    public function groupGetTitle(mixed $h): string
    {
        $title = $this->msg($h, 'title');
        if ($title === null || !($title instanceof CData) || Pointer::isNull($title)) {
            return '';
        }
        return $this->nsStringToPhp($title);
    }

    /**
     * 设置容器组标题。
     *
     * @param mixed  $h 容器组句柄
     * @param string $t 标题
     */
    public function groupSetTitle(mixed $h, string $t): void
    {
        $this->msg($h, 'setTitle:', [$this->makeNSString($t)]);
    }

    /**
     * 设置容器组的子控件（替换已有子控件）。
     *
     * @param mixed $h     容器组句柄
     * @param mixed $child 子控件句柄
     */
    public function groupSetChild(mixed $h, mixed $child): void
    {
        $this->msg($h, 'setContentView:', [$child]);
    }

    /**
     * 查询容器组是否启用边距。
     *
     * 简化实现：始终返回 false。
     *
     * @param mixed $h 容器组句柄
     * @return bool 是否启用边距
     */
    public function groupGetMargined(mixed $h): bool
    {
        return false;
    }

    /**
     * 设置容器组边距启用状态。
     *
     * 用 setContentViewMargins: 间接实现：true 时设 {8,8}，false 时设 {0,0}。
     *
     * @param mixed $h 容器组句柄
     * @param bool  $m 是否启用边距
     */
    public function groupSetMargined(mixed $h, bool $m): void
    {
        $margin = $m ? 8.0 : 0.0;
        $size = $this->makeSize($margin, $margin);
        $this->msg($h, 'setContentViewMargins:', [$size]);
    }

    /* ==============================================================
     * Form
     * ============================================================ */

    /**
     * 创建表单布局容器。
     *
     * 用 NSView 作为容器，PHP 端维护「标签-控件」对列表与布局状态。
     * 每行：左侧 80px 宽 label（NSTextField 模拟），右侧 child 占剩余宽度。
     *
     * @return mixed 表单容器句柄
     */
    public function formCreate(): mixed
    {
        $view = $this->msg($this->clsNSView, 'alloc');
        $rect = $this->makeRect(0.0, 0.0, 100.0, 100.0);
        $view = $this->msg($view, 'initWithFrame:', [$rect]);
        $this->forms[spl_object_id($view)] = [
            'children' => [],
            'padded'   => false,
        ];
        return $view;
    }

    /**
     * 向表单追加一组「标签-控件」对。
     *
     * 创建 NSTextField 作为 label（同 labelCreate 配置），与 child 一起加入容器，
     * 记录到 children 数组并触发 layoutForm 重新排列。
     *
     * @param mixed  $h        表单容器句柄
     * @param string $label    标签文本
     * @param mixed  $child    子控件句柄
     * @param bool   $stretchy 该行是否拉伸占据剩余空间
     * @throws UiException 表单句柄未跟踪
     */
    public function formAppend(mixed $h, string $label, mixed $child, bool $stretchy): void
    {
        $key = spl_object_id($h);
        if (!isset($this->forms[$key])) {
            throw new UiException("formAppend: invalid form handle (spl_object_id=$key)");
        }
        // 创建 label（用 NSTextField 模拟，同 labelCreate）
        $labelWidget = $this->msg($this->clsNSTextField, 'alloc');
        $rect = $this->makeRect(0.0, 0.0, 80.0, 25.0);
        $labelWidget = $this->msg($labelWidget, 'initWithFrame:', [$rect]);
        $this->msg($labelWidget, 'setEditable:', [0]);
        $this->msg($labelWidget, 'setBezeled:', [0]);
        $this->msg($labelWidget, 'setDrawsBackground:', [0]);
        $this->msg($labelWidget, 'setSelectable:', [0]);
        $this->msg($labelWidget, 'setStringValue:', [$this->makeNSString($label)]);

        $this->msg($h, 'addSubview:', [$labelWidget]);
        $this->msg($h, 'addSubview:', [$child]);

        $this->forms[$key]['children'][] = [
            'label'    => $labelWidget,
            'handle'   => $child,
            'stretchy' => $stretchy,
        ];
        $this->layoutForm($h);
    }

    /**
     * 删除指定索引位置的表单项。
     *
     * 从父视图移除 label 和 child，释放 label（child 由调用方负责释放），
     * 从 children 数组移除并重新布局。
     *
     * @param mixed $h     表单容器句柄
     * @param int   $index 表单项索引
     * @throws UiException 表单未跟踪或索引越界
     */
    public function formDelete(mixed $h, int $index): void
    {
        $key = spl_object_id($h);
        if (!isset($this->forms[$key])) {
            throw new UiException("formDelete: form not tracked (spl_object_id=$key)");
        }
        $children = $this->forms[$key]['children'];
        if (!array_key_exists($index, $children)) {
            throw new UiException("formDelete: invalid index $index");
        }
        // 从父视图移除 label 和 child
        $this->msg($children[$index]['label'], 'removeFromSuperview');
        $this->msg($children[$index]['handle'], 'removeFromSuperview');
        // 释放内部创建的 label（child 由调用方负责）
        $this->msg($children[$index]['label'], 'release');
        // 保持索引连续
        array_splice($this->forms[$key]['children'], $index, 1);
        $this->layoutForm($h);
    }

    /**
     * 获取表单中的子项数量。
     *
     * @param mixed $h 表单容器句柄
     * @return int 子项数量
     */
    public function formNumChildren(mixed $h): int
    {
        $key = spl_object_id($h);
        return isset($this->forms[$key]) ? count($this->forms[$key]['children']) : 0;
    }

    /**
     * 查询表单是否启用内边距填充。
     *
     * @param mixed $h 表单容器句柄
     * @return bool 是否填充
     */
    public function formGetPadded(mixed $h): bool
    {
        $key = spl_object_id($h);
        return $this->forms[$key]['padded'] ?? false;
    }

    /**
     * 设置表单是否启用内边距填充。
     *
     * @param mixed $h 表单容器句柄
     * @param bool  $p 是否填充
     * @throws UiException 表单未跟踪
     */
    public function formSetPadded(mixed $h, bool $p): void
    {
        $key = spl_object_id($h);
        if (!isset($this->forms[$key])) {
            throw new UiException("formSetPadded: form not tracked (spl_object_id=$key)");
        }
        $this->forms[$key]['padded'] = $p;
        $this->layoutForm($h);
    }

    /**
     * 重新布局 Form 子控件。
     *
     * 简化限制：使用固定默认尺寸 100x100（同 layoutBox）。
     * 每行高度：stretchy 行均分剩余空间，非 stretchy 行固定 25。
     * label 宽度固定 80，child 占剩余宽度（totalW - 80）。
     * Cocoa 坐标系 y 轴向上，需将「自顶向下」的行序翻转为 Cocoa y：
     *   y_cocoa = totalH - yTop - rowH（yTop 为自顶向下的累积偏移）
     *
     * @param mixed $h 表单容器句柄
     */
    private function layoutForm(mixed $h): void
    {
        $key = spl_object_id($h);
        $form = $this->forms[$key] ?? null;
        if (!$form || empty($form['children'])) {
            return;
        }

        $totalW = 100;
        $totalH = 100;
        $padded = $form['padded'] ? 4 : 0;
        $n = count($form['children']);
        $stretchyCount = count(array_filter(
            $form['children'],
            fn ($c) => $c['stretchy']
        ));
        $nonStretchy = $n - $stretchyCount;

        $stretchyH = $stretchyCount > 0
            ? ($totalH - ($n - 1) * $padded - $nonStretchy * 25) / $stretchyCount
            : 0;

        $labelW = 80.0;
        $childX = $labelW;
        $childW = $totalW - $labelW;
        $selSetFrame = $this->sel('setFrame:');

        $yTop = 0.0; // 自顶向下的累积 y（视觉顶部为 0）
        foreach ($form['children'] as $c) {
            $rowH = $c['stretchy'] ? (float) $stretchyH : 25.0;
            // 翻转 y：Cocoa y 从底部开始
            $yCocoa = (float) ($totalH - $yTop - $rowH);

            $labelRect = $this->makeRect(0.0, $yCocoa, $labelW, $rowH);
            $this->msg($c['label'], $selSetFrame, [$labelRect]);

            $childRect = $this->makeRect($childX, $yCocoa, (float) $childW, $rowH);
            $this->msg($c['handle'], $selSetFrame, [$childRect]);

            $yTop += $rowH + $padded;
        }
    }

    /* ==============================================================
     * Grid
     * ============================================================ */

    /**
     * 创建网格布局容器。
     *
     * 用 NSView 作为容器，PHP 端维护 cells 列表与 padded 状态。
     *
     * @return mixed 网格容器句柄
     */
    public function gridCreate(): mixed
    {
        $view = $this->msg($this->clsNSView, 'alloc');
        $rect = $this->makeRect(0.0, 0.0, 100.0, 100.0);
        $view = $this->msg($view, 'initWithFrame:', [$rect]);
        $this->grids[spl_object_id($view)] = [
            'cells'  => [],
            'padded' => false,
        ];
        return $view;
    }

    /**
     * 在网格指定坐标处追加子控件。
     *
     * @param mixed $h       网格容器句柄
     * @param mixed $child   子控件句柄
     * @param int   $left    起始列
     * @param int   $top     起始行
     * @param int   $xspan   横向跨列数
     * @param int   $yspan   纵向跨行数
     * @param bool  $hexpand 是否水平拉伸
     * @param int   $halign  水平对齐（uiAlign：0=Fill,1=Start,2=Center,3=End）
     * @param bool  $vexpand 是否垂直拉伸
     * @param int   $valign  垂直对齐（uiAlign：0=Fill,1=Start,2=Center,3=End）
     * @throws UiException 网格句柄未跟踪
     */
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
            throw new UiException("gridAppend: invalid grid handle (spl_object_id=$key)");
        }
        $this->grids[$key]['cells'][] = [
            'handle'  => $child,
            'left'    => $left,
            'top'     => $top,
            'xspan'   => max(1, $xspan),
            'yspan'   => max(1, $yspan),
            'hexpand' => $hexpand,
            'halign'  => $halign,
            'vexpand' => $vexpand,
            'valign'  => $valign,
        ];
        $this->msg($h, 'addSubview:', [$child]);
        $this->layoutGrid($h);
    }

    /**
     * 相对已有控件的位置插入子控件。
     *
     * uiAt 枚举：Leading=0, Top=1, Trailing=2, Bottom=3
     *   - Leading：在 existing 左侧
     *   - Top：在 existing 上方
     *   - Trailing：在 existing 右侧
     *   - Bottom：在 existing 下方
     *
     * @param mixed $h        网格容器句柄
     * @param mixed $child    要插入的子控件句柄
     * @param mixed $existing 参照的已有控件句柄
     * @param int   $at       相对位置（uiAt）
     * @param int   $xspan    横向跨列数
     * @param int   $yspan    纵向跨行数
     * @param bool  $hexpand  是否水平拉伸
     * @param int   $halign   水平对齐
     * @param bool  $vexpand  是否垂直拉伸
     * @param int   $valign   垂直对齐
     * @throws UiException 网格未跟踪、参照控件未找到或 at 值非法
     */
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
            throw new UiException("gridInsertAt: invalid grid handle (spl_object_id=$key)");
        }
        // 查找 existing cell（通过 C 指针地址比较，兼容不同 PHP CData 包装器）
        $existingCell = null;
        $existingPtr = $existing instanceof CData
            ? (int) \FFI::cast('intptr_t', $existing)
            : 0;
        foreach ($this->grids[$key]['cells'] as $cell) {
            $cellPtr = $cell['handle'] instanceof CData
                ? (int) \FFI::cast('intptr_t', $cell['handle'])
                : 0;
            if ($cellPtr !== 0 && $cellPtr === $existingPtr) {
                $existingCell = $cell;
                break;
            }
        }
        if ($existingCell === null) {
            throw new UiException("gridInsertAt: existing cell not found");
        }
        $xspan = max(1, $xspan);
        $yspan = max(1, $yspan);
        // 根据 at 方向计算新 cell 的 left/top
        $newLeft = $existingCell['left'];
        $newTop = $existingCell['top'];
        switch ($at) {
            case 0: // Leading：在 existing 左侧
                $newLeft = $existingCell['left'] - $xspan;
                break;
            case 1: // Top：在 existing 上方
                $newTop = $existingCell['top'] - $yspan;
                break;
            case 2: // Trailing：在 existing 右侧
                $newLeft = $existingCell['left'] + $existingCell['xspan'];
                break;
            case 3: // Bottom：在 existing 下方
                $newTop = $existingCell['top'] + $existingCell['yspan'];
                break;
            default:
                throw new UiException("gridInsertAt: invalid at value $at");
        }
        $this->grids[$key]['cells'][] = [
            'handle'  => $child,
            'left'    => $newLeft,
            'top'     => $newTop,
            'xspan'   => $xspan,
            'yspan'   => $yspan,
            'hexpand' => $hexpand,
            'halign'  => $halign,
            'vexpand' => $vexpand,
            'valign'  => $valign,
        ];
        $this->msg($h, 'addSubview:', [$child]);
        $this->layoutGrid($h);
    }

    /**
     * 查询网格是否启用内边距填充。
     *
     * @param mixed $h 网格容器句柄
     * @return bool 是否填充
     */
    public function gridGetPadded(mixed $h): bool
    {
        $key = spl_object_id($h);
        return $this->grids[$key]['padded'] ?? false;
    }

    /**
     * 设置网格是否启用内边距填充。
     *
     * @param mixed $h 网格容器句柄
     * @param bool  $p 是否填充
     * @throws UiException 网格未跟踪
     */
    public function gridSetPadded(mixed $h, bool $p): void
    {
        $key = spl_object_id($h);
        if (!isset($this->grids[$key])) {
            throw new UiException("gridSetPadded: grid not tracked (spl_object_id=$key)");
        }
        $this->grids[$key]['padded'] = $p;
        $this->layoutGrid($h);
    }

    /**
     * 重新布局 Grid 子控件。
     *
     * 简化限制：
     *   - 使用固定默认尺寸 100x100（同 layoutBox）
     *   - 列宽/行高均分，忽略 hexpand/vexpand/halign/valign（仅记录状态）
     *   - padded 启用时每个单元格向内缩进 padded/2，保证单元格间留有间距
     *
     * Cocoa 坐标系 y 轴向上，top=0 视觉顶部，需翻转：
     *   y_cocoa = totalH - (top + yspan) * rowH
     *
     * @param mixed $h 网格容器句柄
     */
    private function layoutGrid(mixed $h): void
    {
        $key = spl_object_id($h);
        $grid = $this->grids[$key] ?? null;
        if (!$grid || empty($grid['cells'])) {
            return;
        }

        $totalW = 100;
        $totalH = 100;
        $padded = $grid['padded'] ? 4 : 0;

        // 计算列数和行数
        $cols = 1;
        $rows = 1;
        foreach ($grid['cells'] as $cell) {
            $cols = max($cols, $cell['left'] + $cell['xspan']);
            $rows = max($rows, $cell['top'] + $cell['yspan']);
        }

        $colW = $totalW / $cols;
        $rowH = $totalH / $rows;

        $selSetFrame = $this->sel('setFrame:');
        $halfPad = $padded / 2;

        foreach ($grid['cells'] as $cell) {
            $x = $cell['left'] * $colW + $halfPad;
            // 翻转 y：Cocoa y 从底部开始，top=0 在视觉顶部
            $y = $totalH - ($cell['top'] + $cell['yspan']) * $rowH + $halfPad;
            $w = $cell['xspan'] * $colW - $padded;
            $hgt = $cell['yspan'] * $rowH - $padded;
            // 防止负宽高
            if ($w < 1.0) {
                $w = 1.0;
            }
            if ($hgt < 1.0) {
                $hgt = 1.0;
            }
            $rect = $this->makeRect((float) $x, (float) $y, (float) $w, (float) $hgt);
            $this->msg($cell['handle'], $selSetFrame, [$rect]);
        }
    }
}
