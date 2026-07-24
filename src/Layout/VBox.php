<?php
declare(strict_types=1);

namespace Kingbes\Ui\Layout;

use Kingbes\Ui\Control;
use Kingbes\Ui\Window;

/**
 * 垂直盒式布局（VBox）。
 *
 * 子控件从上到下排列，高度均分，宽度铺满父容器。
 */
class VBox extends Box
{
    /**
     * @param Control|Window $parent 父容器或窗口。
     */
    public function __construct(Control|Window $parent)
    {
        parent::__construct(true, $parent);
    }
}
