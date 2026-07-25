<?php
declare(strict_types=1);

namespace Kingbes\Ui\Layout;

use Kingbes\Ui\App;
use Kingbes\Ui\Control;
use Kingbes\Ui\Graphics\Image;
use Kingbes\Ui\Window;

/**
 * 标签页容器。
 *
 * 基于 Win32 "SysTabControl32" 类。每个标签页对应一个子 Container，
 * 切换标签时显示对应页面、隐藏其他页面。
 *
 * 用法：
 *   $tab = new Tab($parent);
 *   $page1 = new VBox($tab);
 *   $tab->addPage('第一页', $page1);
 *   $page1->add(new Button($page1, '按钮'));
 *
 * 事件：
 *   - onPageChanged：切换标签后触发（无参数）。
 */
class Tab extends Container
{
    /** WS_TABSTOP */
    private const WS_TABSTOP = 0x00010000;

    /** WS_CLIPSIBLINGS：避免与兄弟控件重叠时闪烁 */
    private const WS_CLIPSIBLINGS = 0x04000000;

    /**
     * 标签栏高度（像素）。Tab 内容区域从此高度下方开始。
     * Win32 SysTabControl32 默认标签栏约 24-28px，取 28 留余量。
     */
    private const TAB_HEADER_HEIGHT = 28;

    /** 内边距（内容区域四周）。 */
    protected int $padding = 4;

    /** 当前选中页面索引（-1 表示无页面）。 */
    private int $selectedIndex = -1;

    /** ImageList 句柄（int），0 表示未创建。 */
    private int $imageListId = 0;

    /** Image → 索引缓存，避免重复注册。 */
    private array $imageCache = [];

    /** 切换标签回调（无参数）。 */
    public ?\Closure $onPageChanged = null;

    /**
     * @param Control|Window $parent 父容器或窗口。
     */
    public function __construct(Control|Window $parent)
    {
        parent::__construct($parent);
    }

    protected function create(): void
    {
        $this->hwnd = App::platform()->controlCreate(
            'SysTabControl32',
            '',
            self::WS_TABSTOP | self::WS_CLIPSIBLINGS,
            0,
            $this->parentHwnd(),
            0
        );
    }

    /**
     * 添加一个标签页。
     *
     * @param string    $name 标签文本。
     * @param Container $page 页面容器（其 parent 应为本 Tab）。
     * @return int 新标签页索引。
     */
    public function addPage(string $name, Container $page): int
    {
        // 插入标签项（-1 表示追加）
        App::platform()->tabInsertItem($this->hwnd, -1, $name);
        $index = count($this->children);
        // 加入子列表（供 layout 递归和 destroy 销毁）
        $this->children[] = $page;
        $page->setToplevel(false);
        // 初始隐藏所有页面
        $page->hide();
        // 第一个页面自动选中并显示
        if ($index === 0) {
            $this->selectedIndex = 0;
            App::platform()->tabSetSelected($this->hwnd, 0);
            $page->show();
        }
        return $index;
    }

    /**
     * 移除指定索引的标签页。
     */
    public function removePage(int $index): void
    {
        if ($index < 0 || $index >= count($this->children)) {
            return;
        }
        App::platform()->tabDeleteItem($this->hwnd, $index);
        $page = array_splice($this->children, $index, 1)[0];
        $page->destroy();
        // 调整选中索引
        $count = count($this->children);
        if ($count === 0) {
            $this->selectedIndex = -1;
        } elseif ($this->selectedIndex >= $count) {
            $this->selectedIndex = $count - 1;
            App::platform()->tabSetSelected($this->hwnd, $this->selectedIndex);
            $this->children[$this->selectedIndex]->show();
        }
    }

    /**
     * 获取当前选中页面索引（-1 表示无页面）。
     */
    public function getSelectedIndex(): int
    {
        return App::platform()->tabGetSelected($this->hwnd);
    }

    /**
     * 切换到指定标签页。
     */
    public function selectPage(int $index): void
    {
        if ($index < 0 || $index >= count($this->children)) {
            return;
        }
        App::platform()->tabSetSelected($this->hwnd, $index);
        $this->showPage($index);
    }

    /**
     * 获取标签页数量。
     */
    public function getPageCount(): int
    {
        return count($this->children);
    }

    /**
     * 注册图像到 Tab 的 ImageList，返回图像索引（惰性创建 ImageList）。
     *
     * 同一 Image 重复注册返回相同索引。
     *
     * @param Image $image 图像对象（建议 16x16 图标尺寸）。
     * @return int 图像索引（0-based），-1 失败。
     */
    public function addImage(Image $image): int
    {
        $hash = spl_object_hash($image);
        if (isset($this->imageCache[$hash])) {
            return $this->imageCache[$hash];
        }
        // 惰性创建 ImageList 并关联到 Tab
        if ($this->imageListId === 0) {
            $this->imageListId = App::platform()->imageListCreate(16, 16);
            App::platform()->tabSetImageList($this->hwnd, $this->imageListId);
        }
        $index = App::platform()->imageListAddImage($this->imageListId, $image);
        if ($index >= 0) {
            $this->imageCache[$hash] = $index;
        }
        return $index;
    }

    /**
     * 为指定页签设置图标。
     *
     * 内部通过 addImage 注册图像，再通过 TCM_SETITEMW 设置页签的图像索引。
     *
     * @param int   $pageIndex 页签索引（0-based）。
     * @param Image $image     图像对象。
     */
    public function setPageImage(int $pageIndex, Image $image): void
    {
        if ($pageIndex < 0 || $pageIndex >= count($this->children)) {
            return;
        }
        $imageIndex = $this->addImage($image);
        if ($imageIndex < 0) {
            return;
        }
        App::platform()->tabSetItemImage($this->hwnd, $pageIndex, $imageIndex);
    }

    /**
     * 获取指定索引的页面容器。
     */
    public function getPage(int $index): ?Container
    {
        return $this->children[$index] ?? null;
    }

    /**
     * 显示指定页面，隐藏其他页面（切换标签时调用）。
     */
    private function showPage(int $index): void
    {
        if ($index < 0 || $index >= count($this->children)) {
            return;
        }
        foreach ($this->children as $i => $page) {
            if ($i === $index) {
                $page->show();
            } else {
                $page->hide();
            }
        }
        $this->selectedIndex = $index;
        // 对新页面执行布局
        $this->layoutPage($index);
    }

    /**
     * 布局指定页面（在 Tab 内容区域内铺满）。
     */
    private function layoutPage(int $index): void
    {
        if ($index < 0 || $index >= count($this->children)) {
            return;
        }
        // 获取 Tab 控件自身尺寸
        // layout 传入的 $width/$height 是 Tab 控件的尺寸
        // 内容区域扣除标签栏高度和内边距
        $page = $this->children[$index];
        $px = $this->padding;
        $py = self::TAB_HEADER_HEIGHT + $this->padding;
        // layout() 接收的是 Tab 在父级中的位置和尺寸
        // 但页面坐标相对于 Tab 控件客户区，所以用 0 起始
        $pw = max(0, $this->lastWidth - $this->padding * 2);
        $ph = max(0, $this->lastHeight - self::TAB_HEADER_HEIGHT - $this->padding * 2);
        $page->setBounds($px, $py, $pw, $ph);
        if ($page instanceof Container) {
            $page->layout(0, 0, $pw, $ph);
        }
    }

    /** 上次布局尺寸（供 layoutPage 使用）。 */
    private int $lastWidth = 0;
    private int $lastHeight = 0;

    /**
     * 布局：记录尺寸，布局当前选中页面。
     */
    public function layout(int $x, int $y, int $width, int $height): void
    {
        $this->lastWidth = $width;
        $this->lastHeight = $height;
        $idx = $this->getSelectedIndex();
        if ($idx >= 0) {
            $this->layoutPage($idx);
        }
    }

    /**
     * 平台消息分发调用：TCN_SELCHANGE 时切换页面显示并触发回调。
     * 由 WindowsPlatform::dispatchWmNotify 通过 method_exists 调用。
     */
    public function handleSelChanged(): void
    {
        $idx = $this->getSelectedIndex();
        if ($idx >= 0 && $idx !== $this->selectedIndex) {
            $this->showPage($idx);
        }
        if ($this->onPageChanged !== null) {
            try {
                ($this->onPageChanged)();
            } catch (\Throwable $e) {
                trigger_error(
                    'onPageChanged callback error: ' . $e->getMessage(),
                    \E_USER_WARNING
                );
            }
        }
    }

    /**
     * 设置内边距。
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
}
