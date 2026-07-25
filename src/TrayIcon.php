<?php
declare(strict_types=1);

namespace Kingbes\Ui;

use Kingbes\Ui\Menu\Menu;

/**
 * 系统托盘图标。
 *
 * 封装 Windows Shell_NotifyIconW API，支持：
 *   - 添加/修改/删除托盘图标
 *   - 设置提示文本（鼠标悬停显示）
 *   - 显示气球通知（Balloon Tip）
 *   - 左键点击回调（通常用于显示/隐藏窗口）
 *   - 右键菜单（通过 Menu 类构造）
 *   - 双击回调
 *
 * 生命周期：
 *   - 创建时调用 Shell_NotifyIconW(NIM_ADD) 添加到系统托盘
 *   - 修改图标/提示/气球时调用 NIM_MODIFY
 *   - 销毁时调用 NIM_DELETE 移除托盘图标
 *
 * 示例：
 *   $tray = new TrayIcon($window, '我的应用');
 *   $tray->setIconFromIconId(TrayIcon::IDI_APPLICATION)
 *        ->setTooltip('我的应用 - 运行中')
 *        ->showBalloon('提示', '应用已启动', TrayIcon::BALLOON_INFO);
 *
 *   $tray->onClick = fn() => $window->show();
 *   $menu = new Menu(false);
 *   $menu->addItem('显示')->onClick = fn() => $window->show();
 *   $menu->addItem('退出')->onClick = fn() => App::quit();
 *   $tray->setContextMenu($menu);
 */
class TrayIcon
{
    // ============================================================
    // 预定义图标 ID（用于 setIconFromIconId）
    // ============================================================

    /** 默认应用图标 */
    public const IDI_APPLICATION = 32512;
    /** 系统错误图标（红色停止） */
    public const IDI_HAND = 32513;
    /** 询问图标 */
    public const IDI_QUESTION = 32514;
    /** 警告图标（黄色感叹号） */
    public const IDI_EXCLAMATION = 32515;
    /** 信息图标（蓝色 i） */
    public const IDI_ASTERISK = 32516;

    // ============================================================
    // 气球通知类型
    // ============================================================

    /** 无图标 */
    public const BALLOON_NONE = 0;
    /** 信息图标（蓝色 i） */
    public const BALLOON_INFO = 1;
    /** 警告图标（黄色感叹号） */
    public const BALLOON_WARNING = 2;
    /** 错误图标（红色停止） */
    public const BALLOON_ERROR = 3;

    // ============================================================
    // 鼠标事件类型（回调参数）
    // ============================================================

    /** 左键点击 */
    public const EVENT_LEFT_CLICK = 1;
    /** 左键双击 */
    public const EVENT_LEFT_DOUBLE_CLICK = 2;
    /** 右键点击 */
    public const EVENT_RIGHT_CLICK = 3;

    // ============================================================
    // 托盘通知消息（lParam 值，非鼠标消息）
    // ============================================================

    /** 气球显示（NIN_BALLOONSHOW） */
    public const NIN_BALLOONSHOW = 0x0402;
    /** 气球隐藏（NIN_BALLOONHIDE，托盘图标被删除时） */
    public const NIN_BALLOONHIDE = 0x0403;
    /** 气球超时消失（NIN_BALLOONTIMEOUT） */
    public const NIN_BALLOONTIMEOUT = 0x0404;
    /** 用户点击气球（NIN_BALLOONUSERCLICK） */
    public const NIN_BALLOONUSERCLICK = 0x0405;

    /**
     * 关联的窗口（用于接收托盘回调消息）。
     */
    private Window $window;

    /**
     * 托盘图标 ID（uID，同一窗口内多个托盘需唯一）。
     */
    private int $trayId = 1;

    /**
     * 当前托盘是否已添加到系统托盘。
     */
    private bool $added = false;

    /**
     * 当前提示文本。
     */
    private string $tooltip = '';

    /**
     * 当前图标句柄（int 表示，0 表示无图标）。
     */
    private int $hiconInt = 0;

    /**
     * 是否持有图标所有权（LoadImage 加载的需 DestroyIcon，预定义的不需）。
     */
    private bool $ownsIcon = false;

    /**
     * 右键上下文菜单（可选）。
     */
    private ?Menu $contextMenu = null;

    // ============================================================
    // 事件闭包
    // ============================================================

    /** 左键点击回调。参数：无。 */
    public ?\Closure $onClick = null;

    /** 左键双击回调。参数：无。 */
    public ?\Closure $onDoubleClick = null;

    /** 右键点击回调。参数：无。若设置了 contextMenu 则默认弹出菜单。 */
    public ?\Closure $onRightClick = null;

    /** 气球被用户点击回调。参数：无。点击气球通常用于打开详情窗口。 */
    public ?\Closure $onBalloonClick = null;

    /** 气球超时消失回调（用户未点击）。参数：无。 */
    public ?\Closure $onBalloonTimeout = null;

    /**
     * 创建托盘图标实例。
     *
     * 创建时不会立即添加到系统托盘，需调用 setIconXxx() 后自动添加，
     * 或显式调用 add()。
     *
     * @param Window $window 关联窗口（接收托盘回调消息）。
     * @param string $tooltip 初始提示文本（最多 128 字符）。
     */
    public function __construct(Window $window, string $tooltip = '')
    {
        $this->window = $window;
        $this->tooltip = $tooltip;
        // 注册到平台以便分发托盘回调消息
        App::platform()->registerTrayIcon($this);
    }

    /**
     * 从 .ico 文件加载图标。
     *
     * @param string $file .ico 文件路径。
     * @return self 链式调用。
     */
    public function setIconFromFile(string $file): self
    {
        $this->freeOwnedIcon();
        $this->hiconInt = App::platform()->loadIconFromFile($file);
        $this->ownsIcon = true;
        $this->addOrUpdate();
        return $this;
    }

    /**
     * 从预定义系统图标 ID 加载图标。
     *
     * @param int $iconId IDI_APPLICATION / IDI_HAND / IDI_QUESTION / IDI_EXCLAMATION / IDI_ASTERISK。
     * @return self 链式调用。
     */
    public function setIconFromIconId(int $iconId): self
    {
        $this->freeOwnedIcon();
        $this->hiconInt = App::platform()->loadSystemIcon($iconId);
        $this->ownsIcon = false;  // 系统图标共享，不需 DestroyIcon
        $this->addOrUpdate();
        return $this;
    }

    /**
     * 从 Image 对象加载图标（PNG/JPEG/BMP/GIF/TIFF 任意 GDI+ 格式）。
     *
     * 内部通过 GDI+ GdipCreateHICONFromBitmap 转换为 HICON，
     * 适合需要彩色或半透明自定义图标的场景。
     * 析构时由 TrayIcon 自动 DestroyIcon 释放。
     *
     * @param \Kingbes\Ui\Graphics\Image $image 图像对象。
     * @return self 链式调用。
     */
    public function setIconFromImage(\Kingbes\Ui\Graphics\Image $image): self
    {
        $this->freeOwnedIcon();
        $this->hiconInt = App::platform()->iconCreateFromImage($image);
        $this->ownsIcon = true;  // GDI+ 创建的 HICON 需要 DestroyIcon
        $this->addOrUpdate();
        return $this;
    }

    /**
     * 设置提示文本（鼠标悬停显示，最多 128 字符）。
     *
     * @param string $text 提示文本。
     * @return self 链式调用。
     */
    public function setTooltip(string $text): self
    {
        $this->tooltip = $text;
        if ($this->added) {
            $this->addOrUpdate();
        }
        return $this;
    }

    /**
     * 显示气球通知。
     *
     * @param string $title   气球标题（最多 64 字符）。
     * @param string $message 气球内容（最多 256 字符）。
     * @param int    $type    BALLOON_NONE/INFO/WARNING/ERROR。
     * @param int    $timeout 超时毫秒（系统可能限制最小/最大值）。
     * @return self 链式调用。
     */
    public function showBalloon(string $title, string $message, int $type = self::BALLOON_INFO, int $timeout = 10000): self
    {
        if (!$this->added) {
            return $this;
        }
        App::platform()->trayShowBalloon(
            $this->window->getHwnd(),
            $this->trayId,
            $title,
            $message,
            $type,
            $timeout
        );
        return $this;
    }

    /**
     * 设置右键上下文菜单。
     *
     * 用户右键托盘图标时自动弹出此菜单。若同时设置了 onRightClick 回调，
     * 则回调会在弹出菜单前触发。
     *
     * @param Menu $menu 弹出菜单（new Menu(false)）。
     * @return self 链式调用。
     */
    public function setContextMenu(Menu $menu): self
    {
        $this->contextMenu = $menu;
        return $this;
    }

    /**
     * 显式添加到系统托盘。
     *
     * 通常无需手动调用，setIconFromFile/setIconFromIconId 会自动触发。
     */
    public function add(): self
    {
        if ($this->added) {
            return $this;
        }
        App::platform()->trayAdd(
            $this->window->getHwnd(),
            $this->trayId,
            $this->hiconInt,
            $this->tooltip
        );
        $this->added = true;
        return $this;
    }

    /**
     * 从系统托盘移除。
     */
    public function remove(): void
    {
        if (!$this->added) {
            return;
        }
        App::platform()->trayRemove($this->window->getHwnd(), $this->trayId);
        $this->added = false;
    }

    /**
     * 修改托盘图标/提示（调用 NIM_MODIFY）。
     */
    public function modify(): void
    {
        if (!$this->added) {
            return;
        }
        $this->addOrUpdate();
    }

    /**
     * 获取关联窗口。
     */
    public function getWindow(): Window
    {
        return $this->window;
    }

    /**
     * 获取托盘 ID。
     */
    public function getTrayId(): int
    {
        return $this->trayId;
    }

    /**
     * 内部：设置托盘 ID（由平台 registerTrayIcon 调用）。
     */
    public function _setTrayId(int $id): void
    {
        $this->trayId = $id;
    }

    /**
     * 获取当前图标句柄（int）。
     */
    public function getIconHandle(): int
    {
        return $this->hiconInt;
    }

    /**
     * 获取右键菜单。
     */
    public function getContextMenu(): ?Menu
    {
        return $this->contextMenu;
    }

    /**
     * 平台消息分发调用：处理托盘回调消息。
     *
     * 由 WindowsPlatform.dispatchWindowProc 在收到 WM_TRAYICON 时调用。
     *
     * @param int $mouseMsg 鼠标消息类型或气球通知消息：
     *     WM_LBUTTONUP=0x0202 / WM_LBUTTONDBLCLK=0x0203 / WM_RBUTTONUP=0x0205
     *     NIN_BALLOONSHOW=0x0402 / NIN_BALLOONHIDE=0x0403
     *     NIN_BALLOONTIMEOUT=0x0404 / NIN_BALLOONUSERCLICK=0x0405
     */
    public function handleCallback(int $mouseMsg): void
    {
        switch ($mouseMsg) {
            case 0x0202:  // WM_LBUTTONUP
                if ($this->onClick !== null) {
                    try {
                        ($this->onClick)();
                    } catch (\Throwable $e) {
                        trigger_error('TrayIcon onClick error: ' . $e->getMessage(), \E_USER_WARNING);
                    }
                }
                break;
            case 0x0203:  // WM_LBUTTONDBLCLK
                if ($this->onDoubleClick !== null) {
                    try {
                        ($this->onDoubleClick)();
                    } catch (\Throwable $e) {
                        trigger_error('TrayIcon onDoubleClick error: ' . $e->getMessage(), \E_USER_WARNING);
                    }
                }
                break;
            case 0x0205:  // WM_RBUTTONUP
                if ($this->onRightClick !== null) {
                    try {
                        ($this->onRightClick)();
                    } catch (\Throwable $e) {
                        trigger_error('TrayIcon onRightClick error: ' . $e->getMessage(), \E_USER_WARNING);
                    }
                }
                $this->showContextMenu();
                break;
            case self::NIN_BALLOONUSERCLICK:
                if ($this->onBalloonClick !== null) {
                    try {
                        ($this->onBalloonClick)();
                    } catch (\Throwable $e) {
                        trigger_error('TrayIcon onBalloonClick error: ' . $e->getMessage(), \E_USER_WARNING);
                    }
                }
                break;
            case self::NIN_BALLOONTIMEOUT:
            case self::NIN_BALLOONHIDE:
                if ($this->onBalloonTimeout !== null) {
                    try {
                        ($this->onBalloonTimeout)();
                    } catch (\Throwable $e) {
                        trigger_error('TrayIcon onBalloonTimeout error: ' . $e->getMessage(), \E_USER_WARNING);
                    }
                }
                break;
        }
    }

    /**
     * 弹出右键菜单（内部调用）。
     */
    private function showContextMenu(): void
    {
        if ($this->contextMenu === null) {
            return;
        }
        App::platform()->trayShowContextMenu($this->window->getHwnd(), $this->contextMenu->getHwnd());
    }

    /**
     * 内部：添加或更新托盘图标。
     */
    private function addOrUpdate(): void
    {
        if ($this->added) {
            App::platform()->trayModify(
                $this->window->getHwnd(),
                $this->trayId,
                $this->hiconInt,
                $this->tooltip
            );
        } else {
            $this->add();
        }
    }

    /**
     * 内部：释放持有的图标（若 ownsIcon）。
     */
    private function freeOwnedIcon(): void
    {
        if ($this->ownsIcon && $this->hiconInt !== 0) {
            App::platform()->destroyIconInt($this->hiconInt);
            $this->hiconInt = 0;
            $this->ownsIcon = false;
        }
    }

    /**
     * 析构时移除托盘图标并释放资源。
     */
    public function __destruct()
    {
        try {
            $this->remove();
            $this->freeOwnedIcon();
            App::platform()->unregisterTrayIcon($this);
        } catch (\Throwable $e) {
            // 析构时忽略错误
        }
    }
}
