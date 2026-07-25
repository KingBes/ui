<?php
declare(strict_types=1);

namespace Kingbes\Ui\Platform\Linux;

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
 * Linux GTK3 后端桩实现。
 *
 * 通过 FFI 加载 libgtk-3.so.0 / libgobject-2.0.so.0，实现 Window/Button/
 * Label/Entry/Box 基础流程。未实现的方法抛 UnsupportedOperationException。
 *
 * 关键设计：
 *   - 所有 GtkWidget 指针对外用 int 传递，内部用 INT_TO_PTR 联合体完成
 *     int↔GtkWidget* 转换（不同 FFI 作用域不能直接 cast）。
 *   - 信号回调用 g_signal_connect_data 注册 PHP 闭包；闭包引用存
 *     $signalCallbacks 防 GC 回收导致 GTK 调用悬垂闭包崩溃。
 *   - 主循环用 gtk_main（阻塞），通过 g_timeout_add(10ms) 集成
 *     runTimers()/runQueueMain() 共享逻辑。
 *   - 控件类型用 $controlTypes 跟踪（'Button'/'Label'/'Edit'），用于
 *     controlSetText/controlGetText 区分调用对应 GTK API。
 *
 * 注意：本机 Windows 无法运行 GTK，仅做 php -l 语法检查。
 */
class GtkPlatform extends AbstractPlatform
{
    // ============================================================
    // GTK / GObject 常量
    // ============================================================

    /** GTK_WINDOW_TOPLEVEL */
    private const GTK_WINDOW_TOPLEVEL = 0;

    /** GTK_ORIENTATION_VERTICAL（gtk_box_new 第一个参数） */
    private const GTK_ORIENTATION_VERTICAL = 1;

    /** GTK_ORIENTATION_HORIZONTAL */
    private const GTK_ORIENTATION_HORIZONTAL = 0;

    /** G_SOURCE_CONTINUE（g_timeout_add 回调返回值） */
    private const G_SOURCE_CONTINUE = 1;

    /**
     * G_TYPE_BOOLEAN = 5 << 2 = 20。
     *
     * G_TYPE_FUNDAMENTAL_SHIFT = 2，G_TYPE_BOOLEAN = 5（fundamental type ID）。
     * 用于 g_value_init 初始化 GValue 为 boolean 类型。
     */
    private const G_TYPE_BOOLEAN = 20;

    // ============================================================
    // FFI 实例
    // ============================================================

    /** @var \FFI|null libgobject-2.0.so.0（信号、定时器） */
    private ?\FFI $gobj = null;

    /** @var \FFI|null libgtk-3.so.0（窗口、控件、事件循环） */
    private ?\FFI $gtk = null;

    // ============================================================
    // 运行期状态
    // ============================================================

    /**
     * 信号回调闭包保活表：hwnd(int) => \Closure。
     *
     * g_signal_connect_data 注册的闭包必须保活，否则 GC 回收后
     * GTK 调用悬垂闭包会崩溃。
     *
     * @var array<int, \Closure>
     */
    private array $signalCallbacks = [];

    /**
     * 控件类型表：hwnd(int) => 原生类名（'Button'/'Label'/'Edit'）。
     *
     * 用于 controlSetText/controlGetText 区分调用对应 GTK API。
     *
     * @var array<int, string>
     */
    private array $controlTypes = [];

    /**
     * GtkWidget CData 保活表：hwnd(int) => GtkWidget CData。
     *
     * 防止 FFI 回收 CData 后指针失效；所有创建的控件/窗口都登记在此。
     *
     * @var array<int, \FFI\CData>
     */
    private array $widgets = [];

    /**
     * 控件 ID 自增计数器。
     */
    private int $nextControlId = 1000;

    // ============================================================
    // 构造器：加载 GTK3 + GObject
    // ============================================================

    public function __construct()
    {
        Library::permit('libgtk-3.so.0');
        Library::permit('libgobject-2.0.so.0');

        $this->gobj = Library::load('libgobject-2.0.so.0', self::GOBJECT_HEADER);
        $this->gtk = Library::load('libgtk-3.so.0', self::GTK_HEADER);

        // gtk_init(NULL, NULL) 初始化 GTK
        SafeCall::invoke($this->gtk, 'gtk_init', [null, null]);
    }

    // ============================================================
    // C 头声明
    // ============================================================

    /**
     * libgobject-2.0.so.0 头声明。
     *
     * GCallback 在 GLib 中是 void(*)(void)，但 GTK 信号回调实际签名是
     * void callback(GtkWidget*, gpointer user_data)（2 个参数）。
     * PHP FFI 要求闭包签名与函数指针类型完全匹配，因此声明
     * SignalCallback 为 void(*)(void*, void*)，而非用 GCallback。
     *
     * GSourceFunc 签名：gboolean callback(gpointer user_data)，gboolean 是 int。
     */
    private const GOBJECT_HEADER = <<<C
typedef void* gpointer;
typedef unsigned long gulong;
typedef int gint;
typedef unsigned int guint;

/* GTK 信号回调：void callback(gpointer instance, gpointer user_data) */
typedef void (*SignalCallback)(gpointer instance, gpointer user_data);

/* GSourceFunc：gboolean callback(gpointer user_data)，gboolean 是 int */
typedef int (*SourceFunc)(gpointer user_data);

/* 联合体用于 int↔指针 转换（不同 FFI 作用域不能直接 cast） */
typedef union { long long i; void* p; } INT_TO_PTR;

/* 信号连接：用 SignalCallback 替代 GCallback，使闭包签名匹配 */
gulong g_signal_connect_data(gpointer instance, const char *detailed_signal,
                             SignalCallback c_handler, gpointer data,
                             void* destroy_data, unsigned int connect_flags);

/* 超时回调 */
guint g_timeout_add(guint interval, SourceFunc func, gpointer data);

/* GValue：属性设置的类型安全包装（避免 vararg g_object_set）。
 * PHP FFI 不支持 C vararg，无法直接调用 g_object_set(..., true, null)；
 * 改用 g_object_set_property + GValue 是标准做法。
 * G_TYPE_BOOLEAN = 5 << 2 = 20。 */
typedef struct _GValue {
    unsigned long g_type;
    union {
        int v_int;
        long long v_int64;
        double v_double;
        void* v_pointer;
    } data[2];
} GValue;
void g_value_init(GValue* value, unsigned long g_type);
void g_value_set_boolean(GValue* value, int v_boolean);
void g_value_unset(GValue* value);
void g_object_set_property(void* object, const char* property_name, const GValue* value);
C;

    /**
     * libgtk-3.so.0 头声明（核心窗口/控件/事件循环 API）。
     */
    private const GTK_HEADER = <<<C
typedef void* GtkWidget;
typedef void* GtkWindow;
typedef void* GtkContainer;
typedef void* GtkBox;
typedef void* GtkFixed;
typedef int gint;
typedef int gboolean;

/* 联合体用于 int↔指针 转换 */
typedef union { long long i; void* p; } INT_TO_PTR;

/* 初始化 */
void gtk_init(int *argc, char ***argv);

/* 窗口 */
GtkWidget* gtk_window_new(int type);  /* GTK_WINDOW_TOPLEVEL = 0 */
void gtk_window_set_title(GtkWindow *window, const char *title);
void gtk_window_set_default_size(GtkWindow *window, gint width, gint height);
void gtk_window_resize(GtkWindow *window, gint width, gint height);
void gtk_window_get_position(GtkWindow *window, gint *root_x, gint *root_y);
void gtk_window_set_position(GtkWindow *window, int position);
void gtk_window_get_size(GtkWindow *window, gint *width, gint *height);
const char* gtk_window_get_title(GtkWindow *window);

/* 容器与布局 */
GtkWidget* gtk_box_new(int orientation, gint spacing);  /* GTK_ORIENTATION_VERTICAL=1 */
void gtk_container_add(GtkContainer *container, GtkWidget *widget);
void gtk_box_pack_start(GtkBox *box, GtkWidget *child, gboolean expand, gboolean fill, guint padding);

/* GtkFixed（用于绝对定位控件） */
GtkWidget* gtk_fixed_new(void);
void gtk_fixed_put(GtkFixed *fixed, GtkWidget *widget, gint x, gint y);
void gtk_fixed_move(GtkFixed *fixed, GtkWidget *widget, gint x, gint y);

/* 标签 */
GtkWidget* gtk_label_new(const char *str);
void gtk_label_set_text(void* label, const char *str);
const char* gtk_label_get_text(void* label);

/* 按钮 */
GtkWidget* gtk_button_new_with_label(const char *label);
void gtk_button_set_label(void* button, const char *label);
const char* gtk_button_get_label(void* button);

/* 输入框 */
GtkWidget* gtk_entry_new(void);
void gtk_entry_set_text(void* entry, const char *text);
const char* gtk_entry_get_text(void* entry);

/* 通用 widget 操作 */
void gtk_widget_show_all(GtkWidget *widget);
void gtk_widget_show(GtkWidget *widget);
void gtk_widget_hide(GtkWidget *widget);
void gtk_widget_destroy(GtkWidget *widget);
void gtk_widget_set_size_request(GtkWidget *widget, gint width, gint height);
void gtk_widget_set_sensitive(GtkWidget *widget, gboolean sensitive);
void gtk_widget_queue_resize(GtkWidget *widget);

/* 事件循环 */
void gtk_main(void);
void gtk_main_quit(void);

/* GtkSettings：全局 GTK 设置（用于深色主题切换）。
 * gtk_settings_get_default 返回进程单例 GtkSettings 对象，
 * 通过 g_object_set_property 修改 "gtk-application-prefer-dark-theme"
 * 布尔属性即可切换深色/浅色偏好。 */
typedef struct _GtkSettings GtkSettings;
GtkSettings* gtk_settings_get_default(void);
C;

    // ============================================================
    // int↔指针 辅助（用 INT_TO_PTR 联合体，禁止跨作用域 cast）
    // ============================================================

    /**
     * GtkWidget 指针 → int（用 gtk 作用域的 INT_TO_PTR）。
     */
    private function ptrToInt(\FFI\CData $ptr): int
    {
        $c = $this->gtk->new('INT_TO_PTR');
        $c->p = $ptr;
        return (int) $c->i;
    }

    /**
     * int → GtkWidget 指针（用 gtk 作用域的 INT_TO_PTR）。
     */
    private function intToPtr(int $i): \FFI\CData
    {
        $c = $this->gtk->new('INT_TO_PTR');
        $c->i = $i;
        return $c->p;
    }

    // ============================================================
    // 窗口方法
    // ============================================================

    /**
     * 创建顶层窗口。
     *
     * gtk_window_new(GTK_WINDOW_TOPLEVEL)，设置标题与默认尺寸。
     * 返回 GtkWidget 指针 int。
     */
    public function windowCreate(string $title, int $width, int $height): int
    {
        $window = SafeCall::invoke($this->gtk, 'gtk_window_new', [self::GTK_WINDOW_TOPLEVEL]);
        Pointer::assertNotNull($window, 'gtk_window_new');

        SafeCall::invoke($this->gtk, 'gtk_window_set_title', [$window, $title]);
        SafeCall::invoke($this->gtk, 'gtk_window_set_default_size', [$window, $width, $height]);

        // 注册 destroy 信号：关闭窗口时退出 gtk_main
        $onDestroy = function ($win, $data): void {
            SafeCall::invoke($this->gtk, 'gtk_main_quit', []);
        };
        SafeCall::invoke($this->gobj, 'g_signal_connect_data', [
            $window, 'destroy', $onDestroy, null, null, 0
        ]);

        $hwnd = $this->ptrToInt($window);
        $this->widgets[$hwnd] = $window;
        $this->signalCallbacks[$hwnd] = $onDestroy;
        return $hwnd;
    }

    public function windowDestroy(int $hwnd): void
    {
        $widget = $this->widgets[$hwnd] ?? null;
        if ($widget === null) {
            return;
        }
        SafeCall::invoke($this->gtk, 'gtk_widget_destroy', [$widget]);
        unset($this->widgets[$hwnd], $this->signalCallbacks[$hwnd], $this->controlTypes[$hwnd]);
    }

    public function windowSetTitle(int $hwnd, string $title): void
    {
        $widget = $this->widgets[$hwnd] ?? null;
        if ($widget === null) {
            return;
        }
        SafeCall::invoke($this->gtk, 'gtk_window_set_title', [$widget, $title]);
    }

    public function windowGetTitle(int $hwnd): string
    {
        $widget = $this->widgets[$hwnd] ?? null;
        if ($widget === null) {
            return '';
        }
        $title = SafeCall::invoke($this->gtk, 'gtk_window_get_title', [$widget]);
        return $title === null ? '' : (string) $title;
    }

    public function windowSetPosition(int $hwnd, int $x, int $y): void
    {
        $widget = $this->widgets[$hwnd] ?? null;
        if ($widget === null) {
            return;
        }
        // GTK_POS_NONE = 0；用 gtk_window_set_position 设置位置策略。
        // 简化：直接调用 set_position(0) 让窗口管理器决定。
        SafeCall::invoke($this->gtk, 'gtk_window_set_position', [$widget, 0]);
    }

    public function windowGetPosition(int $hwnd): Point
    {
        $widget = $this->widgets[$hwnd] ?? null;
        if ($widget === null) {
            return Point::zero();
        }
        $x = $this->gtk->new('gint');
        $y = $this->gtk->new('gint');
        SafeCall::invoke($this->gtk, 'gtk_window_get_position', [
            $widget, \FFI::addr($x), \FFI::addr($y)
        ]);
        return Point::of((int) $x, (int) $y);
    }

    public function windowSetSize(int $hwnd, int $width, int $height): void
    {
        $widget = $this->widgets[$hwnd] ?? null;
        if ($widget === null) {
            return;
        }
        SafeCall::invoke($this->gtk, 'gtk_window_resize', [$widget, $width, $height]);
    }

    public function windowGetSize(int $hwnd): Size
    {
        $widget = $this->widgets[$hwnd] ?? null;
        if ($widget === null) {
            return Size::zero();
        }
        $w = $this->gtk->new('gint');
        $h = $this->gtk->new('gint');
        SafeCall::invoke($this->gtk, 'gtk_window_get_size', [
            $widget, \FFI::addr($w), \FFI::addr($h)
        ]);
        return Size::of((int) $w, (int) $h);
    }

    public function windowGetClientSize(int $hwnd): Size
    {
        // 简化：客户区尺寸等同于窗口尺寸
        return $this->windowGetSize($hwnd);
    }

    public function windowSetFullscreen(int $hwnd, bool $fullscreen): void
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'Linux');
    }

    public function windowSetBorderless(int $hwnd, bool $borderless): void
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'Linux');
    }

    public function windowSetResizeable(int $hwnd, bool $resizeable): void
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'Linux');
    }

    public function windowMaximize(int $hwnd): void
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'Linux');
    }

    public function windowMinimize(int $hwnd): void
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'Linux');
    }

    public function windowRestore(int $hwnd): void
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'Linux');
    }

    public function windowShow(int $hwnd): void
    {
        $widget = $this->widgets[$hwnd] ?? null;
        if ($widget === null) {
            return;
        }
        SafeCall::invoke($this->gtk, 'gtk_widget_show_all', [$widget]);
    }

    public function windowHide(int $hwnd): void
    {
        $widget = $this->widgets[$hwnd] ?? null;
        if ($widget === null) {
            return;
        }
        SafeCall::invoke($this->gtk, 'gtk_widget_hide', [$widget]);
    }

    public function windowSetTopmost(int $hwnd, bool $topmost): void
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'Linux');
    }

    public function windowSetChild(int $hwnd, int $childHwnd): void
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'Linux');
    }

    public function windowSetScrollable(int $hwnd, int $contentHeight): void
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'Linux');
    }

    public function windowIsFocused(int $hwnd): bool
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'Linux');
    }

    public function windowSetMenu(int $hwnd, int $menuHwnd): void
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'Linux');
    }

    // ============================================================
    // 控件方法
    // ============================================================

    /**
     * 创建子控件。
     *
     * className "Button" → gtk_button_new_with_label(text)
     * className "Label"  → gtk_label_new(text)
     * className "Edit"   → gtk_entry_new()
     *
     * 返回 GtkWidget 指针 int。
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

        $widget = match ($className) {
            'Button' => SafeCall::invoke($this->gtk, 'gtk_button_new_with_label', [$text]),
            'Label'  => SafeCall::invoke($this->gtk, 'gtk_label_new', [$text]),
            'Edit'   => SafeCall::invoke($this->gtk, 'gtk_entry_new', []),
            default  => throw UnsupportedOperationException::forMethod(__METHOD__, 'Linux'),
        };
        Pointer::assertNotNull($widget, 'gtk_control_create');

        // 加入父容器（若提供）
        if ($parentHwnd !== 0 && isset($this->widgets[$parentHwnd])) {
            SafeCall::invoke($this->gtk, 'gtk_container_add', [
                $this->widgets[$parentHwnd], $widget
            ]);
        }

        $hwnd = $this->ptrToInt($widget);
        $this->widgets[$hwnd] = $widget;
        $this->controlTypes[$hwnd] = $className;
        return $hwnd;
    }

    public function controlDestroy(int $hwnd): void
    {
        $widget = $this->widgets[$hwnd] ?? null;
        if ($widget === null) {
            return;
        }
        SafeCall::invoke($this->gtk, 'gtk_widget_destroy', [$widget]);
        unset($this->widgets[$hwnd], $this->controlTypes[$hwnd], $this->signalCallbacks[$hwnd]);
    }

    public function controlSetText(int $hwnd, string $text): void
    {
        $widget = $this->widgets[$hwnd] ?? null;
        if ($widget === null) {
            return;
        }
        $type = $this->controlTypes[$hwnd] ?? '';
        match ($type) {
            'Button' => SafeCall::invoke($this->gtk, 'gtk_button_set_label', [$widget, $text]),
            'Label'  => SafeCall::invoke($this->gtk, 'gtk_label_set_text', [$widget, $text]),
            'Edit'   => SafeCall::invoke($this->gtk, 'gtk_entry_set_text', [$widget, $text]),
            default  => null,
        };
    }

    public function controlGetText(int $hwnd): string
    {
        $widget = $this->widgets[$hwnd] ?? null;
        if ($widget === null) {
            return '';
        }
        $type = $this->controlTypes[$hwnd] ?? '';
        $text = match ($type) {
            'Button' => SafeCall::invoke($this->gtk, 'gtk_button_get_label', [$widget]),
            'Label'  => SafeCall::invoke($this->gtk, 'gtk_label_get_text', [$widget]),
            'Edit'   => SafeCall::invoke($this->gtk, 'gtk_entry_get_text', [$widget]),
            default  => null,
        };
        return $text === null ? '' : (string) $text;
    }

    /**
     * 设置控件边界（位置 + 尺寸）。
     *
     * 简化实现：仅设置尺寸请求（gtk_widget_set_size_request），
     * 位置由 GTK 容器布局决定，忽略 x/y 参数。
     */
    public function controlSetBounds(int $hwnd, int $x, int $y, int $width, int $height): void
    {
        $widget = $this->widgets[$hwnd] ?? null;
        if ($widget === null) {
            return;
        }
        if ($width > 0 && $height > 0) {
            SafeCall::invoke($this->gtk, 'gtk_widget_set_size_request', [$widget, $width, $height]);
        }
    }

    public function controlShow(int $hwnd): void
    {
        $widget = $this->widgets[$hwnd] ?? null;
        if ($widget === null) {
            return;
        }
        SafeCall::invoke($this->gtk, 'gtk_widget_show', [$widget]);
    }

    public function controlHide(int $hwnd): void
    {
        $widget = $this->widgets[$hwnd] ?? null;
        if ($widget === null) {
            return;
        }
        SafeCall::invoke($this->gtk, 'gtk_widget_hide', [$widget]);
    }

    public function controlEnable(int $hwnd, bool $enabled): void
    {
        $widget = $this->widgets[$hwnd] ?? null;
        if ($widget === null) {
            return;
        }
        SafeCall::invoke($this->gtk, 'gtk_widget_set_sensitive', [$widget, $enabled ? 1 : 0]);
    }

    public function controlIsChecked(int $hwnd): bool
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'Linux');
    }

    public function controlSetChecked(int $hwnd, bool $checked): void
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'Linux');
    }

    public function controlAddString(int $hwnd, string $text): void
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'Linux');
    }

    public function controlRemoveString(int $hwnd, int $index): void
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'Linux');
    }

    public function controlClear(int $hwnd): void
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'Linux');
    }

    public function controlGetSelectedIndex(int $hwnd): int
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'Linux');
    }

    public function controlSetSelectedIndex(int $hwnd, int $index): void
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'Linux');
    }

    public function controlSetRange(int $hwnd, int $min, int $max): void
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'Linux');
    }

    public function controlSetValue(int $hwnd, int $value): void
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'Linux');
    }

    public function controlGetValue(int $hwnd): int
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'Linux');
    }

    // ============================================================
    // Tab 标签页方法（未实现）
    // ============================================================

    public function tabInsertItem(int $tabHwnd, int $index, string $text): void
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'Linux');
    }

    public function tabDeleteItem(int $tabHwnd, int $index): void
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'Linux');
    }

    public function tabGetSelected(int $tabHwnd): int
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'Linux');
    }

    public function tabSetSelected(int $tabHwnd, int $index): void
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'Linux');
    }

    public function tabGetItemCount(int $tabHwnd): int
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'Linux');
    }

    // ============================================================
    // DateTimePicker 方法（未实现）
    // ============================================================

    public function dateTimePickerGetTime(int $hwnd): ?array
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'Linux');
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
        throw UnsupportedOperationException::forMethod(__METHOD__, 'Linux');
    }

    public function dateTimePickerSetFormat(int $hwnd, string $format): void
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'Linux');
    }

    // ============================================================
    // 菜单方法（未实现）
    // ============================================================

    public function menuCreateBar(): int
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'Linux');
    }

    public function menuCreatePopup(): int
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'Linux');
    }

    public function menuAppendItem(int $menuHwnd, string $text, int $id): void
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'Linux');
    }

    public function menuAppendSeparator(int $menuHwnd): void
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'Linux');
    }

    public function menuAppendSubmenu(int $menuHwnd, string $text, int $submenuHwnd): void
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'Linux');
    }

    public function menuSetEnabled(int $menuHwnd, int $id, bool $enabled): void
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'Linux');
    }

    public function menuSetChecked(int $menuHwnd, int $id, bool $checked): void
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'Linux');
    }

    public function menuDestroy(int $menuHwnd): void
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'Linux');
    }

    // ============================================================
    // 对话框方法（未实现）
    // ============================================================

    public function dialogMsgBox(int $parentHwnd, string $text, string $caption, int $type): int
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'Linux');
    }

    public function dialogOpenFile(int $parentHwnd, array $filters): ?string
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'Linux');
    }

    public function dialogSaveFile(int $parentHwnd, array $filters): ?string
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'Linux');
    }

    public function dialogOpenFolder(int $parentHwnd): ?string
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'Linux');
    }

    public function dialogChooseColor(int $parentHwnd): ?Color
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'Linux');
    }

    public function dialogChooseFont(int $parentHwnd): ?array
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'Linux');
    }

    // ============================================================
    // 绘图方法（未实现）
    // ============================================================

    public function areaCreate(int $parentHwnd): int
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'Linux');
    }

    public function areaInvalidate(int $hwnd): void
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'Linux');
    }

    public function drawContextCreate(int $hwnd): mixed
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'Linux');
    }

    public function drawContextFree(mixed $ctx): void
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'Linux');
    }

    public function drawLine(mixed $ctx, int $x1, int $y1, int $x2, int $y2): void
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'Linux');
    }

    public function drawRect(mixed $ctx, int $x, int $y, int $width, int $height): void
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'Linux');
    }

    public function drawEllipse(mixed $ctx, int $x, int $y, int $width, int $height): void
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'Linux');
    }

    public function drawText(mixed $ctx, int $x, int $y, string $text): void
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'Linux');
    }

    public function drawTextAttributed(mixed $ctx, int $x, int $y, int $attributedStringId): void
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'Linux');
    }

    public function setPen(mixed $ctx, Color $color, int $width): void
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'Linux');
    }

    public function setBrush(mixed $ctx, Color $color): void
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'Linux');
    }

    public function setFont(mixed $ctx, string $name, int $size): void
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'Linux');
    }

    public function setColor(mixed $ctx, Color $color): void
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'Linux');
    }

    // ============================================================
    // 事件循环
    // ============================================================

    /**
     * 进入事件循环（阻塞直到 quit）。
     *
     * 实现：
     *   1. g_timeout_add(10ms) 注册一个轮询回调，每 10ms 调用
     *      runTimers() + runQueueMain()，返回 G_SOURCE_CONTINUE。
     *   2. gtk_main() 进入阻塞事件循环。
     *
     * 注意：闭包必须保活到 $signalCallbacks 防 GC 回收。
     */
    public function run(): void
    {
        $this->running = true;

        // 定时器轮询闭包：每 10ms 触发一次
        $tick = function ($data): int {
            $this->runTimers();
            $this->runQueueMain();
            return self::G_SOURCE_CONTINUE;
        };
        SafeCall::invoke($this->gobj, 'g_timeout_add', [10, $tick, null]);

        // 保活闭包（防止 GC 回收后 GTK 调用悬垂闭包崩溃）
        $this->signalCallbacks[0] = $tick;

        SafeCall::invoke($this->gtk, 'gtk_main', []);
        $this->running = false;
    }

    /**
     * 退出事件循环。
     *
     * 先询问 shouldQuit 回调；返回 false 则不退出。
     */
    public function quit(): void
    {
        if (!$this->shouldQuit()) {
            return;
        }
        SafeCall::invoke($this->gtk, 'gtk_main_quit', []);
    }

    // queueMain 与 triggerRelayout 继承自 AbstractPlatform。
    // wakeUpMainLoop 默认空实现即可：g_timeout_add 的 10ms 轮询会
    // 自动拾取 queueMain 队列，无需主动唤醒阻塞的 gtk_main。

    // ============================================================
    // 主题
    // ============================================================

    /**
     * 设置应用主题。
     *
     * GTK 实现：通过 g_object_set_property 修改 GtkSettings 单例的
     * "gtk-application-prefer-dark-theme" 布尔属性。
     *
     *   - Theme::DARK：属性设为 true，GTK 启用深色偏好
     *   - Theme::LIGHT / Theme::CLASSIC / Theme::SYSTEM：属性设为 false，
     *     GTK 跟随系统主题或保持浅色
     *
     * 注意：PHP FFI 不支持 C vararg，无法直接调用
     * g_object_set(settings, "gtk-application-prefer-dark-theme", true, null)。
     * 改用 g_object_set_property + GValue 方案（标准 GLib 做法）：
     *   1. g_value_init(&value, G_TYPE_BOOLEAN) 初始化 GValue 为 boolean 类型
     *   2. g_value_set_boolean(&value, preferDark) 写入布尔值
     *   3. g_object_set_property(settings, name, &value) 设置属性
     *   4. g_value_unset(&value) 释放 GValue 内部资源
     *
     * G_TYPE_BOOLEAN = 5 << 2 = 20（G_TYPE_FUNDAMENTAL_SHIFT = 2，
     * G_TYPE_BOOLEAN = 5）。
     */
    public function setAppTheme(string $theme): void
    {
        // 获取 GtkSettings 进程单例
        $settings = SafeCall::invoke($this->gtk, 'gtk_settings_get_default', []);
        if ($settings === null) {
            return;
        }

        // Theme::DARK 启用深色偏好；其他主题关闭（GTK 跟随系统或保持浅色）
        $preferDark = ($theme === Theme::DARK) ? 1 : 0;

        // 构造 GValue 包装 boolean 值并设置属性
        $gvalue = $this->gobj->new('GValue');
        SafeCall::invoke($this->gobj, 'g_value_init', [
            \FFI::addr($gvalue), self::G_TYPE_BOOLEAN
        ]);
        SafeCall::invoke($this->gobj, 'g_value_set_boolean', [
            \FFI::addr($gvalue), $preferDark
        ]);
        SafeCall::invoke($this->gobj, 'g_object_set_property', [
            $settings, 'gtk-application-prefer-dark-theme', \FFI::addr($gvalue)
        ]);
        SafeCall::invoke($this->gobj, 'g_value_unset', [
            \FFI::addr($gvalue)
        ]);
    }

    // ============================================================
    // 系统服务
    // ============================================================

    /**
     * 屏幕尺寸。
     *
     * 简化实现：抛 UnsupportedOperationException（需 gdk_screen_width/
     * height，本桩未加载 gdk）。
     */
    public function screenSize(): Size
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'Linux');
    }

    public function clipboardSetText(string $text): void
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'Linux');
    }

    public function clipboardGetText(): string
    {
        throw UnsupportedOperationException::forMethod(__METHOD__, 'Linux');
    }

    // timer/clearTimer/onShouldQuit 继承自 AbstractPlatform。
}
