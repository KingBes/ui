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
}
