<?php
declare(strict_types=1);

namespace Kingbes\Ui\Control;

use Kingbes\Ui\App;
use Kingbes\Ui\Control;
use Kingbes\Ui\Graphics\DrawContext;
use Kingbes\Ui\Window;

/**
 * 自定义绘图区控件。
 *
 * 基于 Win32 PhpUiWindow 类的 WS_CHILD 子窗口（WindowProc 通过控件类型表
 * 区分 'Area'）。WM_PAINT 时由平台创建 DrawContext 并回调 onDraw；
 * WM_MOUSEMOVE/LBUTTONDOWN 等 鼠标消息回调 onMouseDown/onMouseUp/onMouseMove。
 *
 * 典型用法：
 *   $area = new Area($parent);
 *   $area->onDraw = fn(DrawContext $ctx) => { $ctx->drawLine(...); };
 *   $area->onMouseMove = fn(MouseEvent $e) => { ... $area->invalidate(); };
 *
 * 鼠标事件闭包 onMouseDown/onMouseUp/onMouseMove 继承自 Control 基类。
 *
 * 键盘事件：
 *   onKeyDown/onKeyUp 继承自 Control 基类。Area 在鼠标左键按下时调用
 *   SetFocus 获得焦点，使后续 WM_KEYDOWN/WM_KEYUP 能派发到本控件。
 *
 * 鼠标进入/离开：
 *   onMouseEnter/onMouseLeave 由平台通过 TrackMouseEvent + WM_MOUSELEAVE
 *   机制触发。首次 WM_MOUSEMOVE 时注册跟踪，离开时触发 leave 并重置跟踪状态。
 */
class Area extends Control
{
    /**
     * 绘制回调。签名：fn(DrawContext $ctx): void。
     *
     * 每次 invalidate 或窗口需要重绘时触发。DrawContext 由平台在
     * WM_PAINT 时创建并传入，回调结束后自动释放。
     */
    public ?\Closure $onDraw = null;

    /**
     * 鼠标进入回调（无参数）。首次 WM_MOUSEMOVE 时触发，之后到
     * WM_MOUSELEAVE 之间不再重复触发。
     */
    public ?\Closure $onMouseEnter = null;

    /**
     * 鼠标离开回调（无参数）。WM_MOUSELEAVE 时触发，离开后下次进入会
     * 重新触发 onMouseEnter。
     */
    public ?\Closure $onMouseLeave = null;

    /**
     * @param Control|Window $parent 父容器或窗口。
     */
    public function __construct(Control|Window $parent)
    {
        parent::__construct($parent);
    }

    protected function create(): void
    {
        $this->hwnd = App::platform()->areaCreate($this->parentHwnd());
    }

    /**
     * 标记绘图区为脏，触发下一帧重绘（异步）。
     *
     * 调用后平台在下次 WM_PAINT 时重新触发 onDraw。
     */
    public function invalidate(): void
    {
        App::platform()->areaInvalidate($this->hwnd);
    }

    /**
     * 设置虚拟内容尺寸，启用滚动条。
     *
     * 设置后 Area 显示 WS_HSCROLL/WS_VSCROLL 滚动条，onDraw 回调收到
     * 的 DrawContext 已应用滚动偏移，用户按内容坐标系
     * (0, 0) ~ (contentW, contentH) 绘制即可。
     *
     * 传 (0, 0) 关闭滚动条。
     *
     * @param int $contentW 内容总宽度（像素）。
     * @param int $contentH 内容总高度（像素）。
     */
    public function setSize(int $contentW, int $contentH): void
    {
        App::platform()->areaSetScrollable($this->hwnd, $contentW, $contentH);
    }

    /**
     * 程序化滚动到指定内容坐标。
     *
     * @param int $x 目标 x（内容坐标系，自动夹取到有效范围）。
     * @param int $y 目标 y（内容坐标系，自动夹取到有效范围）。
     */
    public function scrollTo(int $x, int $y): void
    {
        App::platform()->areaScrollTo($this->hwnd, $x, $y);
    }

    /**
     * 获取当前滚动位置（内容坐标系）。
     *
     * @return array{x:int, y:int}
     */
    public function getScrollPos(): array
    {
        return App::platform()->areaGetScrollPos($this->hwnd);
    }
}
