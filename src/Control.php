<?php
declare(strict_types=1);

namespace Kingbes\Ui;

/**
 * 控件抽象基类。
 *
 * 所有具体控件（Button/Label/Entry/...）与布局容器（Box/Grid/Form）
 * 均继承自本类。
 *
 * 生命周期：
 *   1. 构造器接收 parent（Control 或 Window），存储父引用与所属窗口。
 *   2. 调用 abstract create() — 子类通过 App::platform()->controlCreate
 *      创建原生控件，将句柄存入 $this->hwnd。
 *   3. 通过 platform->registerControl($this->hwnd, $this) 注册实例，
 *      防止 GC 回收并支持事件反查。
 *
 * 事件模型：
 *   回调为类型化 nullable 闭包属性，直接赋值即可：
 *   `$btn->onClick = fn() => ...`。平台事件分发时通过 hwnd 反查
 *   Control 实例并触发对应属性。
 */
abstract class Control
{
    /**
     * 平台原生句柄（int）。0 表示尚未创建或为逻辑容器（无原生窗口）。
     */
    protected int $hwnd = 0;

    /**
     * 父容器（Control 或 Window）。顶层控件的 parent 为 Window。
     */
    protected Control|null|Window $parent = null;

    /**
     * 所属顶层窗口。用于事件路由与窗口级回调。
     */
    protected ?Window $window = null;

    // ============================================================
    // 通用事件闭包属性
    // ============================================================

    /** 点击事件（Button 等）。 */
    public ?\Closure $onClick = null;
    /** 鼠标按下。参数 MouseEvent。 */
    public ?\Closure $onMouseDown = null;
    /** 鼠标释放。参数 MouseEvent。 */
    public ?\Closure $onMouseUp = null;
    /** 鼠标移动。参数 MouseEvent。 */
    public ?\Closure $onMouseMove = null;
    /** 键盘按下。参数 KeyEvent。 */
    public ?\Closure $onKeyDown = null;
    /** 键盘释放。参数 KeyEvent。 */
    public ?\Closure $onKeyUp = null;

    // ============================================================
    // 构造器
    // ============================================================

    /**
     * @param Control|Window $parent 父容器或所属窗口。
     */
    public function __construct(Control|Window $parent)
    {
        $this->parent = $parent;
        // 沿父链确定所属顶层窗口
        if ($parent instanceof Window) {
            $this->window = $parent;
        } else {
            // $parent 是 Control，继承其 window 引用
            $this->window = $parent->window;
        }
        // 模板方法：子类创建原生控件并设置 $this->hwnd
        $this->create();
        // 注册实例防 GC + 事件反查（hwnd=0 的逻辑容器跳过）
        if ($this->hwnd !== 0) {
            App::platform()->registerControl($this->hwnd, $this);
        }
    }

    /**
     * 子类实现：通过 App::platform()->controlCreate(...) 创建原生控件，
     * 并将返回的 hwnd 赋值给 $this->hwnd。
     *
     * 调用时机：构造器内，$this->parent 与 $this->window 已就绪。
     */
    abstract protected function create(): void;

    // ============================================================
    // 通用方法（委托平台接口）
    // ============================================================

    /**
     * 显示控件。
     */
    public function show(): void
    {
        if ($this->hwnd !== 0) {
            App::platform()->controlShow($this->hwnd);
        }
    }

    /**
     * 隐藏控件。
     */
    public function hide(): void
    {
        if ($this->hwnd !== 0) {
            App::platform()->controlHide($this->hwnd);
        }
    }

    /**
     * 启用/禁用控件。
     */
    public function setEnabled(bool $enabled): void
    {
        if ($this->hwnd !== 0) {
            App::platform()->controlEnable($this->hwnd, $enabled);
        }
    }

    /**
     * 设置控件位置与尺寸。
     */
    public function setBounds(int $x, int $y, int $width, int $height): void
    {
        if ($this->hwnd !== 0) {
            App::platform()->controlSetBounds($this->hwnd, $x, $y, $width, $height);
        }
    }

    /**
     * 获取控件文本。
     */
    public function getText(): string
    {
        if ($this->hwnd === 0) {
            return '';
        }
        return App::platform()->controlGetText($this->hwnd);
    }

    /**
     * 设置控件文本。
     */
    public function setText(string $text): void
    {
        if ($this->hwnd !== 0) {
            App::platform()->controlSetText($this->hwnd, $text);
        }
    }

    /**
     * 销毁控件（释放原生资源 + 注销注册）。
     */
    public function destroy(): void
    {
        if ($this->hwnd !== 0) {
            App::platform()->controlDestroy($this->hwnd);
            App::platform()->unregisterControl($this->hwnd);
            $this->hwnd = 0;
        }
    }

    // ============================================================
    // 访问器
    // ============================================================

    /**
     * 获取平台原生句柄。
     */
    public function getHwnd(): int
    {
        return $this->hwnd;
    }

    /**
     * 获取父容器。
     */
    public function getParent(): Control|Window|null
    {
        return $this->parent;
    }

    /**
     * 获取所属顶层窗口。
     */
    public function getWindow(): ?Window
    {
        return $this->window;
    }

    /**
     * 获取父容器的 hwnd（供子类 create() 使用）。
     */
    protected function parentHwnd(): int
    {
        return $this->parent?->getHwnd() ?? 0;
    }

    /**
     * 控件偏好宽度（像素）。返回 0 表示无偏好，由容器决定。
     *
     * 子类可重写以提供固有宽度（如固定尺寸控件）。布局容器在空间
     * 充足时会优先满足该尺寸；空间不足或为 0 时回退到均分。
     */
    public function getPreferredWidth(): int
    {
        return 0;
    }

    /**
     * 控件偏好高度（像素）。返回 0 表示无偏好，由容器决定。
     *
     * 子类可重写以提供固有高度（如 Button=23、Label=17）。布局容器
     * 在空间充足时会优先满足该尺寸；空间不足或为 0 时回退到均分。
     */
    public function getPreferredHeight(): int
    {
        return 0;
    }
}
