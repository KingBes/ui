<?php
declare(strict_types=1);

namespace Kingbes\Ui\Control;

use Kingbes\Ui\App;
use Kingbes\Ui\Control;
use Kingbes\Ui\Graphics\Color;
use Kingbes\Ui\Graphics\Image;
use Kingbes\Ui\Window;

/**
 * 表格控件（MVC，虚拟模式）。
 *
 * 基于 Win32 "SysListView32" 类（LVS_REPORT + LVS_OWNERDATA 虚拟模式）。
 * 通过 {@see TableModel} 数据源接口按需取数据，支持大数据集（万级行）
 * 而不占用额外内存。
 *
 * 用法：
 *   $table = new Table($parent);
 *   $table->setColumns(['ID', '名称', '价格']);
 *   $table->setModel(new MyModel());
 *   $table->onSelectionChanged = fn(int $row) => ...;
 *   $table->onRowDoubleClicked  = fn(int $row) => ...;
 *
 * 事件：
 *   - onSelectionChanged：选中行变化时触发（参数：当前行索引，-1 表示无选中）。
 *   - onRowDoubleClicked：双击行时触发（参数：被双击的行索引）。
 *
 * 数据变更：
 *   - 全量刷新：$table->refresh()
 *   - 单行刷新：$table->refreshRow($row)
 *   - 行数变化：$table->setRowCount($n)（model 行数变化后通知视图）
 */
class Table extends Control
{
    /** LVS_REPORT：报表视图（带列头） */
    private const LVS_REPORT      = 0x0001;
    /** LVS_SINGLESEL：单选模式（一次只能选一行） */
    private const LVS_SINGLESEL   = 0x0004;
    /** LVS_SHOWSELALWAYS：即使失去焦点也保持选中状态可见 */
    private const LVS_SHOWSELALWAYS = 0x0008;
    /** LVS_OWNERDATA：虚拟模式，由父窗口通过 LVN_GETDISPINFO 提供数据 */
    private const LVS_OWNERDATA   = 0x1000;

    private const WS_TABSTOP       = 0x00010000;
    private const WS_BORDER        = 0x00800000;

    /**
     * 列定义：[['text'=>string, 'width'=>int], ...]
     *
     * @var list<array{text:string, width:int}>
     */
    private array $columns = [];

    /**
     * 数据源模型。
     */
    private ?TableModel $model = null;

    /**
     * 当前已知行数（用于 LVS_OWNERDATA 的 LVM_SETITEMCOUNT）。
     */
    private int $rowCount = 0;

    /**
     * ImageList 句柄（int），0 表示未创建。
     *
     * 由 addImage 惰性创建（首次调用时），用于图像列。
     */
    private int $imageListId = 0;

    /**
     * Image → 索引缓存，避免同一 Image 重复注册。
     *
     * @var array<string, int>
     */
    private array $imageCache = [];

    /**
     * 图像列表中的图像尺寸（像素，默认 16x16 图标）。
     */
    private int $imageSize = 16;

    /** 选中行变化回调。参数：int $row（-1 表示无选中）。 */
    public ?\Closure $onSelectionChanged = null;

    /** 行双击回调。参数：int $row。 */
    public ?\Closure $onRowDoubleClicked = null;

    /** 单元格 checkbox 切换回调。参数：int $row, int $col, bool $checked。 */
    public ?\Closure $onCellCheckboxToggle = null;

    /** 单元格按钮点击回调。参数：int $row, int $col。 */
    public ?\Closure $onCellButtonClick = null;

    // ============================================================
    // 列类型常量
    // ============================================================

    /** 文本列（默认）。通过 getCellValue 提供文本。 */
    public const TYPE_TEXT = 'text';
    /** 图像列。通过 getCellImage 提供 Image。 */
    public const TYPE_IMAGE = 'image';
    /** 复选框列。通过 getCellCheckbox 提供 bool 状态。 */
    public const TYPE_CHECKBOX = 'checkbox';
    /** 进度条列。通过 getCellProgress 提供 0-100 进度值。 */
    public const TYPE_PROGRESS = 'progress';
    /** 颜色块列。通过 getCellColor 提供 Color。 */
    public const TYPE_COLOR = 'color';
    /** 按钮列。通过 getCellButton 提供按钮文本。 */
    public const TYPE_BUTTON = 'button';

    /**
     * 列类型映射表：列索引 => 类型常量。
     *
     * 未设置的列默认为 TYPE_TEXT。设置后平台在 NM_CUSTOMDRAW
     * CDDS_SUBITEM 阶段根据类型自绘对应控件外观。
     *
     * @var array<int, string>
     */
    private array $columnTypes = [];

    /**
     * @param Control|Window $parent 父容器或窗口。
     */
    public function __construct(Control|Window $parent)
    {
        parent::__construct($parent);
    }

    protected function create(): void
    {
        $style = self::LVS_REPORT
            | self::LVS_SINGLESEL
            | self::LVS_SHOWSELALWAYS
            | self::LVS_OWNERDATA
            | self::WS_TABSTOP;
        $exStyle = 0;

        $this->hwnd = App::platform()->controlCreate(
            'SysListView32',
            '',
            $style,
            $exStyle,
            $this->parentHwnd(),
            0
        );

        // 启用扩展样式：
        //   LVS_EX_FULLROWSELECT(0x20) 整行选中
        //   LVS_EX_GRIDLINES(0x01) 网格线
        //   LVS_EX_SUBITEMIMAGES(0x08) 子列支持图像
        App::platform()->tableSetExtendedStyle(
            $this->hwnd,
            0x00000020 | 0x00000001 | 0x00000008
        );
    }

    /**
     * 设置图像列的图标尺寸（默认 16x16）。
     *
     * 必须在首次 addImage 之前调用，否则已创建的 ImageList 尺寸不会改变。
     *
     * @param int $size 图标边长（像素），常用 16/24/32。
     */
    public function setImageSize(int $size): void
    {
        if ($this->imageListId !== 0) {
            trigger_error(
                'setImageSize called after ImageList created; ignored',
                \E_USER_WARNING
            );
            return;
        }
        $this->imageSize = max(1, $size);
    }

    /**
     * 将 Image 注册到 ImageList，返回图像索引（惰性创建 ImageList）。
     *
     * 同一 Image 对象重复注册返回相同索引（基于 spl_object_hash 缓存）。
     *
     * @param Image $image 图像对象。
     * @return int 图像在 ImageList 中的索引（0-based），-1 失败。
     */
    public function addImage(Image $image): int
    {
        $hash = spl_object_hash($image);
        if (isset($this->imageCache[$hash])) {
            return $this->imageCache[$hash];
        }
        // 惰性创建 ImageList
        if ($this->imageListId === 0) {
            $size = $this->imageSize;
            $this->imageListId = App::platform()->imageListCreate($size, $size);
            App::platform()->tableSetImageList($this->hwnd, $this->imageListId);
        }
        $index = App::platform()->imageListAddImage($this->imageListId, $image);
        if ($index >= 0) {
            $this->imageCache[$hash] = $index;
        }
        return $index;
    }

    /**
     * 平台消息分发调用：LVN_GETDISPINFO 的 LVIF_IMAGE 阶段调用。
     *
     * 委托 TableModel::getCellImage（可选方法）获取 Image，再通过 addImage
     * 注册到 ImageList 取得索引。返回 -1 表示该单元格无图像。
     *
     * 内部使用，不应由用户直接调用。
     *
     * @param int $row 行索引。
     * @param int $col 列索引。
     * @return int 图像索引，-1 表示无图像。
     */
    public function resolveCellImage(int $row, int $col): int
    {
        if ($this->model === null) {
            return -1;
        }
        // TableModel::getCellImage 是可选方法，未实现时返回 -1
        if (!method_exists($this->model, 'getCellImage')) {
            return -1;
        }
        try {
            $image = $this->model->getCellImage($row, $col);
        } catch (\Throwable $e) {
            trigger_error(
                'getCellImage error: ' . $e->getMessage(),
                \E_USER_WARNING
            );
            return -1;
        }
        if (!$image instanceof Image) {
            return -1;
        }
        return $this->addImage($image);
    }

    /**
     * 设置列类型（决定该列单元格的自绘方式）。
     *
     * 必须在 setModel 之前调用。设置 TYPE_IMAGE / TYPE_CHECKBOX /
     * TYPE_PROGRESS / TYPE_COLOR / TYPE_BUTTON 后，平台会在
     * NM_CUSTOMDRAW 的 CDDS_SUBITEM 阶段自绘对应控件外观，
     * 并通过对应 getCellXxx 方法取数据。
     *
     * @param int    $col  列索引（0-based）。
     * @param string $type 类型常量（TYPE_TEXT/IMAGE/CHECKBOX/PROGRESS/COLOR/BUTTON）。
     */
    public function setColumnType(int $col, string $type): void
    {
        $this->columnTypes[$col] = $type;
    }

    /**
     * 获取列类型（内部供平台 NM_CUSTOMDRAW / NM_CLICK 使用）。
     */
    public function getColumnType(int $col): string
    {
        return $this->columnTypes[$col] ?? self::TYPE_TEXT;
    }

    /**
     * 平台消息分发调用：获取 checkbox 状态。
     *
     * 委托 TableModel::getCellCheckbox（可选方法）。返回 null 表示无状态。
     */
    public function resolveCellCheckbox(int $row, int $col): ?bool
    {
        if ($this->model === null || !method_exists($this->model, 'getCellCheckbox')) {
            return null;
        }
        try {
            $val = $this->model->getCellCheckbox($row, $col);
            return is_bool($val) ? $val : null;
        } catch (\Throwable $e) {
            trigger_error('getCellCheckbox error: ' . $e->getMessage(), \E_USER_WARNING);
            return null;
        }
    }

    /**
     * 平台消息分发调用：获取进度条值（0-100）。
     */
    public function resolveCellProgress(int $row, int $col): ?int
    {
        if ($this->model === null || !method_exists($this->model, 'getCellProgress')) {
            return null;
        }
        try {
            $val = $this->model->getCellProgress($row, $col);
            return is_int($val) ? max(0, min(100, $val)) : null;
        } catch (\Throwable $e) {
            trigger_error('getCellProgress error: ' . $e->getMessage(), \E_USER_WARNING);
            return null;
        }
    }

    /**
     * 平台消息分发调用：获取颜色块 Color。
     */
    public function resolveCellColor(int $row, int $col): ?Color
    {
        if ($this->model === null || !method_exists($this->model, 'getCellColor')) {
            return null;
        }
        try {
            $val = $this->model->getCellColor($row, $col);
            return $val instanceof Color ? $val : null;
        } catch (\Throwable $e) {
            trigger_error('getCellColor error: ' . $e->getMessage(), \E_USER_WARNING);
            return null;
        }
    }

    /**
     * 平台消息分发调用：获取按钮文本。
     */
    public function resolveCellButton(int $row, int $col): string
    {
        if ($this->model === null || !method_exists($this->model, 'getCellButton')) {
            return '';
        }
        try {
            $val = $this->model->getCellButton($row, $col);
            return is_string($val) ? $val : '';
        } catch (\Throwable $e) {
            trigger_error('getCellButton error: ' . $e->getMessage(), \E_USER_WARNING);
            return '';
        }
    }

    /**
     * 平台消息分发调用：NM_CLICK 命中 checkbox 列时触发切换。
     *
     * 内部先读取当前状态，取反后触发 onCellCheckboxToggle 回调，
     * 然后刷新该行。注意：实际状态更新由回调负责修改 model 数据。
     */
    public function handleCellCheckboxToggle(int $row, int $col): void
    {
        $current = $this->resolveCellCheckbox($row, $col) ?? false;
        $new = !$current;
        if ($this->onCellCheckboxToggle !== null) {
            try {
                ($this->onCellCheckboxToggle)($row, $col, $new);
            } catch (\Throwable $e) {
                trigger_error('onCellCheckboxToggle error: ' . $e->getMessage(), \E_USER_WARNING);
            }
        }
        $this->refreshRow($row);
    }

    /**
     * 平台消息分发调用：NM_CLICK 命中 button 列时触发点击。
     */
    public function handleCellButtonClick(int $row, int $col): void
    {
        if ($this->onCellButtonClick !== null) {
            try {
                ($this->onCellButtonClick)($row, $col);
            } catch (\Throwable $e) {
                trigger_error('onCellButtonClick error: ' . $e->getMessage(), \E_USER_WARNING);
            }
        }
    }

    /**
     * 设置列定义。
     *
     * 必须在 setModel 之前调用。调用后会清空已有行。
     *
     * @param array<int,string> $columnTexts 列标题列表。
     * @param int|array         $columnWidth 每列宽度（像素）：
     *     - int：所有列统一宽度（向后兼容，默认 100）。
     *     - array<int,int>：按列单独指定宽度，键为列索引（0-based）。
     *       长度可与 $columnTexts 不一致，缺失的列回退为默认值 100。
     */
    public function setColumns(array $columnTexts, int|array $columnWidth = 100): void
    {
        // 清空已有列
        App::platform()->tableClearColumns($this->hwnd);
        $this->columns = [];

        $i = 0;
        foreach ($columnTexts as $text) {
            // 数组形式按列取宽度，缺失回退 100；int 形式统一宽度
            $w = is_array($columnWidth)
                ? (isset($columnWidth[$i]) ? (int) $columnWidth[$i] : 100)
                : $columnWidth;
            App::platform()->tableInsertColumn($this->hwnd, $i, $text, $w);
            $this->columns[] = ['text' => $text, 'width' => $w];
            $i++;
        }
    }

    /**
     * 单独设置某列宽度（在 setColumns 之后调用）。
     *
     * 注意：当前 WindowsPlatform 尚未提供 tableSetColumnWidth 平台方法，
     * 此方法暂只更新内部列定义状态，不会即时改变已显示列宽。
     * 待平台层补充 LVM_SETCOLUMNWIDTH 支持后即可生效。
     *
     * @param int $col   列索引（从 0 开始）。
     * @param int $width 列宽度（像素）。
     */
    public function setColumnWidth(int $col, int $width): void
    {
        if (!isset($this->columns[$col])) {
            trigger_error(
                "setColumnWidth: column index {$col} out of range",
                \E_USER_WARNING
            );
            return;
        }

        // 更新内部列定义
        $this->columns[$col]['width'] = $width;

        // 若平台提供了设置列宽的方法则调用，否则仅更新内部状态
        $platform = App::platform();
        if (method_exists($platform, 'tableSetColumnWidth')) {
            $platform->tableSetColumnWidth($this->hwnd, $col, $width);
        }
    }

    /**
     * 设置数据源模型。
     *
     * 调用后会通知视图刷新行数。model 的列数应与 setColumns 一致。
     */
    public function setModel(TableModel $model): void
    {
        $this->model = $model;
        $this->rowCount = $model->getRowCount();
        App::platform()->tableSetItemCount($this->hwnd, $this->rowCount);
    }

    /**
     * 通知视图行数变化（model 数据集大小变化后调用）。
     */
    public function setRowCount(int $count): void
    {
        $this->rowCount = $count;
        App::platform()->tableSetItemCount($this->hwnd, $count);
    }

    /**
     * 刷新整张表（重新请求所有可见行的数据）。
     *
     * 数据内容变化时调用。
     */
    public function refresh(): void
    {
        if ($this->model !== null) {
            $this->rowCount = $this->model->getRowCount();
            App::platform()->tableSetItemCount($this->hwnd, $this->rowCount);
        }
        App::platform()->tableRefresh($this->hwnd);
    }

    /**
     * 刷新指定行（仅重新请求该行的数据）。
     *
     * 单行数据变化时调用，比 refresh 更高效。
     *
     * @param int $row 行索引。
     */
    public function refreshRow(int $row): void
    {
        App::platform()->tableRefreshRow($this->hwnd, $row);
    }

    /**
     * 选中指定行（滚动到可见）。
     *
     * @param int $row 行索引（-1 取消选中）。
     */
    public function select(int $row): void
    {
        App::platform()->tableSelectRow($this->hwnd, $row);
    }

    /**
     * 获取当前选中行索引。
     *
     * @return int 行索引，-1 表示无选中。
     */
    public function getSelectedRow(): int
    {
        return App::platform()->tableGetSelectedRow($this->hwnd);
    }

    /**
     * 获取数据源模型（内部与平台分发使用）。
     */
    public function getModel(): ?TableModel
    {
        return $this->model;
    }

    /**
     * 设置某行某列单元格的背景色（NM_CUSTOMDRAW 着色）。
     *
     * 传 null 清除该行背景色（恢复系统默认）。
     *
     * @param int      $row   行索引。
     * @param int|null $color ARGB 颜色（0xAARRGGBB），null 清除。
     */
    public function setRowBackgroundColor(int $row, ?int $color): void
    {
        App::platform()->tableSetRowBgColor($this->hwnd, $row, $color);
    }

    /**
     * 设置某行文字颜色。
     *
     * @param int      $row   行索引。
     * @param int|null $color ARGB 颜色，null 清除。
     */
    public function setRowTextColor(int $row, ?int $color): void
    {
        App::platform()->tableSetRowTextColor($this->hwnd, $row, $color);
    }

    /**
     * 平台消息分发调用：选中行变化时触发 onSelectionChanged。
     * 由 WindowsPlatform::dispatchWmNotify 通过 method_exists 调用。
     */
    public function handleSelectionChanged(int $row): void
    {
        if ($this->onSelectionChanged !== null) {
            try {
                ($this->onSelectionChanged)($row);
            } catch (\Throwable $e) {
                trigger_error(
                    'onSelectionChanged callback error: ' . $e->getMessage(),
                    \E_USER_WARNING
                );
            }
        }
    }

    /**
     * 平台消息分发调用：双击行时触发 onRowDoubleClicked。
     */
    public function handleRowDoubleClicked(int $row): void
    {
        if ($this->onRowDoubleClicked !== null) {
            try {
                ($this->onRowDoubleClicked)($row);
            } catch (\Throwable $e) {
                trigger_error(
                    'onRowDoubleClicked callback error: ' . $e->getMessage(),
                    \E_USER_WARNING
                );
            }
        }
    }
}
