<?php
declare(strict_types=1);

namespace Kingbes\Ui\Platform;

use Kingbes\Ui\Exception\UiException;

/**
 * 平台后端抽象基类。
 *
 * 定义所有 GUI 后端必须实现的原始操作。具体后端（Windows / Linux Gtk /
 * macOS Cocoa）通过继承本类并提供 FFI 实现来完成跨平台支持。
 *
 * 句柄类型在签名中标注为 mixed，实际在各后端实现中为 \FFI\CData。
 */
abstract class Platform
{
    /**
     * 当前平台后端单例缓存。
     */
    protected static ?Platform $current = null;

    /**
     * 获取当前平台后端实例。
     *
     * 根据 PHP_OS_FAMILY 选择对应后端，首次调用时实例化并缓存，
     * 后续调用直接返回同一实例。
     *
     * @return static 当前平台后端
     * @throws UiException 当运行在不支持的平台时
     */
    public static function current(): static
    {
        if (static::$current === null) {
            static::$current = match (PHP_OS_FAMILY) {
                'Windows' => new Windows\WindowsPlatform(),
                'Linux'   => new Linux\GtkPlatform(),
                'Darwin'  => new Macos\CocoaPlatform(),
                default   => throw new UiException("Unsupported platform: " . PHP_OS_FAMILY),
            };
        }

        return static::$current;
    }

    /* -----------------------------------------------------------------
     * 生命周期
     * --------------------------------------------------------------- */

    /**
     * 初始化后端（加载 FFI、注册全局资源等）。
     */
    abstract public function init(): void;

    /**
     * 释放后端资源。
     */
    abstract public function uninit(): void;

    /**
     * 进入主事件循环（阻塞直至所有窗口关闭或调用 quit）。
     */
    abstract public function main(): void;

    /**
     * 执行一次事件循环迭代。
     *
     * @param bool $wait 是否阻塞等待事件
     * @return bool 是否仍有事件需要处理
     */
    abstract public function mainStep(bool $wait = false): bool;

    /**
     * 退出主事件循环。
     */
    abstract public function quit(): void;

    /**
     * 注册定时器。
     *
     * @param int      $ms  间隔毫秒数
     * @param \Closure $cb  回调函数
     */
    abstract public function timer(int $ms, \Closure $cb): void;

    /* -----------------------------------------------------------------
     * 窗口
     * --------------------------------------------------------------- */

    /**
     * 创建顶层窗口。
     *
     * @param string $title 窗口标题
     * @param int    $w     宽度
     * @param int    $h     高度
     * @return mixed 窗口句柄
     */
    abstract public function windowCreate(string $title, int $w, int $h): mixed;

    /**
     * 设置窗口标题。
     *
     * @param mixed  $h 窗口句柄
     * @param string $t 标题
     */
    abstract public function windowSetTitle(mixed $h, string $t): void;

    /**
     * 设置窗口尺寸。
     *
     * @param mixed $h      窗口句柄
     * @param int   $w      宽度
     * @param int   $height 高度
     */
    abstract public function windowSetSize(mixed $h, int $w, int $height): void;

    /**
     * 设置窗口位置。
     *
     * @param mixed $h 窗口句柄
     * @param int   $x X 坐标
     * @param int   $y Y 坐标
     */
    abstract public function windowSetPosition(mixed $h, int $x, int $y): void;

    /**
     * 获取窗口位置。
     *
     * @param mixed $h 窗口句柄
     * @return array 形如 ['x' => int, 'y' => int]
     */
    abstract public function windowGetPosition(mixed $h): array;

    /**
     * 设置窗口的子控件。
     *
     * @param mixed $h     窗口句柄
     * @param mixed $child 子控件句柄
     */
    abstract public function windowSetChild(mixed $h, mixed $child): void;

    /**
     * 显示窗口。
     *
     * @param mixed $h 窗口句柄
     */
    abstract public function windowShow(mixed $h): void;

    /**
     * 隐藏窗口。
     *
     * @param mixed $h 窗口句柄
     */
    abstract public function windowHide(mixed $h): void;

    /**
     * 注册窗口关闭回调。
     *
     * @param mixed    $h  窗口句柄
     * @param \Closure $cb 回调函数
     */
    abstract public function windowOnClosing(mixed $h, \Closure $cb): void;

    /**
     * 注册窗口尺寸变化回调。
     *
     * @param mixed    $h  窗口句柄
     * @param \Closure $cb 回调函数
     */
    abstract public function windowOnResize(mixed $h, \Closure $cb): void;

    /**
     * 销毁窗口。
     *
     * @param mixed $h 窗口句柄
     */
    abstract public function windowDestroy(mixed $h): void;

    /* -----------------------------------------------------------------
     * 通用控件
     * --------------------------------------------------------------- */

    /**
     * 显示控件。
     *
     * @param mixed $h 控件句柄
     */
    abstract public function controlShow(mixed $h): void;

    /**
     * 隐藏控件。
     *
     * @param mixed $h 控件句柄
     */
    abstract public function controlHide(mixed $h): void;

    /**
     * 启用控件。
     *
     * @param mixed $h 控件句柄
     */
    abstract public function controlEnable(mixed $h): void;

    /**
     * 禁用控件。
     *
     * @param mixed $h 控件句柄
     */
    abstract public function controlDisable(mixed $h): void;

    /**
     * 销毁控件。
     *
     * @param mixed $h 控件句柄
     */
    abstract public function controlDestroy(mixed $h): void;

    /* -----------------------------------------------------------------
     * Button
     * --------------------------------------------------------------- */

    /**
     * 创建按钮。
     *
     * @param string $text 按钮文本
     * @return mixed 按钮句柄
     */
    abstract public function buttonCreate(string $text): mixed;

    /**
     * 获取按钮文本。
     *
     * @param mixed $h 按钮句柄
     * @return string 按钮文本
     */
    abstract public function buttonGetText(mixed $h): string;

    /**
     * 设置按钮文本。
     *
     * @param mixed  $h 按钮句柄
     * @param string $t 文本
     */
    abstract public function buttonSetText(mixed $h, string $t): void;

    /**
     * 注册按钮点击回调。
     *
     * @param mixed    $h  按钮句柄
     * @param \Closure $cb 回调函数
     */
    abstract public function buttonOnClicked(mixed $h, \Closure $cb): void;

    /* -----------------------------------------------------------------
     * Label
     * --------------------------------------------------------------- */

    /**
     * 创建标签。
     *
     * @param string $text 标签文本
     * @return mixed 标签句柄
     */
    abstract public function labelCreate(string $text): mixed;

    /**
     * 获取标签文本。
     *
     * @param mixed $h 标签句柄
     * @return string 标签文本
     */
    abstract public function labelGetText(mixed $h): string;

    /**
     * 设置标签文本。
     *
     * @param mixed  $h 标签句柄
     * @param string $t 文本
     */
    abstract public function labelSetText(mixed $h, string $t): void;

    /* -----------------------------------------------------------------
     * Entry
     * --------------------------------------------------------------- */

    /**
     * 创建单行输入框。
     *
     * @return mixed 输入框句柄
     */
    abstract public function entryCreate(): mixed;

    /**
     * 获取输入框文本。
     *
     * @param mixed $h 输入框句柄
     * @return string 文本
     */
    abstract public function entryGetText(mixed $h): string;

    /**
     * 设置输入框文本。
     *
     * @param mixed  $h 输入框句柄
     * @param string $t 文本
     */
    abstract public function entrySetText(mixed $h, string $t): void;

    /**
     * 注册输入框内容变化回调。
     *
     * @param mixed    $h  输入框句柄
     * @param \Closure $cb 回调函数
     */
    abstract public function entryOnChanged(mixed $h, \Closure $cb): void;

    /**
     * 设置输入框只读状态。
     *
     * @param mixed $h  输入框句柄
     * @param bool  $ro 是否只读
     */
    abstract public function entrySetReadOnly(mixed $h, bool $ro): void;

    /* -----------------------------------------------------------------
     * Checkbox
     * --------------------------------------------------------------- */

    /**
     * 创建复选框。
     *
     * @param string $text 复选框文本
     * @return mixed 复选框句柄
     */
    abstract public function checkboxCreate(string $text): mixed;

    /**
     * 获取复选框文本。
     *
     * @param mixed $h 复选框句柄
     * @return string 文本
     */
    abstract public function checkboxGetText(mixed $h): string;

    /**
     * 设置复选框文本。
     *
     * @param mixed  $h 复选框句柄
     * @param string $t 文本
     */
    abstract public function checkboxSetText(mixed $h, string $t): void;

    /**
     * 查询复选框是否选中。
     *
     * @param mixed $h 复选框句柄
     * @return bool 是否选中
     */
    abstract public function checkboxIsChecked(mixed $h): bool;

    /**
     * 设置复选框选中状态。
     *
     * @param mixed $h 复选框句柄
     * @param bool  $c 是否选中
     */
    abstract public function checkboxSetChecked(mixed $h, bool $c): void;

    /**
     * 注册复选框状态切换回调。
     *
     * @param mixed    $h  复选框句柄
     * @param \Closure $cb 回调函数
     */
    abstract public function checkboxOnToggled(mixed $h, \Closure $cb): void;

    /* -----------------------------------------------------------------
     * Box
     * --------------------------------------------------------------- */

    /**
     * 创建容器盒子。
     *
     * @param bool $horizontal true 为水平布局，false 为垂直布局
     * @return mixed 盒子句柄
     */
    abstract public function boxCreate(bool $horizontal): mixed;

    /**
     * 向盒子追加子控件。
     *
     * @param mixed $h        盒子句柄
     * @param mixed $child    子控件句柄
     * @param bool  $stretchy 是否拉伸占据剩余空间
     */
    abstract public function boxAppend(mixed $h, mixed $child, bool $stretchy): void;

    /**
     * 从盒子移除指定索引位置的子控件。
     *
     * @param mixed $h     盒子句柄
     * @param int   $index 子控件索引
     */
    abstract public function boxRemove(mixed $h, int $index): void;

    /**
     * 设置盒子是否启用内边距填充。
     *
     * @param mixed $h 盒子句柄
     * @param bool  $p 是否填充
     */
    abstract public function boxSetPadded(mixed $h, bool $p): void;

    /* -----------------------------------------------------------------
     * Separator
     * --------------------------------------------------------------- */

    /**
     * 创建分隔线。
     *
     * @param bool $horizontal true 为水平分隔线，false 为垂直分隔线
     * @return mixed 分隔线句柄
     */
    abstract public function separatorCreate(bool $horizontal): mixed;

    /* -----------------------------------------------------------------
     * Tab
     * --------------------------------------------------------------- */

    /**
     * 创建多页签容器。
     *
     * 对应 libui-ng 的 uiTab，子控件以标签页形式堆叠，一次只显示一页。
     *
     * @return mixed 页签容器句柄
     */
    abstract public function tabCreate(): mixed;

    /**
     * 在末尾追加一页。
     *
     * @param mixed  $h     页签容器句柄
     * @param string $name  页签标题
     * @param mixed  $child 子控件句柄
     */
    abstract public function tabAppend(mixed $h, string $name, mixed $child): void;

    /**
     * 在指定位置插入一页。
     *
     * @param mixed  $h     页签容器句柄
     * @param string $name  页签标题
     * @param int    $index 插入位置的从 0 开始索引
     * @param mixed  $child 子控件句柄
     */
    abstract public function tabInsertAt(mixed $h, string $name, int $index, mixed $child): void;

    /**
     * 删除指定位置的页签及其子控件。
     *
     * @param mixed $h     页签容器句柄
     * @param int   $index 页签索引
     */
    abstract public function tabDelete(mixed $h, int $index): void;

    /**
     * 获取页签数量。
     *
     * @param mixed $h 页签容器句柄
     * @return int 页数
     */
    abstract public function tabNumPages(mixed $h): int;

    /**
     * 获取当前选中的页签索引。
     *
     * @param mixed $h 页签容器句柄
     * @return int 当前选中页索引，-1 表示无选中页
     */
    abstract public function tabGetSelected(mixed $h): int;

    /**
     * 设置当前选中的页签。
     *
     * @param mixed $h     页签容器句柄
     * @param int   $index 要选中的页签索引
     */
    abstract public function tabSetSelected(mixed $h, int $index): void;

    /**
     * 查询指定页是否启用了边距。
     *
     * @param mixed $h     页签容器句柄
     * @param int   $index 页签索引
     * @return bool 是否启用边距
     */
    abstract public function tabGetMargined(mixed $h, int $index): bool;

    /**
     * 设置指定页的边距启用状态。
     *
     * @param mixed $h     页签容器句柄
     * @param int   $index 页签索引
     * @param bool  $m     是否启用边距
     */
    abstract public function tabSetMargined(mixed $h, int $index, bool $m): void;

    /* -----------------------------------------------------------------
     * Group
     * --------------------------------------------------------------- */

    /**
     * 创建带标题的容器组。
     *
     * 对应 libui-ng 的 uiGroup，仅能容纳一个子控件，标题显示在边框上方。
     *
     * @param string $title 组标题
     * @return mixed 容器组句柄
     */
    abstract public function groupCreate(string $title): mixed;

    /**
     * 获取容器组标题。
     *
     * @param mixed $h 容器组句柄
     * @return string 标题
     */
    abstract public function groupGetTitle(mixed $h): string;

    /**
     * 设置容器组标题。
     *
     * @param mixed  $h 容器组句柄
     * @param string $t 标题
     */
    abstract public function groupSetTitle(mixed $h, string $t): void;

    /**
     * 设置容器组的子控件（替换已有子控件）。
     *
     * @param mixed $h     容器组句柄
     * @param mixed $child 子控件句柄
     */
    abstract public function groupSetChild(mixed $h, mixed $child): void;

    /**
     * 查询容器组是否启用边距。
     *
     * @param mixed $h 容器组句柄
     * @return bool 是否启用边距
     */
    abstract public function groupGetMargined(mixed $h): bool;

    /**
     * 设置容器组边距启用状态。
     *
     * @param mixed $h 容器组句柄
     * @param bool  $m 是否启用边距
     */
    abstract public function groupSetMargined(mixed $h, bool $m): void;

    /* -----------------------------------------------------------------
     * Form
     * --------------------------------------------------------------- */

    /**
     * 创建表单布局容器。
     *
     * 对应 libui-ng 的 uiForm，子控件以「标签-控件」对的形式垂直排列。
     *
     * @return mixed 表单容器句柄
     */
    abstract public function formCreate(): mixed;

    /**
     * 向表单追加一组「标签-控件」对。
     *
     * @param mixed  $h        表单容器句柄
     * @param string $label    标签文本
     * @param mixed  $child    子控件句柄
     * @param bool   $stretchy 该行是否拉伸占据剩余空间
     */
    abstract public function formAppend(mixed $h, string $label, mixed $child, bool $stretchy): void;

    /**
     * 删除指定索引位置的表单项。
     *
     * @param mixed $h     表单容器句柄
     * @param int   $index 表单项索引
     */
    abstract public function formDelete(mixed $h, int $index): void;

    /**
     * 获取表单中的子项数量。
     *
     * @param mixed $h 表单容器句柄
     * @return int 子项数量
     */
    abstract public function formNumChildren(mixed $h): int;

    /**
     * 查询表单是否启用内边距填充。
     *
     * @param mixed $h 表单容器句柄
     * @return bool 是否填充
     */
    abstract public function formGetPadded(mixed $h): bool;

    /**
     * 设置表单是否启用内边距填充。
     *
     * @param mixed $h 表单容器句柄
     * @param bool  $p 是否填充
     */
    abstract public function formSetPadded(mixed $h, bool $p): void;

    /* -----------------------------------------------------------------
     * Grid
     * --------------------------------------------------------------- */

    /**
     * 创建网格布局容器。
     *
     * 对应 libui-ng 的 uiGrid，子控件按 (left, top) 网格坐标定位。
     *
     * uiAlign 枚举值：Fill=0, Start=1, Center=2, End=3。
     * uiAt 枚举值：Leading=0, Top=1, Trailing=2, Bottom=3。
     *
     * @return mixed 网格容器句柄
     */
    abstract public function gridCreate(): mixed;

    /**
     * 在网格指定坐标处追加子控件。
     *
     * @param mixed $h       网格容器句柄
     * @param mixed $child   子控件句柄
     * @param int   $left    起始列（从 0 开始）
     * @param int   $top     起始行（从 0 开始）
     * @param int   $xspan   横向跨列数
     * @param int   $yspan   纵向跨行数
     * @param bool  $hexpand 是否水平拉伸占据剩余空间
     * @param int   $halign  水平对齐方式（uiAlign：0=Fill,1=Start,2=Center,3=End）
     * @param bool  $vexpand 是否垂直拉伸占据剩余空间
     * @param int   $valign  垂直对齐方式（uiAlign：0=Fill,1=Start,2=Center,3=End）
     */
    abstract public function gridAppend(
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
    ): void;

    /**
     * 相对已有控件的位置插入子控件。
     *
     * @param mixed $h       网格容器句柄
     * @param mixed $child   要插入的子控件句柄
     * @param mixed $existing 参照的已有控件句柄
     * @param int   $at      相对位置（uiAt：0=Leading,1=Top,2=Trailing,3=Bottom）
     * @param int   $xspan   横向跨列数
     * @param int   $yspan   纵向跨行数
     * @param bool  $hexpand 是否水平拉伸占据剩余空间
     * @param int   $halign  水平对齐方式（uiAlign：0=Fill,1=Start,2=Center,3=End）
     * @param bool  $vexpand 是否垂直拉伸占据剩余空间
     * @param int   $valign  垂直对齐方式（uiAlign：0=Fill,1=Start,2=Center,3=End）
     */
    abstract public function gridInsertAt(
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
    ): void;

    /**
     * 查询网格是否启用内边距填充。
     *
     * @param mixed $h 网格容器句柄
     * @return bool 是否填充
     */
    abstract public function gridGetPadded(mixed $h): bool;

    /**
     * 设置网格是否启用内边距填充。
     *
     * @param mixed $h 网格容器句柄
     * @param bool  $p 是否填充
     */
    abstract public function gridSetPadded(mixed $h, bool $p): void;

    /* -----------------------------------------------------------------
     * Spinbox（数值微调框）
     * --------------------------------------------------------------- */

    /**
     * 创建数值微调框。
     *
     * @param int $min 最小值
     * @param int $max 最大值
     * @return mixed 控件句柄
     */
    abstract public function spinboxCreate(int $min, int $max): mixed;

    /**
     * 获取微调框当前值。
     *
     * @param mixed $h 控件句柄
     * @return int 当前值
     */
    abstract public function spinboxGetValue(mixed $h): int;

    /**
     * 设置微调框当前值。
     *
     * @param mixed $h 控件句柄
     * @param int   $v 值
     */
    abstract public function spinboxSetValue(mixed $h, int $v): void;

    /**
     * 注册微调框值变化回调。
     *
     * @param mixed    $h  控件句柄
     * @param \Closure $cb 回调函数
     */
    abstract public function spinboxOnChanged(mixed $h, \Closure $cb): void;

    /* -----------------------------------------------------------------
     * Slider（滑块）
     * --------------------------------------------------------------- */

    /**
     * 创建滑块。
     *
     * @param int $min 最小值
     * @param int $max 最大值
     * @return mixed 控件句柄
     */
    abstract public function sliderCreate(int $min, int $max): mixed;

    /**
     * 获取滑块当前值。
     *
     * @param mixed $h 控件句柄
     * @return int 当前值
     */
    abstract public function sliderGetValue(mixed $h): int;

    /**
     * 设置滑块当前值。
     *
     * @param mixed $h 控件句柄
     * @param int   $v 值
     */
    abstract public function sliderSetValue(mixed $h, int $v): void;

    /**
     * 注册滑块值变化回调。
     *
     * @param mixed    $h  控件句柄
     * @param \Closure $cb 回调函数
     */
    abstract public function sliderOnChanged(mixed $h, \Closure $cb): void;

    /* -----------------------------------------------------------------
     * ProgressBar（进度条）
     * --------------------------------------------------------------- */

    /**
     * 创建进度条。
     *
     * @return mixed 控件句柄
     */
    abstract public function progressBarCreate(): mixed;

    /**
     * 获取进度条当前值（0-100）。
     *
     * @param mixed $h 控件句柄
     * @return int 当前值
     */
    abstract public function progressBarGetValue(mixed $h): int;

    /**
     * 设置进度条当前值（0-100，-1 表示不确定动画）。
     *
     * @param mixed $h 控件句柄
     * @param int   $v 值
     */
    abstract public function progressBarSetValue(mixed $h, int $v): void;

    /* -----------------------------------------------------------------
     * Combobox（下拉列表，不可编辑）
     * --------------------------------------------------------------- */

    /**
     * 创建下拉列表。
     *
     * @return mixed 控件句柄
     */
    abstract public function comboboxCreate(): mixed;

    /**
     * 追加一个选项。
     *
     * @param mixed  $h    控件句柄
     * @param string $name 选项文本
     */
    abstract public function comboboxAppend(mixed $h, string $name): void;

    /**
     * 在指定位置插入选项。
     *
     * @param mixed  $h     控件句柄
     * @param string $name  选项文本
     * @param int    $index 插入位置
     */
    abstract public function comboboxInsertAt(mixed $h, string $name, int $index): void;

    /**
     * 删除指定位置的选项。
     *
     * @param mixed $h     控件句柄
     * @param int   $index 位置
     */
    abstract public function comboboxDelete(mixed $h, int $index): void;

    /**
     * 清空所有选项。
     *
     * @param mixed $h 控件句柄
     */
    abstract public function comboboxClear(mixed $h): void;

    /**
     * 获取选项数量。
     *
     * @param mixed $h 控件句柄
     * @return int 数量
     */
    abstract public function comboboxNumItems(mixed $h): int;

    /**
     * 获取当前选中项索引，-1 表示无选中。
     *
     * @param mixed $h 控件句柄
     * @return int 索引
     */
    abstract public function comboboxGetSelected(mixed $h): int;

    /**
     * 设置当前选中项。
     *
     * @param mixed $h     控件句柄
     * @param int   $index 索引
     */
    abstract public function comboboxSetSelected(mixed $h, int $index): void;

    /**
     * 注册选中变化回调。
     *
     * @param mixed    $h  控件句柄
     * @param \Closure $cb 回调函数
     */
    abstract public function comboboxOnSelected(mixed $h, \Closure $cb): void;

    /* -----------------------------------------------------------------
     * EditableCombobox（可编辑下拉列表）
     * --------------------------------------------------------------- */

    /**
     * 创建可编辑下拉列表。
     *
     * @return mixed 控件句柄
     */
    abstract public function editableComboboxCreate(): mixed;

    /**
     * 追加一个选项。
     *
     * @param mixed  $h    控件句柄
     * @param string $name 选项文本
     */
    abstract public function editableComboboxAppend(mixed $h, string $name): void;

    /**
     * 在指定位置插入选项。
     *
     * @param mixed  $h     控件句柄
     * @param string $name  选项文本
     * @param int    $index 插入位置
     */
    abstract public function editableComboboxInsertAt(mixed $h, string $name, int $index): void;

    /**
     * 删除指定位置的选项。
     *
     * @param mixed $h     控件句柄
     * @param int   $index 位置
     */
    abstract public function editableComboboxDelete(mixed $h, int $index): void;

    /**
     * 清空所有选项。
     *
     * @param mixed $h 控件句柄
     */
    abstract public function editableComboboxClear(mixed $h): void;

    /**
     * 获取选项数量。
     *
     * @param mixed $h 控件句柄
     * @return int 数量
     */
    abstract public function editableComboboxNumItems(mixed $h): int;

    /**
     * 获取当前选中项索引，-1 表示无选中。
     *
     * @param mixed $h 控件句柄
     * @return int 索引
     */
    abstract public function editableComboboxGetSelected(mixed $h): int;

    /**
     * 设置当前选中项。
     *
     * @param mixed $h     控件句柄
     * @param int   $index 索引
     */
    abstract public function editableComboboxSetSelected(mixed $h, int $index): void;

    /**
     * 设置输入框文本。
     *
     * @param mixed  $h 控件句柄
     * @param string $t 文本
     */
    abstract public function editableComboboxSetText(mixed $h, string $t): void;

    /**
     * 获取输入框文本。
     *
     * @param mixed $h 控件句柄
     * @return string 文本
     */
    abstract public function editableComboboxGetText(mixed $h): string;

    /**
     * 注册文本或选中变化回调。
     *
     * @param mixed    $h  控件句柄
     * @param \Closure $cb 回调函数
     */
    abstract public function editableComboboxOnChanged(mixed $h, \Closure $cb): void;

    /* -----------------------------------------------------------------
     * RadioButtons（单选按钮组）
     * --------------------------------------------------------------- */

    /**
     * 创建单选按钮组容器。
     *
     * @return mixed 控件句柄
     */
    abstract public function radioButtonsCreate(): mixed;

    /**
     * 追加一个选项。
     *
     * @param mixed  $h    控件句柄
     * @param string $text 选项文本
     */
    abstract public function radioButtonsAppend(mixed $h, string $text): void;

    /**
     * 获取当前选中项索引，-1 表示无选中。
     *
     * @param mixed $h 控件句柄
     * @return int 索引
     */
    abstract public function radioButtonsGetSelected(mixed $h): int;

    /**
     * 设置当前选中项。
     *
     * @param mixed $h     控件句柄
     * @param int   $index 索引
     */
    abstract public function radioButtonsSetSelected(mixed $h, int $index): void;

    /**
     * 注册选中变化回调。
     *
     * @param mixed    $h  控件句柄
     * @param \Closure $cb 回调函数
     */
    abstract public function radioButtonsOnSelected(mixed $h, \Closure $cb): void;

    /* -----------------------------------------------------------------
     * MultilineEntry（多行文本输入框）
     * --------------------------------------------------------------- */

    /**
     * 创建多行文本输入框。
     *
     * @return mixed 控件句柄
     */
    abstract public function multilineEntryCreate(): mixed;

    /**
     * 获取全部文本。
     *
     * @param mixed $h 控件句柄
     * @return string 文本
     */
    abstract public function multilineEntryGetText(mixed $h): string;

    /**
     * 设置全部文本（替换）。
     *
     * @param mixed  $h 控件句柄
     * @param string $t 文本
     */
    abstract public function multilineEntrySetText(mixed $h, string $t): void;

    /**
     * 追加文本到末尾。
     *
     * @param mixed  $h 控件句柄
     * @param string $t 文本
     */
    abstract public function multilineEntryAppend(mixed $h, string $t): void;

    /**
     * 注册内容变化回调。
     *
     * @param mixed    $h  控件句柄
     * @param \Closure $cb 回调函数
     */
    abstract public function multilineEntryOnChanged(mixed $h, \Closure $cb): void;

    /**
     * 设置只读状态。
     *
     * @param mixed $h  控件句柄
     * @param bool  $ro 是否只读
     */
    abstract public function multilineEntrySetReadOnly(mixed $h, bool $ro): void;

    /* -----------------------------------------------------------------
     * PasswordEntry（密码输入框）
     * --------------------------------------------------------------- */

    /**
     * 创建密码输入框。
     *
     * @return mixed 控件句柄
     */
    abstract public function passwordEntryCreate(): mixed;

    /**
     * 获取密码文本。
     *
     * @param mixed $h 控件句柄
     * @return string 文本
     */
    abstract public function passwordEntryGetText(mixed $h): string;

    /**
     * 设置密码文本。
     *
     * @param mixed  $h 控件句柄
     * @param string $t 文本
     */
    abstract public function passwordEntrySetText(mixed $h, string $t): void;

    /**
     * 注册内容变化回调。
     *
     * @param mixed    $h  控件句柄
     * @param \Closure $cb 回调函数
     */
    abstract public function passwordEntryOnChanged(mixed $h, \Closure $cb): void;

    /* -----------------------------------------------------------------
     * SearchEntry（搜索输入框）
     * --------------------------------------------------------------- */

    /**
     * 创建搜索输入框。
     *
     * @return mixed 控件句柄
     */
    abstract public function searchEntryCreate(): mixed;

    /**
     * 获取搜索文本。
     *
     * @param mixed $h 控件句柄
     * @return string 文本
     */
    abstract public function searchEntryGetText(mixed $h): string;

    /**
     * 设置搜索文本。
     *
     * @param mixed  $h 控件句柄
     * @param string $t 文本
     */
    abstract public function searchEntrySetText(mixed $h, string $t): void;

    /**
     * 注册内容变化回调。
     *
     * @param mixed    $h  控件句柄
     * @param \Closure $cb 回调函数
     */
    abstract public function searchEntryOnChanged(mixed $h, \Closure $cb): void;

    /* -----------------------------------------------------------------
     * DateTimePicker（日期时间选择器）
     * --------------------------------------------------------------- */

    /**
     * 创建日期时间选择器。
     *
     * @return mixed 控件句柄
     */
    abstract public function dateTimePickerCreate(): mixed;

    /**
     * 获取当前选择的时间（Unix 时间戳，秒）。
     *
     * @param mixed $h 控件句柄
     * @return int Unix 时间戳
     */
    abstract public function dateTimePickerGetTime(mixed $h): int;

    /**
     * 设置时间（Unix 时间戳，秒）。
     *
     * @param mixed $h 控件句柄
     * @param int   $t Unix 时间戳
     */
    abstract public function dateTimePickerSetTime(mixed $h, int $t): void;
}
