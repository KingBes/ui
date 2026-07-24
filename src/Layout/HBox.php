<?php
declare(strict_types=1);

namespace Kingbes\Ui\Layout;

use Kingbes\Ui\Control;
use Kingbes\Ui\Window;

/**
 * 水平盒式布局（HBox）。
 *
 * 子控件从左到右排列，宽度均分，高度铺满父容器。
 */
class HBox extends Box
{
    /**
     * @param Control|Window $parent 父容器或窗口。
     */
    public function __construct(Control|Window $parent)
    {
        parent::__construct(false, $parent);
    }
}
