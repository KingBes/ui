<?php
declare(strict_types=1);

namespace Kingbes\Ui;

use Kingbes\Ui\Geometry\Point;
use Kingbes\Ui\Geometry\Size;
use Kingbes\Ui\Layout\Container;
use Kingbes\Ui\Menu\Menu;

/**
 * 顶层窗口。
 *
 * 窗口是 GUI 应用的顶层容器，持有平台原生窗口句柄。可通过 setChild()
 * 挂载一个 toplevel 布局容器（Box/Grid/Form），窗口尺寸变化时自动
 * 触发该容器的重布局。
 *
 * Window 与 Control 是平级关系（非继承），因为窗口的创建/销毁/事件
 * 路径与子控件不同（windowCreate vs controlCreate）。
 */
class Window
{
    /**
     * 平台原生窗口句柄。
     */
    private int $hwnd = 0;

    /**
     * 顶层布局容器（由 setChild 设置）。
     */
    private ?Control $child = null;

    /**
     * 窗口菜单栏（由 setMenu 设置，用于 WM_COMMAND 菜单点击分发）。
     */
    private ?Menu $menu = null;

    /**
     * 客户区内边距（像素）。布局时顶层容器会被放置在
     * (margin, margin, w-2*margin, h-2*margin) 矩形内。
     */
    private int $margined = 0;

    // ============================================================
    // 事件闭包属性
    // ============================================================

    /** 关闭回调。参数：无。返回 false 可阻止关闭（平台支持时）。 */
    public ?\Closure $onClose = null;
    /** 尺寸变化回调。参数：ResizeEvent。 */
    public ?\Closure $onResize = null;
    /** 焦点变化回调。参数：bool $focused。 */
    public ?\Closure $onFocus = null;
    /** 位置变化回调。参数：Point（窗口左上角屏幕坐标）。 */
    public ?\Closure $onPositionChanged = null;

    /**
     * 创建顶层窗口。
     *
     * @param string $title  窗口标题。
     * @param int    $width  初始宽度（像素）。
     * @param int    $height 初始高度（像素）。
     */
    public function __construct(string $title, int $width, int $height)
    {
        $this->hwnd = App::platform()->windowCreate($title, $width, $height);
        // 注册防 GC + 事件反查
        App::platform()->registerWindow($this->hwnd, $this);
    }

    // ============================================================
    // 标题
    // ============================================================

    public function setTitle(string $title): void
    {
        App::platform()->windowSetTitle($this->hwnd, $title);
    }

    public function getTitle(): string
    {
        return App::platform()->windowGetTitle($this->hwnd);
    }

    // ============================================================
    // 位置与尺寸
    // ============================================================

    public function setPosition(int $x, int $y): void
    {
        App::platform()->windowSetPosition($this->hwnd, $x, $y);
    }

    public function getPosition(): Point
    {
        return App::platform()->windowGetPosition($this->hwnd);
    }

    public function setSize(int $width, int $height): void
    {
        App::platform()->windowSetSize($this->hwnd, $width, $height);
    }

    public function getSize(): Size
    {
        return App::platform()->windowGetSize($this->hwnd);
    }

    /**
     * 获取客户区尺寸（不含标题栏/边框）。
     */
    public function getClientSize(): Size
    {
        return App::platform()->windowGetClientSize($this->hwnd);
    }

    // ============================================================
    // 窗口样式
    // ============================================================

    public function setFullscreen(bool $fullscreen): void
    {
        App::platform()->windowSetFullscreen($this->hwnd, $fullscreen);
    }

    public function setBorderless(bool $borderless): void
    {
        App::platform()->windowSetBorderless($this->hwnd, $borderless);
    }

    public function setResizeable(bool $resizeable): void
    {
        App::platform()->windowSetResizeable($this->hwnd, $resizeable);
    }

    // ============================================================
    // 窗口状态
    // ============================================================

    public function maximize(): void
    {
        App::platform()->windowMaximize($this->hwnd);
    }

    public function minimize(): void
    {
        App::platform()->windowMinimize($this->hwnd);
    }

    public function restore(): void
    {
        App::platform()->windowRestore($this->hwnd);
    }

    public function show(): void
    {
        App::platform()->windowShow($this->hwnd);
    }

    public function hide(): void
    {
        App::platform()->windowHide($this->hwnd);
    }

    public function setTopmost(bool $topmost): void
    {
        App::platform()->windowSetTopmost($this->hwnd, $topmost);
    }

    /**
     * 从 .ico 文件设置窗口图标。
     *
     * 同时设置大图标（Alt+Tab 显示）和小图标（任务栏/标题栏）。
     *
     * @param string $file .ico 文件路径。
     */
    public function setIconFromFile(string $file): self
    {
        App::platform()->windowSetIconFromFile($this->hwnd, $file);
        return $this;
    }

    /**
     * 设置窗口图标为预定义系统图标。
     *
     * @param int $iconId IDI_APPLICATION / IDI_HAND / IDI_QUESTION / IDI_EXCLAMATION / IDI_ASTERISK。
     *                    可使用 TrayIcon::IDI_* 常量。
     */
    public function setIconFromId(int $iconId): self
    {
        App::platform()->windowSetIconFromId($this->hwnd, $iconId);
        return $this;
    }

    /**
     * 从 Image 对象设置窗口图标（PNG/JPEG/BMP/GIF/TIFF 任意 GDI+ 格式）。
     *
     * 内部通过 GDI+ GdipCreateHICONFromBitmap 转换为 HICON，
     * 然后用 WM_SETICON 同时设置 ICON_BIG 和 ICON_SMALL。
     * 析构时由窗口自动 DestroyIcon 释放。
     *
     * @param \Kingbes\Ui\Graphics\Image $image 图像对象。
     */
    public function setIconFromImage(\Kingbes\Ui\Graphics\Image $image): self
    {
        App::platform()->windowSetIconFromImage($this->hwnd, $image);
        return $this;
    }

    /**
     * 设置窗口标题栏深色模式（跨平台）。
     *
     * 跨平台行为：
     *   - Windows 10 1809+：通过 DwmSetWindowAttribute
     *    (DWMWA_USE_IMMERSIVE_DARK_MODE) 设置标题栏为深色或浅色。
     *     可覆盖全局 Theme::DARK 设置，实现单窗口浅色。
     *   - macOS：NSWindow 外观由应用级 NSAppearance 决定（由
     *     App::setTheme() 经 [NSApp setAppearance:] 统一设置），
     *     本方法为空实现，单窗口覆盖需直接操作 NSWindow.appearance。
     *   - GTK：空实现。GTK 深色偏好由 App::setTheme() 经
     *     gtk-application-prefer-dark-theme 全局设置，无单窗口 API。
     *
     * 链式调用在所有平台均正常返回。
     *
     * @param bool $dark true=深色标题栏，false=浅色标题栏。
     */
    public function setDarkMode(bool $dark): self
    {
        App::platform()->setWindowDarkMode($this->hwnd, $dark);
        return $this;
    }

    public function isFocused(): bool
    {
        return App::platform()->windowIsFocused($this->hwnd);
    }

    // ============================================================
    // 子容器与滚动
    // ============================================================

    /**
     * 设置窗口的顶层布局容器。
     *
     * 若 $child 是 Container，自动标记 toplevel=true，使窗口尺寸
     * 变化时触发其 layout()。
     */
    public function setChild(Control $child): self
    {
        $this->child = $child;
        if ($child instanceof Container) {
            $child->setToplevel(true);
        }
        App::platform()->windowSetChild($this->hwnd, $child->getHwnd());
        return $this;
    }

    /**
     * 启用窗口垂直滚动条。
     *
     * @param int $contentHeight 内容总高度（像素）。
     */
    public function setScrollable(int $contentHeight): self
    {
        App::platform()->windowSetScrollable($this->hwnd, $contentHeight);
        return $this;
    }

    /**
     * 设置客户区内边距（像素）。
     *
     * 设置后顶层布局容器会被放置在 (margin, margin, w-2*margin, h-2*margin)
     * 矩形内，使子控件与窗口边框保持视觉间距。立即触发重布局。
     *
     * @param int $pixels 边距像素数，<0 视为 0。
     */
    public function setMargined(int $pixels): self
    {
        $this->margined = max(0, $pixels);
        App::platform()->triggerRelayout($this->hwnd);
        return $this;
    }

    /**
     * 获取客户区内边距（像素）。
     */
    public function getMargined(): int
    {
        return $this->margined;
    }

    /**
     * 获取顶层布局容器（供 triggerRelayout 使用）。
     */
    public function getChildContainer(): ?Control
    {
        return $this->child;
    }

    // ============================================================
    // 菜单
    // ============================================================

    /**
     * 挂载菜单栏。
     *
     * 将 Menu 实例绑定到窗口：调用平台 windowSetMenu 挂载 HMENU，
     * 并存储 Menu 引用以供 WM_COMMAND 菜单点击分发（findItemById）。
     *
     * @param Menu $menu 菜单栏实例（new Menu(true)）。
     */
    public function setMenu(Menu $menu): self
    {
        $this->menu = $menu;
        App::platform()->windowSetMenu($this->hwnd, $menu->getHwnd());
        return $this;
    }

    /**
     * 获取窗口菜单栏（供 WM_COMMAND 菜单点击分发使用）。
     */
    public function getMenu(): ?Menu
    {
        return $this->menu;
    }

    // ============================================================
    // 生命周期
    // ============================================================

    /**
     * 关闭并销毁窗口。
     */
    public function close(): void
    {
        if ($this->hwnd === 0) {
            return;
        }
        App::platform()->windowDestroy($this->hwnd);
        App::platform()->unregisterWindow($this->hwnd);
        $this->hwnd = 0;
        $this->child = null;
        $this->menu = null;
    }

    /**
     * 获取平台原生窗口句柄。
     */
    public function getHwnd(): int
    {
        return $this->hwnd;
    }
}
