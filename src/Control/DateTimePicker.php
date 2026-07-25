<?php
declare(strict_types=1);

namespace Kingbes\Ui\Control;

use Kingbes\Ui\App;
use Kingbes\Ui\Control;
use Kingbes\Ui\Window;

/**
 * 日期时间选择器控件。
 *
 * 基于 Win32 "SysDateTimePick32" 类。支持三种显示模式：
 *   - DATE：仅日期（短日期格式，下拉日历）
 *   - TIME：仅时间（UpDown 控件调整时分秒）
 *   - DATETIME：日期 + 时间（自定义格式 "yyyy-MM-dd HH:mm:ss"）
 *
 * 用法：
 *   $dtp = new DateTimePicker($parent, DateTimePicker::MODE_DATETIME);
 *   $dtp->setTime(2026, 7, 25, 14, 30, 0);
 *   $info = $dtp->getTime();   // ['year'=>2026, 'month'=>7, ...]
 *   $dtp->onChanged = function (DateTimePicker $dtp): void { ... };
 *
 * 事件：
 *   - onChanged：用户修改日期时间后触发（参数：本控件实例）。
 */
class DateTimePicker extends Control
{
    /** 模式：仅日期（短日期格式，下拉日历） */
    public const MODE_DATE = 0;
    /** 模式：仅时间（UpDown 调整） */
    public const MODE_TIME = 1;
    /** 模式：日期 + 时间（自定义格式） */
    public const MODE_DATETIME = 2;

    /** DTS_SHORTDATEFORMAT：短日期下拉日历 */
    private const DTS_SHORTDATEFORMAT = 0x0000;
    /** DTS_TIMEFORMAT | DTS_UPDOWN：仅时间 + UpDown */
    private const DTS_TIMEFORMAT = 0x0009;
    /** DTS_LONGDATEFORMAT：长日期 */
    private const DTS_LONGDATEFORMAT = 0x0004;
    /** DTS_UPDOWN：用 UpDown 替代下拉日历 */
    private const DTS_UPDOWN = 0x0001;
    /** DTS_SHOWNONE：允许无选择（复选框） */
    private const DTS_SHOWNONE = 0x0002;

    /** WS_TABSTOP */
    private const WS_TABSTOP = 0x00010000;

    /** 当前显示模式 */
    private int $mode;

    /** 日期时间变化回调（参数：本控件实例）。 */
    public ?\Closure $onChanged = null;

    /**
     * @param Control|Window $parent 父容器或窗口。
     * @param int            $mode   显示模式（MODE_DATE / MODE_TIME / MODE_DATETIME）。
     */
    public function __construct(Control|Window $parent, int $mode = self::MODE_DATE)
    {
        $this->mode = $mode;
        parent::__construct($parent);
        // DATETIME 模式需设置自定义格式显示日期 + 时间
        if ($mode === self::MODE_DATETIME) {
            $this->setFormat('yyyy-MM-dd HH:mm:ss');
        }
    }

    protected function create(): void
    {
        $style = match ($this->mode) {
            self::MODE_TIME     => self::DTS_TIMEFORMAT,
            self::MODE_DATETIME => self::DTS_SHORTDATEFORMAT, // DATETIME 用自定义格式覆盖
            default             => self::DTS_SHORTDATEFORMAT,
        };
        $style |= self::WS_TABSTOP;

        $this->hwnd = App::platform()->controlCreate(
            'SysDateTimePick32',
            '',
            $style,
            0,
            $this->parentHwnd(),
            0
        );
    }

    /**
     * 获取当前日期时间。
     *
     * @return array{year:int,month:int,day:int,hour:int,minute:int,second:int}|null
     *     用户未选择（DTS_SHOWNONE 模式下取消勾选）时返回 null。
     */
    public function getTime(): ?array
    {
        return App::platform()->dateTimePickerGetTime($this->hwnd);
    }

    /**
     * 设置当前日期时间。
     */
    public function setTime(
        int $year,
        int $month,
        int $day,
        int $hour = 0,
        int $minute = 0,
        int $second = 0
    ): void {
        App::platform()->dateTimePickerSetTime(
            $this->hwnd,
            $year,
            $month,
            $day,
            $hour,
            $minute,
            $second
        );
    }

    /**
     * 设置自定义显示格式（如 "yyyy-MM-dd HH:mm:ss"）。
     *
     * 格式占位符：
     *   yyyy=4位年  MM=2位月  dd=2位日
     *   HH=2位时(24h)  mm=2位分  ss=2位秒
     *   ddd=星期缩写  dddd=星期全名
     */
    public function setFormat(string $format): void
    {
        App::platform()->dateTimePickerSetFormat($this->hwnd, $format);
    }

    /**
     * 平台消息分发调用：DTN_DATETIMECHANGE 时触发 onChanged 回调。
     * 由 WindowsPlatform::dispatchWmNotify 通过 method_exists 调用。
     */
    public function handleDateTimeChange(): void
    {
        if ($this->onChanged !== null) {
            try {
                ($this->onChanged)($this);
            } catch (\Throwable $e) {
                trigger_error(
                    'onChanged callback error: ' . $e->getMessage(),
                    \E_USER_WARNING
                );
            }
        }
    }
}
