<?php
declare(strict_types=1);

namespace Kingbes\Ui\Platform\Linux;

use Kingbes\Ui\Exception\UiException;
use Kingbes\Ui\Platform\Platform;
use Kingbes\Phpc\Library;
use Kingbes\Phpc\SafeCall;
use Kingbes\Phpc\Pointer;
use FFI\CData;

/**
 * Linux GTK3 平台后端。
 *
 * 通过 FFI 调用 libgtk-3.so.0 与 libgobject-2.0.so.0 实现跨平台 GUI 原始操作。
 * 所有 g_signal_connect_data 注册的闭包必须存入 $closures 注册表防止 PHP GC
 * 回收，否则 GTK 触发悬垂闭包会段错误。
 *
 * 句柄类型在签名中标注为 mixed，实际为 \FFI\CData（GtkWidget* / GtkWindow* 等）。
 */
class GtkPlatform extends Platform
{
    /** @var \FFI|null GTK3 核心 FFI 实例 */
    private ?\FFI $gtk = null;

    /** @var \FFI|null GObject FFI 实例（信号 / 定时器 / 主循环迭代） */
    private ?\FFI $gobj = null;

    /** @var array<string, \Closure> 闭包注册表：防止 GC。key 用 spl_object_id($h) . ':type' */
    private array $closures = [];

    /** @var array<int, \Closure> 定时器回调表：timer id → Closure */
    private array $timers = [];

    /** @var array<int, array<int, mixed>> box 子控件索引：spl_object_id(box) → [widget1, widget2, ...] */
    private array $boxChildren = [];

    /** @var array<int, array<int, array{0: mixed, 1: mixed}>> form 子项表：spl_object_id(form) → [[labelWidget, child], ...] */
    private array $formChildren = [];

    /** @var array<int, int> form 当前已用行数：spl_object_id(form) → row count */
    private array $formRows = [];

    /** @var array<int, bool> form padded 状态：spl_object_id(form) → bool */
    private array $formPadded = [];

    /** @var array<int, mixed> group 当前子控件引用：spl_object_id(group) → child widget */
    private array $groupChildren = [];

    /** @var int 定时器内部计数器（保留字段，g_timeout_add 已返回 GLib 自有 id） */
    private static int $nextTimerId = 1;

    /**
     * 加载 GTK3 与 GObject FFI 库并声明 C 头。
     *
     * 注意：FFI cdef 不支持 #define，常量在 PHP 代码中以整数直接使用：
     *   - GTK_WINDOW_TOPLEVEL = 0
     *   - GTK_ORIENTATION_HORIZONTAL = 0
     *   - GTK_ORIENTATION_VERTICAL = 1
     *
     * GCallback 在 GLib 中是 void(*)(void)，但 GTK 信号回调实际签名是
     * void callback(GtkWidget*, gpointer user_data)，PHP FFI 要求闭包签名与
     * 函数指针类型完全匹配，因此 SignalCallback 声明为 void(*)(void*, void*)。
     *
     * @throws \Kingbes\Phpc\Exception\LibraryNotPermittedException 库未在白名单
     */
    private function loadLibraries(): void
    {
        Library::permit('libgtk-3.so.0');
        Library::permit('libgobject-2.0.so.0');

        $this->gobj = Library::load('libgobject-2.0.so.0', <<<C
typedef void* gpointer;
typedef unsigned long gulong;
typedef int gint;
typedef unsigned int guint;
typedef int gboolean;

/* GTK 信号回调：void callback(gpointer instance, gpointer user_data) */
typedef void (*SignalCallback)(gpointer instance, gpointer user_data);

/* GSourceFunc：gboolean callback(gpointer user_data)，gboolean 是 int */
typedef int (*SourceFunc)(gpointer user_data);

/* 信号连接：用 SignalCallback 替代 GCallback，使闭包签名匹配 */
gulong g_signal_connect_data(gpointer instance, const char *detailed_signal,
                             SignalCallback c_handler, gpointer data,
                             void* destroy_data, unsigned int connect_flags);

/* 定时器 */
guint g_timeout_add(guint interval, SourceFunc func, gpointer data);

/* 主循环单步迭代：返回 gboolean（true=已处理事件） */
gboolean g_main_context_iteration(void* context, gboolean may_block);
C);

        $this->gtk = Library::load('libgtk-3.so.0', <<<C
typedef void* GtkWidget;
typedef void* GtkWindow;
typedef void* GtkContainer;
typedef void* GtkBox;
typedef void* GtkButton;
typedef void* GtkLabel;
typedef void* GtkEntry;
typedef void* GtkEditable;
typedef void* GtkCheckButton;
typedef void* GtkSeparator;
typedef void* GtkNotebook;
typedef void* GtkFrame;
typedef void* GtkGrid;
typedef int gint;
typedef int gboolean;
typedef char gchar;
typedef unsigned int guint;
typedef int GtkAlign;

/* 初始化 */
void gtk_init(int *argc, char ***argv);
void gtk_init_without_args(void);

/* 主循环 */
void gtk_main(void);
void gtk_main_quit(void);
guint gtk_main_level(void);

/* 窗口 */
GtkWidget* gtk_window_new(int type);
void gtk_window_set_title(GtkWindow *window, const char *title);
void gtk_window_set_default_size(GtkWindow *window, gint width, gint height);
void gtk_window_get_position(GtkWindow *window, gint *root_x, gint *root_y);
void gtk_window_move(GtkWindow *window, gint x, gint y);
void gtk_window_get_size(GtkWindow *window, gint *width, gint *height);
void gtk_window_resize(GtkWindow *window, gint width, gint height);

/* Widget 通用 */
void gtk_widget_show(GtkWidget *widget);
void gtk_widget_show_all(GtkWidget *widget);
void gtk_widget_hide(GtkWidget *widget);
void gtk_widget_destroy(GtkWidget *widget);
void gtk_widget_set_sensitive(GtkWidget *widget, gboolean sensitive);

/* 容器 */
void gtk_container_add(GtkContainer *container, GtkWidget *widget);
void gtk_container_remove(GtkContainer *container, GtkWidget *widget);

/* Box */
GtkWidget* gtk_box_new(int orientation, gint spacing);
void gtk_box_pack_start(GtkBox *box, GtkWidget *child, gboolean expand, gboolean fill, guint padding);
void gtk_box_set_spacing(GtkBox *box, gint spacing);

/* Button */
GtkWidget* gtk_button_new_with_label(const char *label);
const gchar* gtk_button_get_label(GtkButton *button);
void gtk_button_set_label(GtkButton *button, const gchar *label);

/* Label */
GtkWidget* gtk_label_new(const gchar *str);
const gchar* gtk_label_get_text(GtkLabel *label);
void gtk_label_set_text(GtkLabel *label, const gchar *str);

/* Entry */
GtkWidget* gtk_entry_new(void);
const gchar* gtk_entry_get_text(GtkEntry *entry);
void gtk_entry_set_text(GtkEntry *entry, const gchar *text);
void gtk_editable_set_editable(GtkEditable *editable, gboolean is_editable);

/* CheckButton（GTK3 用 GtkCheckButton，继承自 GtkButton） */
GtkWidget* gtk_check_button_new_with_label(const gchar *label);
gboolean gtk_check_button_get_active(GtkCheckButton *button);
void gtk_check_button_set_active(GtkCheckButton *button, gboolean is_active);

/* Separator */
GtkWidget* gtk_separator_new(int orientation);

/* Notebook（Tab 容器） */
GtkWidget* gtk_notebook_new();
gint gtk_notebook_append_page(GtkNotebook *notebook, GtkWidget *child, GtkWidget *tab_label);
gint gtk_notebook_insert_page(GtkNotebook *notebook, GtkWidget *child, GtkWidget *tab_label, gint position);
void gtk_notebook_remove_page(GtkNotebook *notebook, gint page_num);
gint gtk_notebook_get_n_pages(GtkNotebook *notebook);
gint gtk_notebook_get_current_page(GtkNotebook *notebook);
void gtk_notebook_set_current_page(GtkNotebook *notebook, gint page_num);

/* Frame（Group 容器） */
GtkWidget* gtk_frame_new(const char *label);
void gtk_frame_set_label(GtkFrame *frame, const char *label);
const char* gtk_frame_get_label(GtkFrame *frame);

/* Grid（Form / Grid 容器） */
GtkWidget* gtk_grid_new();
void gtk_grid_attach(GtkGrid *grid, GtkWidget *child, gint left, gint top, gint width, gint height);
void gtk_grid_attach_next_to(GtkGrid *grid, GtkWidget *child, GtkWidget *sibling, int side, gint width, gint height);
void gtk_grid_set_row_spacing(GtkGrid *grid, guint spacing);
void gtk_grid_set_column_spacing(GtkGrid *grid, guint spacing);
guint gtk_grid_get_row_spacing(GtkGrid *grid);
guint gtk_grid_get_column_spacing(GtkGrid *grid);

/* Widget expand / align / margin（Grid/Form/Group 共用） */
void gtk_widget_set_hexpand(GtkWidget *widget, gboolean expand);
void gtk_widget_set_vexpand(GtkWidget *widget, gboolean expand);
void gtk_widget_set_halign(GtkWidget *widget, int align);
void gtk_widget_set_valign(GtkWidget *widget, int align);
gboolean gtk_widget_get_hexpand(GtkWidget *widget);
gboolean gtk_widget_get_vexpand(GtkWidget *widget);
void gtk_widget_set_margin_start(GtkWidget *widget, gint margin);
void gtk_widget_set_margin_end(GtkWidget *widget, gint margin);
void gtk_widget_set_margin_top(GtkWidget *widget, gint margin);
void gtk_widget_set_margin_bottom(GtkWidget *widget, gint margin);
C);
    }

    /* ==============================================================
     * 生命周期
     * ============================================================ */

    /**
     * 初始化 GTK：加载 FFI 库并调用 gtk_init_without_args。
     *
     * @throws UiException gtk_init 失败
     */
    public function init(): void
    {
        if ($this->gtk !== null) {
            return; // 已初始化
        }
        $this->loadLibraries();
        try {
            SafeCall::invoke($this->gtk, 'gtk_init_without_args', []);
        } catch (\Throwable $e) {
            throw new UiException("gtk_init failed: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * 进入 GTK 主事件循环（阻塞直至所有窗口关闭或调用 quit）。
     */
    public function main(): void
    {
        SafeCall::invoke($this->gtk, 'gtk_main', []);
    }

    /**
     * 执行一次 GTK 主循环迭代。
     *
     * @param bool $wait 是否阻塞等待事件
     * @return bool 是否仍有事件需要处理
     */
    public function mainStep(bool $wait = false): bool
    {
        return (bool) SafeCall::invoke(
            $this->gobj,
            'g_main_context_iteration',
            [null, $wait ? 1 : 0]
        );
    }

    /**
     * 退出 GTK 主循环。
     */
    public function quit(): void
    {
        SafeCall::invoke($this->gtk, 'gtk_main_quit', []);
    }

    /**
     * 释放后端资源。GTK 无显式 uninit，仅清理 PHP 端注册表引用。
     */
    public function uninit(): void
    {
        // GTK 无显式 uninit 调用
    }

    /**
     * 注册定时器。
     *
     * 包一层：GSourceFunc 签名是 int(*)(void* $data)，返回 1 继续 0 停止。
     * 用户闭包返回 bool，true 继续 false 停止。
     *
     * @param int      $ms 间隔毫秒数
     * @param \Closure $cb  回调函数
     */
    public function timer(int $ms, \Closure $cb): void
    {
        $wrapped = function ($data) use ($cb) {
            $continue = (bool) $cb();
            return $continue ? 1 : 0;
        };
        $id = SafeCall::invoke($this->gobj, 'g_timeout_add', [$ms, $wrapped, null]);
        $this->timers[(int) $id] = $wrapped; // 防 GC
    }

    /* ==============================================================
     * 窗口
     * ============================================================ */

    public function windowCreate(string $title, int $w, int $h): mixed
    {
        $win = SafeCall::expectNotNull($this->gtk, 'gtk_window_new', [0 /* GTK_WINDOW_TOPLEVEL */]);
        SafeCall::invoke($this->gtk, 'gtk_window_set_title', [$win, $title]);
        SafeCall::invoke($this->gtk, 'gtk_window_set_default_size', [$win, $w, $h]);
        return $win;
    }

    public function windowSetTitle(mixed $h, string $t): void
    {
        SafeCall::invoke($this->gtk, 'gtk_window_set_title', [$h, $t]);
    }

    public function windowSetSize(mixed $h, int $w, int $height): void
    {
        SafeCall::invoke($this->gtk, 'gtk_window_resize', [$h, $w, $height]);
    }

    public function windowSetPosition(mixed $h, int $x, int $y): void
    {
        SafeCall::invoke($this->gtk, 'gtk_window_move', [$h, $x, $y]);
    }

    public function windowGetPosition(mixed $h): array
    {
        // 分配两个 gint 输出参数，取地址传入，调用后读取
        $x = $this->gtk->new('gint[1]');
        $y = $this->gtk->new('gint[1]');
        SafeCall::invoke($this->gtk, 'gtk_window_get_position', [
            $h,
            \FFI::addr($x[0]),
            \FFI::addr($y[0]),
        ]);
        return ['x' => (int) $x[0], 'y' => (int) $y[0]];
    }

    public function windowSetChild(mixed $h, mixed $child): void
    {
        SafeCall::invoke($this->gtk, 'gtk_container_add', [$h, $child]);
    }

    public function windowShow(mixed $h): void
    {
        SafeCall::invoke($this->gtk, 'gtk_widget_show', [$h]);
    }

    public function windowHide(mixed $h): void
    {
        SafeCall::invoke($this->gtk, 'gtk_widget_hide', [$h]);
    }

    /**
     * 注册窗口关闭回调。
     *
     * 语义反转：
     *   - libui 语义：闭包返回 true 允许关闭，返回 false 阻止关闭
     *   - GTK 语义：delete-event 回调返回 true 阻止关闭，返回 false 不阻止
     *
     * 因此 wrapper 把 libui 的允许标志反转：允许时返回 0（不阻止），阻止时返回 1。
     *
     * @param mixed    $h  窗口句柄
     * @param \Closure $cb 回调函数，签名 fn(mixed $widget): bool
     */
    public function windowOnClosing(mixed $h, \Closure $cb): void
    {
        $wrapped = function ($widget, $data) use ($cb) {
            $allow = (bool) $cb($widget);
            return $allow ? 0 : 1; // 反转：libui true 允许关闭 → GTK 0 不阻止
        };
        SafeCall::invoke($this->gobj, 'g_signal_connect_data', [
            $h, "delete-event", $wrapped, null, null, 0,
        ]);
        $this->closures[spl_object_id($h) . ':close'] = $wrapped;
    }

    public function windowOnResize(mixed $h, \Closure $cb): void
    {
        $wrapped = function ($widget, $data) use ($cb) {
            $cb($widget);
        };
        SafeCall::invoke($this->gobj, 'g_signal_connect_data', [
            $h, "configure-event", $wrapped, null, null, 0,
        ]);
        $this->closures[spl_object_id($h) . ':resize'] = $wrapped;
    }

    public function windowDestroy(mixed $h): void
    {
        SafeCall::invoke($this->gtk, 'gtk_widget_destroy', [$h]);
    }

    /* ==============================================================
     * 通用控件
     * ============================================================ */

    public function controlShow(mixed $h): void
    {
        SafeCall::invoke($this->gtk, 'gtk_widget_show', [$h]);
    }

    public function controlHide(mixed $h): void
    {
        SafeCall::invoke($this->gtk, 'gtk_widget_hide', [$h]);
    }

    public function controlEnable(mixed $h): void
    {
        SafeCall::invoke($this->gtk, 'gtk_widget_set_sensitive', [$h, 1]);
    }

    public function controlDisable(mixed $h): void
    {
        SafeCall::invoke($this->gtk, 'gtk_widget_set_sensitive', [$h, 0]);
    }

    public function controlDestroy(mixed $h): void
    {
        SafeCall::invoke($this->gtk, 'gtk_widget_destroy', [$h]);
        $id = spl_object_id($h);
        $prefix = $id . ':';
        foreach (array_keys($this->closures) as $k) {
            if (str_starts_with($k, $prefix)) {
                unset($this->closures[$k]);
            }
        }
        // 清理布局容器相关注册表，避免悬空引用残留
        unset(
            $this->boxChildren[$id],
            $this->formChildren[$id],
            $this->formRows[$id],
            $this->formPadded[$id],
            $this->groupChildren[$id]
        );
    }

    /* ==============================================================
     * Button
     * ============================================================ */

    public function buttonCreate(string $text): mixed
    {
        return SafeCall::expectNotNull($this->gtk, 'gtk_button_new_with_label', [$text]);
    }

    public function buttonGetText(mixed $h): string
    {
        $result = SafeCall::invoke($this->gtk, 'gtk_button_get_label', [$h]);
        if ($result === null) {
            return '';
        }
        return \FFI::string($result);
    }

    public function buttonSetText(mixed $h, string $t): void
    {
        SafeCall::invoke($this->gtk, 'gtk_button_set_label', [$h, $t]);
    }

    public function buttonOnClicked(mixed $h, \Closure $cb): void
    {
        $wrapped = function ($widget, $data) use ($cb) {
            $cb($widget);
        };
        SafeCall::invoke($this->gobj, 'g_signal_connect_data', [
            $h, "clicked", $wrapped, null, null, 0,
        ]);
        $this->closures[spl_object_id($h) . ':click'] = $wrapped;
    }

    /* ==============================================================
     * Label
     * ============================================================ */

    public function labelCreate(string $text): mixed
    {
        return SafeCall::expectNotNull($this->gtk, 'gtk_label_new', [$text]);
    }

    public function labelGetText(mixed $h): string
    {
        $result = SafeCall::invoke($this->gtk, 'gtk_label_get_text', [$h]);
        if ($result === null) {
            return '';
        }
        return \FFI::string($result);
    }

    public function labelSetText(mixed $h, string $t): void
    {
        SafeCall::invoke($this->gtk, 'gtk_label_set_text', [$h, $t]);
    }

    /* ==============================================================
     * Entry
     * ============================================================ */

    public function entryCreate(): mixed
    {
        return SafeCall::expectNotNull($this->gtk, 'gtk_entry_new', []);
    }

    public function entryGetText(mixed $h): string
    {
        $result = SafeCall::invoke($this->gtk, 'gtk_entry_get_text', [$h]);
        if ($result === null) {
            return '';
        }
        return \FFI::string($result);
    }

    public function entrySetText(mixed $h, string $t): void
    {
        SafeCall::invoke($this->gtk, 'gtk_entry_set_text', [$h, $t]);
    }

    public function entryOnChanged(mixed $h, \Closure $cb): void
    {
        $wrapped = function ($widget, $data) use ($cb) {
            $cb($widget);
        };
        SafeCall::invoke($this->gobj, 'g_signal_connect_data', [
            $h, "changed", $wrapped, null, null, 0,
        ]);
        $this->closures[spl_object_id($h) . ':changed'] = $wrapped;
    }

    public function entrySetReadOnly(mixed $h, bool $ro): void
    {
        // gtk_editable_set_editable：true 表示可编辑，ro 时传 0
        SafeCall::invoke($this->gtk, 'gtk_editable_set_editable', [$h, $ro ? 0 : 1]);
    }

    /* ==============================================================
     * Checkbox
     * ============================================================ */

    public function checkboxCreate(string $text): mixed
    {
        return SafeCall::expectNotNull($this->gtk, 'gtk_check_button_new_with_label', [$text]);
    }

    public function checkboxGetText(mixed $h): string
    {
        // GtkCheckButton 继承 GtkButton，复用 gtk_button_get_label
        $result = SafeCall::invoke($this->gtk, 'gtk_button_get_label', [$h]);
        if ($result === null) {
            return '';
        }
        return \FFI::string($result);
    }

    public function checkboxSetText(mixed $h, string $t): void
    {
        SafeCall::invoke($this->gtk, 'gtk_button_set_label', [$h, $t]);
    }

    public function checkboxIsChecked(mixed $h): bool
    {
        return (bool) SafeCall::invoke($this->gtk, 'gtk_check_button_get_active', [$h]);
    }

    public function checkboxSetChecked(mixed $h, bool $c): void
    {
        SafeCall::invoke($this->gtk, 'gtk_check_button_set_active', [$h, $c ? 1 : 0]);
    }

    public function checkboxOnToggled(mixed $h, \Closure $cb): void
    {
        $wrapped = function ($widget, $data) use ($cb) {
            $cb($widget);
        };
        SafeCall::invoke($this->gobj, 'g_signal_connect_data', [
            $h, "toggled", $wrapped, null, null, 0,
        ]);
        $this->closures[spl_object_id($h) . ':toggled'] = $wrapped;
    }

    /* ==============================================================
     * Box
     * ============================================================ */

    public function boxCreate(bool $horizontal): mixed
    {
        // GTK_ORIENTATION_HORIZONTAL = 0, GTK_ORIENTATION_VERTICAL = 1
        $orientation = $horizontal ? 0 : 1;
        $box = SafeCall::expectNotNull($this->gtk, 'gtk_box_new', [$orientation, 0]);
        $this->boxChildren[spl_object_id($box)] = [];
        return $box;
    }

    public function boxAppend(mixed $h, mixed $child, bool $stretchy): void
    {
        $expand = $stretchy ? 1 : 0;
        $fill = $stretchy ? 1 : 0;
        SafeCall::invoke($this->gtk, 'gtk_box_pack_start', [$h, $child, $expand, $fill, 0]);
        // 维护 PHP 端索引以便 boxRemove 按索引取控件
        $key = spl_object_id($h);
        if (!isset($this->boxChildren[$key])) {
            $this->boxChildren[$key] = [];
        }
        $this->boxChildren[$key][] = $child;
    }

    /**
     * 按索引从盒子移除子控件。
     *
     * GTK 没有"按索引移除"的原生 API，gtk_container_remove 需要传 widget 指针。
     * 因此在 PHP 端维护 boxChildren[spl_object_id(box)] = [widget1, widget2, ...]
     * 索引表，boxAppend 时追加，boxRemove 时按索引取出 widget 调
     * gtk_container_remove 后用 array_splice 保持索引连续。
     *
     * @param mixed $h     盒子句柄
     * @param int   $index 子控件索引
     * @throws UiException box 未跟踪或索引越界
     */
    public function boxRemove(mixed $h, int $index): void
    {
        $key = spl_object_id($h);
        if (!isset($this->boxChildren[$key])) {
            throw new UiException("boxRemove: box not tracked (spl_object_id=$key)");
        }
        if (!array_key_exists($index, $this->boxChildren[$key])) {
            throw new UiException("boxRemove: invalid index $index");
        }
        $child = $this->boxChildren[$key][$index];
        SafeCall::invoke($this->gtk, 'gtk_container_remove', [$h, $child]);
        // 保持索引连续：用 array_splice 移除并重新索引
        array_splice($this->boxChildren[$key], $index, 1);
    }

    public function boxSetPadded(mixed $h, bool $p): void
    {
        // padded 时设 4px 间距，否则 0
        SafeCall::invoke($this->gtk, 'gtk_box_set_spacing', [$h, $p ? 4 : 0]);
    }

    /* ==============================================================
     * Separator
     * ============================================================ */

    public function separatorCreate(bool $horizontal): mixed
    {
        $orientation = $horizontal ? 0 : 1;
        return SafeCall::expectNotNull($this->gtk, 'gtk_separator_new', [$orientation]);
    }

    /* ==============================================================
     * Tab（GtkNotebook）
     * ============================================================ */

    /**
     * 创建多页签容器。
     *
     * 用 GtkNotebook 实现。GTK Notebook 自带页签切换交互，
     * tab_label 参数传 NULL 时使用默认标签，此处统一创建 GtkLabel。
     *
     * @return mixed 页签容器句柄
     */
    public function tabCreate(): mixed
    {
        return SafeCall::expectNotNull($this->gtk, 'gtk_notebook_new', []);
    }

    public function tabAppend(mixed $h, string $name, mixed $child): void
    {
        // 先创建标签控件，再追加页（tab_label 不能为空字符串否则部分 GTK 版本会显示默认标签）
        $label = SafeCall::expectNotNull($this->gtk, 'gtk_label_new', [$name]);
        SafeCall::invoke($this->gtk, 'gtk_notebook_append_page', [$h, $child, $label]);
    }

    public function tabInsertAt(mixed $h, string $name, int $index, mixed $child): void
    {
        $label = SafeCall::expectNotNull($this->gtk, 'gtk_label_new', [$name]);
        SafeCall::invoke($this->gtk, 'gtk_notebook_insert_page', [$h, $child, $label, $index]);
    }

    public function tabDelete(mixed $h, int $index): void
    {
        SafeCall::invoke($this->gtk, 'gtk_notebook_remove_page', [$h, $index]);
    }

    public function tabNumPages(mixed $h): int
    {
        return (int) SafeCall::invoke($this->gtk, 'gtk_notebook_get_n_pages', [$h]);
    }

    public function tabGetSelected(mixed $h): int
    {
        return (int) SafeCall::invoke($this->gtk, 'gtk_notebook_get_current_page', [$h]);
    }

    public function tabSetSelected(mixed $h, int $index): void
    {
        SafeCall::invoke($this->gtk, 'gtk_notebook_set_current_page', [$h, $index]);
    }

    public function tabGetMargined(mixed $h, int $index): bool
    {
        // GTK 无原生 margined 概念，简化为始终返回 false
        return false;
    }

    public function tabSetMargined(mixed $h, int $index, bool $m): void
    {
        // GTK 无原生 margined 概念，简化为 no-op
        // 完整实现需跟踪页索引到子控件映射后调用 gtk_widget_set_margin_start/end/top/bottom
    }

    /* ==============================================================
     * Group（GtkFrame + GtkLabel）
     * ============================================================ */

    /**
     * 创建带标题的容器组。
     *
     * 用 GtkFrame 实现。GtkFrame 自带边框和可选标题，与 libui 的 uiGroup 语义一致。
     *
     * @param string $title 组标题
     * @return mixed 容器组句柄
     */
    public function groupCreate(string $title): mixed
    {
        return SafeCall::expectNotNull($this->gtk, 'gtk_frame_new', [$title]);
    }

    public function groupGetTitle(mixed $h): string
    {
        $result = SafeCall::invoke($this->gtk, 'gtk_frame_get_label', [$h]);
        if ($result === null) {
            return '';
        }
        return \FFI::string($result);
    }

    public function groupSetTitle(mixed $h, string $t): void
    {
        SafeCall::invoke($this->gtk, 'gtk_frame_set_label', [$h, $t]);
    }

    public function groupSetChild(mixed $h, mixed $child): void
    {
        SafeCall::invoke($this->gtk, 'gtk_container_add', [$h, $child]);
        // 记录子控件引用，供 groupSetMargined 间接设置 margin
        $this->groupChildren[spl_object_id($h)] = $child;
    }

    public function groupGetMargined(mixed $h): bool
    {
        // GTK 无原生 margined 概念，简化为始终返回 false
        return false;
    }

    public function groupSetMargined(mixed $h, bool $m): void
    {
        // 用子控件的 margin 间接实现 group 的 margined 语义
        $id = spl_object_id($h);
        if (!isset($this->groupChildren[$id])) {
            return; // 未设置子控件，no-op
        }
        $child = $this->groupChildren[$id];
        $margin = $m ? 8 : 0;
        SafeCall::invoke($this->gtk, 'gtk_widget_set_margin_start', [$child, $margin]);
        SafeCall::invoke($this->gtk, 'gtk_widget_set_margin_end', [$child, $margin]);
        SafeCall::invoke($this->gtk, 'gtk_widget_set_margin_top', [$child, $margin]);
        SafeCall::invoke($this->gtk, 'gtk_widget_set_margin_bottom', [$child, $margin]);
    }

    /* ==============================================================
     * Form（GtkGrid 1 列布局）
     * ============================================================ */

    /**
     * 创建表单布局容器。
     *
     * 用 GtkGrid 实现：每行第 0 列放标签，第 1 列放子控件，多行垂直堆叠。
     *
     * @return mixed 表单容器句柄
     */
    public function formCreate(): mixed
    {
        $grid = SafeCall::expectNotNull($this->gtk, 'gtk_grid_new', []);
        $id = spl_object_id($grid);
        $this->formChildren[$id] = [];
        $this->formRows[$id] = 0;
        $this->formPadded[$id] = false;
        return $grid;
    }

    public function formAppend(mixed $h, string $label, mixed $child, bool $stretchy): void
    {
        $id = spl_object_id($h);
        if (!isset($this->formRows[$id])) {
            // 兼容直接对 GtkGrid 调用而非经 formCreate 创建的情况
            $this->formRows[$id] = 0;
            $this->formChildren[$id] = [];
        }
        $row = $this->formRows[$id];
        // 标签控件 attach 到第 0 列
        $labelWidget = SafeCall::expectNotNull($this->gtk, 'gtk_label_new', [$label]);
        SafeCall::invoke($this->gtk, 'gtk_grid_attach', [$h, $labelWidget, 0, $row, 1, 1]);
        // 子控件 attach 到第 1 列；stretchy 时 vexpand=true 让控件垂直拉伸
        SafeCall::invoke($this->gtk, 'gtk_grid_attach', [$h, $child, 1, $row, 1, 1]);
        if ($stretchy) {
            SafeCall::invoke($this->gtk, 'gtk_widget_set_vexpand', [$child, 1]);
        }
        $this->formChildren[$id][] = [$labelWidget, $child];
        $this->formRows[$id] = $row + 1;
    }

    /**
     * 删除表单中指定索引的子项。
     *
     * GTK 无按索引删除 grid 子控件的 API，采用「清空 + 重建」策略：
     * 用 gtk_container_remove 移除全部已跟踪子控件，再按剩余项重新 attach。
     *
     * @param mixed $h     表单容器句柄
     * @param int   $index 表单项索引
     * @throws UiException 索引越界
     */
    public function formDelete(mixed $h, int $index): void
    {
        $id = spl_object_id($h);
        if (!isset($this->formChildren[$id]) || !array_key_exists($index, $this->formChildren[$id])) {
            throw new UiException("formDelete: invalid index $index");
        }
        // 移除当前所有子控件
        foreach ($this->formChildren[$id] as [$labelWidget, $child]) {
            SafeCall::invoke($this->gtk, 'gtk_container_remove', [$h, $labelWidget]);
            SafeCall::invoke($this->gtk, 'gtk_container_remove', [$h, $child]);
        }
        // 从列表中删除指定项并保持索引连续
        array_splice($this->formChildren[$id], $index, 1);
        // 重新 attach 剩余项
        $row = 0;
        foreach ($this->formChildren[$id] as [$labelWidget, $child]) {
            SafeCall::invoke($this->gtk, 'gtk_grid_attach', [$h, $labelWidget, 0, $row, 1, 1]);
            SafeCall::invoke($this->gtk, 'gtk_grid_attach', [$h, $child, 1, $row, 1, 1]);
            $row++;
        }
        $this->formRows[$id] = $row;
    }

    public function formNumChildren(mixed $h): int
    {
        $id = spl_object_id($h);
        return isset($this->formChildren[$id]) ? count($this->formChildren[$id]) : 0;
    }

    public function formGetPadded(mixed $h): bool
    {
        $id = spl_object_id($h);
        return $this->formPadded[$id] ?? false;
    }

    public function formSetPadded(mixed $h, bool $p): void
    {
        $id = spl_object_id($h);
        $this->formPadded[$id] = $p;
        $spacing = $p ? 4 : 0;
        SafeCall::invoke($this->gtk, 'gtk_grid_set_row_spacing', [$h, $spacing]);
        SafeCall::invoke($this->gtk, 'gtk_grid_set_column_spacing', [$h, $spacing]);
    }

    /* ==============================================================
     * Grid（GtkGrid 完整二维）
     * ============================================================ */

    /**
     * 创建网格布局容器。
     *
     * 用 GtkGrid 实现二维网格布局。子控件按 (left, top) 坐标 attach。
     *
     * uiAlign → GtkAlign 数值完全一致，直接透传：
     *   Fill=0 → GTK_ALIGN_FILL=0, Start=1 → GTK_ALIGN_START=1,
     *   Center=2 → GTK_ALIGN_CENTER=2, End=3 → GTK_ALIGN_END=3
     *
     * @return mixed 网格容器句柄
     */
    public function gridCreate(): mixed
    {
        return SafeCall::expectNotNull($this->gtk, 'gtk_grid_new', []);
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
        SafeCall::invoke($this->gtk, 'gtk_grid_attach', [$h, $child, $left, $top, $xspan, $yspan]);
        SafeCall::invoke($this->gtk, 'gtk_widget_set_hexpand', [$child, $hexpand ? 1 : 0]);
        SafeCall::invoke($this->gtk, 'gtk_widget_set_vexpand', [$child, $vexpand ? 1 : 0]);
        SafeCall::invoke($this->gtk, 'gtk_widget_set_halign', [$child, $halign]);
        SafeCall::invoke($this->gtk, 'gtk_widget_set_valign', [$child, $valign]);
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
        // uiAt → GtkPositionType 映射（GtkPositionType: LEFT=0, RIGHT=1, TOP=2, BOTTOM=3）：
        // Leading(0) → LEFT(0), Top(1) → TOP(2), Trailing(2) → RIGHT(1), Bottom(3) → BOTTOM(3)
        $sideMap = [0 => 0, 1 => 2, 2 => 1, 3 => 3];
        $side = $sideMap[$at] ?? 0;
        SafeCall::invoke($this->gtk, 'gtk_grid_attach_next_to', [$h, $child, $existing, $side, $xspan, $yspan]);
        SafeCall::invoke($this->gtk, 'gtk_widget_set_hexpand', [$child, $hexpand ? 1 : 0]);
        SafeCall::invoke($this->gtk, 'gtk_widget_set_vexpand', [$child, $vexpand ? 1 : 0]);
        SafeCall::invoke($this->gtk, 'gtk_widget_set_halign', [$child, $halign]);
        SafeCall::invoke($this->gtk, 'gtk_widget_set_valign', [$child, $valign]);
    }

    public function gridGetPadded(mixed $h): bool
    {
        return (bool) SafeCall::invoke($this->gtk, 'gtk_grid_get_row_spacing', [$h]);
    }

    public function gridSetPadded(mixed $h, bool $p): void
    {
        $spacing = $p ? 4 : 0;
        SafeCall::invoke($this->gtk, 'gtk_grid_set_row_spacing', [$h, $spacing]);
        SafeCall::invoke($this->gtk, 'gtk_grid_set_column_spacing', [$h, $spacing]);
    }
}
