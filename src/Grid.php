<?php
declare(strict_types=1);

namespace Kingbes\Ui;

/**
 * 二维网格布局容器。
 *
 * 子控件按 left/top/xspan/yspan 占据网格单元格。
 *
 * 对齐常量（与 libui-ng 一致）：
 *   - Align: Fill=0, Start=1, Center=2, End=3
 *   - At（用于 insertAt 的相对方位）: Leading=0, Top=1, Trailing=2, Bottom=3
 */
class Grid extends Control
{
    /**
     * 创建二维网格容器。
     */
    public function __construct()
    {
        $this->handle = static::platform()->gridCreate();
    }

    /**
     * 在指定网格位置放置子控件。
     *
     * @param Control $child    子控件
     * @param int     $left     起始列
     * @param int     $top      起始行
     * @param int     $xspan    横向跨列数
     * @param int     $yspan    纵向跨行数
     * @param bool    $hexpand  是否横向扩展
     * @param int     $halign   横向对齐（Align 常量）
     * @param bool    $vexpand  是否纵向扩展
     * @param int     $valign   纵向对齐（Align 常量）
     * @return static 当前实例（支持链式调用）
     */
    public function append(
        Control $child,
        int $left,
        int $top,
        int $xspan,
        int $yspan,
        bool $hexpand,
        int $halign,
        bool $vexpand,
        int $valign
    ): static {
        static::platform()->gridAppend(
            $this->handle,
            $child->getHandle(),
            $left, $top, $xspan, $yspan,
            $hexpand, $halign, $vexpand, $valign
        );
        return $this;
    }

    /**
     * 在已有子控件的相对位置插入新子控件。
     *
     * @param Control $child    新子控件
     * @param Control $existing 已存在的参考子控件
     * @param int     $at       相对方位（At 常量：Leading/Top/Trailing/Bottom）
     * @param int     $xspan    横向跨列数
     * @param int     $yspan    纵向跨行数
     * @param bool    $hexpand  是否横向扩展
     * @param int     $halign   横向对齐
     * @param bool    $vexpand  是否纵向扩展
     * @param int     $valign   纵向对齐
     * @return static 当前实例（支持链式调用）
     */
    public function insertAt(
        Control $child,
        Control $existing,
        int $at,
        int $xspan,
        int $yspan,
        bool $hexpand,
        int $halign,
        bool $vexpand,
        int $valign
    ): static {
        static::platform()->gridInsertAt(
            $this->handle,
            $child->getHandle(),
            $existing->getHandle(),
            $at, $xspan, $yspan,
            $hexpand, $halign, $vexpand, $valign
        );
        return $this;
    }

    /**
     * 查询是否启用内边距。
     *
     * @return bool 是否启用
     */
    public function getPadded(): bool
    {
        return static::platform()->gridGetPadded($this->handle);
    }

    /**
     * 设置是否启用内边距。
     *
     * @param bool $padded 是否启用
     * @return static 当前实例（支持链式调用）
     */
    public function setPadded(bool $padded): static
    {
        static::platform()->gridSetPadded($this->handle, $padded);
        return $this;
    }
}
