<?php
declare(strict_types=1);

namespace Kingbes\Ui;

/**
 * 垂直布局盒子的语法糖子类。
 *
 * 等价于 Box::vertical()，提供更具语义化的构造方式：
 *   $box = new VBox();
 */
class VBox extends Box
{
    /**
     * 创建垂直布局盒子。
     */
    public function __construct()
    {
        parent::__construct(false);
    }
}
