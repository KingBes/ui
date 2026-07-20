<?php
declare(strict_types=1);

namespace Kingbes\Ui;

/**
 * 水平布局盒子的语法糖子类。
 *
 * 等价于 Box::horizontal()，提供更具语义化的构造方式：
 *   $box = new HBox();
 */
class HBox extends Box
{
    /**
     * 创建水平布局盒子。
     */
    public function __construct()
    {
        parent::__construct(true);
    }
}
