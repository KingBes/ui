<?php
declare(strict_types=1);

namespace Kingbes\Ui\Layout;

use Kingbes\Ui\App;
use Kingbes\Ui\Control;
use Kingbes\Ui\Window;

/**
 * 分组容器。
 *
 * 基于 Win32 "Button" 类（BS_GROUPBOX 样式，外观为带标题的凹陷边框）。
 * 内嵌单个子控件（通过 setChild 设置），常用于视觉上聚合一组相关控件。
 *
 * 注意：BS_GROUPBOX 本身不响应点击，仅作为视觉容器。其子控件由布局
 * 系统定位，标题文本通过 setText 设置。
 *
 * 内边距（padding）：默认在内容区域四周留出 8 像素，使子控件不紧贴边框。
 */
class Group extends Container
{
    /** BS_GROUPBOX：分组框样式 */
    private const BS_GROUPBOX = 0x00000007;

    /** WS_GROUP：分组（避免 Tab 焦点串入边框按钮） */
    private const WS_GROUP    = 0x00020000;

    private string $title;
    private ?Control $child = null;

    /** 内边距（像素）。 */
    protected int $padding = 8;

    /**
     * @param Control|Window $parent 父容器或窗口。
     * @param string         $title  分组标题（空字符串则无标题）。
     */
    public function __construct(Control|Window $parent, string $title = '')
    {
        $this->title = $title;
        parent::__construct($parent);
    }

    protected function create(): void
    {
        $this->hwnd = App::platform()->controlCreate(
            'Button',
            $this->title,
            self::BS_GROUPBOX | self::WS_GROUP,
            0,
            $this->parentHwnd(),
            0
        );
    }

    /**
     * 设置分组标题。
     */
    public function setTitle(string $title): void
    {
        $this->title = $title;
        $this->setText($title);
    }

    /**
     * 获取分组标题。
     */
    public function getTitle(): string
    {
        return $this->title;
    }

    /**
     * 设置内嵌子控件。
     *
     * Group 只持有一个子控件。若多次调用，后一次覆盖前一次。
     */
    public function setChild(Control $child): static
    {
        $this->child = $child;
        // 同步到 children 列表（供 destroy 递归销毁）
        $this->children = [$child];
        if ($child instanceof self) {
            $child->setToplevel(false);
        }
        return $this;
    }

    /**
     * 获取内嵌子控件。
     */
    public function getChild(): ?Control
    {
        return $this->child;
    }

    /**
     * 设置内边距（像素）。
     */
    public function setPadding(int $padding): static
    {
        $this->padding = max(0, $padding);
        return $this;
    }

    public function getPadding(): int
    {
        return $this->padding;
    }

    /**
     * 布局：子控件铺满内容区域（扣除内边距和顶部标题区）。
     *
     * Group 顶部约 12 像素为标题区，子控件从 padding+12 开始。
     */
    public function layout(int $x, int $y, int $width, int $height): void
    {
        if ($this->child === null) {
            return;
        }
        $topInset = $this->padding + 12;
        $cx = $this->padding;
        $cy = $topInset;
        $cw = max(0, $width - $this->padding * 2);
        $ch = max(0, $height - $topInset - $this->padding);
        $this->child->setBounds($cx, $cy, $cw, $ch);
        if ($this->child instanceof Container) {
            $this->child->layout(0, 0, $cw, $ch);
        }
    }
}
