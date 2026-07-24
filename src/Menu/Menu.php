<?php
declare(strict_types=1);

namespace Kingbes\Ui\Menu;

use Kingbes\Ui\App;

/**
 * 菜单（菜单栏或弹出子菜单）。
 *
 * 封装平台 HMENU 句柄，提供 addItem/addSeparator/addSubmenu 构建菜单结构。
 *
 * 菜单项 ID 从 9000 起自增（$nextId），避免与控件 ID（1000+）冲突。
 *
 * 用法：
 *   $menuBar = new Menu(true);            // 菜单栏
 *   $fileMenu = new Menu(false);          // 弹出子菜单
 *   $fileMenu->addItem("新建");
 *   $fileMenu->addSeparator();
 *   $fileMenu->addItem("退出");
 *   $menuBar->addSubmenu("文件", $fileMenu);
 *   $window->setMenu($menuBar);
 *
 * findItemById 递归搜索所有 items 及子菜单，用于 WM_COMMAND 分发。
 */
class Menu
{
    /**
     * 平台 HMENU 句柄（int）。
     */
    private int $hwnd;

    /**
     * 是否为菜单栏（true）或弹出菜单（false）。
     */
    private bool $isBar;

    /**
     * 菜单项列表。
     *
     * @var list<MenuItem>
     */
    private array $items = [];

    /**
     * 菜单项 ID 自增生成器（从 9000 起，避免与控件 ID 冲突）。
     */
    private static int $nextId = 9000;

    /**
     * @param bool $isBar true=菜单栏（CreateMenu），false=弹出菜单（CreatePopupMenu）。
     */
    public function __construct(bool $isBar = false)
    {
        $this->isBar = $isBar;
        $this->hwnd = $isBar
            ? App::platform()->menuCreateBar()
            : App::platform()->menuCreatePopup();
    }

    /**
     * 添加文本菜单项。
     *
     * @param string $text 菜单项文本（支持中文/emoji）。
     * @return MenuItem 新建的菜单项（可链式设置 onClick/setEnabled/setChecked）。
     */
    public function addItem(string $text): MenuItem
    {
        $id = self::$nextId++;
        $item = new MenuItem($text, $id, $this->hwnd);
        $this->items[] = $item;
        App::platform()->menuAppendItem($this->hwnd, $text, $id);
        return $item;
    }

    /**
     * 添加分隔符。
     *
     * @return MenuItem 分隔符项（isSeparator()=true，无 ID/onClick）。
     */
    public function addSeparator(): MenuItem
    {
        $item = MenuItem::forSeparator($this->hwnd);
        $this->items[] = $item;
        App::platform()->menuAppendSeparator($this->hwnd);
        return $item;
    }

    /**
     * 添加子菜单入口。
     *
     * @param string $text    子菜单入口文本。
     * @param Menu   $submenu 子菜单实例（已构建其菜单项）。
     * @return MenuItem 子菜单入口项（可链式设置 enabled）。
     */
    public function addSubmenu(string $text, Menu $submenu): MenuItem
    {
        $id = self::$nextId++;
        $item = new MenuItem($text, $id, $this->hwnd);
        $item->setSubmenu($submenu);
        $this->items[] = $item;
        App::platform()->menuAppendSubmenu($this->hwnd, $text, $submenu->getHwnd());
        return $item;
    }

    /**
     * 获取平台 HMENU 句柄。
     */
    public function getHwnd(): int
    {
        return $this->hwnd;
    }

    /**
     * 是否为菜单栏。
     */
    public function isBar(): bool
    {
        return $this->isBar;
    }

    /**
     * 获取直接子菜单项列表。
     *
     * @return list<MenuItem>
     */
    public function getItems(): array
    {
        return $this->items;
    }

    /**
     * 按 ID 查找菜单项（递归搜索子菜单）。
     *
     * 用于 WM_COMMAND 分发：根据 LOWORD(wParam) 的菜单项 ID
     * 找到对应的 MenuItem，触发其 onClick。
     *
     * @param int $id 菜单项 ID。
     * @return MenuItem|null 找到返回该项，否则 null。
     */
    public function findItemById(int $id): ?MenuItem
    {
        foreach ($this->items as $item) {
            if ($item->isSeparator()) {
                continue;
            }
            if ($item->getId() === $id) {
                return $item;
            }
            // 递归搜索子菜单
            $submenu = $item->getSubmenu();
            if ($submenu !== null) {
                $found = $submenu->findItemById($id);
                if ($found !== null) {
                    return $found;
                }
            }
        }
        return null;
    }

    /**
     * 销毁菜单（释放平台 HMENU 资源）。
     */
    public function destroy(): void
    {
        // 先递归销毁子菜单
        foreach ($this->items as $item) {
            $submenu = $item->getSubmenu();
            if ($submenu !== null) {
                $submenu->destroy();
            }
        }
        App::platform()->menuDestroy($this->hwnd);
        $this->items = [];
    }
}
