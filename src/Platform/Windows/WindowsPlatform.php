<?php
declare(strict_types=1);

namespace Kingbes\Ui\Platform\Windows;

use Kingbes\Ui\Exception\UnsupportedOperationException;
use Kingbes\Ui\Geometry\Point;
use Kingbes\Ui\Geometry\Size;
use Kingbes\Ui\Graphics\Color;
use Kingbes\Ui\Graphics\DrawContext;
use Kingbes\Ui\Platform\AbstractPlatform;
use Kingbes\Ui\Control\Button;
use Kingbes\Ui\Control\Checkbox;
use Kingbes\Ui\Control\RadioBox;
use Kingbes\Ui\Control\Entry;
use Kingbes\Ui\Control\TextArea;
use Kingbes\Ui\Control\ComboBox;
use Kingbes\Ui\Control\ListBox;
use Kingbes\Ui\Control\Slider;
use Kingbes\Ui\Control\ProgressBar;
use Kingbes\Ui\Control\SpinBox;
use Kingbes\Ui\Control\Table;
use Kingbes\Ui\Events\KeyEvent;
use Kingbes\Ui\TrayIcon;
use Kingbes\Ui\Events\MouseEvent;
use Kingbes\Ui\Events\ResizeEvent;
use Kingbes\Ui\Theme;
use Kingbes\Phpc\Library;

/**
 * Windows 后端平台实现（Unicode W 系列）。
 *
 * 通过 FFI 加载 6 个系统 DLL（user32/gdi32/kernel32/comctl32/comdlg32/shell32），
 * 以 PHP 闭包作为 WindowProc 响应窗口消息，提供窗口/事件循环/系统服务能力。
 *
 * 关键设计：
 *   - 所有文本 API 使用 Unicode W 系列（RegisterClassExW/CreateWindowExW/
 *     DefWindowProcW/PeekMessageW/SendMessageW/SetWindowTextW 等），支持中文与 emoji。
 *   - 字符串处理：PHP UTF-8 ↔ UTF-16LE（self::conv 回退链 mb_convert_encoding → iconv），FFI 用 wchar_t[]。
 *   - 所有句柄对外用 int 传递，内部用 INT_TO_PTR 联合体完成 int↔HWND 转换。
 *   - WindowProc 闭包存于 $this->windowProc 属性防 GC 回收导致崩溃。
 *   - 主循环用 PeekMessageW 非阻塞轮询 + usleep(1000)，保证定时器/queueMain 执行。
 *   - 控件创建后用 CreateFontW 创建 "Segoe UI" 默认字体，SendMessageW(WM_SETFONT) 设置。
 *   - WM_COMMAND 通过 lParam(控件 HWND) 反查控件实例，分发到对应事件闭包。
 *   - WM_KEYDOWN/WM_KEYUP 在消息循环中拦截，分发到控件的 onKeyDown/onKeyUp。
 */
class WindowsPlatform extends AbstractPlatform
{
    // ============================================================
    // Win32 常量
    // ============================================================

    /** 窗口类名（注册一次） */
    private const WINDOW_CLASS_NAME = 'PhpUiWindow';

    // 窗口风格
    private const WS_OVERLAPPEDWINDOW = 0x00CF0000;
    private const WS_VISIBLE          = 0x10000000;
    private const WS_POPUP            = 0x80000000;
    private const WS_CAPTION          = 0x00C00000;
    private const WS_THICKFRAME       = 0x00040000;
    private const WS_VSCROLL          = 0x00200000;
    private const WS_HSCROLL          = 0x00100000;
    private const WS_CHILD            = 0x40000000;
    private const WS_CLIPCHILDREN     = 0x02000000;
    private const WS_BORDER           = 0x00800000;
    private const WS_TABSTOP          = 0x00010000;
    private const WS_GROUP            = 0x00020000;

    // 扩展样式
    private const WS_EX_CLIENTEDGE    = 0x00000200;

    // 类风格
    private const CS_HREDRAW = 0x0002;
    private const CS_VREDRAW = 0x0001;

    // GetWindowLongPtr 索引
    private const GWL_STYLE      = -16;
    private const GWLP_USERDATA  = -21;
    private const GWLP_WNDPROC    = -4;

    // ShowWindow 命令
    private const SW_HIDE      = 0;
    private const SW_SHOW      = 5;
    private const SW_MAXIMIZE  = 3;
    private const SW_MINIMIZE  = 6;
    private const SW_RESTORE   = 9;

    // SetWindowPos 标志
    private const SWP_NOSIZE       = 0x0001;
    private const SWP_NOMOVE       = 0x0002;
    private const SWP_NOZORDER     = 0x0004;
    private const SWP_FRAMECHANGED = 0x0020;

    // HWND 特殊句柄
    private const HWND_TOPMOST    = -1;
    private const HWND_NOTOPMOST  = -2;

    // 消息
    private const WM_CREATE      = 0x0001;
    private const WM_DESTROY     = 0x0002;
    private const WM_SIZE        = 0x0005;
    private const WM_MOVE        = 0x0003;
    private const WM_PAINT       = 0x000F;
    private const WM_ERASEBKGND  = 0x0014;
    private const WM_CLOSE       = 0x0010;
    private const WM_QUIT        = 0x0012;
    private const WM_NULL        = 0x0000;
    private const WM_SETFONT     = 0x0030;
    private const WM_COMMAND     = 0x0111;
    private const WM_NOTIFY      = 0x004E;
    private const WM_HSCROLL     = 0x0114;
    private const WM_VSCROLL     = 0x0115;
    private const WM_KEYDOWN     = 0x0100;
    private const WM_KEYUP       = 0x0101;
    private const WM_MOUSEMOVE   = 0x0200;
    private const WM_LBUTTONDOWN = 0x0201;
    private const WM_LBUTTONUP   = 0x0202;
    private const WM_RBUTTONDOWN = 0x0204;
    private const WM_RBUTTONUP   = 0x0205;
    private const WM_MBUTTONDOWN = 0x0207;
    private const WM_MBUTTONUP   = 0x0208;
    private const WM_MOUSELEAVE  = 0x02A3;

    // PeekMessage
    private const PM_REMOVE = 1;

    // GetSystemMetrics
    private const SM_CXSCREEN = 0;
    private const SM_CYSCREEN = 1;

    // ScrollBar 类型（SetScrollInfo/GetScrollInfo 第二参数 nBar）
    private const SB_CTL  = 2;  // 控件滚动条（Slider/ListBox 等）
    private const SB_VERT = 1;  // 窗口垂直滚动条
    private const SB_HORZ = 0;  // 窗口水平滚动条

    // 光标 / 颜色
    private const IDC_ARROW    = 32512;
    private const COLOR_WINDOW = 5;

    // 剪贴板
    private const CF_UNICODETEXT = 13;
    private const GMEM_MOVEABLE   = 0x0002;

    // CreateWindow 默认位置（带符号 32 位值）
    private const CW_USEDEFAULT = -2147483648;

    // ============================================================
    // 控件样式常量
    // ============================================================

    // Button 风格
    private const BS_PUSHBUTTON       = 0x00010000;
    private const BS_AUTOCHECKBOX     = 0x00000003;
    private const BS_AUTORADIOBUTTON  = 0x00000004;

    // Static 风格
    private const SS_LEFT             = 0x00000000;
    private const SS_CENTER           = 0x00000001;
    private const SS_RIGHT            = 0x00000002;

    // Edit 风格
    private const ES_AUTOHSCROLL      = 0x0080;
    private const ES_MULTILINE        = 0x0004;
    private const ES_AUTOVSCROLL      = 0x0040;
    private const ES_WANTRETURN       = 0x1000;
    private const ES_READONLY         = 0x0800;
    private const ES_PASSWORD         = 0x0020;

    // ComboBox 风格
    private const CBS_DROPDOWNLIST    = 0x0003;

    // ListBox 风格
    private const LBS_STANDARD         = 0x00A00003;

    // Trackbar 风格
    private const TBS_AUTOTICKS       = 0x0001;

    // UpDown 风格
    private const UDS_SETBUDDY         = 0x0002;
    private const UDS_ALIGNRIGHT       = 0x0004;
    private const UDS_ARROWKEYS        = 0x0020;
    private const UDS_AUTOBUDDY        = 0x0010;

    // ============================================================
    // 控件消息常量
    // ============================================================

    // Button 消息
    private const BM_GETCHECK  = 0x00F0;
    private const BM_SETCHECK  = 0x00F1;

    // 复选状态
    private const BST_UNCHECKED = 0;
    private const BST_CHECKED   = 1;

    // ComboBox 消息
    private const CB_ADDSTRING     = 0x0143;
    private const CB_DELETESTRING  = 0x0144;
    private const CB_RESETCONTENT  = 0x014B;
    private const CB_GETCURSEL     = 0x0147;
    private const CB_SETCURSEL     = 0x014E;

    // ListBox 消息
    private const LB_ADDSTRING     = 0x0180;
    private const LB_DELETESTRING  = 0x0182;
    private const LB_RESETCONTENT  = 0x0184;
    private const LB_GETCURSEL      = 0x0188;
    private const LB_SETCURSEL      = 0x0186;

    // Trackbar 消息
    private const TBM_SETRANGEMIN  = 0x041D;
    private const TBM_SETRANGEMAX  = 0x041E;
    private const TBM_GETPOS        = 0x0400;
    private const TBM_SETPOS        = 0x0405;

    // UpDown 消息
    private const UDM_SETRANGE      = 0x0465;
    private const UDM_GETPOS        = 0x0468;
    private const UDM_SETPOS        = 0x0467;

    // ProgressBar 消息
    private const PBM_SETRANGE     = 0x0401;
    private const PBM_SETPOS       = 0x0402;
    private const PBM_SETMARQUEE   = 0x040A; // 启用/关闭滚动动画

    // ProgressBar 样式
    private const PBS_MARQUEE      = 0x0008; // 不确定状态样式

    // Tab 控件消息（TCM_FIRST = 0x1300）
    private const TCM_GETITEMCOUNT = 0x1304;
    private const TCM_DELETEITEM   = 0x1308;
    private const TCM_GETCURSEL    = 0x130B;
    private const TCM_SETCURSEL    = 0x130C;
    private const TCM_INSERTITEMW  = 0x1332;
    private const TCM_GETITEMW     = 0x133C; // TCM_FIRST + 60
    private const TCM_SETITEMW     = 0x133E; // TCM_FIRST + 62
    private const TCM_SETIMAGELIST = 0x1303; // TCM_FIRST + 3

    // Tab 控件通知码（TCN_FIRST = -550）
    private const TCN_SELCHANGE    = -551;

    // TCITEM mask
    private const TCIF_TEXT        = 0x0001;
    private const TCIF_IMAGE       = 0x0002;

    // Static 控件消息（STM_SETIMAGE）：设置 Static 控件的图像
    private const STM_SETIMAGE     = 0x0172;
    // Static 控件图像类型
    private const IMAGE_BITMAP     = 0;
    private const IMAGE_ICON       = 1;

    // Button 消息（BM_SETIMAGE）：设置按钮图像
    private const BM_SETIMAGE      = 0x00F7;

    // MENUITEMINFO mask
    private const MIIM_BITMAP      = 0x00000080; // hbmpItem 有效

    // WM_COMMAND 通知码
    private const BN_CLICKED     = 0;
    private const EN_CHANGE      = 0x0300;
    private const CBN_SELCHANGE  = 1;
    private const CBN_EDITCHANGE = 5;
    private const LBN_SELCHANGE  = 1;

    // WM_HSCROLL/WM_VSCROLL 通知码
    private const SB_LINEUP         = 0;
    private const SB_LINEDOWN       = 1;
    private const SB_PAGEUP         = 2;
    private const SB_PAGEDOWN       = 3;
    private const SB_THUMBPOSITION = 4;
    private const SB_THUMBTRACK     = 5;
    private const SB_TOP            = 6;
    private const SB_BOTTOM         = 7;
    private const SB_ENDSCROLL      = 8;

    // TrackMouseEvent 标志
    private const TME_LEAVE = 0x0002;

    // DateTimePicker 样式
    private const DTS_SHORTDATEFORMAT  = 0x0000; // 默认短日期
    private const DTS_UPDOWN          = 0x0001; // 用 UpDown 替代下拉日历
    private const DTS_SHOWNONE        = 0x0002; // 允许无选择（复选框）
    private const DTS_LONGDATEFORMAT  = 0x0004; // 长日期
    private const DTS_TIMEFORMAT      = 0x0009; // 仅时间（DTS_TIMEFORMAT|DTS_UPDOWN）
    private const DTS_RIGHTALIGN      = 0x0020; // 右对齐

    // DateTimePicker 消息（DTM_FIRST = 0x1000）
    private const DTM_GETSYSTEMTIME = 0x1001; // DTM_FIRST + 1
    private const DTM_SETSYSTEMTIME = 0x1002; // DTM_FIRST + 2
    private const DTM_SETFORMATW    = 0x1032; // DTM_FIRST + 50 (Unicode)

    // DateTimePicker 通知（DTN_FIRST2 = -753）
    private const DTN_DATETIMECHANGE = -759;

    // UpDown 控件通知（UDN_FIRST = -721）
    private const UDN_DELTAPOS = -722;

    // DateTimePicker 返回值
    private const GDT_VALID = 0; // 时间有效
    private const GDT_NONE  = 1; // 未选择（DTS_SHOWNONE）

    // ============================================================
    // ListView 消息常量（LVM_FIRST = 0x1000）
    // ============================================================

    private const LVM_FIRST               = 0x1000;
    private const LVM_SETITEMCOUNT        = 0x102F; // LVM_FIRST + 47
    private const LVM_GETITEMCOUNT        = 0x1004; // LVM_FIRST + 4
    private const LVM_DELETEALLITEMS      = 0x1009; // LVM_FIRST + 9
    private const LVM_INSERTCOLUMNW       = 0x1061; // LVM_FIRST + 97 (Unicode)
    private const LVM_DELETECOLUMN        = 0x101C; // LVM_FIRST + 28
    private const LVM_GETSELECTIONMARK    = 0x102E; // LVM_FIRST + 46
    private const LVM_SETSELECTIONMARK    = 0x102D; // LVM_FIRST + 45
    private const LVM_GETNEXTITEM         = 0x100C; // LVM_FIRST + 12
    private const LVM_SETITEMSTATE        = 0x102B; // LVM_FIRST + 43
    private const LVM_ENSUREVISIBLE       = 0x1013; // LVM_FIRST + 19
    private const LVM_REDRAWITEMS         = 0x1016; // LVM_FIRST + 22
    private const LVM_GETITEMSTATE        = 0x102C; // LVM_FIRST + 44
    private const LVM_SETEXTENDEDLISTVIEWSTYLE = 0x1036; // LVM_FIRST + 54
    private const LVM_SETIMAGELIST        = 0x1003; // LVM_FIRST + 3

    // ListView 扩展样式
    private const LVS_EX_SUBITEMIMAGES = 0x00000008; // 子列支持图像
    // ListView 图像列表类型（LVM_SETIMAGELIST 的 wParam）
    private const LVSIL_SMALL = 1;

    // LVITEM mask
    private const LVIF_TEXT   = 0x0001;
    private const LVIF_IMAGE  = 0x0002;
    private const LVIF_STATE  = 0x0008;

    // LVITEM state
    private const LVIS_SELECTED = 0x0002;
    private const LVIS_FOCUSED  = 0x0001;

    // LVM_GETNEXTITEM 参数
    private const LVNI_SELECTED = 0x0002;

    // LVCOLUMN mask
    private const LVCF_FMT   = 0x0001;
    private const LVCF_WIDTH = 0x0002;
    private const LVCF_TEXT  = 0x0004;

    // LVCOLUMN fmt
    private const LVCFMT_LEFT = 0x0000;

    // ListView 通知码（LVN_FIRST = -100）
    private const LVN_ITEMCHANGED   = -101; // LVN_FIRST - 1
    private const LVN_GETDISPINFO   = -177; // LVN_FIRST - 77 (Unicode: LVN_GETDISPINFOW)
    private const LVN_GETDISPINFOW  = -177;

    // NM 通知码（NM_FIRST = 0）
    private const NM_DBLCLK    = -3;
    private const NM_CUSTOMDRAW = -12;
    private const NM_CLICK     = -2;   // NM_FIRST - 2

    // NM_CUSTOMDRAW 阶段
    private const CDDS_PREPAINT     = 0x00000001;
    private const CDDS_ITEMPREPAINT = 0x00010001;
    private const CDDS_SUBITEM      = 0x00020000;

    // NM_CUSTOMDRAW 返回值
    private const CDRF_DODEFAULT         = 0x00000000;
    private const CDRF_NOTIFYITEMDRAW    = 0x00000020;
    private const CDRF_NOTIFYPOSTPAINT   = 0x00000010;
    private const CDRF_NEWFONT           = 0x00000040;
    private const CDRF_NOTIFYSUBITEMDRAW = 0x00000020;
    private const CDRF_SKIPDEFAULT       = 0x00000004;

    // LVM_SUBITEMHITTEST = LVM_FIRST + 57
    private const LVM_SUBITEMHITTEST = 0x1039;

    // LVHITTESTINFO flags（命中测试标志）
    private const LVHT_ONITEMICON    = 0x0002;
    private const LVHT_ONITEMLABEL   = 0x0004;
    private const LVHT_ONITEMSTATEICON = 0x0008;
    private const LVHT_ONITEM = 0x000E;  // 上述三者 OR

    // DrawEdge edge 标志
    private const BDR_RAISEDINNER = 0x0004;
    private const BDR_SUNKENINNER = 0x0008;
    private const BDR_RAISEDOUTER = 0x0001;
    private const BDR_SUNKENOUTER = 0x0002;
    private const EDGE_SUNKEN     = 0x0005;  // BDR_SUNKENOUTER | BDR_SUNKENINNER
    private const EDGE_RAISED     = 0x0001;  // BDR_RAISEDOUTER | BDR_RAISEDINNER (此处简化为外框)
    private const EDGE_ETCHED     = 0x0006;
    private const BF_TOP    = 0x0001;
    private const BF_BOTTOM = 0x0004;
    private const BF_LEFT   = 0x0008;
    private const BF_RIGHT  = 0x0002;
    private const BF_RECT   = 0x000F;  // TOP|LEFT|BOTTOM|RIGHT

    // DrawFrameControl uType / uState
    private const DFC_BUTTON = 0x0004;
    private const DFC_MENU   = 0x0002;
    private const DFCS_BUTTONPUSH  = 0x0000;
    private const DFCS_BUTTONCHECK = 0x0001;
    private const DFCS_CHECKED     = 0x0100;
    private const DFCS_FLAT        = 0x4000;
    private const DFCS_PUSHED      = 0x0200;
    private const DFCS_INACTIVE    = 0x0100;

    // GetSysColorBrush 系统颜色索引
    private const COLOR_BTNFACE    = 15;
    private const COLOR_HIGHLIGHT  = 13;
    private const COLOR_BTNTEXT    = 18;
    private const COLOR_WINDOWTEXT = 8;

    // DrawTextW 格式标志
    private const DT_CENTER      = 0x00000001;
    private const DT_VCENTER     = 0x00000004;
    private const DT_SINGLELINE  = 0x00000020;
    private const DT_CALCRECT    = 0x00000400;
    private const DT_END_ELLIPSIS = 0x00008000;

    // LVM_SETCOLUMNWIDTH（Task 7：tableSetColumnWidth）
    private const LVM_SETCOLUMNWIDTH = 0x101E; // LVM_FIRST + 30

    // SCROLLINFO fMask
    private const SIF_RANGE           = 0x0001;
    private const SIF_PAGE           = 0x0002;
    private const SIF_POS             = 0x0004;
    private const SIF_TRACKPOS        = 0x0010;
    private const SIF_ALL             = 0x0017; // RANGE|PAGE|POS|TRACKPOS

    // 虚拟键码
    private const VK_RETURN = 0x0D;

    // ============================================================
    // 菜单标志常量（AppendMenuW / EnableMenuItem / CheckMenuItem）
    // ============================================================

    private const MF_STRING    = 0x00000000; // 菜单项为文本字符串
    private const MF_SEPARATOR = 0x00000800; // 分隔符
    private const MF_POPUP     = 0x00000010; // 弹出子菜单
    private const MF_BYCOMMAND = 0x00000000; // 按 ID 查找（默认）
    private const MF_ENABLED   = 0x00000000; // 启用
    private const MF_GRAYED    = 0x00000001; // 禁用（灰显）
    private const MF_CHECKED   = 0x00000008; // 勾选
    private const MF_UNCHECKED = 0x00000000; // 取消勾选

    // 菜单相关消息
    private const WM_INITMENUPOPUP = 0x0117;

    // ============================================================
    // 托盘图标（Shell_NotifyIconW）
    // ============================================================

    /** NIM_ADD / NIM_MODIFY / NIM_DELETE：添加/修改/删除托盘图标 */
    private const NIM_ADD    = 0x00000000;
    private const NIM_MODIFY = 0x00000001;
    private const NIM_DELETE = 0x00000002;

    /** NOTIFYICONDATAW.uFlags 标志位 */
    private const NIF_MESSAGE = 0x00000001; // uCallbackMessage 有效
    private const NIF_ICON    = 0x00000002; // hIcon 有效
    private const NIF_TIP     = 0x00000004; // szTip 有效
    private const NIF_INFO    = 0x00000010; // szInfo/szInfoTitle 有效（气球）
    private const NIF_STATE   = 0x00000008; // dwState/dwStateMask 有效

    /** 托盘气球通知图标类型 */
    private const NIIF_NONE      = 0x00000000;
    private const NIIF_INFO      = 0x00000001;
    private const NIIF_WARNING   = 0x00000002;
    private const NIIF_ERROR     = 0x00000003;
    private const NIIF_USER      = 0x00000004;

    /** 托盘回调消息的 lParam（鼠标消息） */
    private const WM_LBUTTONCLK_TRAY  = 0x0202;  // WM_LBUTTONUP
    private const WM_LBUTTONDBLCLK_TRAY = 0x0203; // WM_LBUTTONDBLCLK
    private const WM_RBUTTONUP_TRAY   = 0x0205;  // WM_RBUTTONUP

    /** 自定义消息基址（WM_APP = 0x8000）用于托盘回调 */
    private const WM_APP = 0x8000;
    private const WM_TRAYICON = 0x8000;  // 托盘回调消息 ID

    // ============================================================
    // 窗口图标（LoadImageW + WM_SETICON）
    // ============================================================

    /** WM_SETICON 消息（设置窗口图标） */
    private const WM_SETICON = 0x0080;
    /** ICON_BIG / ICON_SMALL：大/小图标 */
    private const ICON_BIG   = 1;
    private const ICON_SMALL = 0;

    /** LoadImageW type 参数 */
    private const IMAGE_BITMAP_LOAD = 0;  // LR_BITMAP
    private const IMAGE_ICON_LOAD   = 1;  // LR_ICON
    private const IMAGE_CURSOR_LOAD = 2;  // LR_CURSOR

    /** LoadImageW fuLoad 标志 */
    private const LR_LOADFROMFILE = 0x00000010;
    private const LR_DEFAULTSIZE  = 0x00000040;

    /** TrackPopupMenu uFlags 标志 */
    private const TPM_LEFTBUTTON  = 0x00000000;
    private const TPM_RIGHTBUTTON = 0x00000002;
    private const TPM_LEFTALIGN   = 0x00000000;
    private const TPM_CENTERALIGN = 0x00000004;
    private const TPM_RIGHTALIGN  = 0x00000008;
    private const TPM_RETURNCMD   = 0x00000100;  // 返回选中项 ID 而非 BOOL

    /** LoadIconW 预定义图标 ID（IDI_*） */
    private const IDI_APPLICATION = 32512;
    private const IDI_HAND        = 32513;
    private const IDI_QUESTION    = 32514;
    private const IDI_EXCLAMATION = 32515;
    private const IDI_ASTERISK    = 32516;
    private const IDI_INFORMATION = 32516;

    // ============================================================
    // 视觉样式 / 激活上下文 / 深色模式 常量（Task 3/4）
    // ============================================================

    /** ACTCTX dwFlags 标志 */
    private const ACTCTX_FLAG_PROCESSOR_ARCHITECTURE = 0;
    private const ACTCTX_FLAG_PROCESSOR_ARCHITECTURE_VALID = 0x001;
    private const ACTCTX_FLAG_LANGID_VALID                 = 0x002;
    private const ACTCTX_FLAG_ASSEMBLY_DIRECTORY_VALID     = 0x004;
    private const ACTCTX_FLAG_RESOURCE_NAME_VALID          = 0x008;
    private const ACTCTX_FLAG_SET_PROCESS_DEFAULT          = 0x010;
    private const ACTCTX_FLAG_ASSEMBLY_NAME_VALID          = 0x040;
    private const ACTCTX_FLAG_SOURCE_IS_FILE               = 0x080;

    /** DwmSetWindowAttribute 属性 ID：沉浸式深色模式（Windows 10 1809+ 用 20，旧版用 19） */
    private const DWMWA_USE_IMMERSIVE_DARK_MODE      = 20;
    private const DWMWA_USE_IMMERSIVE_DARK_MODE_OLD  = 19;

    /** DwmSetWindowAttribute 属性 ID：窗口圆角偏好（Win11 22000+，1=不圆角 2=圆角） */
    private const DWMWA_WINDOW_CORNER_PREFERENCE = 33;

    /** DwmSetWindowAttribute 属性 ID：系统背景类型（Win11 22000+，2=Mica 3=Acrylic 4=Tabbed） */
    private const DWMWA_SYSTEMBACKDROP_TYPE = 38;

    /**
     * 运行时 manifest XML 模板：ComCtl32 v6 依赖。
     *
     * 写入临时文件后由 CreateActCtxW 激活，启用现代视觉样式（圆角按钮、
     * 主题色控件等）。UTF-8 with BOM 编码（CreateActCtxW 实测可识别）。
     * 注意：不声明 dpiAware/PerMonitorV2，避免 DPI 缩放与现有布局逻辑
     * 冲突导致控件坐标错乱（"隔着一层"现象）。DPI 感知待后续单独实现。
     */
    private const MANIFEST_XML = <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<assembly xmlns="urn:schemas-microsoft-com:asm.v1" manifestVersion="1.0">
  <dependency>
    <dependentAssembly>
      <assemblyIdentity
        type="win32"
        name="Microsoft.Windows.Common-Controls"
        version="6.0.0.0"
        processorArchitecture="*"
        publicKeyToken="6595b64144ccf1df"
        language="*"
      />
    </dependentAssembly>
  </dependency>
  <compatibility xmlns="urn:schemas-microsoft-com:compatibility.v1">
    <application>
      <!-- Win10/11 -->
      <supportedOS Id="{8e0f7a12-bfb3-4fe8-b9a5-48fd50a15a9a}"/>
      <!-- Win8.1 -->
      <supportedOS Id="{1f676c76-80e1-4239-95bb-83d0f6cc0b7e}"/>
      <!-- Win8 -->
      <supportedOS Id="{4a2f28e3-53b9-4441-ba9c-d69d4a4a6e38}"/>
      <!-- Win7 -->
      <supportedOS Id="{35138b9a-5d96-4fbd-8e2d-a2440225f93a}"/>
    </application>
  </compatibility>
</assembly>
XML;

    // ============================================================
    // FFI 实例
    // ============================================================

    /** @var \FFI|null user32 */
    private ?\FFI $user32 = null;
    /** @var \FFI|null gdi32 */
    private ?\FFI $gdi32 = null;
    /** @var \FFI|null kernel32 */
    private ?\FFI $kernel32 = null;
    /** @var \FFI|null comctl32 */
    private ?\FFI $comctl32 = null;
    /** @var \FFI|null comdlg32 */
    private ?\FFI $comdlg32 = null;
    /** @var \FFI|null shell32 */
    private ?\FFI $shell32 = null;
    /** @var \FFI|null ole32（CoTaskMemFree 用于释放 PIDL） */
    private ?\FFI $ole32 = null;
    /** @var \FFI|null gdiplus（GDI+ 彩色 emoji 渲染） */
    private ?\FFI $gdiplus = null;

    /** GDI+ 启动 token（CData 保活，进程退出时由 GdiplusShutdown 释放）。 */
    private $gdiplusToken = null;

    // ============================================================
    // 运行期状态
    // ============================================================

    /** WindowProc 闭包引用（防 GC 回收导致窗口崩溃）。 */
    private ?\Closure $windowProc = null;

    /** 窗口类是否已注册（进程内只注册一次）。 */
    private bool $classRegistered = false;

    /** 类名 wchar_t[] 缓冲（owned=false，进程内常驻防释放）。 */
    private $classNameBuf = null;

    /** 模态对话框守护标志。 */
    protected bool $inModalDialog = false;

    /** 全屏状态保存：hwnd(int) => [left, top, right, bottom, style] */
    private array $fullscreenState = [];

    /**
     * 窗口滚动状态：hwnd(int) => ['contentHeight' => int, 'scrollPos' => int]。
     *
     * windowSetScrollable 启用窗口垂直滚动时初始化；WM_VSCROLL 时更新
     * scrollPos；relayoutWindowNow 读取以偏移子容器位置（用 contentHeight
     * 作为容器高度，baseY = -scrollPos）。
     *
     * @var array<int, array{contentHeight:int, scrollPos:int}>
     */
    private array $windowScrollInfo = [];

    /** 控件 ID 自增计数器。 */
    private int $nextControlId = 1000;

    /** 控件类型表：hwnd(int) => 原生类名（用于区分 ComboBox/ListBox 消息）。 */
    private array $controlTypes = [];

    /**
     * 已注册的 TrayIcon 实例列表：trayId => TrayIcon。
     *
     * 用于 WM_TRAYICON 回调分发，根据 lParam（鼠标消息类型）调用
     * 对应 TrayIcon::handleCallback。
     *
     * @var array<int, \Kingbes\Ui\TrayIcon>
     */
    private array $trayIcons = [];

    /** TrayIcon ID 自增计数器（同一窗口内多个托盘需唯一）。 */
    private int $nextTrayId = 1;

    /**
     * 窗口自定义图标缓存：hwnd(int) => HICON int 句柄。
     *
     * windowSetIconFromImage 时存储，下次设置前 / 窗口销毁时调用
     * destroyIconInt 释放，避免内存泄漏。
     *
     * @var array<int, int>
     */
    private array $windowImageIcons = [];

    /** 默认字体 HFONT（CData，保持引用防 GC）。 */
    private $defaultFont = null;

    /** 默认字体 HFONT int 值（用于 WPARAM）。 */
    private int $defaultFontInt = 0;

    // ============================================================
    // 主题与视觉样式状态（Task 3/4/6）
    // ============================================================

    /** 激活上下文句柄（HANDLE，kernel32 作用域；非 null 表示已激活）。 */
    private $hActCtx = null;

    /** 激活上下文 cookie（ULONG_PTR，DeactivateActCtx 用）。 */
    private $actCtxCookie = 0;

    /** uxtheme.dll FFI 实例（视觉样式 API：OpenThemeData/DrawThemeBackground 等）。 */
    private ?\FFI $uxtheme = null;

    /**
     * uxtheme.dll FFI 实例（仅 SetPreferredAppMode 深色模式偏好）。
     *
     * 单独加载：SetPreferredAppMode 是 ordinal 135 未文档化导出，
     * 旧系统 cdef 按名称解析会失败，导致整个 uxtheme 不可用；
     * 拆分后视觉样式 API 仍可独立加载。
     */
    private ?\FFI $uxthemeDark = null;

    /** dwmapi.dll FFI 实例（DwmSetWindowAttribute 标题栏深色；旧系统可能加载失败）。 */
    private ?\FFI $dwmapi = null;

    /** 当前主题（Theme::SYSTEM/CLASSIC/DARK/LIGHT）。 */
    private string $currentTheme = Theme::SYSTEM;

    /** 现代字体 HFONT（CData，gdi32 作用域；非 CLASSIC 主题时创建，9pt Segoe UI）。 */
    private $hFont = null;

    /** manifest 临时文件路径（析构时 unlink）。 */
    private string $manifestFile = '';

    /**
     * 菜单 HMENU CData 保活表：menuHwnd(int) => HMENU CData。
     *
     * menuCreateBar/menuCreatePopup 返回的 HMENU CData 必须保活，
     * 否则 GC 回收后地址失效。EnableMenuItem/CheckMenuItem 等操作
     * 需从本表取出原始 CData 引用传入，不能用 intToHwnd 重新创建。
     *
     * @var array<int, \FFI\CData>
     */
    private array $menus = [];

    /**
     * Area 鼠标跟踪状态：hwnd(int) => bool。
     *
     * true 表示已调用 TrackMouseEvent 注册 WM_MOUSELEAVE，处于"鼠标在
     * 区域内"状态。WM_MOUSELEAVE 触发后清除，下次 WM_MOUSEMOVE 时重新
     * 注册并触发 onMouseEnter。
     *
     * @var array<int, bool>
     */
    private array $areaMouseTracking = [];

    /**
     * Area 滚动状态：hwnd(int) => ['w'=>int, 'h'=>int, 'x'=>int, 'y'=>int]。
     *
     * areaSetScrollable 初始化：w/h 为内容尺寸，x/y 为当前滚动偏移。
     * WM_HSCROLL/WM_VSCROLL 时更新 x/y 并 Invalidate 触发重绘。
     * WM_PAINT 时由 GdipTranslateWorldTransform(-x, -y) 应用偏移，
     * 用户 onDraw 在内容坐标系（0,0 ~ w,h）内绘制即可。
     * 鼠标事件分发时将客户端坐标 +x/+y 转换为内容坐标。
     *
     * @var array<int, array{w:int, h:int, x:int, y:int}>
     */
    private array $areaScrollInfo = [];

    /**
     * Table 行背景色：hwnd(int) => [row(int) => COLORREF(int)]。
     *
     * NM_CUSTOMDRAW 时查表，若命中则设置 clrTextBk。
     * 由 tableSetRowBgColor 写入，tableSetRowBgColor(null) 清除。
     *
     * @var array<int, array<int, int>>
     */
    private array $tableRowBgColors = [];

    /**
     * Table 行文字色：hwnd(int) => [row(int) => COLORREF(int)]。
     *
     * NM_CUSTOMDRAW 时查表，若命中则设置 clrText。
     *
     * @var array<int, array<int, int>>
     */
    private array $tableRowTextColors = [];

    /**
     * ImageList 保活表：int 句柄 => HIMAGELIST CData。
     *
     * 由 tableCreateImageList / tabCreateImageList 写入，
     * 防止 GC 回收后地址失效（与 $menus 同理）。
     *
     * @var array<int, \FFI\CData>
     */
    private array $imageLists = [];

    // ============================================================
    // 构造器：加载 6 个系统 DLL
    // ============================================================

    public function __construct()
    {
        Library::permit('user32.dll');
        Library::permit('gdi32.dll');
        Library::permit('kernel32.dll');
        Library::permit('comctl32.dll');
        Library::permit('comdlg32.dll');
        Library::permit('shell32.dll');
        Library::permit('ole32.dll');
        Library::permit('gdiplus.dll');

        $this->user32   = Library::load('user32.dll',   self::USER32_HEADER);
        $this->gdi32    = Library::load('gdi32.dll',    self::GDI32_HEADER);
        $this->kernel32 = Library::load('kernel32.dll', self::KERNEL32_HEADER);
        $this->comctl32 = Library::load('comctl32.dll', self::COMCTL32_HEADER);
        $this->comdlg32 = Library::load('comdlg32.dll', self::COMMDLG32_HEADER);
        $this->shell32  = Library::load('shell32.dll',  self::SHELL32_HEADER);
        $this->ole32    = Library::load('ole32.dll',    self::OLE32_HEADER);
        $this->gdiplus  = Library::load('gdiplus.dll',  self::GDIPLUS_HEADER);

        // 初始化 GDI+（彩色 emoji 渲染必需，否则所有 GDI+ 函数返回错误）
        $this->initGdiplus();

        // 动态加载 uxtheme.dll / dwmapi.dll（Task 3/4：视觉样式 + 深色模式）。
        // 旧系统（XP/2000）可能没有这两个 DLL 或缺失部分导出函数，
        // 用 \FFI::cdef 直接加载并捕获异常静默失败，保证向后兼容。
        // uxtheme 拆分为两个 cdef：标准视觉样式 API 与 SetPreferredAppMode
        // （后者 ordinal 135 未文档化导出，按名称解析失败会让整个 cdef 失败）。
        $this->uxtheme     = $this->loadOptionalFfi('uxtheme.dll', self::UXTHEME_HEADER);
        $this->uxthemeDark = $this->loadOptionalFfi('uxtheme.dll', self::UXTHEME_DARK_HEADER);
        $this->dwmapi      = $this->loadOptionalFfi('dwmapi.dll',  self::DWMAPI_HEADER);
    }

    /**
     * 动态加载可选 FFI 库（DLL 不存在或函数缺失时静默返回 null）。
     *
     * 用于 uxtheme.dll / dwmapi.dll：Windows 2000/XP 可能没有这些 DLL，
     * 或部分导出函数不存在（如 SetPreferredAppMode 仅 Win10 1809+ 有）。
     * cdef 失败时返回 null，调用方需判空。
     */
    private function loadOptionalFfi(string $lib, string $header): ?\FFI
    {
        try {
            return \FFI::cdef($header, $lib);
        } catch (\Throwable $e) {
            // 旧系统无此 DLL 或函数不存在，静默降级
            return null;
        }
    }

    /**
     * 初始化 GDI+：GdiplusStartup，token 存 $this->gdiplusToken。
     *
     * SuppressBackgroundThread=0 表示使用调用线程，无需 GdiplusNotificationHook。
     */
    private function initGdiplus(): void
    {
        $input = $this->gdiplus->new('GdiplusStartupInput');
        $input->GdiplusVersion = 1;
        $input->DebugEventCallback = null;
        $input->SuppressBackgroundThread = 0;
        $input->SuppressExternalCodecs = 0;

        $this->gdiplusToken = $this->gdiplus->new('ULONG_PTR');
        $status = (int) $this->gdiplus->GdiplusStartup(
            \FFI::addr($this->gdiplusToken),
            \FFI::addr($input),
            null
        );
        if ($status !== 0) {
            throw new \RuntimeException(
                'GdiplusStartup failed with status ' . $status
                . ' (彩色 emoji 渲染不可用)'
            );
        }
    }

    /**
     * 析构：释放激活上下文 + 现代字体 + 清理 manifest 临时文件。
     *
     * 注意：FFI 实例本身由 PHP GC 自动释放，此处只处理需要显式释放
     * 的系统资源（HACTCTX / HFONT / 临时文件）。
     */
    public function __destruct()
    {
        // 1. 释放激活上下文（DeactivateActCtx + ReleaseActCtx）
        if ($this->hActCtx !== null && $this->kernel32 !== null) {
            try {
                // 将 cookie 整数封装回 ULONG_PTR（用 INT_TO_PTR 联合体）
                $caster = $this->kernel32->new('INT_TO_PTR');
                $caster->i = $this->actCtxCookie;
                $this->kernel32->DeactivateActCtx(0, $caster->i);
            } catch (\Throwable $e) {
                // 析构期静默忽略
            }
            try {
                $this->kernel32->ReleaseActCtx($this->hActCtx);
            } catch (\Throwable $e) {
                // 析构期静默忽略
            }
            $this->hActCtx = null;
        }

        // 2. 释放现代字体 HFONT（Task 6）
        if ($this->hFont !== null && $this->gdi32 !== null) {
            try {
                $this->gdi32->DeleteObject($this->hFont);
            } catch (\Throwable $e) {
                // 忽略
            }
            $this->hFont = null;
        }

        // 3. 清理 manifest 临时文件
        if ($this->manifestFile !== '' && is_file($this->manifestFile)) {
            @unlink($this->manifestFile);
        }
        $this->manifestFile = '';
    }

    // ============================================================
    // 主题与视觉样式（Task 3/4）
    // ============================================================

    /**
     * 启用 ComCtl32 v6 视觉样式（运行时 manifest 激活）。
     *
     * 流程：
     *   1. 幂等检查：$this->hActCtx 已设置则直接返回。
     *   2. 将 manifest XML（UTF-8 with BOM）写入临时文件。
     *   3. 构造 ACTCTXW，dwFlags=ACTCTX_FLAG_SOURCE_IS_FILE，
     *      lpSource 指向 manifest 文件路径的 wchar_t[]。
     *   4. CreateActCtxW 创建句柄，失败（旧系统）静默返回。
     *   5. ActivateActCtx 激活，记录 hActCtx 和 cookie 供析构释放。
     *
     * 幂等性：可能被 setAppTheme() 和 App::platform() 多次调用。
     */
    public function enableVisualStyles(): void
    {
        // 幂等：已激活则直接返回
        if ($this->hActCtx !== null) {
            return;
        }

        // 写 manifest 临时文件（UTF-8 with BOM，CreateActCtxW 可识别）
        $this->manifestFile = sys_get_temp_dir()
            . '/php_ui_manifest_' . getmypid() . '.xml';
        $bom = "\xEF\xBB\xBF";
        $written = @file_put_contents($this->manifestFile, $bom . self::MANIFEST_XML);
        if ($written === false) {
            // 无法写临时文件，静默放弃（视觉样式不可用，不影响功能）
            $this->manifestFile = '';
            return;
        }

        // manifest 文件路径 → wchar_t[]（kernel32 作用域内，owned=false 防 GC）
        $pathWide = $this->wideBufIn($this->kernel32, $this->manifestFile);

        $actctx = $this->kernel32->new('ACTCTXW');
        $actctx->cbSize = \FFI::sizeof($actctx);
        $actctx->dwFlags = self::ACTCTX_FLAG_SOURCE_IS_FILE;
        $actctx->lpSource = \FFI::addr($pathWide[0]);
        $actctx->wProcessorArchitecture = 0;
        $actctx->wLangId = 0;
        $actctx->lpAssemblyDirectory = null;
        $actctx->lpResourceName = null;
        $actctx->lpApplicationName = null;
        $actctx->hModule = null;

        $hActCtx = $this->kernel32->CreateActCtxW(\FFI::addr($actctx));

        // INVALID_HANDLE_VALUE = -1（CreateActCtxW 失败时返回）
        if ($hActCtx === null || \FFI::isNull($hActCtx)) {
            // 旧系统不支持激活上下文，静默降级到经典样式
            return;
        }
        // 检查 INVALID_HANDLE_VALUE (-1)：CreateActCtxW 失败时返回
        $caster = $this->kernel32->new('INT_TO_PTR');
        $caster->p = $hActCtx;
        $hInt = (int) $caster->i;
        if ($hInt === -1) {
            // manifest 加载失败，静默降级到经典样式
            return;
        }

        // 激活上下文：cookie 存 $this->actCtxCookie（ULONG_PTR 整数）
        $cookie = $this->kernel32->new('ULONG_PTR');
        $ok = (int) $this->kernel32->ActivateActCtx($hActCtx, \FFI::addr($cookie));
        if ($ok === 0) {
            // 激活失败，释放句柄后静默返回
            try {
                $this->kernel32->ReleaseActCtx($hActCtx);
            } catch (\Throwable $e) {
                // 忽略
            }
            return;
        }

        // 将 ULONG_PTR CData 转为整数保存（用 cdata 属性读取标量值）
        $this->actCtxCookie = (int) $cookie->cdata;
        $this->hActCtx = $hActCtx;
    }

    /**
     * 设置应用主题（覆盖父类空实现）。
     *
     * - 记录 $this->currentTheme
     * - 非 CLASSIC 主题调用 enableVisualStyles()（幂等）启用 ComCtl32 v6
     * - 根据 $theme 调用 SetPreferredAppMode 设置深色/浅色
     *
     * @param string $theme Theme::SYSTEM/CLASSIC/DARK/LIGHT
     */
    public function setAppTheme(string $theme): void
    {
        $this->currentTheme = $theme;

        // CLASSIC 保持经典灰样式，不启用视觉样式
        if ($theme !== Theme::CLASSIC) {
            $this->enableVisualStyles();
        }

        // 根据 $theme 设置深色/浅色偏好
        switch ($theme) {
            case Theme::DARK:
                $this->setPreferredAppMode(2); // ForceDark
                break;
            case Theme::LIGHT:
                $this->setPreferredAppMode(3); // ForceLight
                break;
            case Theme::SYSTEM:
                $this->setPreferredAppMode(0); // Default（跟随系统）
                break;
            case Theme::CLASSIC:
            default:
                // CLASSIC 不调用 SetPreferredAppMode，保持系统默认
                break;
        }
    }

    /**
     * 调用 uxtheme SetPreferredAppMode 设置应用深色/浅色偏好。
     *
     * mode: 0=Default, 1=AllowDark, 2=ForceDark, 3=ForceLight。
     * uxthemeDark.dll 加载失败或函数不存在时静默返回（旧系统兼容）。
     */
    private function setPreferredAppMode(int $mode): void
    {
        if ($this->uxthemeDark === null) {
            return;
        }
        try {
            $this->uxthemeDark->SetPreferredAppMode($mode);
        } catch (\Throwable $e) {
            // 函数不存在或调用失败，静默跳过
        }
    }

    /**
     * 设置窗口标题栏深色模式（覆盖父类空实现）。
     *
     * 通过 DwmSetWindowAttribute(DWMWA_USE_IMMERSIVE_DARK_MODE=20) 让
     * 标题栏跟随深色模式。Win10 1809+ 用 attr=20，旧版本用 attr=19。
     *
     * @param int $hwnd 窗口句柄（int）。
     * @param bool $dark true=深色标题栏，false=浅色标题栏。
     */
    public function setWindowDarkMode(int $hwnd, bool $dark): void
    {
        if ($this->dwmapi === null) {
            return;
        }

        // HWND 跨作用域：int → dwmapi 作用域 void*（需 INT_TO_PTR，已在 DWMAPI_HEADER 声明）
        $hwndC = $this->intToPtrIn($this->dwmapi, $hwnd);

        // BOOL 值（int）：1=深色，0=浅色。用 int[1] 数组便于取地址。
        $value = $dark ? 1 : 0;

        // 优先尝试 attr=20（Win10 1809+），失败再尝试 attr=19（旧版本）
        foreach ([self::DWMWA_USE_IMMERSIVE_DARK_MODE, self::DWMWA_USE_IMMERSIVE_DARK_MODE_OLD] as $attr) {
            try {
                $boolVar = $this->dwmapi->new('int[1]');
                $boolVar[0] = $value;
                $hr = (int) $this->dwmapi->DwmSetWindowAttribute(
                    $hwndC,
                    $attr,
                    \FFI::addr($boolVar[0]),
                    4 // sizeof(int) = 4
                );
                // S_OK = 0 成功；非 0 失败则尝试下一个 attr
                if ($hr === 0) {
                    return;
                }
            } catch (\Throwable $e) {
                // 旧版本 attr 不支持，尝试下一个
                continue;
            }
        }
    }

    /**
     * 设置窗口圆角偏好（Win11 22000+）。
     *
     * 通过 DwmSetWindowAttribute(DWMWA_WINDOW_CORNER_PREFERENCE=33)：
     * - $round=true  → DWMWCP_ROUND=2（圆角）
     * - $round=false → DWMWCP_DONOTROUND=1（直角）
     *
     * 旧版 Windows（Win10 及以下）无此属性，FFI 调用会抛异常或返回错误码，
     * 静默降级到系统默认（直角），不影响功能。
     */
    private function setWindowCornerPreference(int $hwnd, bool $round): void
    {
        if ($this->dwmapi === null) {
            return;
        }
        try {
            $hwndC = $this->intToPtrIn($this->dwmapi, $hwnd);
            $pref = $this->dwmapi->new('int[1]');
            $pref[0] = $round ? 2 : 1; // DWMWCP_ROUND=2, DWMWCP_DONOTROUND=1
            $this->dwmapi->DwmSetWindowAttribute(
                $hwndC,
                self::DWMWA_WINDOW_CORNER_PREFERENCE,
                \FFI::addr($pref[0]),
                4
            );
        } catch (\Throwable $e) {
            // 旧版 Windows 无此属性，静默降级
        }
    }

    /**
     * 设置窗口 Mica 云母背景（Win11 22000+）。
     *
     * 通过 DwmSetWindowAttribute(DWMWA_SYSTEMBACKDROP_TYPE=38)：
     * - $enable=true  → DWMSBT_MAINWINDOW=2（Mica）
     * - $enable=false → DWMSBT_NONE=1（无系统背景）
     *
     * 注意：Mica 需要窗口客户区背景透明才能透出。配合
     * registerWindowClass 中非 CLASSIC 主题使用 NULL hbrBackground，
     * 让 DWM 的 Mica 透过客户区显示。旧版 Windows 无此属性静默降级。
     */
    private function setWindowMica(int $hwnd, bool $enable): void
    {
        if ($this->dwmapi === null) {
            return;
        }
        try {
            $hwndC = $this->intToPtrIn($this->dwmapi, $hwnd);
            $backdrop = $this->dwmapi->new('int[1]');
            // DWMSBT_AUTO=0, DWMSBT_NONE=1, DWMSBT_MAINWINDOW=2(Mica),
            // DWMSBT_TRANSIENTWINDOW=3(Acrylic), DWMSBT_TABBEDWINDOW=4
            $backdrop[0] = $enable ? 2 : 1;
            $this->dwmapi->DwmSetWindowAttribute(
                $hwndC,
                self::DWMWA_SYSTEMBACKDROP_TYPE,
                \FFI::addr($backdrop[0]),
                4
            );
        } catch (\Throwable $e) {
            // 旧版 Windows 无此属性，静默降级
        }
    }

    // ============================================================
    // C 头声明
    // ============================================================

    /**
     * user32.dll 头声明（Unicode W 系列）。
     *
     * 注意：WPARAM/LPARAM/LRESULT/LONG_PTR/UINT_PTR 用 8 字节类型
     * （long long / unsigned long long），匹配 x64 调用约定；
     * DWORD/UINT/LONG 用 4 字节类型。字符串参数用 LPCWSTR（const wchar_t*）。
     */
    private const USER32_HEADER = <<<C
typedef void* HWND;
typedef void* HMENU;
typedef void* HDC;
typedef void* HINSTANCE;
typedef void* HBRUSH;
typedef void* HICON;
typedef void* HCURSOR;
typedef void* HBITMAP;
typedef unsigned long DWORD;
typedef unsigned int UINT;
typedef int LONG;
typedef long long LONG_PTR;
typedef unsigned long long UINT_PTR;
typedef unsigned long long ULONG_PTR;
typedef unsigned long long WPARAM;
typedef long long LPARAM;
typedef long long LRESULT;
typedef unsigned short ATOM;
// wchar_t 在 Windows 上为 16 位无符号整数（UTF-16LE 码元）。
// PHP FFI 的 C 解析器不包含标准头，需显式定义。
typedef unsigned short wchar_t;
typedef const char* LPCSTR;
typedef char* LPSTR;
typedef const wchar_t* LPCWSTR;
typedef wchar_t* LPWSTR;
typedef int BOOL;

typedef union { long long i; void* p; } INT_TO_PTR;
typedef long long DWORD_PTR;
typedef long long LONG_PTR;
typedef unsigned long COLORREF;

typedef struct tagPOINT { LONG x; LONG y; } POINT;
typedef struct tagRECT { LONG left; LONG top; LONG right; LONG bottom; } RECT;

typedef unsigned char BYTE;

typedef struct tagSCROLLINFO {
    UINT cbSize;
    UINT fMask;
    int  nMin;
    int  nMax;
    UINT nPage;
    int  nPos;
    int  nTrackPos;
} SCROLLINFO;
typedef SCROLLINFO* LPSCROLLINFO;

// WM_NOTIFY 通用头部
typedef struct tagNMHDR {
    HWND     hwndFrom;
    UINT_PTR idFrom;
    UINT     code;   // 通知码（int 范围，TCN_SELCHANGE=-551 需用有符号比较）
} NMHDR;

// UpDown 控件通知结构（UDN_DELTAPOS）
typedef struct _NM_UPDOWN {
    NMHDR hdr;
    int   iPos;   // 当前位置
    int   iDelta; // 增量（+1 或 -1）
} NMUPDOWN;

// ListView 项结构（LVITEMW）
typedef struct tagLVITEMW {
    UINT   mask;
    int    iItem;
    int    iSubItem;
    UINT   state;
    UINT   stateMask;
    LPWSTR pszText;
    int    cchTextMax;
    int    iImage;
    LPARAM lParam;
} LVITEMW;

// ListView 列结构（LVCOLUMNW）
typedef struct tagLVCOLUMNW {
    UINT   mask;
    int    fmt;
    int    cx;
    LPWSTR pszText;
    int    cchTextMax;
    int    iSubItem;
    int    iImage;
    int    iOrder;
} LVCOLUMNW;

// ListView 命中测试结构（LVM_SUBITEMHITTEST 用）
typedef struct tagLVHITTESTINFO {
    POINT pt;
    UINT  flags;
    int   iItem;
    int   iSubItem;
    int   iGroup;
} LVHITTESTINFO;

// NMLISTVIEW：LVN_ITEMCHANGED 等通知结构
typedef struct tagNMLISTVIEW {
    NMHDR  hdr;
    int    iItem;
    int    iSubItem;
    UINT   uNewState;
    UINT   uOldState;
    UINT   uChanged;
    POINT  ptAction;
    LPARAM lParam;
} NMLISTVIEW;

// NMLVDISPINFO：LVN_GETDISPINFO 通知结构（包含 LVITEMW）
typedef struct tagNMLVDISPINFO {
    NMHDR   hdr;
    LVITEMW item;
} NMLVDISPINFO;

// NMLVCUSTOMDRAW：NM_CUSTOMDRAW 自绘结构
typedef struct tagNMLVCUSTOMDRAW {
    NMHDR   hdr;
    DWORD   dwDrawStage;
    HDC     hdc;
    RECT    rc;
    DWORD_PTR dwItemSpec;
    UINT    uItemState;
    LONG_PTR lItemlParam;
    COLORREF clrText;
    COLORREF clrTextBk;
    int     iSubItem;
    DWORD   dwItemType;
    COLORREF clrFace;
    int     iIconEffect;
    int     iIconPhase;
    int     iPartId;
    int     iStateId;
    RECT    rcText;
    UINT    uAlign;
} NMLVCUSTOMDRAW;

// Tab 控件项结构（TCITEMW）
typedef struct tagTCITEMW {
    UINT   mask;
    DWORD  dwState;
    DWORD  dwStateMask;
    LPWSTR pszText;
    int    cchTextMax;
    int    iImage;
    LPARAM lParam;
} TCITEMW;

typedef struct tagPAINTSTRUCT {
    HDC  hdc;
    BOOL fErase;
    RECT rcPaint;
    BOOL fRestore;
    BOOL fIncUpdate;
    BYTE rgbReserved[32];
} PAINTSTRUCT;

typedef struct tagMSG {
    HWND    hwnd;
    UINT    message;
    WPARAM  wParam;
    LPARAM  lParam;
    DWORD   time;
    LONG    pt_x;
    LONG    pt_y;
} MSG;

typedef struct tagWNDCLASSEXW {
    UINT        cbSize;
    UINT        style;
    LRESULT (*lpfnWndProc)(HWND, UINT, WPARAM, LPARAM);
    int         cbClsExtra;
    int         cbWndExtra;
    HINSTANCE   hInstance;
    HICON       hIcon;
    HCURSOR     hCursor;
    HBRUSH      hbrBackground;
    LPCWSTR     lpszMenuName;
    LPCWSTR     lpszClassName;
    HICON       hIconSm;
} WNDCLASSEXW;

ATOM    RegisterClassExW(const WNDCLASSEXW *lpWndClass);
HWND    CreateWindowExW(DWORD dwExStyle, LPCWSTR lpClassName, LPCWSTR lpWindowName,
                        DWORD dwStyle, int X, int Y, int nWidth, int nHeight,
                        HWND hWndParent, HMENU hMenu, HINSTANCE hInstance, void* lpParam);
LRESULT DefWindowProcW(HWND hWnd, UINT Msg, WPARAM wParam, LPARAM lParam);
LRESULT DispatchMessageW(const MSG *lpmsg);
int     PeekMessageW(MSG *lpMsg, HWND hWnd, UINT wMsgFilterMin, UINT wMsgFilterMax, UINT wRemoveMsg);
int     TranslateMessage(const MSG *lpMsg);
int     PostQuitMessage(int nExitCode);
BOOL    PostMessageW(HWND hWnd, UINT Msg, WPARAM wParam, LPARAM lParam);
LRESULT SendMessageW(HWND hWnd, UINT Msg, WPARAM wParam, LPARAM lParam);
BOOL    DestroyWindow(HWND hWnd);
int     ShowWindow(HWND hWnd, int nCmdShow);
BOOL    UpdateWindow(HWND hWnd);
BOOL    SetWindowPos(HWND hWnd, HWND hWndInsertAfter, int X, int Y, int cx, int cy, UINT uFlags);
BOOL    SetWindowTextW(HWND hWnd, LPCWSTR lpString);
int     GetWindowTextW(HWND hWnd, LPWSTR lpString, int nMaxCount);
int     GetWindowTextLengthW(HWND hWnd);
BOOL    GetWindowRect(HWND hWnd, RECT *lpRect);
BOOL    GetClientRect(HWND hWnd, RECT *lpRect);
LONG_PTR GetWindowLongPtrW(HWND hWnd, int nIndex);
LONG_PTR SetWindowLongPtrW(HWND hWnd, int nIndex, LONG_PTR dwNewLong);
HWND    GetForegroundWindow(void);
HWND    SetParent(HWND hWndChild, HWND hWndNewParent);
HWND    GetParent(HWND hWnd);
HWND    GetWindow(HWND hWnd, UINT uCmd);
BOOL    SetMenu(HWND hWnd, HMENU hMenu);
int     GetSystemMetrics(int nIndex);
HCURSOR LoadCursorW(HINSTANCE hInstance, UINT_PTR lpCursorName);
HICON   LoadIconW(HINSTANCE hInstance, UINT_PTR lpIconName);
HICON   LoadImageW(HINSTANCE hInst, LPCWSTR name, UINT type, int cx, int cy, UINT fuLoad);
HICON   CopyIcon(HICON hIcon);
BOOL    DestroyIcon(HICON hIcon);
BOOL    SetForegroundWindow(HWND hWnd);
BOOL    InvalidateRect(HWND hWnd, const RECT *lpRect, BOOL bErase);
BOOL    OpenClipboard(HWND hWndNewOwner);
BOOL    EmptyClipboard(void);
void*   SetClipboardData(UINT uFormat, void* hMem);
BOOL    CloseClipboard(void);
void*   GetClipboardData(UINT uFormat);
int     MessageBoxW(HWND hWnd, LPCWSTR lpText, LPCWSTR lpCaption, UINT uType);
BOOL    EnableWindow(HWND hWnd, BOOL bEnable);
HMENU   CreateMenu(void);
HMENU   CreatePopupMenu(void);
BOOL    DestroyMenu(HMENU hMenu);
BOOL    AppendMenuW(HMENU hMenu, UINT uFlags, UINT_PTR uIDNewItem, LPCWSTR lpNewItem);
BOOL    InsertMenuW(HMENU hMenu, UINT uPosition, UINT uFlags, UINT_PTR uIDNewItem, LPCWSTR lpNewItem);
BOOL    CheckMenuItem(HMENU hMenu, UINT uIDCheckItem, UINT uCheck);
BOOL    EnableMenuItem(HMENU hMenu, UINT uIDEnableItem, UINT uEnable);
BOOL    DrawMenuBar(HWND hWnd);
/* 弹出菜单（TrackPopupMenu，用于托盘右键菜单） */
BOOL    TrackPopupMenu(HMENU hMenu, UINT uFlags, int x, int y, int nReserved, HWND hWnd, const RECT *prcRect);

/* 菜单项信息（用于设置菜单项位图图标） */
typedef struct tagMENUITEMINFOW {
    UINT    cbSize;
    UINT    fMask;
    UINT    fType;
    UINT    fState;
    UINT    wID;
    HMENU   hSubMenu;
    HBITMAP hbmpChecked;
    HBITMAP hbmpUnchecked;
    ULONG_PTR dwItemData;
    LPWSTR  dwTypeData;
    UINT    cch;
    HBITMAP hbmpItem;
} MENUITEMINFOW;

BOOL SetMenuItemInfoW(HMENU hmenu, UINT item, BOOL fByPosition, const MENUITEMINFOW* lpmii);
BOOL GetMenuItemInfoW(HMENU hmenu, UINT item, BOOL fByPosition, MENUITEMINFOW* lpmii);
HDC     BeginPaint(HWND hwnd, PAINTSTRUCT* lpPaint);
BOOL    EndPaint(HWND hwnd, PAINTSTRUCT* lpPaint);
int     SetScrollInfo(HWND hwnd, int nBar, LPSCROLLINFO lpsi, BOOL redraw);
BOOL    GetScrollInfo(HWND hwnd, int nBar, LPSCROLLINFO lpsi);

/* 鼠标跟踪：用于 Area WM_MOUSELEAVE 通知 */
typedef struct tagTRACKMOUSEEVENT {
    DWORD cbSize;
    DWORD dwFlags;
    HWND  hwndTrack;
    DWORD dwHoverTime;
} TRACKMOUSEEVENT;
BOOL    TrackMouseEvent(TRACKMOUSEEVENT* lpEventTrack);

/* 焦点：Area 在鼠标点击时调用 SetFocus 获得键盘焦点 */
HWND    SetFocus(HWND hWnd);
HWND    GetFocus(void);

/* 自绘辅助：用于 Table 单元格自绘 checkbox/progress/color/button */
int     FillRect(HDC hDC, const RECT *lprc, HBRUSH hbr);
BOOL    DrawEdge(HDC hdc, RECT *qrc, int edge, int grfFlags);
BOOL    DrawFrameControl(HDC hdc, RECT *lprc, int uType, int uState);
int     DrawTextW(HDC hdc, LPCWSTR lpchText, int cchText, RECT *lprc, UINT format);
BOOL    InflateRect(RECT *lprc, int dx, int dy);
BOOL    PtInRect(const RECT *lprc, POINT pt);
BOOL    ScreenToClient(HWND hWnd, POINT *lpPoint);
DWORD   GetMessagePos(void);
HBRUSH  GetSysColorBrush(int nIndex);

/* SYSTEMTIME：DateTimePicker 日期时间值 */
typedef struct _SYSTEMTIME {
    unsigned short wYear;
    unsigned short wMonth;
    unsigned short wDayOfWeek;
    unsigned short wDay;
    unsigned short wHour;
    unsigned short wMinute;
    unsigned short wSecond;
    unsigned short wMilliseconds;
} SYSTEMTIME;
C;

    /**
     * gdi32.dll 头声明。
     */
    private const GDI32_HEADER = <<<C
typedef int BOOL;
typedef unsigned long DWORD;
typedef unsigned int UINT;
typedef int LONG;
typedef void* HDC;
typedef void* HGDIOBJ;
typedef void* HPEN;
typedef void* HBRUSH;
typedef void* HFONT;
typedef void* HBITMAP;
typedef unsigned short wchar_t;
typedef const char* LPCSTR;
typedef const wchar_t* LPCWSTR;

typedef union { long long i; void* p; } INT_TO_PTR;

HPEN    CreatePen(int fnPenStyle, int nWidth, DWORD crColor);
HBRUSH  CreateSolidBrush(DWORD crColor);
HFONT   CreateFontW(int nHeight, int nWidth, int nEscapement, int nOrientation,
                    int fnWeight, DWORD fdwItalic, DWORD fdwUnderline,
                    DWORD fdwStrikeOut, DWORD fdwCharSet,
                    DWORD fdwOutputPrecision, DWORD fdwClipPrecision,
                    DWORD fdwQuality, DWORD fdwPitchAndFamily, LPCWSTR lpszFace);
HGDIOBJ SelectObject(HDC hdc, HGDIOBJ hgdiobj);
BOOL    DeleteObject(HGDIOBJ hObject);
BOOL    MoveToEx(HDC hdc, int X, int Y, void* lpPoint);
BOOL    LineTo(HDC hdc, int nXEnd, int nYEnd);
BOOL    Rectangle(HDC hdc, int nLeftRect, int nTopRect, int nRightRect, int nBottomRect);
BOOL    Ellipse(HDC hdc, int nLeftRect, int nTopRect, int nRightRect, int nBottomRect);
BOOL    TextOutW(HDC hdc, int nXStart, int nYStart, LPCWSTR lpString, int cbString);
DWORD   SetTextColor(HDC hdc, DWORD crColor);
int     SetBkMode(HDC hdc, int iBkMode);

/* ---- 双缓冲（消除 Area 自定义绘图闪屏） ---- */
HDC     CreateCompatibleDC(HDC hdc);
HBITMAP CreateCompatibleBitmap(HDC hdc, int nWidth, int nHeight);
BOOL    BitBlt(HDC hdcDest, int nXDest, int nYDest, int nWidth, int nHeight,
               HDC hdcSrc, int nXSrc, int nYSrc, DWORD dwRop);
BOOL    DeleteDC(HDC hdc);
C;

    /**
     * kernel32.dll 头声明。
     */
    private const KERNEL32_HEADER = <<<C
typedef void* HMODULE;
typedef const char* LPCSTR;
typedef unsigned long DWORD;
typedef unsigned int UINT;
typedef int BOOL;
typedef unsigned short wchar_t;
typedef const wchar_t* LPCWSTR;
typedef unsigned long long ULONG_PTR;
typedef void* HANDLE;
typedef void* LPVOID;
typedef wchar_t* LPWSTR;

/* int↔指针 联合体（与 user32/gdi32 作用域内同名，但本作用域专用） */
typedef union { long long i; void* p; } INT_TO_PTR;

void*   GetProcAddress(HMODULE hModule, const char* lpProcName);
void    ExitProcess(UINT uExitCode);
DWORD   GetLastError(void);
void    SetLastError(DWORD dwErrCode);

void*   GlobalAlloc(UINT uFlags, DWORD dwBytes);
char*   GlobalLock(void* hMem);
BOOL    GlobalUnlock(void* hMem);
void*   GlobalFree(void* hMem);

/* ---- 激活上下文（Task 3：运行时 manifest 视觉样式激活） ---- */
typedef struct _ACTCTXW {
    DWORD       cbSize;
    DWORD       dwFlags;
    LPCWSTR     lpSource;
    unsigned short wProcessorArchitecture;
    unsigned short wLangId;
    LPWSTR      lpAssemblyDirectory;
    LPWSTR      lpResourceName;
    LPWSTR      lpApplicationName;
    HMODULE     hModule;
} ACTCTXW, *PACTCTXW;

HANDLE  CreateActCtxW(PACTCTXW pActCtx);
BOOL    ActivateActCtx(HANDLE hActCtx, ULONG_PTR *lpCookie);
BOOL    DeactivateActCtx(DWORD dwFlags, ULONG_PTR ulCookie);
void    ReleaseActCtx(HANDLE hActCtx);
C;

    /**
     * comctl32.dll 头声明。
     */
    private const COMCTL32_HEADER = <<<C
typedef unsigned long DWORD;
typedef unsigned int UINT;
typedef int BOOL;
typedef long long LPARAM;

typedef union { long long i; void* p; } INT_TO_PTR;

typedef struct tagINITCOMMONCONTROLSEX {
    DWORD dwSize;
    DWORD dwICC;
} INITCOMMONCONTROLSEX;

void InitCommonControlsEx(const INITCOMMONCONTROLSEX *piccs);
void InitCommonControls(void);

/* ImageList（图像列表，供 ListView/Tab 图像列使用） */
typedef void* HIMAGELIST;
typedef void* HBITMAP;

// 创建图像列表：cx/cy=图像尺寸，flags=0x00000020(ILC_COLOR32)，初始/增长容量
HIMAGELIST ImageList_Create(int cx, int cy, UINT flags, int cInitial, int cGrow);
// 添加 HBITMAP 到图像列表，返回索引；-1 失败。hbmMask 可为 NULL
int ImageList_Add(HIMAGELIST himl, HBITMAP hbmImage, HBITMAP hbmMask);
// 销毁图像列表
BOOL ImageList_Destroy(HIMAGELIST himl);
C;

    /**
     * comdlg32.dll 头声明（Unicode W 系列，批次 5 完整结构体）。
     *
     * OPENFILENAMEW / CHOOSECOLORW / LOGFONTW / CHOOSEFONTW 结构体在
     * x64 上的大小分别为 152 / 72 / 92 / 100 字节，由 FFI 自动对齐，
     * 通过 \FFI::sizeof() 获取实际大小填入 lStructSize。
     */
    private const COMMDLG32_HEADER = <<<C
typedef void* HWND;
typedef void* HINSTANCE;
typedef void* HDC;
typedef unsigned long DWORD;
typedef unsigned int UINT;
typedef int BOOL;
typedef int INT;
typedef int LONG;
typedef unsigned short WORD;
typedef unsigned char BYTE;
typedef long long LPARAM;
typedef unsigned short wchar_t;
typedef const wchar_t* LPCWSTR;
typedef wchar_t* LPWSTR;
typedef DWORD* LPDWORD;
typedef DWORD COLORREF;

typedef union { long long i; void* p; } INT_TO_PTR;

typedef struct tagOFNW {
    DWORD        lStructSize;
    HWND         hwndOwner;
    HINSTANCE    hInstance;
    LPCWSTR      lpstrFilter;
    LPWSTR       lpstrCustomFilter;
    DWORD        nMaxCustFilter;
    DWORD        nFilterIndex;
    LPWSTR       lpstrFile;
    DWORD        nMaxFile;
    LPWSTR       lpstrFileTitle;
    DWORD        nMaxFileTitle;
    LPCWSTR      lpstrInitialDir;
    LPCWSTR      lpstrTitle;
    DWORD        Flags;
    WORD         nFileOffset;
    WORD         nFileExtension;
    LPCWSTR      lpstrDefExt;
    LPARAM       lCustData;
    void*        lpfnHook;
    LPCWSTR      lpTemplateName;
    void*        pvReserved;
    DWORD        dwReserved;
    DWORD        FlagsEx;
} OPENFILENAMEW;

typedef struct {
    DWORD        lStructSize;
    HWND         hwndOwner;
    HWND         hInstance;
    DWORD        rgbResult;
    LPDWORD      lpCustColors;
    DWORD        Flags;
    LPARAM       lCustData;
    void*        lpfnHook;
    LPCWSTR      lpTemplateName;
} CHOOSECOLORW;

typedef struct tagLOGFONTW {
    LONG      lfHeight;
    LONG      lfWidth;
    LONG      lfEscapement;
    LONG      lfOrientation;
    LONG      lfWeight;
    BYTE      lfItalic;
    BYTE      lfUnderline;
    BYTE      lfStrikeOut;
    BYTE      lfCharSet;
    BYTE      lfOutPrecision;
    BYTE      lfClipPrecision;
    BYTE      lfQuality;
    BYTE      lfPitchAndFamily;
    wchar_t   lfFaceName[32];
} LOGFONTW;

typedef struct {
    DWORD       lStructSize;
    HWND        hwndOwner;
    HDC         hDC;
    LOGFONTW*   lpLogFont;
    INT         iPointSize;
    DWORD       Flags;
    COLORREF    rgbColors;
    LPARAM      lCustData;
    void*       lpfnHook;
    LPCWSTR     lpTemplateName;
    HINSTANCE   hInstance;
    LPWSTR      lpszStyle;
    WORD        nFontType;
    WORD        __alignment;
    INT         nSizeMin;
    INT         nSizeMax;
} CHOOSEFONTW;

BOOL GetOpenFileNameW(OPENFILENAMEW *lpofn);
BOOL GetSaveFileNameW(OPENFILENAMEW *lpofn);
BOOL ChooseColorW(CHOOSECOLORW *lpcc);
BOOL ChooseFontW(CHOOSEFONTW *lpcf);
C;

    /**
     * shell32.dll 头声明（Unicode W 系列，批次 5 完整结构体）。
     */
    private const SHELL32_HEADER = <<<C
typedef void* HWND;
typedef void* LPCITEMIDLIST;
typedef void* HICON;
typedef unsigned int UINT;
typedef int BOOL;
typedef unsigned short wchar_t;
typedef long long LPARAM;
typedef unsigned long DWORD;
typedef const wchar_t* LPCWSTR;
typedef wchar_t* LPWSTR;
typedef char* LPSTR;

typedef union { long long i; void* p; } INT_TO_PTR;

typedef struct _browseinfoW {
    HWND          hwndOwner;
    LPCITEMIDLIST pidlRoot;
    LPWSTR        pszDisplayName;
    LPCWSTR       lpszTitle;
    UINT          ulFlags;
    void*         lpfn;
    LPARAM        lParam;
    int           iImage;
} BROWSEINFOW;

void* SHBrowseForFolderW(BROWSEINFOW *lpbi);
BOOL SHGetPathFromIDListW(void* pidl, wchar_t* pszPath);

/* 系统托盘：Shell_NotifyIconW（使用 v3 尺寸，不含 GUID/balloonIcon 字段，
   通过 cbSize = sizeof(到 szInfoTitle+dwInfoFlags) 兼容 Windows 2000+） */
typedef struct _NOTIFYICONDATAW {
    DWORD cbSize;
    HWND  hWnd;
    UINT  uID;
    UINT  uFlags;
    UINT  uCallbackMessage;
    HICON hIcon;
    wchar_t szTip[128];
    DWORD dwState;
    DWORD dwStateMask;
    wchar_t szInfo[256];
    union {
        UINT uTimeout;
        UINT uVersion;
    } DUMMYUNIONNAME;
    wchar_t szInfoTitle[64];
    DWORD dwInfoFlags;
} NOTIFYICONDATAW;

BOOL Shell_NotifyIconW(DWORD dwMessage, NOTIFYICONDATAW *lpdata);
C;

    /**
     * ole32.dll 头声明（仅 CoTaskMemFree 用于释放 SHBrowseForFolderW 返回的 PIDL）。
     */
    private const OLE32_HEADER = <<<C
typedef void* LPVOID;

typedef union { long long i; void* p; } INT_TO_PTR;

void CoTaskMemFree(LPVOID pv);
C;

    /**
     * gdiplus.dll 头声明（彩色 emoji 渲染）。
     *
     * GDI+ 的 GdipDrawString 支持 Segoe UI Emoji 彩色字体，是彩色 emoji
     * 渲染的关键。所有 GpGraphics/GpFont/GpBrush/GpFontFamily 对象用完
     * 必须 Delete，否则内存泄漏。
     *
     * 注意：wchar_t 需在 gdiplus 作用域内显式 typedef（与 user32/gdi32
     * 作用域不互通）。HDC 跨作用域经 int 转换。
     */
    private const GDIPLUS_HEADER = <<<C
typedef int BOOL;
typedef unsigned int UINT;
typedef unsigned int UINT32;
typedef unsigned long DWORD;
typedef unsigned long ULONG_PTR;
typedef unsigned short wchar_t;
typedef void* HDC;
typedef void* GpGraphics;
typedef void* GpFont;
typedef void* GpFontFamily;
typedef void* GpBrush;
typedef void* GpSolidFill;
typedef void* GpStringFormat;
typedef int Status;

typedef union { long long i; void* p; } INT_TO_PTR;

typedef struct {
    UINT32 GdiplusVersion;
    void*  DebugEventCallback;
    BOOL   SuppressBackgroundThread;
    BOOL   SuppressExternalCodecs;
} GdiplusStartupInput;

typedef struct {
    float X;
    float Y;
    float Width;
    float Height;
} RectF;

Status  GdiplusStartup(ULONG_PTR* token, GdiplusStartupInput* input, void* output);
void    GdiplusShutdown(ULONG_PTR token);

Status  GdipCreateFromHDC(HDC hdc, GpGraphics** graphics);
Status  GdipDeleteGraphics(GpGraphics* graphics);

Status  GdipCreateFontFamilyFromName(const wchar_t* name, void* fontCollection, GpFontFamily** fontFamily);
Status  GdipDeleteFontFamily(GpFontFamily* fontFamily);
Status  GdipCreateFont(GpFontFamily* family, float emSize, int style, int unit, GpFont** font);
Status  GdipDeleteFont(GpFont* font);
Status  GdipCreateSolidFill(int argb, GpSolidFill** brush);
Status  GdipDeleteBrush(GpBrush* brush);
Status  GdipDrawString(GpGraphics* graphics, const wchar_t* string, int length, GpFont* font, RectF* layoutRect, GpStringFormat* format, GpBrush* brush);
Status  GdipMeasureString(GpGraphics* graphics, const wchar_t* string, int length, GpFont* font, RectF* layoutRect, GpStringFormat* format, RectF* boundingBox, int* codepointsFitted, int* linesFilled);

/* ---- 路径系统 ---- */
typedef void* GpPath;
typedef void* GpPen;
typedef void* GpLineGradient;
typedef void* GpMatrix;

typedef struct { float X; float Y; } PointF;

Status  GdipCreatePath(int fillMode, GpPath** path);
Status  GdipDeletePath(GpPath* path);
Status  GdipStartPathFigure(GpPath* path);
Status  GdipAddPathLine(GpPath* path, float x1, float y1, float x2, float y2);
Status  GdipAddPathBezier(GpPath* path, float x1, float y1, float x2, float y2, float x3, float y3, float x4, float y4);
Status  GdipAddPathArc(GpPath* path, float x, float y, float width, float height, float startAngle, float sweepAngle);
Status  GdipClosePathFigure(GpPath* path);

/* ---- GDI+ 画笔 ---- */
Status  GdipCreatePen1(int argb, float width, int unit, GpPen** pen);
Status  GdipDeletePen(GpPen* pen);

/* ---- GDI+ 填充/描边/曲线/圆弧 ---- */
Status  GdipDrawPath(GpGraphics* graphics, GpPen* pen, GpPath* path);
Status  GdipFillPath(GpGraphics* graphics, GpBrush* brush, GpPath* path);
Status  GdipFillRectangle(GpGraphics* graphics, GpBrush* brush, float x, float y, float width, float height);
Status  GdipDrawRectangle(GpGraphics* graphics, GpPen* pen, float x, float y, float width, float height);
Status  GdipFillEllipse(GpGraphics* graphics, GpBrush* brush, float x, float y, float width, float height);
Status  GdipDrawEllipse(GpGraphics* graphics, GpPen* pen, float x, float y, float width, float height);
Status  GdipDrawLine(GpGraphics* graphics, GpPen* pen, float x1, float y1, float x2, float y2);
Status  GdipDrawBezier(GpGraphics* graphics, GpPen* pen, float x1, float y1, float x2, float y2, float x3, float y3, float x4, float y4);
Status  GdipDrawArc(GpGraphics* graphics, GpPen* pen, float x, float y, float width, float height, float startAngle, float sweepAngle);

/* ---- 变换矩阵 ---- */
Status  GdipTranslateWorldTransform(GpGraphics* graphics, float dx, float dy, int order);
Status  GdipScaleWorldTransform(GpGraphics* graphics, float sx, float sy, int order);
Status  GdipRotateWorldTransform(GpGraphics* graphics, float angle, int order);
Status  GdipResetWorldTransform(GpGraphics* graphics);
Status  GdipSaveGraphics(GpGraphics* graphics, unsigned int* state);
Status  GdipRestoreGraphics(GpGraphics* graphics, unsigned int state);

/* ---- 裁剪 ---- */
Status  GdipSetClipPath(GpGraphics* graphics, GpPath* path, int combineMode);
Status  GdipSetClipRect(GpGraphics* graphics, float x, float y, float width, float height, int combineMode);
Status  GdipResetClip(GpGraphics* graphics);

/* ---- 线性渐变画笔 ---- */
Status  GdipCreateLineBrush(PointF* point1, PointF* point2, int color1, int color2, int wrapMode, GpLineGradient** lineGradient);
Status  GdipSetLinePresetBlend(GpLineGradient* brush, int* blendColors, float* blendPositions, int count);
Status  GdipSetLineGammaCorrection(GpLineGradient* brush, BOOL useGammaCorrection);

/* ---- 图像 / 位图 ---- */
typedef void* GpImage;
typedef void* GpBitmap;

/* 从文件加载图像（支持 BMP/PNG/JPEG/GIF/TIFF，wchar_t 路径） */
Status  GdipLoadImageFromFile(const wchar_t* filename, GpImage** image);
Status  GdipDisposeImage(GpImage* image);
Status  GdipGetImageWidth(GpImage* image, unsigned int* width);
Status  GdipGetImageHeight(GpImage* image, unsigned int* height);

/* 将 GpBitmap 转换为 HBITMAP（带 alpha 预乘背景色），供 ImageList 使用。
   hbm 接收结果，调用方需 DeleteObject 释放。argbBackground 为背景色（0xAARRGGBB）。 */
Status  GdipCreateHBITMAPFromBitmap(GpBitmap* bitmap, void** hbm, int argbBackground);

/* 将 GpBitmap 转换为 HICON（保持 alpha 通道），供窗口/托盘图标使用。
   hicon 接收结果，调用方需 DestroyIcon 释放。 */
Status  GdipCreateHICONFromBitmap(GpBitmap* bitmap, void** hicon);

/* 获取 GpImage 的类型（ImageType：0=Unknown/1=Bitmap/2=Metafile/3=...） */
Status  GdipGetImageType(GpImage* image, int* type);

/* 在 (x, y) 处按图像原始尺寸绘制 */
Status  GdipDrawImage(GpGraphics* graphics, GpImage* image, float x, float y);

/* 在 (x, y) 处绘制到指定尺寸 (w, h)（缩放） */
Status  GdipDrawImageRect(GpGraphics* graphics, GpImage* image, float x, float y, float width, float height);

/* 在目标矩形 (dx,dy,dw,dh) 绘制源矩形 (sx,sy,sw,sh) 的图像内容（裁剪 + 缩放） */
Status  GdipDrawImageRectRect(GpGraphics* graphics, GpImage* image,
    float dstx, float dsty, float dstwidth, float dstheight,
    float srcx, float srcy, float srcwidth, float srcheight,
    int srcUnit, void* imageAttributes, void* callback, void* callbackData);
C;

    /**
     * uxtheme.dll 头声明（Task 3：视觉样式 API）。
     *
     * 视觉样式 API（OpenThemeData/DrawThemeBackground/...）让 Table 自绘单元格
     * （checkbox/button/progress）按当前主题渲染，呈现 Win11 现代外观。
     * SetWindowTheme 对 ListView/TreeView 应用 "Explorer" 子样式（资源管理器
     * 风格的浅色高亮、Windows 11 圆角行选择）。
     *
     * 注意：本 cdef 是独立作用域，需在内部重新声明用到的所有类型
     * （HWND/HDC/LPCWSTR/RECT/DWORD/wchar_t 等），与 user32 作用域不互通。
     * HTHEME 是 HANDLE，用 void* 表示；NULL 通过 PHP null 传入。
     *
     * 历史问题：原 UXTHEME_HEADER 同时包含 SetPreferredAppMode（ordinal 135，
     * 未文档化导出，PHP FFI 按名称解析失败），导致整个 cdef 失败，视觉样式
     * API 全部不可用。现拆分为 UXTHEME_HEADER（标准 API）+ UXTHEME_DARK_HEADER
     * （SetPreferredAppMode），独立加载。
     */
    private const UXTHEME_HEADER = <<<C
typedef void* HWND;
typedef void* HDC;
typedef void* HTHEME;
typedef unsigned long DWORD;
typedef long HRESULT;
typedef int BOOL;
typedef int LONG;
typedef unsigned short wchar_t;
typedef const wchar_t* LPCWSTR;
typedef struct tagRECT { LONG left; LONG top; LONG right; LONG bottom; } RECT;

/* int↔指针 联合体（本作用域专用，供 intToPtrIn 转换 HWND/HDC） */
typedef union { long long i; void* p; } INT_TO_PTR;

HRESULT SetWindowTheme(HWND hwnd, LPCWSTR pszSubAppName, LPCWSTR pszSubIdList);
HTHEME  OpenThemeData(HWND hwnd, LPCWSTR pszClassList);
HRESULT CloseThemeData(HTHEME hTheme);
HRESULT DrawThemeBackground(HTHEME hTheme, HDC hdc, int iPartId, int iStateId, const RECT* pRect, const RECT* pClipRect);
HRESULT DrawThemeText(HTHEME hTheme, HDC hdc, int iPartId, int iStateId, LPCWSTR pszText, int iCharCount, DWORD dwTextFlags, DWORD dwTextFlags2, const RECT* pRect);
BOOL    IsThemeActive(void);
BOOL    IsAppThemed(void);
C;

    /**
     * uxtheme.dll 头声明（Task 4：仅 SetPreferredAppMode 深色模式偏好）。
     *
     * SetPreferredAppMode 是未文档化导出（ordinal 135），Win10 1809+ 才有。
     * mode: 0=Default, 1=AllowDark, 2=ForceDark, 3=ForceLight。
     * 旧系统 cdef 加载此声明会失败（按名称解析不到），loadOptionalFfi 返回 null，
     * 此时不影响视觉样式 API（UXTHEME_HEADER 独立加载）。
     */
    private const UXTHEME_DARK_HEADER = <<<C
int SetPreferredAppMode(int mode);
C;

    /**
     * dwmapi.dll 头声明（Task 4：标题栏深色模式）。
     *
     * DwmSetWindowAttribute 设置 DWMWA_USE_IMMERSIVE_DARK_MODE=20
     * （Win10 1809+，旧版本用 19）使标题栏跟随深色模式。
     */
    private const DWMAPI_HEADER = <<<C
typedef void* HWND;
typedef unsigned long DWORD;
typedef long HRESULT;
typedef const void* LPCVOID;
typedef int BOOL;

/* int↔指针 联合体（本作用域专用，供 intToPtrIn 转换 HWND） */
typedef union { long long i; void* p; } INT_TO_PTR;

HRESULT DwmSetWindowAttribute(HWND hwnd, DWORD attr, LPCVOID pvAttribute, DWORD cbAttribute);
C;

    // ============================================================
    // int↔指针 辅助（用 INT_TO_PTR 联合体，禁止跨作用域 cast）
    // ============================================================

    private function ptrToInt(\FFI\CData $ptr): int
    {
        $c = $this->user32->new('INT_TO_PTR');
        $c->p = $ptr;
        return (int) $c->i;
    }

    private function hwndInt(\FFI\CData $hwnd): int
    {
        $c = $this->user32->new('INT_TO_PTR');
        $c->p = $hwnd;
        return (int) $c->i;
    }

    private function intToHwnd(int $i): \FFI\CData
    {
        $c = $this->user32->new('INT_TO_PTR');
        $c->i = $i;
        return $c->p;
    }

    private function hmenuToInt(\FFI\CData $hmenu): int
    {
        $c = $this->user32->new('INT_TO_PTR');
        $c->p = $hmenu;
        return (int) $c->i;
    }

    /** gdi32 指针 → int（gdi32 作用域内用 INT_TO_PTR） */
    private function gdiPtrToInt(\FFI\CData $ptr): int
    {
        $c = $this->gdi32->new('INT_TO_PTR');
        $c->p = $ptr;
        return (int) $c->i;
    }

    /**
     * 在指定 FFI 作用域将 int 转为 void* 指针（用于跨作用域结构体字段赋值）。
     *
     * 不同 FFI 作用域的 void* 类型不互通，因此在 comdlg32/shell32/ole32
     * 作用域内设置 HWND 字段时需用对应作用域的 INT_TO_PTR 转换。
     *
     * 公开访问：DrawContext 需将 HDC int 转为 gdi32/gdiplus 作用域指针。
     */
    public function intToPtrIn(\FFI $ffi, int $i): \FFI\CData
    {
        $c = $ffi->new('INT_TO_PTR');
        $c->i = $i;
        return $c->p;
    }

    /**
     * 在指定 FFI 作用域将 void* 指针转为 int（用于跨作用域指针传递）。
     */
    public function ptrToIntIn(\FFI $ffi, \FFI\CData $ptr): int
    {
        $c = $ffi->new('INT_TO_PTR');
        $c->p = $ptr;
        return (int) $c->i;
    }

    // ============================================================
    // 公开 FFI 作用域访问（供 DrawContext 使用）
    // ============================================================

    public function getUser32(): \FFI
    {
        return $this->user32;
    }

    public function getGdi32(): \FFI
    {
        return $this->gdi32;
    }

    public function getGdiplus(): \FFI
    {
        return $this->gdiplus;
    }

    public function getComctl32(): \FFI
    {
        return $this->comctl32;
    }

    // ============================================================
    // GDI+ 图像 → HBITMAP 转换（供 Button/Label/Tab/Table/MenuItem 使用）
    // ============================================================

    /**
     * 将 GDI+ GpImage 转换为 HBITMAP（int 形式返回，跨作用域通用）。
     *
     * 内部流程：
     *   1. 在 gdiplus 作用域调用 GdipCreateHBITMAPFromBitmap 生成 HBITMAP
     *      （alpha 预乘到白色背景，避免透明区域变黑）。
     *   2. 将 gdiplus 作用域的 void* 指针通过 INT_TO_PTR 转为 int 返回。
     *
     * 调用方负责通过 {@see deleteGdiObjectInt()} 在不再使用时 DeleteObject。
     *
     * @param \FFI\CData $gpImage gdiplus 作用域的 GpImage CData（由 Image::getGpImage() 提供）。
     * @return int HBITMAP 指针的 int 表示，0 表示失败。
     */
    public function gdipImageToHbitmapInt(\FFI\CData $gpImage): int
    {
        $gp = $this->gdiplus;
        // GpImage 与 GpBitmap 均为 void*，同作用域可直接 cast
        $bitmap = $gp->cast('GpBitmap', $gpImage);
        // void* 由 new() 自动初始化为 null
        $hbm = $gp->new('void*');
        // argbBackground=0x00FFFFFF（不透明白），让透明像素合成到白底
        $status = (int) $gp->GdipCreateHBITMAPFromBitmap(
            $bitmap,
            \FFI::addr($hbm),
            0x00FFFFFF
        );
        if ($status !== 0 || $hbm === null || \FFI::isNull($hbm)) {
            return 0;
        }
        return $this->ptrToIntIn($gp, $hbm);
    }

    /**
     * 通过 int 句柄 DeleteObject 释放 GDI 对象（HBITMAP/HBRUSH/HPEN/HFONT 等）。
     *
     * int 在 gdi32 作用域内通过 INT_TO_PTR 转回 HGDIOBJ 指针后调用 DeleteObject。
     *
     * @param int $hObj GDI 对象的 int 句柄。
     */
    public function deleteGdiObjectInt(int $hObj): void
    {
        if ($hObj === 0) {
            return;
        }
        $obj = $this->intToPtrIn($this->gdi32, $hObj);
        $this->gdi32->DeleteObject($obj);
    }

    /**
     * 在 comctl32 作用域将 int 转为 HBITMAP CData（供 ImageList_Add 使用）。
     *
     * ImageList_Add 需要 comctl32 作用域的 HBITMAP，而 {@see gdipImageToHbitmapInt}
     * 返回的 int 来自 gdiplus 作用域。本方法在 comctl32 作用域重建 HBITMAP 指针。
     */
    public function intToComctl32Bitmap(int $hbmInt): \FFI\CData
    {
        return $this->intToPtrIn($this->comctl32, $hbmInt);
    }

    /**
     * 在 user32 作用域将 int 转为 HBITMAP CData（供 MENUITEMINFOW.hbmpItem 使用）。
     */
    public function intToUser32Bitmap(int $hbmInt): \FFI\CData
    {
        return $this->intToPtrIn($this->user32, $hbmInt);
    }

    // ============================================================
    // Unicode 编码辅助（UTF-8 ↔ UTF-16LE）
    // ============================================================

    /**
     * 编码转换回退链：mb_convert_encoding → iconv → 原字符串。
     *
     * mbstring 扩展未加载时自动回退到 iconv（本仓库运行环境不一定
     * 启用 mbstring，iconv 由 PHP 核心捆绑，始终可用）。iconv 仍失败
     * 则返回原字符串（最坏情况，调用方应能处理）。
     *
     * 用 self::conv() / WindowsPlatform::conv() 替代直接调用
     * mb_convert_encoding，确保无 mbstring 环境下不崩溃。
     *
     * @param string $s    待转换字符串。
     * @param string $to   目标编码（如 'UTF-16LE'）。
     * @param string $from 源编码（如 'UTF-8'）。
     */
    public static function conv(string $s, string $to, string $from): string
    {
        if (function_exists('mb_convert_encoding')) {
            return \mb_convert_encoding($s, $to, $from);
        }
        $r = @iconv($from, $to, $s);
        if ($r !== false) {
            return $r;
        }
        return $s;
    }

    /**
     * UTF-8 字符串 → wchar_t[] CData（含 \0 终止）。
     *
     * owned=false 确保缓冲在进程内常驻，避免 FFI 回收后悬空指针。
     * 调用方需通过 \FFI::addr($arr[0]) 获取 LPCWSTR 指针。
     */
    private function utf8ToWide(string $utf8): \FFI\CData
    {
        $wide = self::conv($utf8, 'UTF-16LE', 'UTF-8');
        $len = intdiv(strlen($wide), 2);
        $arr = $this->user32->new('wchar_t[' . ($len + 1) . ']', false);
        for ($i = 0; $i < $len; $i++) {
            $arr[$i] = ord($wide[$i * 2]) | (ord($wide[$i * 2 + 1]) << 8);
        }
        $arr[$len] = 0;
        return $arr;
    }

    /**
     * wchar_t[] CData → UTF-8 字符串。
     */
    private function wideToUtf8(\FFI\CData $wideStr): string
    {
        $bytes = '';
        $i = 0;
        while (true) {
            $ch = $wideStr[$i];
            if ($ch == 0) {
                break;
            }
            $bytes .= chr($ch & 0xFF) . chr(($ch >> 8) & 0xFF);
            $i++;
        }
        return self::conv($bytes, 'UTF-8', 'UTF-16LE');
    }

    /**
     * 在指定 FFI 作用域创建 wchar_t[] 缓冲（owned=false 持久化，防 GC）。
     *
     * 用于 comdlg32/shell32 作用域的结构体字段赋值——这些作用域的 wchar_t
     * 与 user32 作用域不互通，必须在本作用域内创建缓冲。
     *
     * @param \FFI  $ffi       目标 FFI 作用域（需含 wchar_t typedef）
     * @param string $utf8     UTF-8 字符串
     * @param int    $minChars 最小 wchar_t 数（不足时补 \0）
     */
    private function wideBufIn(\FFI $ffi, string $utf8, int $minChars = 0): \FFI\CData
    {
        $wide = self::conv($utf8, 'UTF-16LE', 'UTF-8');
        $len = max(intdiv(strlen($wide), 2), $minChars);
        $arr = $ffi->new('wchar_t[' . ($len + 1) . ']', false);
        for ($i = 0; $i < $len; $i++) {
            $arr[$i] = ord($wide[$i * 2]) | (ord($wide[$i * 2 + 1]) << 8);
        }
        $arr[$len] = 0;
        return $arr;
    }

    /**
     * 在指定 FFI 作用域创建零初始化 wchar_t[] 缓冲（owned=false 持久化）。
     */
    private function wideBufZero(\FFI $ffi, int $chars): \FFI\CData
    {
        $arr = $ffi->new('wchar_t[' . $chars . ']', false);
        for ($i = 0; $i < $chars; $i++) {
            $arr[$i] = 0;
        }
        return $arr;
    }

    /**
     * 构建 OPENFILENAMEW 的 lpstrFilter 双 \0 结尾 UTF-16LE 缓冲。
     *
     * 输入格式：
     *   - "描述|过滤"  → "描述\0过滤\0"
     *   - "*.txt"     → "*.txt\0*.txt\0"（无 | 时用模式本身作为描述）
     * 多对串联，最后额外一个 \0 终止整个字符串。
     *
     * @param \FFI $ffi 目标 FFI 作用域（comdlg32）
     * @param array<int,string> $filters 过滤器列表
     */
    private function buildOpenFileNameFilter(\FFI $ffi, array $filters): \FFI\CData
    {
        $parts = [];
        foreach ($filters as $f) {
            if (str_contains($f, '|')) {
                [$desc, $pat] = explode('|', $f, 2);
                $parts[] = $desc;
                $parts[] = $pat;
            } else {
                // 无 "|"：用模式本身作为描述
                $parts[] = $f;
                $parts[] = $f;
            }
        }

        // 预计算总 wchar_t 数（含段间 \0 与终止 \0）
        $encoded = [];
        $totalChars = 1; // 终止 \0
        foreach ($parts as $p) {
            $wide = self::conv($p, 'UTF-16LE', 'UTF-8');
            $encoded[] = $wide;
            $totalChars += intdiv(strlen($wide), 2) + 1; // 内容 + 段间 \0
        }

        $arr = $ffi->new('wchar_t[' . $totalChars . ']', false);
        $idx = 0;
        foreach ($encoded as $wide) {
            $len = intdiv(strlen($wide), 2);
            for ($i = 0; $i < $len; $i++) {
                $arr[$idx++] = ord($wide[$i * 2]) | (ord($wide[$i * 2 + 1]) << 8);
            }
            $arr[$idx++] = 0; // 段间 \0
        }
        $arr[$idx++] = 0; // 终止 \0
        return $arr;
    }

    // ============================================================
    // 默认字体（CreateFontW "Segoe UI" 支持中文回退）
    // ============================================================

    /**
     * 创建默认字体（单例，首次调用创建，后续返回缓存）。
     *
     * @param int $size 字号（磅），默认 14。
     * @return int HFONT 的 int 表示（用于 SendMessageW 的 WPARAM）。
     */
    private function createDefaultFont(int $size = 14): int
    {
        if ($this->defaultFontInt !== 0) {
            return $this->defaultFontInt;
        }
        $faceName = $this->utf8ToWide('Segoe UI');
        $height = -max(1, (int) round($size * 96 / 72));
        $font = $this->gdi32->CreateFontW(
            $height, 0, 0, 0,
            400,   // FW_NORMAL
            0, 0, 0,
            1,     // DEFAULT_CHARSET
            0, 0, 0,
            0,     // DEFAULT_PITCH | FF_DONTCARE
            \FFI::addr($faceName[0])
        );
        $this->defaultFont = $font;
        $this->defaultFontInt = $this->gdiPtrToInt($font);
        return $this->defaultFontInt;
    }

    /**
     * 为指定窗口/控件设置默认字体。
     */
    private function applyDefaultFont(\FFI\CData $hwnd): void
    {
        $fontInt = $this->createDefaultFont();
        $this->user32->SendMessageW($hwnd, self::WM_SETFONT, $fontInt, 1);
    }

    // ============================================================
    // Task 6: 窗口类注册与窗口创建（WNDCLASSEXW）
    // ============================================================

    /**
     * 注册窗口类 PhpUiWindow（仅注册一次）。
     */
    private function registerWindowClass(): void
    {
        if ($this->classRegistered) {
            return;
        }

        // 初始化通用控件（Trackbar/UpDown/ProgressBar/Tab/DateTimePicker 等）
        // 0x013F = ICC_LISTVIEW|ICC_TREEVIEW|ICC_BAR|ICC_TAB|ICC_UPDOWN|ICC_PROGRESS|ICC_DATE_CLASSES
        $icc = $this->comctl32->new('INITCOMMONCONTROLSEX');
        $icc->dwSize = 8;
        $icc->dwICC = 0x013F;
        $this->comctl32->InitCommonControlsEx(\FFI::addr($icc));

        // WindowProc 闭包
        $this->windowProc = function ($hwnd, $msg, $wParam, $lParam): int {
            return $this->dispatchWindowProc($hwnd, (int) $msg, (int) $wParam, (int) $lParam);
        };

        // 类名 wchar_t[]（owned=false 常驻进程）
        $this->classNameBuf = $this->utf8ToWide(self::WINDOW_CLASS_NAME);
        $classNamePtr = \FFI::addr($this->classNameBuf[0]);

        $wc = $this->user32->new('WNDCLASSEXW');
        $wc->cbSize = \FFI::sizeof($wc);
        $wc->style = self::CS_HREDRAW | self::CS_VREDRAW;
        $wc->lpfnWndProc = $this->windowProc;
        $wc->cbClsExtra = 0;
        $wc->cbWndExtra = 0;
        $wc->hInstance = null;
        $wc->hIcon = null;
        $wc->hCursor = $this->user32->LoadCursorW(null, self::IDC_ARROW);
        // 窗口背景画刷：CLASSIC 主题用 COLOR_WINDOW+1（白色）保持经典外观；
        // 非 CLASSIC 主题用 NULL 画刷（不绘制背景），让 DWM 的 Mica 云母背景
        // 透过客户区显示（Win11 22000+）。Win10 无 Mica 时背景不擦除，由
        // 控件自绘覆盖，客户区空隙可能显示为前一帧内容，属可接受降级。
        $bgC = $this->user32->new('INT_TO_PTR');
        $bgC->i = $this->currentTheme === Theme::CLASSIC ? (self::COLOR_WINDOW + 1) : 0;
        $wc->hbrBackground = $bgC->p;
        $wc->lpszMenuName = null;
        $wc->lpszClassName = $classNamePtr;
        $wc->hIconSm = null;

        $atom = $this->user32->RegisterClassExW(\FFI::addr($wc));
        if ($atom == 0) {
            throw new \RuntimeException(
                'RegisterClassExW failed for class "' . self::WINDOW_CLASS_NAME . '"'
            );
        }

        // Task 6：非 CLASSIC 主题创建现代字体（Segoe UI 9pt）。
        // controlCreate 创建控件后通过 SendMessageW(WM_SETFONT) 应用，
        // 使按钮/输入框等控件使用现代字体而非系统默认位图字体。
        if ($this->currentTheme !== Theme::CLASSIC && $this->hFont === null) {
            $this->createModernFont();
        }

        $this->classRegistered = true;
    }

    /**
     * 创建现代字体（Segoe UI Variable 9pt，gdi32 作用域 HFONT）。
     *
     * 字体高度用负值表示按字符高度计算：-12 ≈ 9pt@96dpi。
     * CData 存 $this->hFont 防 GC，int 值通过 gdiPtrToInt 在应用时转换。
     * Win11 优先用 "Segoe UI Variable"；旧系统无此字体会自动 fallback。
     */
    private function createModernFont(): void
    {
        $faceName = $this->utf8ToWide('Segoe UI Variable');
        // 9pt @ 96dpi ≈ 12 像素，负值表示"字符高度"而非"单元格高度"
        $height = -12;
        $font = $this->gdi32->CreateFontW(
            $height, 0, 0, 0,
            400,   // FW_NORMAL
            0, 0, 0,
            1,     // DEFAULT_CHARSET
            0, 0, 0,
            0,     // DEFAULT_PITCH | FF_DONTCARE
            \FFI::addr($faceName[0])
        );
        $this->hFont = $font;
    }

    /**
     * WindowProc 消息分发。
     */
    private function dispatchWindowProc(\FFI\CData $hwnd, int $msg, int $wParam, int $lParam): int
    {
        // 模态守护
        if ($this->inModalDialog) {
            return (int) $this->user32->DefWindowProcW($hwnd, $msg, $wParam, $lParam);
        }

        $hwndInt = $this->hwndInt($hwnd);
        $isWindow = isset($this->windows[$hwndInt]);

        if ($isWindow) {
            switch ($msg) {
                case self::WM_MOVE:
                    $window = $this->windows[$hwndInt] ?? null;
                    if ($window !== null && $window->onPositionChanged !== null) {
                        // lParam 低字=x 高字=y（屏幕坐标，已为客户端区域左上角）
                        $x = $lParam & 0xFFFF;
                        $y = ($lParam >> 16) & 0xFFFF;
                        // 符号扩展为有符号 16 位（多显示器负坐标）
                        if ($x >= 0x8000) {
                            $x -= 0x10000;
                        }
                        if ($y >= 0x8000) {
                            $y -= 0x10000;
                        }
                        try {
                            ($window->onPositionChanged)(Point::of($x, $y));
                        } catch (\Throwable $e) {
                            trigger_error(
                                'onPositionChanged callback error: ' . $e->getMessage(),
                                \E_USER_WARNING
                            );
                        }
                    }
                    return 0;

                case self::WM_SIZE:
                    $this->relayoutWindowNow($hwndInt);
                    // 同步滚动条 page 大小（客户区高度变化后更新 SCROLLINFO）
                    if (isset($this->windowScrollInfo[$hwndInt])) {
                        $client = $this->windowGetClientSize($hwndInt);
                        $this->applyScrollInfo($hwndInt, $client->height);
                    }
                    $window = $this->windows[$hwndInt] ?? null;
                    if ($window !== null && $window->onResize !== null) {
                        $w = $lParam & 0xFFFF;
                        $h = ($lParam >> 16) & 0xFFFF;
                        try {
                            ($window->onResize)(new ResizeEvent($w, $h));
                        } catch (\Throwable $e) {
                            trigger_error(
                                'onResize callback error: ' . $e->getMessage(),
                                \E_USER_WARNING
                            );
                        }
                    }
                    return 0;

                case self::WM_CLOSE:
                    $window = $this->windows[$hwndInt] ?? null;
                    $prevent = false;
                    if ($window !== null && $window->onClose !== null) {
                        try {
                            $ret = ($window->onClose)();
                            if ($ret === false) {
                                $prevent = true;
                            }
                        } catch (\Throwable $e) {
                            trigger_error(
                                'onClose callback error: ' . $e->getMessage(),
                                \E_USER_WARNING
                            );
                        }
                    }
                    if ($prevent) {
                        return 0;
                    }
                    return (int) $this->user32->DefWindowProcW($hwnd, $msg, $wParam, $lParam);

                case self::WM_DESTROY:
                    $this->unregisterWindow($hwndInt);
                    unset($this->windowScrollInfo[$hwndInt]);
                    // 释放窗口自定义 Image 图标
                    if (isset($this->windowImageIcons[$hwndInt])) {
                        $this->destroyIconInt($this->windowImageIcons[$hwndInt]);
                        unset($this->windowImageIcons[$hwndInt]);
                    }
                    if ($this->windows === []) {
                        $this->user32->PostQuitMessage(0);
                    }
                    return 0;

                case self::WM_COMMAND:
                    return $this->dispatchWmCommand($hwndInt, $wParam, $lParam);

                case self::WM_HSCROLL:
                case self::WM_VSCROLL:
                    return $this->dispatchScroll($hwndInt, $msg, $wParam, $lParam);

                case self::WM_NOTIFY:
                    // Table（ListView）通知优先处理：NM_CUSTOMDRAW 需要返回非 0 值
                    $tableResult = $this->handleTableNotify($lParam);
                    if ($tableResult !== null) {
                        return $tableResult;
                    }
                    if ($this->dispatchWmNotify($lParam)) {
                        return 0;
                    }
                    return (int) $this->user32->DefWindowProcW($hwnd, $msg, $wParam, $lParam);

                case self::WM_PAINT:
                    return (int) $this->user32->DefWindowProcW($hwnd, $msg, $wParam, $lParam);

                case self::WM_MOUSEMOVE:
                case self::WM_LBUTTONDOWN:
                case self::WM_LBUTTONUP:
                case self::WM_RBUTTONDOWN:
                case self::WM_RBUTTONUP:
                case self::WM_MBUTTONDOWN:
                case self::WM_MBUTTONUP:
                case self::WM_KEYDOWN:
                case self::WM_KEYUP:
                    return (int) $this->user32->DefWindowProcW($hwnd, $msg, $wParam, $lParam);

                // 托盘图标回调消息（WM_APP = 0x8000）
                case self::WM_TRAYICON:
                    $this->handleTrayCallback($hwndInt, $wParam, $lParam);
                    return 0;

                default:
                    return (int) $this->user32->DefWindowProcW($hwnd, $msg, $wParam, $lParam);
            }
        }

        // 控件分支：Area 控件（PhpUiWindow 类的 WS_CHILD 子窗口）在此处理
        // WM_PAINT/鼠标消息；其他控件走 DefWindowProcW 默认处理。
        if (($this->controlTypes[$hwndInt] ?? '') === 'Area') {
            return $this->dispatchAreaMessage($hwnd, $hwndInt, $msg, $wParam, $lParam);
        }

        // Container 控件（PhpUiWindow 类的 WS_CHILD 子窗口，如 HBox/VBox/Grid/Form）
        // 作为按钮等子控件的父窗口，会收到子控件发来的 WM_COMMAND 通知。
        // 必须在此分发到对应控件的 onClick/onChange/onSelect 回调，否则按钮点击无效。
        // 注意：lParam 是控件 HWND，用 $this->controls 反查控件实例。
        if ($msg === self::WM_COMMAND && $lParam !== 0) {
            return $this->dispatchWmCommand($hwndInt, $wParam, $lParam);
        }

        // Container 也可能收到子控件（Slider/UpDown 等）发来的 WM_HSCROLL/WM_VSCROLL
        if ($msg === self::WM_HSCROLL || $msg === self::WM_VSCROLL) {
            return $this->dispatchScroll($hwndInt, $msg, $wParam, $lParam);
        }

        // Container 也可能收到子控件（如 Tab/Table）发来的 WM_NOTIFY 通知
        // Table 通知（LVN_GETDISPINFO/LVN_ITEMCHANGED/NM_CUSTOMDRAW 等）优先处理
        if ($msg === self::WM_NOTIFY) {
            $tableResult = $this->handleTableNotify($lParam);
            if ($tableResult !== null) {
                return $tableResult;
            }
            if ($this->dispatchWmNotify($lParam)) {
                return 0;
            }
        }

        return (int) $this->user32->DefWindowProcW($hwnd, $msg, $wParam, $lParam);
    }

    /**
     * Area 控件消息分发：WM_PAINT 触发 onDraw，鼠标消息触发对应回调。
     */
    private function dispatchAreaMessage(\FFI\CData $hwnd, int $hwndInt, int $msg, int $wParam, int $lParam): int
    {
        $area = $this->controls[$hwndInt] ?? null;

        switch ($msg) {
            case self::WM_ERASEBKGND:
                // 抑制背景擦除，由 onDraw 回调完整绘制背景，避免闪屏
                return 1;

            case self::WM_PAINT:
                $ctx = $this->drawContextCreate($hwndInt);
                try {
                    // 应用 Area 滚动偏移：在用户 onDraw 之前平移世界坐标系，
                    // 使用户按内容坐标系（0,0 ~ w,h）绘制即可。
                    $scroll = $this->areaScrollInfo[$hwndInt] ?? null;
                    if ($scroll !== null && ($scroll['x'] > 0 || $scroll['y'] > 0)) {
                        $this->gdiplus->GdipTranslateWorldTransform(
                            $ctx->getGraphics(),
                            (float) -$scroll['x'],
                            (float) -$scroll['y'],
                            0 // MatrixOrderPrepend
                        );
                    }
                    if ($area !== null && $area->onDraw !== null) {
                        try {
                            ($area->onDraw)($ctx);
                        } catch (\Throwable $e) {
                            trigger_error(
                                'onDraw callback error: ' . $e->getMessage(),
                                \E_USER_WARNING
                            );
                        }
                    }
                } finally {
                    $this->drawContextFree($ctx);
                }
                return 0;

            case self::WM_MOUSEMOVE:
            case self::WM_LBUTTONDOWN:
            case self::WM_LBUTTONUP:
            case self::WM_RBUTTONDOWN:
            case self::WM_RBUTTONUP:
            case self::WM_MBUTTONDOWN:
            case self::WM_MBUTTONUP:
                // 首次 mousemove：注册鼠标离开跟踪并触发 onMouseEnter
                if (!($this->areaMouseTracking[$hwndInt] ?? false)) {
                    $this->areaMouseTracking[$hwndInt] = true;
                    $this->areaTrackMouseLeave($hwnd);
                    if ($area !== null && $area->onMouseEnter !== null) {
                        try {
                            ($area->onMouseEnter)();
                        } catch (\Throwable $e) {
                            trigger_error(
                                'onMouseEnter callback error: ' . $e->getMessage(),
                                \E_USER_WARNING
                            );
                        }
                    }
                }
                // 左键按下时让 Area 获得焦点，使其能收到键盘消息
                if ($msg === self::WM_LBUTTONDOWN) {
                    $this->user32->SetFocus($hwnd);
                }
                $this->dispatchAreaMouse($area, $msg, $wParam, $lParam, $hwndInt);
                return 0;

            case self::WM_MOUSELEAVE:
                // 鼠标离开：触发 onMouseLeave 并清除跟踪状态
                unset($this->areaMouseTracking[$hwndInt]);
                if ($area !== null && $area->onMouseLeave !== null) {
                    try {
                        ($area->onMouseLeave)();
                    } catch (\Throwable $e) {
                        trigger_error(
                            'onMouseLeave callback error: ' . $e->getMessage(),
                            \E_USER_WARNING
                        );
                    }
                }
                return 0;

            case self::WM_SIZE:
                // Area 尺寸变化时刷新滚动条 page（避免 page>range 时显示无效滑块）
                $info = $this->areaScrollInfo[$hwndInt] ?? null;
                if ($info !== null) {
                    $client = $this->controlClientSize($hwndInt);
                    $h = $this->intToHwnd($hwndInt);
                    // 夹取 x/y 到新范围
                    $maxX = max(0, $info['w'] - $client['w']);
                    $maxY = max(0, $info['h'] - $client['h']);
                    $info['x'] = max(0, min($info['x'], $maxX));
                    $info['y'] = max(0, min($info['y'], $maxY));
                    $this->areaScrollInfo[$hwndInt] = $info;
                    if ($info['w'] > 0) {
                        $si = $this->buildScrollInfo(0, $info['w'], $client['w'], $info['x']);
                        $this->user32->SetScrollInfo($h, self::SB_HORZ, \FFI::addr($si), 1);
                    }
                    if ($info['h'] > 0) {
                        $si = $this->buildScrollInfo(0, $info['h'], $client['h'], $info['y']);
                        $this->user32->SetScrollInfo($h, self::SB_VERT, \FFI::addr($si), 1);
                    }
                    $this->user32->InvalidateRect($h, null, 1);
                }
                return 0;

            case self::WM_HSCROLL:
            case self::WM_VSCROLL:
                // Area 自身滚动条消息（lParam==0 表示窗口滚动条）。
                // 走专用处理：更新 areaScrollInfo 的 x/y 并 Invalidate 重绘。
                return $this->handleAreaScroll($hwndInt, $msg, $wParam);

            default:
                return (int) $this->user32->DefWindowProcW($hwnd, $msg, $wParam, $lParam);
        }
    }

    /**
     * 注册 WM_MOUSELEAVE 通知（TrackMouseEvent + TME_LEAVE）。
     *
     * 调用后当鼠标离开 Area 客户区时，系统会投递 WM_MOUSELEAVE 消息。
     * 一次 TrackMouseEvent 只生效一次，触发后需重新注册。
     */
    private function areaTrackMouseLeave(\FFI\CData $hwnd): void
    {
        $tme = $this->user32->new('TRACKMOUSEEVENT');
        $tme->cbSize = \FFI::sizeof($tme);
        $tme->dwFlags = self::TME_LEAVE;
        $tme->hwndTrack = $hwnd;
        $tme->dwHoverTime = 0;
        $this->user32->TrackMouseEvent(\FFI::addr($tme));
    }

    /**
     * Area 鼠标消息分发。
     *
     * lParam 低字=x 高字=y（signed short，需符号扩展）。
     * wParam 的 MK_LBUTTON=0x0001/MK_RBUTTON=0x0002/MK_MBUTTON=0x0010。
     */
    private function dispatchAreaMouse(?object $area, int $msg, int $wParam, int $lParam, int $hwndInt = 0): void
    {
        if ($area === null) {
            return;
        }
        // 符号扩展 signed short → int
        $x = $lParam & 0xFFFF;
        if ($x >= 0x8000) {
            $x -= 0x10000;
        }
        $y = ($lParam >> 16) & 0xFFFF;
        if ($y >= 0x8000) {
            $y -= 0x10000;
        }

        // Area 滚动偏移：客户端坐标 + scrollX/Y = 内容坐标
        // 用户 onDraw 按内容坐标系绘制，鼠标坐标也统一为内容坐标系。
        if ($hwndInt !== 0) {
            $scroll = $this->areaScrollInfo[$hwndInt] ?? null;
            if ($scroll !== null) {
                $x += $scroll['x'];
                $y += $scroll['y'];
            }
        }

        // 修饰键：MK_SHIFT=0x0004, MK_CONTROL=0x0008
        $modifiers = MouseEvent::MODIFIER_NONE;
        if ($wParam & 0x0004) {
            $modifiers |= MouseEvent::MODIFIER_SHIFT;
        }
        if ($wParam & 0x0008) {
            $modifiers |= MouseEvent::MODIFIER_CTRL;
        }

        $button = MouseEvent::BUTTON_NONE;
        $callback = null;

        switch ($msg) {
            case self::WM_LBUTTONDOWN:
                $button = MouseEvent::BUTTON_LEFT;
                $callback = $area->onMouseDown ?? null;
                break;
            case self::WM_RBUTTONDOWN:
                $button = MouseEvent::BUTTON_RIGHT;
                $callback = $area->onMouseDown ?? null;
                break;
            case self::WM_MBUTTONDOWN:
                $button = MouseEvent::BUTTON_MIDDLE;
                $callback = $area->onMouseDown ?? null;
                break;
            case self::WM_LBUTTONUP:
                $button = MouseEvent::BUTTON_LEFT;
                $callback = $area->onMouseUp ?? null;
                break;
            case self::WM_RBUTTONUP:
                $button = MouseEvent::BUTTON_RIGHT;
                $callback = $area->onMouseUp ?? null;
                break;
            case self::WM_MBUTTONUP:
                $button = MouseEvent::BUTTON_MIDDLE;
                $callback = $area->onMouseUp ?? null;
                break;
            case self::WM_MOUSEMOVE:
                // 从 wParam 推断当前按下的按键
                if ($wParam & 0x0001) {
                    $button = MouseEvent::BUTTON_LEFT;
                } elseif ($wParam & 0x0002) {
                    $button = MouseEvent::BUTTON_RIGHT;
                } elseif ($wParam & 0x0010) {
                    $button = MouseEvent::BUTTON_MIDDLE;
                }
                $callback = $area->onMouseMove ?? null;
                break;
        }

        if ($callback === null) {
            return;
        }
        try {
            $callback(new MouseEvent($x, $y, $button, $modifiers));
        } catch (\Throwable $e) {
            trigger_error(
                'mouse callback error: ' . $e->getMessage(),
                \E_USER_WARNING
            );
        }
    }

    /**
     * WM_COMMAND 分发：
     *   - lParam != 0 → 控件通知（通过 lParam 反查控件实例）
     *   - lParam == 0 → 菜单命令，LOWORD(wParam) = 菜单项 ID
     *     先在窗口菜单栏（getMenu）查找；找不到再遍历所有 TrayIcon 的
     *     contextMenu（托盘右键菜单不挂在窗口上，必须单独查找）。
     */
    private function dispatchWmCommand(int $hwndInt, int $wParam, int $lParam): int
    {
        if ($lParam === 0) {
            // 菜单/加速键命令
            $menuItemId = $wParam & 0xFFFF; // LOWORD(wParam)
            $item = null;

            // 1. 先在窗口菜单栏查找
            $window = $this->windows[$hwndInt] ?? null;
            if ($window !== null && method_exists($window, 'getMenu')) {
                $menu = $window->getMenu();
                if ($menu !== null) {
                    $item = $menu->findItemById($menuItemId);
                }
            }

            // 2. 菜单栏找不到，遍历所有 TrayIcon 的右键上下文菜单
            if ($item === null) {
                foreach ($this->trayIcons as $tray) {
                    $ctxMenu = $tray->getContextMenu();
                    if ($ctxMenu === null) {
                        continue;
                    }
                    $item = $ctxMenu->findItemById($menuItemId);
                    if ($item !== null) {
                        break;
                    }
                }
            }

            if ($item !== null && $item->onClick !== null) {
                try {
                    ($item->onClick)();
                } catch (\Throwable $e) {
                    trigger_error(
                        'menu onClick callback error: ' . $e->getMessage(),
                        \E_USER_WARNING
                    );
                }
            }
            return 0;
        }
        $notification = ($wParam >> 16) & 0xFFFF;
        $control = $this->controls[$lParam] ?? null;
        if ($control === null) {
            return 0;
        }

        if ($notification === self::BN_CLICKED) {
            // RadioBox 点击时手动选中并取消同组其他项。
            // BS_AUTORADIOBUTTON 在 Container（子窗口）父下自动切换不稳定，
            // 此处显式调用 setChecked(true) 触发 uncheckSiblings 逻辑。
            if ($control instanceof \Kingbes\Ui\Control\RadioBox) {
                $control->setChecked(true);
            }
            if ($control->onClick !== null) {
                try {
                    ($control->onClick)();
                } catch (\Throwable $e) {
                    trigger_error(
                        'onClick callback error: ' . $e->getMessage(),
                        \E_USER_WARNING
                    );
                }
            }
        } elseif ($notification === self::EN_CHANGE) {
            if (property_exists($control, 'onChange') && $control->onChange !== null) {
                try {
                    ($control->onChange)();
                } catch (\Throwable $e) {
                    trigger_error(
                        'onChange callback error: ' . $e->getMessage(),
                        \E_USER_WARNING
                    );
                }
            }
        } elseif ($notification === self::CBN_SELCHANGE || $notification === self::LBN_SELCHANGE) {
            if (property_exists($control, 'onSelect') && $control->onSelect !== null) {
                try {
                    ($control->onSelect)();
                } catch (\Throwable $e) {
                    trigger_error(
                        'onSelect callback error: ' . $e->getMessage(),
                        \E_USER_WARNING
                    );
                }
            }
        } elseif ($notification === self::CBN_EDITCHANGE) {
            // EditableComboBox 编辑框文本变化
            if (property_exists($control, 'onChange') && $control->onChange !== null) {
                try {
                    ($control->onChange)();
                } catch (\Throwable $e) {
                    trigger_error(
                        'onChange callback error: ' . $e->getMessage(),
                        \E_USER_WARNING
                    );
                }
            }
        }
        return 0;
    }

    /**
     * WM_NOTIFY 分发：处理 Tab 控件 TCN_SELCHANGE 等通知。
     *
     * lParam 指向 NMHDR 结构：hwndFrom（发送通知的控件 HWND）、
     * idFrom（控件 ID）、code（通知码）。
     *
     * @return bool true 表示已处理，调用方应返回 0。
     */
    private function dispatchWmNotify(int $lParam): bool
    {
        if ($lParam === 0) {
            return false;
        }
        // int → user32 作用域指针 → NMHDR*
        $caster = $this->user32->new('INT_TO_PTR');
        $caster->i = $lParam;
        $nmhdr = $this->user32->cast('NMHDR*', $caster->p);

        $fromHwnd = $this->hwndInt($nmhdr->hwndFrom);
        // NMHDR.code 是 UINT，负通知码需转有符号 32 位
        $code = (int) $nmhdr->code;
        if ($code > 0x7FFFFFFF) {
            $code -= 0x100000000;
        }

        if ($code === self::TCN_SELCHANGE) {
            $control = $this->controls[$fromHwnd] ?? null;
            if ($control !== null && method_exists($control, 'handleSelChanged')) {
                try {
                    $control->handleSelChanged();
                } catch (\Throwable $e) {
                    trigger_error(
                        'handleSelChanged error: ' . $e->getMessage(),
                        \E_USER_WARNING
                    );
                }
                return true;
            }
        }

        // DateTimePicker 日期时间变化通知
        // NMDATETIMECHANGE = { NMHDR nmhdr; DWORD dwFlags; SYSTEMTIME st; }
        // nmhdr 之后紧跟 dwFlags，再跟 SYSTEMTIME（8 个 WORD）
        if ($code === self::DTN_DATETIMECHANGE) {
            $control = $this->controls[$fromHwnd] ?? null;
            if ($control !== null && method_exists($control, 'handleDateTimeChange')) {
                try {
                    $control->handleDateTimeChange();
                } catch (\Throwable $e) {
                    trigger_error(
                        'handleDateTimeChange error: ' . $e->getMessage(),
                        \E_USER_WARNING
                    );
                }
                return true;
            }
        }

        // UpDown（SpinBox）数值即将变化通知
        // NMUPDOWN = { NMHDR hdr; int iPos; int iDelta; }
        // UDN_DELTAPOS 是"即将改变"通知：此时 UpDown 位置变更尚未应用，
        // 同步调用 onChanged 会在回调内通过 UDM_GETPOS 重入 UpDown 控件，
        // 干扰其变更流程。改为用 queueMain 异步触发回调：等当前消息处理
        // 完成、DefWindowProcW 已应用变更后再读取新值，避免重入。
        // return false 让 DefWindowProcW 正常处理（UDN_DELTAPOS 返回 FALSE
        // 表示允许变更）。
        //
        // 另外：UDS_SETBUDDYINT 在 ComCtl32 v6 + SetWindowTheme("Explorer")
        // 环境下不生效，UpDown 不会自动更新 buddy Edit 的文本。因此在
        // 异步回调中手动同步 Edit 文本（在 onChanged 之前，确保回调内
        // 读取到的是最新显示值）。
        if ($code === self::UDN_DELTAPOS) {
            $control = $this->controls[$fromHwnd] ?? null;
            if ($control instanceof \Kingbes\Ui\Control\SpinBox) {
                $spin = $control;
                $this->queueMain(function () use ($spin): void {
                    // 手动同步 Edit 文本（UDS_SETBUDDYINT 不生效的 workaround）
                    // 直接通过 $this 调用，避免依赖 App 静态方法（命名空间解析问题）
                    $value = $spin->getValue();
                    $this->controlSetText($spin->getHwnd(), (string)$value);
                    if ($spin->onChanged !== null) {
                        try {
                            ($spin->onChanged)();
                        } catch (\Throwable $e) {
                            trigger_error(
                                'onChanged callback error: ' . $e->getMessage(),
                                \E_USER_WARNING
                            );
                        }
                    }
                });
                return false;
            }
        }
        return false;
    }

    /**
     * 处理 Table（ListView）的 WM_NOTIFY 通知。
     *
     * 覆盖以下通知码：
     *   - LVN_GETDISPINFO（-177）：虚拟模式按需取数据，调用 model->getCellValue
     *     填充 LVITEMW.pszText。
     *   - LVN_ITEMCHANGED（-101）：选中行变化，触发 onSelectionChanged。
     *   - NM_DBLCLK（-3）：双击行，触发 onRowDoubleClicked。
     *   - NM_CUSTOMDRAW（-12）：自绘，应用 setRowBgColor/setRowTextColor。
     *
     * @param int $lParam WM_NOTIFY 的 lParam（NMHDR* 指针的 int 表示）。
     * @return int|null 非 null 表示已处理（值为返回给系统的 LRESULT）；
     *                  null 表示未处理，调用方继续走 dispatchWmNotify。
     */
    private function handleTableNotify(int $lParam): ?int
    {
        if ($lParam === 0) {
            return null;
        }

        // int → user32 作用域指针 → NMHDR*
        $caster = $this->user32->new('INT_TO_PTR');
        $caster->i = $lParam;
        $nmhdr = $this->user32->cast('NMHDR*', $caster->p);

        $fromHwnd = $this->hwndInt($nmhdr->hwndFrom);
        $code = (int) $nmhdr->code;
        if ($code > 0x7FFFFFFF) {
            $code -= 0x100000000;
        }

        // 仅处理 ListView（Table 控件）的通知
        $control = $this->controls[$fromHwnd] ?? null;
        if ($control === null || !($control instanceof \Kingbes\Ui\Control\Table)) {
            return null;
        }
        $model = $control->getModel();
        if ($model === null) {
            return null;
        }

        // LVN_GETDISPINFO：虚拟模式按需取数据
        if ($code === self::LVN_GETDISPINFOW) {
            // lParam 指向 NMLVDISPINFO（NMHDR + LVITEMW）
            $dispInfo = $this->user32->cast('NMLVDISPINFO*', $caster->p);
            $item = $dispInfo->item;
            $mask = (int) $item->mask;
            $row = (int) $item->iItem;
            $col = (int) $item->iSubItem;

            if (($mask & self::LVIF_TEXT) !== 0) {
                $text = $model->getCellValue($row, $col);

                // 写入 pszText 缓冲（pszText 由控件分配，cchTextMax 为容量）
                $textWide = self::conv($text, 'UTF-16LE', 'UTF-8');
                $textChars = intdiv(strlen($textWide), 2);
                $maxChars = (int) $item->cchTextMax;
                $writeChars = min($textChars, max(0, $maxChars - 1));

                // pszText 是 wchar_t* 指针，需 cast 后写入
                $pszTextPtr = $item->pszText;
                if ($pszTextPtr !== null && \FFI::isNull($pszTextPtr) === false && $writeChars >= 0) {
                    // 直接按 wchar_t* 写入
                    $wcharArr = $this->user32->cast('wchar_t*', $pszTextPtr);
                    for ($i = 0; $i < $writeChars; $i++) {
                        $wcharArr[$i] = ord($textWide[$i * 2]) | (ord($textWide[$i * 2 + 1]) << 8);
                    }
                    $wcharArr[$writeChars] = 0;
                }
            }

            // LVIF_IMAGE：图像列。委托 Table::resolveCellImage 获取图像索引。
            // -1 表示该单元格无图像（ListView 不绘制图标）。
            if (($mask & self::LVIF_IMAGE) !== 0) {
                $imageIndex = -1;
                if (method_exists($control, 'resolveCellImage')) {
                    try {
                        $imageIndex = (int) $control->resolveCellImage($row, $col);
                    } catch (\Throwable $e) {
                        trigger_error(
                            'resolveCellImage error: ' . $e->getMessage(),
                            \E_USER_WARNING
                        );
                    }
                }
                $item->iImage = $imageIndex;
            }
            return 0;
        }

        // LVN_ITEMCHANGED：选中行变化
        if ($code === self::LVN_ITEMCHANGED) {
            $nmlv = $this->user32->cast('NMLISTVIEW*', $caster->p);
            // 仅当 LVIS_SELECTED 状态变化时触发
            $newState = (int) $nmlv->uNewState;
            $oldState = (int) $nmlv->uOldState;
            $newSel = ($newState & self::LVIS_SELECTED) !== 0;
            $oldSel = ($oldState & self::LVIS_SELECTED) !== 0;
            if ($newSel !== $oldSel) {
                $row = (int) $nmlv->iItem;
                $selRow = $newSel ? $row : -1;
                if (method_exists($control, 'handleSelectionChanged')) {
                    try {
                        $control->handleSelectionChanged($selRow);
                    } catch (\Throwable $e) {
                        trigger_error(
                            'handleSelectionChanged error: ' . $e->getMessage(),
                            \E_USER_WARNING
                        );
                    }
                }
            }
            return 0;
        }

        // NM_DBLCLK：双击行
        if ($code === self::NM_DBLCLK) {
            $row = $this->tableGetSelectedRow($fromHwnd);
            if ($row >= 0 && method_exists($control, 'handleRowDoubleClicked')) {
                try {
                    $control->handleRowDoubleClicked($row);
                } catch (\Throwable $e) {
                    trigger_error(
                        'handleRowDoubleClicked error: ' . $e->getMessage(),
                        \E_USER_WARNING
                    );
                }
            }
            return 0;
        }

        // NM_CLICK：单击命中 checkbox/button 列时触发对应回调
        if ($code === self::NM_CLICK) {
            $this->handleTableClick($control, $fromHwnd);
            return 0;
        }

        // NM_CUSTOMDRAW：行级背景色/文字色
        if ($code === self::NM_CUSTOMDRAW) {
            return $this->handleTableCustomDraw($control, $lParam, $caster);
        }

        return null;
    }

    /**
     * 处理 Table 的 NM_CUSTOMDRAW 通知（行级着色 + 单元格自绘）。
     *
     * 流程：
     *   1. CDDS_PREPAINT 阶段返回 CDRF_NOTIFYITEMDRAW，请求逐项通知。
     *   2. CDDS_ITEMPREPAINT 阶段查表设置 clrTextBk/clrText，并请求子项通知。
     *   3. CDDS_SUBITEM | CDDS_ITEMPREPAINT 阶段：根据列类型自绘
     *      checkbox/progress/color/button，返回 CDRF_SKIPDEFAULT 跳过系统默认绘制。
     *      文本/图像列返回 CDRF_DODEFAULT 由系统绘制。
     *
     * @param \Kingbes\Ui\Control\Table $control Table 控件实例。
     * @param int                       $lParam  NMLVCUSTOMDRAW* 的 int 表示。
     * @param \FFI\CData                $caster  已绑定的 INT_TO_PTR 联合体（含 lParam 指针）。
     * @return int LRESULT 返回值（CDRF_XXX）。
     */
    private function handleTableCustomDraw(
        \Kingbes\Ui\Control\Table $control,
        int $lParam,
        \FFI\CData $caster
    ): int {
        $nmlvcd = $this->user32->cast('NMLVCUSTOMDRAW*', $caster->p);
        $stage = (int) $nmlvcd->dwDrawStage;

        if ($stage === self::CDDS_PREPAINT) {
            return self::CDRF_NOTIFYITEMDRAW;
        }

        if (($stage & self::CDDS_ITEMPREPAINT) === self::CDDS_ITEMPREPAINT
            && ($stage & self::CDDS_SUBITEM) === 0
        ) {
            $row = (int) $nmlvcd->dwItemSpec;
            $hwnd = $control->getHwnd();

            // 应用背景色
            $bgColor = $this->tableRowBgColors[$hwnd][$row] ?? null;
            if ($bgColor !== null) {
                $nmlvcd->clrTextBk = $bgColor;
            }

            // 应用文字色
            $textColor = $this->tableRowTextColors[$hwnd][$row] ?? null;
            if ($textColor !== null) {
                $nmlvcd->clrText = $textColor;
            }

            // 请求子项绘制通知（CDDS_SUBITEM 阶段）
            return self::CDRF_NOTIFYSUBITEMDRAW;
        }

        // CDDS_SUBITEM | CDDS_ITEMPREPAINT：每列自绘
        if (($stage & self::CDDS_SUBITEM) === self::CDDS_SUBITEM) {
            $row = (int) $nmlvcd->dwItemSpec;
            $col = (int) $nmlvcd->iSubItem;
            $type = $control->getColumnType($col);

            // 文本/图像列：交给系统绘制
            if ($type === Table::TYPE_TEXT || $type === Table::TYPE_IMAGE) {
                return self::CDRF_DODEFAULT;
            }

            // 自绘 checkbox/progress/color/button
            $hdc = $nmlvcd->hdc;
            $rc = $nmlvcd->rc;
            try {
                $this->drawTableCell($control, $type, $row, $col, $hdc, $rc);
            } catch (\Throwable $e) {
                trigger_error(
                    'drawTableCell error: ' . $e->getMessage(),
                    \E_USER_WARNING
                );
            }
            return self::CDRF_SKIPDEFAULT;
        }

        return self::CDRF_DODEFAULT;
    }

    /**
     * 判断当前是否应使用 uxtheme 视觉样式渲染。
     *
     * 满足以下全部条件时返回 true：
     *   - uxtheme.dll 已成功 cdef 加载
     *   - 当前主题非 Theme::CLASSIC
     *   - IsThemeActive() 返回真（系统启用了视觉样式）
     *
     * 任意条件不满足时返回 false，调用方需回退到 DrawFrameControl/FillRect。
     */
    private function themeIsActive(): bool
    {
        if ($this->uxtheme === null) {
            return false;
        }
        if ($this->currentTheme === Theme::CLASSIC) {
            return false;
        }
        try {
            return (bool) $this->uxtheme->IsThemeActive();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 把 user32 作用域 HDC CData 转为 uxtheme 作用域 HDC（通过 int 中转）。
     */
    private function hdcToUxtheme(\FFI\CData $hdc): \FFI\CData
    {
        return $this->intToPtrIn($this->uxtheme, $this->ptrToInt($hdc));
    }

    /**
     * 在 uxtheme 作用域复制一份 user32 作用域 RECT（DrawThemeBackground 用）。
     */
    private function rectToUxtheme(\FFI\CData $rc): \FFI\CData
    {
        $ux = $this->uxtheme->new('RECT');
        $ux->left   = (int) $rc->left;
        $ux->top    = (int) $rc->top;
        $ux->right  = (int) $rc->right;
        $ux->bottom = (int) $rc->bottom;
        return $ux;
    }

    /**
     * 自绘 Table 单元格（checkbox/progress/color/button）。
     *
     * @param Table     $control Table 实例。
     * @param string    $type    列类型（TYPE_CHECKBOX/PROGRESS/COLOR/BUTTON）。
     * @param int       $row     行索引。
     * @param int       $col     列索引。
     * @param \FFI\CData $hdc    设备上下文（user32 作用域 HDC）。
     * @param \FFI\CData $rc     单元格矩形（user32 作用域 RECT）。
     */
    private function drawTableCell(
        \Kingbes\Ui\Control\Table $control,
        string $type,
        int $row,
        int $col,
        \FFI\CData $hdc,
        \FFI\CData $rc
    ): void {
        switch ($type) {
            case Table::TYPE_CHECKBOX:
                $checked = $control->resolveCellCheckbox($row, $col) ?? false;
                $this->drawCellCheckbox($hdc, $rc, $checked);
                break;
            case Table::TYPE_PROGRESS:
                $value = $control->resolveCellProgress($row, $col) ?? 0;
                $this->drawCellProgress($hdc, $rc, $value);
                break;
            case Table::TYPE_COLOR:
                $color = $control->resolveCellColor($row, $col);
                $this->drawCellColor($hdc, $rc, $color);
                break;
            case Table::TYPE_BUTTON:
                $text = $control->resolveCellButton($row, $col);
                $this->drawCellButton($hdc, $rc, $text);
                break;
        }
    }

    /**
     * 绘制 checkbox 单元格。
     *
     * 主题激活时用 DrawThemeBackground(BP_CHECKBOX=3, CBS_CHECKEDNORMAL=5 /
     * CBS_UNCHECKEDNORMAL=1) 渲染 Win11 风格复选框；否则回退到
     * DrawFrameControl(DFC_BUTTON | DFCS_BUTTONCHECK)。
     */
    private function drawCellCheckbox(\FFI\CData $hdc, \FFI\CData $rc, bool $checked): void
    {
        // checkbox 尺寸 16x16，垂直居中，左侧留 4px 间距
        $cx = 16;
        $cy = 16;
        $left = (int) $rc->left + 4;
        $top = (int) $rc->top + (((int) $rc->bottom - (int) $rc->top) - $cy) / 2;

        // 主题化路径：OpenThemeData("Button") + DrawThemeBackground
        if ($this->themeIsActive()) {
            try {
                $classList = $this->wideBufIn($this->uxtheme, 'Button');
                $hTheme = $this->uxtheme->OpenThemeData(null, \FFI::addr($classList[0]));
                if ($hTheme !== null && !\FFI::isNull($hTheme)) {
                    $cbRect = $this->uxtheme->new('RECT');
                    $cbRect->left = $left;
                    $cbRect->top = $top;
                    $cbRect->right = $left + $cx;
                    $cbRect->bottom = $top + $cy;
                    // BP_CHECKBOX=3, CBS_UNCHECKEDNORMAL=1, CBS_CHECKEDNORMAL=5
                    $stateId = $checked ? 5 : 1;
                    $hdcUx = $this->hdcToUxtheme($hdc);
                    $this->uxtheme->DrawThemeBackground(
                        $hTheme,
                        $hdcUx,
                        3,
                        $stateId,
                        \FFI::addr($cbRect),
                        null
                    );
                    $this->uxtheme->CloseThemeData($hTheme);
                    return;
                }
            } catch (\Throwable $e) {
                // 主题化失败，回退到 DrawFrameControl
            }
        }

        // 回退：DrawFrameControl DFC_BUTTON | DFCS_BUTTONCHECK
        $cbRect = $this->user32->new('RECT');
        $cbRect->left = $left;
        $cbRect->top = $top;
        $cbRect->right = $left + $cx;
        $cbRect->bottom = $top + $cy;

        $state = self::DFCS_BUTTONCHECK | ($checked ? self::DFCS_CHECKED : 0);
        $this->user32->DrawFrameControl($hdc, \FFI::addr($cbRect), self::DFC_BUTTON, $state);
    }

    /**
     * 绘制进度条单元格。
     *
     * 主题激活时用 OpenThemeData("Progress") + DrawThemeBackground(PP_BAR=1)
     * 绘制轨道，再 DrawThemeBackground(PP_FILL=2) 按进度裁剪填充；否则回退到
     * DrawEdge(EDGE_SUNKEN) + FillRect(COLOR_HIGHLIGHT)。
     */
    private function drawCellProgress(\FFI\CData $hdc, \FFI\CData $rc, int $value): void
    {
        $value = max(0, min(100, $value));
        $left = (int) $rc->left + 4;
        $right = (int) $rc->right - 4;
        $top = (int) $rc->top + 4;
        $bottom = (int) $rc->bottom - 4;
        $w = $right - $left;
        $h = $bottom - $top;
        if ($w <= 0 || $h <= 0) {
            return;
        }

        // 主题化路径：OpenThemeData("Progress") + DrawThemeBackground
        if ($this->themeIsActive()) {
            try {
                $classList = $this->wideBufIn($this->uxtheme, 'Progress');
                $hTheme = $this->uxtheme->OpenThemeData(null, \FFI::addr($classList[0]));
                if ($hTheme !== null && !\FFI::isNull($hTheme)) {
                    $hdcUx = $this->hdcToUxtheme($hdc);
                    // PP_BAR=1：整条轨道
                    $barRect = $this->uxtheme->new('RECT');
                    $barRect->left = $left;
                    $barRect->top = $top;
                    $barRect->right = $right;
                    $barRect->bottom = $bottom;
                    $this->uxtheme->DrawThemeBackground(
                        $hTheme, $hdcUx, 1, 0, \FFI::addr($barRect), null
                    );
                    // PP_FILL=2：按进度裁剪绘制填充块
                    $fillW = (int) ($w * $value / 100);
                    if ($fillW > 0) {
                        $fillRect = $this->uxtheme->new('RECT');
                        $fillRect->left = $left;
                        $fillRect->top = $top;
                        $fillRect->right = $left + $fillW;
                        $fillRect->bottom = $bottom;
                        $this->uxtheme->DrawThemeBackground(
                            $hTheme, $hdcUx, 2, 0, \FFI::addr($fillRect), null
                        );
                    }
                    $this->uxtheme->CloseThemeData($hTheme);
                    return;
                }
            } catch (\Throwable $e) {
                // 主题化失败，回退到 DrawEdge + FillRect
            }
        }

        // 回退：DrawEdge 外框 + FillRect 进度填充
        $frameRect = $this->user32->new('RECT');
        $frameRect->left = $left;
        $frameRect->top = $top;
        $frameRect->right = $right;
        $frameRect->bottom = $bottom;
        $this->user32->DrawEdge($hdc, \FFI::addr($frameRect), self::EDGE_SUNKEN, self::BF_RECT);

        $fillW = (int) ($w * $value / 100);
        if ($fillW > 0) {
            $fillRect = $this->user32->new('RECT');
            $fillRect->left = $left + 2;
            $fillRect->top = $top + 2;
            $fillRect->right = $left + 2 + $fillW;
            $fillRect->bottom = $bottom - 2;
            $hbr = $this->user32->GetSysColorBrush(self::COLOR_HIGHLIGHT);
            $this->user32->FillRect($hdc, \FFI::addr($fillRect), $hbr);
        }
    }

    /**
     * 绘制颜色块单元格（CreateSolidBrush + FillRect）。
     */
    private function drawCellColor(\FFI\CData $hdc, \FFI\CData $rc, ?\Kingbes\Ui\Graphics\Color $color): void
    {
        $left = (int) $rc->left + 4;
        $right = (int) $rc->right - 4;
        $top = (int) $rc->top + 4;
        $bottom = (int) $rc->bottom - 4;
        if ($right - $left <= 0 || $bottom - $top <= 0) {
            return;
        }

        $blockRect = $this->user32->new('RECT');
        $blockRect->left = $left;
        $blockRect->top = $top;
        $blockRect->right = $right;
        $blockRect->bottom = $bottom;

        // 颜色：null 用灰色，否则 RGB
        if ($color !== null) {
            $rgb = ($color->r & 0xFF) | (($color->g & 0xFF) << 8) | (($color->b & 0xFF) << 16);
        } else {
            $rgb = 0x808080;
        }
        // CreateSolidBrush/DeleteObject 在 gdi32 作用域；FillRect 在 user32 作用域。
        // 通过 int 中转把 gdi32 HBRUSH 传给 user32 FillRect（跨作用域 CData 不互通）。
        $hbrGdi = $this->gdi32->CreateSolidBrush($rgb);
        $hbrInt = $this->gdiPtrToInt($hbrGdi);
        $hbrUser = $this->intToPtrIn($this->user32, $hbrInt);
        try {
            $this->user32->FillRect($hdc, \FFI::addr($blockRect), $hbrUser);
            // 边框
            $this->user32->DrawEdge($hdc, \FFI::addr($blockRect), self::EDGE_SUNKEN, self::BF_RECT);
        } finally {
            $this->gdi32->DeleteObject($hbrGdi);
        }
    }

    /**
     * 绘制按钮单元格。
     *
     * 改进：
     *   - 用 DrawTextW(DT_CALCRECT) 预测量文字宽高
     *   - 按钮尺寸 = min(文字+padding, 格子-边距)，在格子内居中
     *   - 主题激活时用 DrawThemeBackground(BP_PUSHBUTTON=1, PBS_NORMAL=1)
     *     + DrawThemeText 渲染；否则回退到 DrawFrameControl + DrawTextW
     *   - 文本渲染加 DT_END_ELLIPSIS 防止溢出
     */
    private function drawCellButton(\FFI\CData $hdc, \FFI\CData $rc, string $text): void
    {
        $cellLeft = (int) $rc->left;
        $cellRight = (int) $rc->right;
        $cellTop = (int) $rc->top;
        $cellBottom = (int) $rc->bottom;
        $cellW = $cellRight - $cellLeft;
        $cellH = $cellBottom - $cellTop;
        if ($cellW <= 0 || $cellH <= 0) {
            return;
        }

        // 测量文字宽高（DrawTextW DT_CALCRECT 不渲染，仅计算）
        $textW = 0;
        $textH = 0;
        $chars = 0;
        $buf = null;
        if ($text !== '') {
            $wide = self::conv($text, 'UTF-16LE', 'UTF-8');
            $chars = intdiv(strlen($wide), 2);
            $buf = $this->user32->new('wchar_t[' . ($chars + 1) . ']');
            for ($i = 0; $i < $chars; $i++) {
                $buf[$i] = ord($wide[$i * 2]) | (ord($wide[$i * 2 + 1]) << 8);
            }
            $buf[$chars] = 0;

            $textRect = $this->user32->new('RECT');
            $textRect->left = 0;
            $textRect->top = 0;
            $textRect->right = 1000;
            $textRect->bottom = 1000;
            $this->user32->DrawTextW(
                $hdc,
                \FFI::addr($buf[0]),
                $chars,
                \FFI::addr($textRect),
                self::DT_CALCRECT | self::DT_SINGLELINE
            );
            $textW = (int) $textRect->right - (int) $textRect->left;
            $textH = (int) $textRect->bottom - (int) $textRect->top;
        }

        // 按钮尺寸：文字 + padding，不超出格子（留 4px 边距）
        $btnW = min($textW + 16, $cellW - 8);
        $btnH = min($textH + 6, $cellH - 6);
        if ($btnW < 0) { $btnW = 0; }
        if ($btnH < 0) { $btnH = 0; }
        // 居中
        $btnLeft = $cellLeft + (int) (($cellW - $btnW) / 2);
        $btnTop  = $cellTop  + (int) (($cellH - $btnH) / 2);

        $btnRect = $this->user32->new('RECT');
        $btnRect->left = $btnLeft;
        $btnRect->top = $btnTop;
        $btnRect->right = $btnLeft + $btnW;
        $btnRect->bottom = $btnTop + $btnH;

        // 主题化路径：OpenThemeData("Button") + DrawThemeBackground + DrawThemeText
        $useTheme = $this->themeIsActive();
        if ($useTheme) {
            try {
                $classList = $this->wideBufIn($this->uxtheme, 'Button');
                $hTheme = $this->uxtheme->OpenThemeData(null, \FFI::addr($classList[0]));
                if ($hTheme !== null && !\FFI::isNull($hTheme)) {
                    $hdcUx = $this->hdcToUxtheme($hdc);
                    $uxBtnRect = $this->rectToUxtheme($btnRect);
                    // BP_PUSHBUTTON=1, PBS_NORMAL=1
                    $this->uxtheme->DrawThemeBackground(
                        $hTheme, $hdcUx, 1, 1, \FFI::addr($uxBtnRect), null
                    );
                    if ($text !== '' && $buf !== null) {
                        // 在 uxtheme 作用域复制 wchar_t[] 缓冲（跨作用域不互通）
                        $uxBuf = $this->wideBufIn($this->uxtheme, $text);
                        // DT_CENTER|DT_VCENTER|DT_SINGLELINE|DT_END_ELLIPSIS
                        $flags = self::DT_CENTER | self::DT_VCENTER
                            | self::DT_SINGLELINE | self::DT_END_ELLIPSIS;
                        $this->uxtheme->DrawThemeText(
                            $hTheme,
                            $hdcUx,
                            1, 1,
                            \FFI::addr($uxBuf[0]),
                            -1,
                            $flags,
                            0,
                            \FFI::addr($uxBtnRect)
                        );
                    }
                    $this->uxtheme->CloseThemeData($hTheme);
                    return;
                }
            } catch (\Throwable $e) {
                // 主题化失败，回退到 DrawFrameControl
            }
        }

        // 回退：DrawFrameControl + DrawTextW
        $this->user32->DrawFrameControl(
            $hdc, \FFI::addr($btnRect), self::DFC_BUTTON,
            self::DFCS_BUTTONPUSH | self::DFCS_FLAT
        );

        if ($text !== '' && $buf !== null) {
            // SetBkMode 在 gdi32 作用域，HDC 来自 user32 作用域，通过 int 中转
            $hdcInt = $this->ptrToInt($hdc);
            $hdcGdi = $this->intToPtrIn($this->gdi32, $hdcInt);
            $this->gdi32->SetBkMode($hdcGdi, 1); // TRANSPARENT
            $this->user32->DrawTextW(
                $hdc,
                \FFI::addr($buf[0]),
                $chars,
                \FFI::addr($btnRect),
                self::DT_CENTER | self::DT_VCENTER
                | self::DT_SINGLELINE | self::DT_END_ELLIPSIS
            );
        }
    }

    /**
     * 处理 Table 的 NM_CLICK 通知：命中测试 + 触发 checkbox/button 回调。
     *
     * 通过 GetMessagePos 获取屏幕坐标，ScreenToClient 转为客户区坐标，
     * LVM_SUBITEMHITTEST 命中测试得到行/列，根据列类型触发对应回调。
     *
     * @param int $fromHwnd ListView HWND 的 int 表示（来自 hwndInt）。
     */
    private function handleTableClick(\Kingbes\Ui\Control\Table $control, int $fromHwnd): void
    {
        // GetMessagePos 返回屏幕坐标（低字 x，高字 y，16 位有符号）
        $pos = $this->user32->GetMessagePos();
        $x = ($pos & 0xFFFF);
        $y = (($pos >> 16) & 0xFFFF);
        // 符号扩展（多显示器负坐标）
        if ($x >= 0x8000) { $x -= 0x10000; }
        if ($y >= 0x8000) { $y -= 0x10000; }

        $pt = $this->user32->new('POINT');
        $pt->x = $x;
        $pt->y = $y;
        $hwndC = $this->intToHwnd($fromHwnd);
        $this->user32->ScreenToClient($hwndC, \FFI::addr($pt));

        $hti = $this->user32->new('LVHITTESTINFO');
        $hti->pt = $pt;
        $hti->flags = 0;
        $hti->iItem = -1;
        $hti->iSubItem = -1;
        $hti->iGroup = -1;

        $ret = (int) $this->user32->SendMessageW(
            $hwndC,
            self::LVM_SUBITEMHITTEST,
            0,
            $this->ptrToInt(\FFI::addr($hti))
        );
        if ($ret < 0) {
            return;
        }
        $row = (int) $hti->iItem;
        $col = (int) $hti->iSubItem;
        if ($row < 0 || $col < 0) {
            return;
        }

        $type = $control->getColumnType($col);
        if ($type === Table::TYPE_CHECKBOX) {
            try {
                $control->handleCellCheckboxToggle($row, $col);
            } catch (\Throwable $e) {
                trigger_error(
                    'handleCellCheckboxToggle error: ' . $e->getMessage(),
                    \E_USER_WARNING
                );
            }
        } elseif ($type === Table::TYPE_BUTTON) {
            try {
                $control->handleCellButtonClick($row, $col);
            } catch (\Throwable $e) {
                trigger_error(
                    'handleCellButtonClick error: ' . $e->getMessage(),
                    \E_USER_WARNING
                );
            }
        }
    }

    /**
     * WM_HSCROLL/WM_VSCROLL 分发。
     *
     *   - lParam != 0：控件滚动条（Slider 等），分发到对应控件的 onChanged。
     *   - lParam == 0 且 WM_VSCROLL：窗口垂直滚动条，更新 scrollPos 并重新布局。
     *     SB_THUMBTRACK/SB_THUMBPOSITION 的滚动位置在 HIWORD(wParam)（16 位有符号）。
     *
     * 注意：lParam==0 也可能来自 Slider 的标准滚动条，但 Slider 使用 SB_CTL
     * 类型且其消息 lParam 非 0，故此处 lParam==0 一律按窗口滚动条处理。
     *
     * @param int $hwndInt 接收消息的窗口句柄。
     */
    private function dispatchScroll(int $hwndInt, int $msg, int $wParam, int $lParam): int
    {
        // 窗口垂直滚动条
        if ($lParam === 0 && $msg === self::WM_VSCROLL) {
            return $this->handleWindowVScroll($hwndInt, $wParam);
        }
        if ($lParam === 0) {
            return 0; // 其他标准滚动条（如 WM_HSCROLL 窗口水平条），忽略
        }
        // 控件滚动条：Slider onChanged / onReleased
        $notification = $wParam & 0xFFFF;
        $control = $this->controls[$lParam] ?? null;
        if ($control !== null) {
            // SB_ENDSCROLL：拖动/操作结束，触发 onReleased
            if ($notification === self::SB_ENDSCROLL
                && property_exists($control, 'onReleased')
                && $control->onReleased !== null
            ) {
                try {
                    ($control->onReleased)();
                } catch (\Throwable $e) {
                    trigger_error(
                        'onReleased callback error: ' . $e->getMessage(),
                        \E_USER_WARNING
                    );
                }
                return 0;
            }
            // 其他通知：值变化，触发 onChanged
            // SpinBox 的 onChanged 由 UDN_DELTAPOS 异步处理（含手动同步 Edit 文本），
            // 此处跳过避免双重触发。
            if ($control instanceof \Kingbes\Ui\Control\SpinBox) {
                return 0;
            }
            if (property_exists($control, 'onChanged') && $control->onChanged !== null) {
                try {
                    ($control->onChanged)();
                } catch (\Throwable $e) {
                    trigger_error(
                        'onChanged callback error: ' . $e->getMessage(),
                        \E_USER_WARNING
                    );
                }
            }
        }
        return 0;
    }

    /**
     * 处理 Area 滚动条 WM_HSCROLL/WM_VSCROLL：更新 x/y 偏移并重绘。
     *
     * 与窗口滚动不同，Area 不重新布局（内容是用户在 onDraw 中按内容坐标系
     * 绘制的），只需更新 areaScrollInfo 的 x/y 并 Invalidate 触发重绘，
     * WM_PAINT 时由 GdipTranslateWorldTransform(-x, -y) 应用偏移。
     *
     * @param int $hwndInt Area 控件句柄。
     * @param int $msg     WM_HSCROLL 或 WM_VSCROLL。
     * @param int $wParam  滚动消息的 wParam（低字=通知码，高字=thumb 位置）。
     * @return int 始终返回 0（已处理）。
     */
    private function handleAreaScroll(int $hwndInt, int $msg, int $wParam): int
    {
        $info = $this->areaScrollInfo[$hwndInt] ?? null;
        if ($info === null) {
            return 0;
        }
        $notification = $wParam & 0xFFFF;
        $client = $this->controlClientSize($hwndInt);

        $isVert = ($msg === self::WM_VSCROLL);
        $contentSize = $isVert ? $info['h'] : $info['w'];
        $page = max(1, $isVert ? $client['h'] : $client['w']);
        $maxPos = max(0, $contentSize - ($isVert ? $client['h'] : $client['w']));
        $oldPos = $isVert ? $info['y'] : $info['x'];
        $newPos = $oldPos;

        switch ($notification) {
            case self::SB_LINEUP:
                $newPos = $oldPos - 16;
                break;
            case self::SB_LINEDOWN:
                $newPos = $oldPos + 16;
                break;
            case self::SB_PAGEUP:
                $newPos = $oldPos - $page;
                break;
            case self::SB_PAGEDOWN:
                $newPos = $oldPos + $page;
                break;
            case self::SB_THUMBTRACK:
            case self::SB_THUMBPOSITION:
                // 高字为 thumb 位置（16 位有符号）
                $newPos = ($wParam >> 16) & 0xFFFF;
                if ($newPos >= 0x8000) {
                    $newPos -= 0x10000;
                }
                break;
            case self::SB_TOP:
                $newPos = 0;
                break;
            case self::SB_BOTTOM:
                $newPos = $maxPos;
                break;
            case self::SB_ENDSCROLL:
                // 拖动/操作结束，无位置变化
                return 0;
        }

        $newPos = max(0, min($newPos, $maxPos));
        if ($newPos === $oldPos) {
            return 0;
        }

        if ($isVert) {
            $info['y'] = $newPos;
        } else {
            $info['x'] = $newPos;
        }
        $this->areaScrollInfo[$hwndInt] = $info;

        // 更新滚动条滑块位置
        $h = $this->intToHwnd($hwndInt);
        $si = $this->user32->new('SCROLLINFO');
        $si->cbSize = \FFI::sizeof($si);
        $si->fMask = self::SIF_POS;
        $si->nPos = $newPos;
        $this->user32->SetScrollInfo(
            $h,
            $isVert ? self::SB_VERT : self::SB_HORZ,
            \FFI::addr($si),
            1
        );

        // 触发重绘（应用新的滚动偏移）
        $this->user32->InvalidateRect($h, null, 1);
        return 0;
    }

    /**
     * 处理窗口垂直滚动条 WM_VSCROLL：更新 scrollPos 并触发重布局。
     *
     * SB_THUMBTRACK/SB_THUMBPOSITION 的目标位置在 HIWORD(wParam)，需
     * 符号扩展为有符号 16 位整数。
     *
     * @param int $hwndInt 窗口句柄。
     * @param int $wParam  WM_VSCROLL 的 wParam。
     * @return int 始终返回 0（已处理）。
     */
    private function handleWindowVScroll(int $hwndInt, int $wParam): int
    {
        $info = $this->windowScrollInfo[$hwndInt] ?? null;
        if ($info === null) {
            return 0;
        }

        $notification = $wParam & 0xFFFF;
        $client = $this->windowGetClientSize($hwndInt);
        $clientH = $client->height;
        $page = max(1, $clientH);
        $maxPos = max(0, $info['contentHeight'] - $clientH);
        $oldPos = $info['scrollPos'];
        $newPos = $oldPos;

        switch ($notification) {
            case self::SB_LINEUP:
                $newPos = $oldPos - 16;
                break;
            case self::SB_LINEDOWN:
                $newPos = $oldPos + 16;
                break;
            case self::SB_PAGEUP:
                $newPos = $oldPos - $page;
                break;
            case self::SB_PAGEDOWN:
                $newPos = $oldPos + $page;
                break;
            case self::SB_THUMBTRACK:
            case self::SB_THUMBPOSITION:
                // HIWORD(wParam) 为 16 位有符号整数
                $newPos = ($wParam >> 16) & 0xFFFF;
                if ($newPos >= 0x8000) {
                    $newPos -= 0x10000;
                }
                break;
            case self::SB_TOP:
                $newPos = 0;
                break;
            case self::SB_BOTTOM:
                $newPos = $maxPos;
                break;
            case self::SB_ENDSCROLL:
            default:
                return 0; // 不处理
        }

        // 钳位到 [0, maxPos]
        $newPos = max(0, min($newPos, $maxPos));
        if ($newPos === $oldPos) {
            return 0;
        }

        $info['scrollPos'] = $newPos;
        $this->windowScrollInfo[$hwndInt] = $info;
        $this->applyScrollInfo($hwndInt, $clientH);
        $this->triggerRelayout($hwndInt);
        return 0;
    }

    // ============================================================
    // 窗口方法
    // ============================================================

    public function windowCreate(string $title, int $width, int $height): int
    {
        $this->registerWindowClass();

        $classNameBuf = $this->utf8ToWide(self::WINDOW_CLASS_NAME);
        $titleBuf = $this->utf8ToWide($title);

        $hwnd = $this->user32->CreateWindowExW(
            0,
            \FFI::addr($classNameBuf[0]),
            \FFI::addr($titleBuf[0]),
            self::WS_OVERLAPPEDWINDOW | self::WS_CLIPCHILDREN,
            self::CW_USEDEFAULT,
            self::CW_USEDEFAULT,
            $width,
            $height,
            null, null, null, null
        );
        if ($hwnd === null || \FFI::isNull($hwnd)) {
            throw new \RuntimeException('CreateWindowExW failed');
        }

        $hwndInt = $this->hwndInt($hwnd);
        $this->user32->SetWindowLongPtrW($hwnd, self::GWLP_USERDATA, $hwndInt);

        // 设置默认字体
        $this->applyDefaultFont($hwnd);

        // DARK 主题下自动让标题栏变深色（DwmSetWindowAttribute）
        if ($this->currentTheme === Theme::DARK) {
            $this->setWindowDarkMode($hwndInt, true);
        }

        // Win11 圆角窗口 + Mica 云母背景（CLASSIC 主题跳过保持经典外观）
        if ($this->currentTheme !== Theme::CLASSIC) {
            $this->setWindowCornerPreference($hwndInt, true);
            $this->setWindowMica($hwndInt, true);
        }

        return $hwndInt;
    }

    public function windowDestroy(int $hwnd): void
    {
        $this->user32->DestroyWindow($this->intToHwnd($hwnd));
    }

    public function windowSetTitle(int $hwnd, string $title): void
    {
        $buf = $this->utf8ToWide($title);
        $this->user32->SetWindowTextW($this->intToHwnd($hwnd), \FFI::addr($buf[0]));
    }

    public function windowGetTitle(int $hwnd): string
    {
        $h = $this->intToHwnd($hwnd);
        $len = (int) $this->user32->GetWindowTextLengthW($h);
        if ($len <= 0) {
            return '';
        }
        $buf = $this->user32->new('wchar_t[' . ($len + 1) . ']');
        $this->user32->GetWindowTextW($h, $buf, $len + 1);
        return $this->wideToUtf8($buf);
    }

    public function windowSetPosition(int $hwnd, int $x, int $y): void
    {
        $this->user32->SetWindowPos(
            $this->intToHwnd($hwnd), null,
            $x, $y, 0, 0,
            self::SWP_NOSIZE | self::SWP_NOZORDER
        );
    }

    public function windowGetPosition(int $hwnd): Point
    {
        $rect = $this->user32->new('RECT');
        $this->user32->GetWindowRect($this->intToHwnd($hwnd), \FFI::addr($rect));
        return Point::of((int) $rect->left, (int) $rect->top);
    }

    public function windowSetSize(int $hwnd, int $width, int $height): void
    {
        $this->user32->SetWindowPos(
            $this->intToHwnd($hwnd), null,
            0, 0, $width, $height,
            self::SWP_NOMOVE | self::SWP_NOZORDER
        );
    }

    public function windowGetSize(int $hwnd): Size
    {
        $rect = $this->user32->new('RECT');
        $this->user32->GetWindowRect($this->intToHwnd($hwnd), \FFI::addr($rect));
        return Size::of((int) $rect->right - (int) $rect->left, (int) $rect->bottom - (int) $rect->top);
    }

    public function windowGetClientSize(int $hwnd): Size
    {
        $rect = $this->user32->new('RECT');
        $this->user32->GetClientRect($this->intToHwnd($hwnd), \FFI::addr($rect));
        return Size::of((int) $rect->right, (int) $rect->bottom);
    }

    public function windowSetFullscreen(int $hwnd, bool $fullscreen): void
    {
        $h = $this->intToHwnd($hwnd);
        if ($fullscreen) {
            if (isset($this->fullscreenState[$hwnd])) {
                return;
            }
            $rect = $this->user32->new('RECT');
            $this->user32->GetWindowRect($h, \FFI::addr($rect));
            $style = (int) $this->user32->GetWindowLongPtrW($h, self::GWL_STYLE);
            $this->fullscreenState[$hwnd] = [
                (int) $rect->left, (int) $rect->top,
                (int) $rect->right, (int) $rect->bottom,
                $style,
            ];
            $this->user32->SetWindowLongPtrW($h, self::GWL_STYLE, self::WS_POPUP | self::WS_VISIBLE);
            $sw = (int) $this->user32->GetSystemMetrics(self::SM_CXSCREEN);
            $sh = (int) $this->user32->GetSystemMetrics(self::SM_CYSCREEN);
            $this->user32->SetWindowPos(
                $h, null, 0, 0, $sw, $sh,
                self::SWP_FRAMECHANGED | self::SWP_NOZORDER
            );
        } else {
            $state = $this->fullscreenState[$hwnd] ?? null;
            if ($state === null) {
                return;
            }
            unset($this->fullscreenState[$hwnd]);
            $this->user32->SetWindowLongPtrW($h, self::GWL_STYLE, $state[4]);
            $w = $state[2] - $state[0];
            $hgt = $state[3] - $state[1];
            $this->user32->SetWindowPos(
                $h, null, $state[0], $state[1], $w, $hgt,
                self::SWP_FRAMECHANGED | self::SWP_NOZORDER
            );
        }
    }

    public function windowSetBorderless(int $hwnd, bool $borderless): void
    {
        $h = $this->intToHwnd($hwnd);
        $style = (int) $this->user32->GetWindowLongPtrW($h, self::GWL_STYLE);
        if ($borderless) {
            $style &= ~(self::WS_CAPTION | self::WS_THICKFRAME);
        } else {
            $style |= (self::WS_CAPTION | self::WS_THICKFRAME);
        }
        $this->user32->SetWindowLongPtrW($h, self::GWL_STYLE, $style);
        $this->user32->SetWindowPos(
            $h, null, 0, 0, 0, 0,
            self::SWP_FRAMECHANGED | self::SWP_NOMOVE | self::SWP_NOSIZE | self::SWP_NOZORDER
        );
        $this->triggerRelayout($hwnd);
    }

    public function windowSetResizeable(int $hwnd, bool $resizeable): void
    {
        $h = $this->intToHwnd($hwnd);
        $style = (int) $this->user32->GetWindowLongPtrW($h, self::GWL_STYLE);
        if ($resizeable) {
            $style |= self::WS_THICKFRAME;
        } else {
            $style &= ~self::WS_THICKFRAME;
        }
        $this->user32->SetWindowLongPtrW($h, self::GWL_STYLE, $style);
        $this->user32->SetWindowPos(
            $h, null, 0, 0, 0, 0,
            self::SWP_FRAMECHANGED | self::SWP_NOMOVE | self::SWP_NOSIZE | self::SWP_NOZORDER
        );
    }

    public function windowMaximize(int $hwnd): void
    {
        $this->user32->ShowWindow($this->intToHwnd($hwnd), self::SW_MAXIMIZE);
    }

    public function windowMinimize(int $hwnd): void
    {
        $this->user32->ShowWindow($this->intToHwnd($hwnd), self::SW_MINIMIZE);
    }

    public function windowRestore(int $hwnd): void
    {
        $this->user32->ShowWindow($this->intToHwnd($hwnd), self::SW_RESTORE);
    }

    public function windowShow(int $hwnd): void
    {
        $this->user32->ShowWindow($this->intToHwnd($hwnd), self::SW_SHOW);
        $this->user32->UpdateWindow($this->intToHwnd($hwnd));
    }

    public function windowHide(int $hwnd): void
    {
        $this->user32->ShowWindow($this->intToHwnd($hwnd), self::SW_HIDE);
    }

    public function windowSetTopmost(int $hwnd, bool $topmost): void
    {
        $insertAfter = $topmost ? self::HWND_TOPMOST : self::HWND_NOTOPMOST;
        $this->user32->SetWindowPos(
            $this->intToHwnd($hwnd),
            $this->intToHwnd($insertAfter),
            0, 0, 0, 0,
            self::SWP_NOMOVE | self::SWP_NOSIZE
        );
    }

    public function windowSetChild(int $hwnd, int $childHwnd): void
    {
        $this->user32->SetParent($this->intToHwnd($childHwnd), $this->intToHwnd($hwnd));
    }

    public function windowSetScrollable(int $hwnd, int $contentHeight): void
    {
        $h = $this->intToHwnd($hwnd);
        // 1. 添加 WS_VSCROLL 样式
        $style = (int) $this->user32->GetWindowLongPtrW($h, self::GWL_STYLE);
        $style |= self::WS_VSCROLL;
        $this->user32->SetWindowLongPtrW($h, self::GWL_STYLE, $style);
        $this->user32->SetWindowPos(
            $h, null, 0, 0, 0, 0,
            self::SWP_FRAMECHANGED | self::SWP_NOMOVE | self::SWP_NOSIZE | self::SWP_NOZORDER
        );

        // 2. 记录滚动状态
        $client = $this->windowGetClientSize($hwnd);
        $this->windowScrollInfo[$hwnd] = [
            'contentHeight' => max(0, $contentHeight),
            'scrollPos'     => 0,
        ];

        // 3. 设置 SCROLLINFO（range=[0, contentHeight], page=clientHeight）
        $this->applyScrollInfo($hwnd, $client->height);

        // 4. 异步触发重布局（用 contentHeight + scrollPos 偏移）
        $this->triggerRelayout($hwnd);
    }

    /**
     * 应用 SCROLLINFO 到窗口垂直滚动条。
     *
     * fMask = SIF_RANGE | SIF_PAGE | SIF_POS：设置范围、页面大小、当前位置。
     * nMax 取 contentHeight；nPage 取 clientHeight（一页可见高度）。
     * 当 contentHeight <= clientHeight 时 Windows 自动隐藏滚动条。
     *
     * @param int $hwnd          窗口句柄。
     * @param int $clientHeight  客户区高度（用于 nPage）。
     */
    private function applyScrollInfo(int $hwnd, int $clientHeight): void
    {
        $info = $this->windowScrollInfo[$hwnd] ?? null;
        if ($info === null) {
            return;
        }
        $si = $this->user32->new('SCROLLINFO');
        $si->cbSize = \FFI::sizeof($si);
        $si->fMask = self::SIF_RANGE | self::SIF_PAGE | self::SIF_POS;
        $si->nMin = 0;
        $si->nMax = $info['contentHeight'];
        $si->nPage = max(0, $clientHeight);
        $si->nPos = $info['scrollPos'];
        $si->nTrackPos = 0;
        $this->user32->SetScrollInfo(
            $this->intToHwnd($hwnd),
            self::SB_VERT,
            \FFI::addr($si),
            1 // redraw
        );
    }

    /**
     * 立即对窗口的顶层容器执行重布局（同步）。
     *
     * 覆盖 AbstractPlatform 实现：当窗口启用了滚动条（windowScrollInfo 中
     * 存在该 hwnd）时，将顶层容器的位置设为 (0, -scrollPos)，高度设为
     * contentHeight，使子控件随滚动条偏移；否则退化为父类默认行为
     * （容器位置 (0,0)，使用客户区尺寸）。
     *
     * 不使用 ScrollWindowEx：避免 WM_SIZE 重布局时丢失滚动位置。
     */
    protected function relayoutWindowNow(int $hwnd): void
    {
        $window = $this->windows[$hwnd] ?? null;
        if ($window === null) {
            return;
        }
        if (!method_exists($window, 'getChildContainer')) {
            return;
        }
        $container = $window->getChildContainer();
        if ($container === null) {
            return;
        }
        if (!method_exists($container, 'layout')) {
            return;
        }
        $size = $this->windowGetClientSize($hwnd);
        $info = $this->windowScrollInfo[$hwnd] ?? null;

        // 窗口内边距（Window::getMargined）。若窗口未设置该方法则视为 0。
        $margin = method_exists($window, 'getMargined') ? (int) $window->getMargined() : 0;
        $margin = max(0, $margin);

        // 关键：先设置顶层 Container 自身的位置和尺寸到 Window 客户区。
        // controlCreate 创建 Container 时初始尺寸为 0x0，若不显式设置，
        // 子控件虽在 layout 中被 setBounds 到 Container 客户区坐标，
        // 但 Container 客户区为 0x0 会裁剪掉所有子控件，导致窗口空白。
        //
        // margined：将 Container 位置偏移到 (margin, margin)，尺寸收缩
        // 2*margin，使子控件与窗口边框保持视觉间距。
        $containerHwnd = $container->getHwnd();
        if ($containerHwnd !== 0) {
            if ($info === null) {
                $this->controlSetBounds(
                    $containerHwnd,
                    $margin,
                    $margin,
                    max(0, $size->width - 2 * $margin),
                    max(0, $size->height - 2 * $margin)
                );
            } else {
                // 有滚动：将容器自身位置偏移到 (margin, margin-scrollPos)，
                // 宽度收缩 2*margin，高度设为 contentHeight-2*margin。
                $this->controlSetBounds(
                    $containerHwnd,
                    $margin,
                    $margin - $info['scrollPos'],
                    max(0, $size->width - 2 * $margin),
                    max(0, $info['contentHeight'] - 2 * $margin)
                );
            }
        }

        if ($info === null) {
            // 无滚动：默认布局
            $container->layout(0, 0, max(0, $size->width - 2 * $margin), max(0, $size->height - 2 * $margin));
            return;
        }
        $container->layout(0, 0, max(0, $size->width - 2 * $margin), max(0, $info['contentHeight'] - 2 * $margin));
    }

    public function windowIsFocused(int $hwnd): bool
    {
        $fg = $this->user32->GetForegroundWindow();
        if ($fg === null) {
            return false;
        }
        return $this->hwndInt($fg) === $hwnd;
    }

    public function windowSetMenu(int $hwnd, int $menuHwnd): void
    {
        $this->user32->SetMenu($this->intToHwnd($hwnd), $this->intToHwnd($menuHwnd));
        $this->user32->SetWindowPos(
            $this->intToHwnd($hwnd), null,
            0, 0, 0, 0,
            self::SWP_FRAMECHANGED | self::SWP_NOMOVE | self::SWP_NOSIZE | self::SWP_NOZORDER
        );
        // 刷新菜单栏显示
        $this->user32->DrawMenuBar($this->intToHwnd($hwnd));
        // SWP_NOSIZE 抑制 WM_SIZE，需异步触发重布局以适应菜单栏占用的客户区变化
        $this->triggerRelayout($hwnd);
    }

    /**
     * 从 .ico 文件加载窗口图标（大图标 + 小图标）。
     */
    public function windowSetIconFromFile(int $hwnd, string $file): void
    {
        if (!is_file($file)) {
            trigger_error('Icon file not found: ' . $file, \E_USER_WARNING);
            return;
        }
        $hiconLarge = $this->loadIconFromFile($file, 0, 0);  // 0=原始尺寸
        $hiconSmall = $this->loadIconFromFile($file, 16, 16);
        $hwndC = $this->intToHwnd($hwnd);
        if ($hiconLarge !== 0) {
            $this->user32->SendMessageW($hwndC, self::WM_SETICON, self::ICON_BIG, $hiconLarge);
        }
        if ($hiconSmall !== 0) {
            $this->user32->SendMessageW($hwndC, self::WM_SETICON, self::ICON_SMALL, $hiconSmall);
        }
        // 触发重绘
        $this->user32->SetWindowPos(
            $hwndC, null, 0, 0, 0, 0,
            self::SWP_FRAMECHANGED | self::SWP_NOMOVE | self::SWP_NOSIZE | self::SWP_NOZORDER
        );
        // loadIconFromFile 已返回 int 句柄，无需额外释放（系统图标共享，文件图标由系统管理）
    }

    /**
     * 设置窗口图标为预定义系统图标。
     */
    public function windowSetIconFromId(int $hwnd, int $iconId): void
    {
        $hicon = $this->user32->LoadIconW(null, $iconId);
        if ($hicon === null || \FFI::isNull($hicon)) {
            return;
        }
        // HICON CData → int（SendMessageW 的 wParam/lParam 为 long long 标量）
        $hiconInt = $this->ptrToInt($hicon);
        $hwndC = $this->intToHwnd($hwnd);
        // 系统图标同时用于大/小
        $this->user32->SendMessageW($hwndC, self::WM_SETICON, self::ICON_BIG, $hiconInt);
        $this->user32->SendMessageW($hwndC, self::WM_SETICON, self::ICON_SMALL, $hiconInt);
        $this->user32->SetWindowPos(
            $hwndC, null, 0, 0, 0, 0,
            self::SWP_FRAMECHANGED | self::SWP_NOMOVE | self::SWP_NOSIZE | self::SWP_NOZORDER
        );
    }

    /**
     * 从 Image 对象设置窗口图标（PNG/JPEG/BMP/GIF/TIFF 任意 GDI+ 格式）。
     *
     * 内部调用 iconCreateFromImage 生成 HICON，然后用 WM_SETICON
     * 同时设置 ICON_BIG 和 ICON_SMALL。HICON 由窗口持有，
     * 下次调用前 / 窗口销毁时调用 destroyIconInt 释放。
     */
    public function windowSetIconFromImage(int $hwnd, object $image): void
    {
        $hiconInt = $this->iconCreateFromImage($image);
        if ($hiconInt === 0) {
            return;
        }
        $hwndC = $this->intToHwnd($hwnd);
        // 先释放上次 Image 图标（若有）
        if (isset($this->windowImageIcons[$hwnd])) {
            $this->destroyIconInt($this->windowImageIcons[$hwnd]);
        }
        $this->windowImageIcons[$hwnd] = $hiconInt;

        $this->user32->SendMessageW($hwndC, self::WM_SETICON, self::ICON_BIG, $hiconInt);
        $this->user32->SendMessageW($hwndC, self::WM_SETICON, self::ICON_SMALL, $hiconInt);
        $this->user32->SetWindowPos(
            $hwndC, null, 0, 0, 0, 0,
            self::SWP_FRAMECHANGED | self::SWP_NOMOVE | self::SWP_NOSIZE | self::SWP_NOZORDER
        );
    }

    // ============================================================
    // 系统托盘（Shell_NotifyIconW）
    // ============================================================

    public function registerTrayIcon(TrayIcon $tray): void
    {
        $id = $this->nextTrayId++;
        $tray->_setTrayId($id);
        $this->trayIcons[$id] = $tray;
    }

    public function unregisterTrayIcon(TrayIcon $tray): void
    {
        $id = $tray->getTrayId();
        unset($this->trayIcons[$id]);
    }

    public function trayAdd(int $hwnd, int $trayId, int $hiconInt, string $tooltip): void
    {
        $nid = $this->shell32->new('NOTIFYICONDATAW');
        $nid->cbSize = \FFI::sizeof($nid);
        $nid->hWnd = $this->intToShellHwnd($hwnd);
        $nid->uID = $trayId;
        $nid->uFlags = self::NIF_MESSAGE | self::NIF_ICON | self::NIF_TIP;
        $nid->uCallbackMessage = self::WM_TRAYICON;
        $nid->hIcon = $this->intToShellHicon($hiconInt);
        $this->writeWideStringField($nid->szTip, $tooltip, 128);

        $this->shell32->Shell_NotifyIconW(self::NIM_ADD, \FFI::addr($nid));
    }

    public function trayModify(int $hwnd, int $trayId, int $hiconInt, string $tooltip): void
    {
        $nid = $this->shell32->new('NOTIFYICONDATAW');
        $nid->cbSize = \FFI::sizeof($nid);
        $nid->hWnd = $this->intToShellHwnd($hwnd);
        $nid->uID = $trayId;
        $nid->uFlags = self::NIF_ICON | self::NIF_TIP;
        $nid->hIcon = $this->intToShellHicon($hiconInt);
        $this->writeWideStringField($nid->szTip, $tooltip, 128);

        $this->shell32->Shell_NotifyIconW(self::NIM_MODIFY, \FFI::addr($nid));
    }

    public function trayRemove(int $hwnd, int $trayId): void
    {
        $nid = $this->shell32->new('NOTIFYICONDATAW');
        $nid->cbSize = \FFI::sizeof($nid);
        $nid->hWnd = $this->intToShellHwnd($hwnd);
        $nid->uID = $trayId;
        $nid->uFlags = 0;

        $this->shell32->Shell_NotifyIconW(self::NIM_DELETE, \FFI::addr($nid));
    }

    public function trayShowBalloon(int $hwnd, int $trayId, string $title, string $message, int $type, int $timeoutMs): void
    {
        $nid = $this->shell32->new('NOTIFYICONDATAW');
        $nid->cbSize = \FFI::sizeof($nid);
        $nid->hWnd = $this->intToShellHwnd($hwnd);
        $nid->uID = $trayId;
        $nid->uFlags = self::NIF_INFO;
        $this->writeWideStringField($nid->szInfo, $message, 256);
        $this->writeWideStringField($nid->szInfoTitle, $title, 64);
        $nid->DUMMYUNIONNAME->uTimeout = $timeoutMs;
        // 映射 BALLOON_* → NIIF_*
        $niifMap = [
            0 => self::NIIF_NONE,
            1 => self::NIIF_INFO,
            2 => self::NIIF_WARNING,
            3 => self::NIIF_ERROR,
        ];
        $nid->dwInfoFlags = $niifMap[$type] ?? self::NIIF_INFO;

        $this->shell32->Shell_NotifyIconW(self::NIM_MODIFY, \FFI::addr($nid));
    }

    public function trayShowContextMenu(int $hwnd, int $menuHwnd): void
    {
        // 必须在前台状态才能正确弹出菜单（Windows 限制）
        $hwndC = $this->intToHwnd($hwnd);
        $this->user32->SetForegroundWindow($hwndC);

        // 获取鼠标光标位置
        $pos = $this->user32->GetMessagePos();
        $x = $pos & 0xFFFF;
        $y = ($pos >> 16) & 0xFFFF;
        if ($x >= 0x8000) { $x -= 0x10000; }
        if ($y >= 0x8000) { $y -= 0x10000; }

        // 弹出菜单（TPM_RETURNCMD 返回选中项 ID，但我们用 WM_COMMAND 分发，所以不用返回值）
        $this->user32->TrackPopupMenu(
            $this->intToHwnd($menuHwnd),
            self::TPM_RIGHTBUTTON | self::TPM_LEFTALIGN,
            $x, $y,
            0,
            $hwndC,
            null
        );

        // Windows 已知 bug：TrackPopupMenu 返回后必须 PostMessage(WM_NULL)，
        // 否则菜单首次点击可能不响应（仅 SetForegroundWindow 不够）。
        $this->user32->PostMessageW($hwndC, self::WM_NULL, 0, 0);
    }

    public function loadIconFromFile(string $file, int $cx = 0, int $cy = 0): int
    {
        $wide = $this->utf8ToWide($file);
        $hicon = $this->user32->LoadImageW(
            null,
            \FFI::addr($wide[0]),
            self::IMAGE_ICON_LOAD,
            $cx, $cy,
            self::LR_LOADFROMFILE
        );
        if ($hicon === null || \FFI::isNull($hicon)) {
            return 0;
        }
        return $this->ptrToInt($hicon);
    }

    public function loadSystemIcon(int $iconId): int
    {
        $hicon = $this->user32->LoadIconW(null, $iconId);
        if ($hicon === null || \FFI::isNull($hicon)) {
            return 0;
        }
        return $this->ptrToInt($hicon);
    }

    /**
     * 从 Image 对象（GDI+ GpImage）创建 HICON。
     *
     * 内部流程：
     *   1. 取 Image::getGpImage() 得到 gdiplus 作用域的 GpImage CData
     *   2. 用 GdipGetImageType 检查类型（必须是 Bitmap=1）
     *   3. 调用 GdipCreateHICONFromBitmap 转换为 HICON
     *   4. 转为 int 句柄返回（调用方需 DestroyIcon 释放）
     *
     * @param object $image Kingbes\Ui\Graphics\Image 实例。
     * @return int HICON int 句柄，失败返回 0。
     */
    public function iconCreateFromImage(object $image): int
    {
        if (!method_exists($image, 'getGpImage')) {
            return 0;
        }
        $gpImage = $image->getGpImage();
        $gp = $this->getGdiplus();

        // 检查图像类型（仅 Bitmap=1 可转 HICON，Metafile 等不支持）
        $type = $gp->new('int');
        $status = (int) $gp->GdipGetImageType($gpImage, \FFI::addr($type));
        if ($status !== 0 || (int) $type->cdata !== 1) {
            return 0;
        }

        // GpImage → HICON（GDI+ 内部会处理 alpha 通道）
        $hiconPtr = $gp->new('void*');
        $status = (int) $gp->GdipCreateHICONFromBitmap($gpImage, \FFI::addr($hiconPtr));
        if ($status !== 0 || $hiconPtr === null || \FFI::isNull($hiconPtr)) {
            return 0;
        }

        // void* → int（gdiplus 作用域内用 INT_TO_PTR）
        $caster = $gp->new('INT_TO_PTR');
        $caster->p = $hiconPtr;
        return (int) $caster->i;
    }

    public function destroyIconInt(int $hiconInt): void
    {
        if ($hiconInt === 0) {
            return;
        }
        $caster = $this->user32->new('INT_TO_PTR');
        $caster->i = $hiconInt;
        $this->user32->DestroyIcon($caster->p);
    }

    /**
     * 内部：处理托盘回调消息。
     *
     * WM_TRAYICON 回调约定：
     *   wParam = 托盘 uID
     *   lParam = 鼠标消息类型（WM_LBUTTONUP=0x0202 / WM_LBUTTONDBLCLK=0x0203 / WM_RBUTTONUP=0x0205 等）
     *
     * @param int $hwndInt 窗口句柄（未使用，预留）。
     * @param int $wParam  托盘 uID。
     * @param int $lParam  鼠标消息类型。
     */
    private function handleTrayCallback(int $hwndInt, int $wParam, int $lParam): void
    {
        $trayId = $wParam;
        $mouseMsg = $lParam;
        $tray = $this->trayIcons[$trayId] ?? null;
        if ($tray === null) {
            return;
        }
        try {
            $tray->handleCallback($mouseMsg);
        } catch (\Throwable $e) {
            trigger_error('TrayIcon callback error: ' . $e->getMessage(), \E_USER_WARNING);
        }
    }

    /**
     * 内部：写入 wchar_t 数组字段（UTF-8 → UTF-16LE 填充，零结尾）。
     *
     * @param \FFI\CData $field  wchar_t[] CData 数组。
     * @param string     $text   UTF-8 文本。
     * @param int        $maxLen 数组最大长度（含末尾 \0）。
     */
    private function writeWideStringField(\FFI\CData $field, string $text, int $maxLen): void
    {
        $wide = self::conv($text, 'UTF-16LE', 'UTF-8');
        $chars = intdiv(strlen($wide), 2);
        $copyLen = min($chars, $maxLen - 1);
        for ($i = 0; $i < $copyLen; $i++) {
            $field[$i] = ord($wide[$i * 2]) | (ord($wide[$i * 2 + 1]) << 8);
        }
        $field[$copyLen] = 0;
    }

    /**
     * 内部：int → shell32 作用域 HWND。
     */
    private function intToShellHwnd(int $hwnd): \FFI\CData
    {
        $caster = $this->shell32->new('INT_TO_PTR');
        $caster->i = $hwnd;
        return $caster->p;
    }

    /**
     * 内部：int → shell32 作用域 HICON。
     */
    private function intToShellHicon(int $hicon): \FFI\CData
    {
        $caster = $this->shell32->new('INT_TO_PTR');
        $caster->i = $hicon;
        return $caster->p;
    }

    // ============================================================
    // Task 7: 事件循环与 queueMain
    // ============================================================

    public function run(): void
    {
        $this->running = true;
        $msg = $this->user32->new('MSG');
        $msgAddr = \FFI::addr($msg);

        while ($this->running) {
            $has = (int) $this->user32->PeekMessageW($msgAddr, null, 0, 0, self::PM_REMOVE);
            if ($has) {
                $msgId = (int) $msg->message;
                if ($msgId === self::WM_QUIT) {
                    break;
                }

                // 拦截控件键盘消息，分发到 onKeyDown/onKeyUp
                if ($msgId === self::WM_KEYDOWN || $msgId === self::WM_KEYUP) {
                    $this->dispatchControlKey($msg, $msgId);
                }

                $this->user32->TranslateMessage($msgAddr);
                $this->user32->DispatchMessageW($msgAddr);
            } else {
                $this->runTimers();
                $this->runQueueMain();
                usleep(1000);
            }
        }

        $this->running = false;
    }

    /**
     * 在消息循环中拦截 WM_KEYDOWN/WM_KEYUP，分发到控件的键盘回调。
     */
    private function dispatchControlKey(\FFI\CData $msg, int $msgId): void
    {
        $hwndInt = $this->hwndInt($msg->hwnd);
        $control = $this->controls[$hwndInt] ?? null;
        if ($control === null) {
            return;
        }
        $keyCode = (int) $msg->wParam;

        if ($msgId === self::WM_KEYDOWN) {
            if ($control->onKeyDown !== null) {
                try {
                    ($control->onKeyDown)(new KeyEvent($keyCode));
                } catch (\Throwable $e) {
                    trigger_error(
                        'onKeyDown callback error: ' . $e->getMessage(),
                        \E_USER_WARNING
                    );
                }
            }
            // Entry onEnter (VK_RETURN)
            if ($keyCode === self::VK_RETURN && $control instanceof Entry) {
                if ($control->onEnter !== null) {
                    try {
                        ($control->onEnter)();
                    } catch (\Throwable $e) {
                        trigger_error(
                            'onEnter callback error: ' . $e->getMessage(),
                            \E_USER_WARNING
                        );
                    }
                }
            }
        } else { // WM_KEYUP
            if ($control->onKeyUp !== null) {
                try {
                    ($control->onKeyUp)(new KeyEvent($keyCode));
                } catch (\Throwable $e) {
                    trigger_error(
                        'onKeyUp callback error: ' . $e->getMessage(),
                        \E_USER_WARNING
                    );
                }
            }
        }
    }

    public function quit(): void
    {
        if (!$this->shouldQuit()) {
            return;
        }
        $this->user32->PostQuitMessage(0);
    }

    // ============================================================
    // 系统服务
    // ============================================================

    public function screenSize(): Size
    {
        $w = (int) $this->user32->GetSystemMetrics(self::SM_CXSCREEN);
        $h = (int) $this->user32->GetSystemMetrics(self::SM_CYSCREEN);
        return Size::of($w, $h);
    }

    public function clipboardSetText(string $text): void
    {
        $wide = self::conv($text, 'UTF-16LE', 'UTF-8');
        $byteCount = strlen($wide) + 2; // 含 \0\0 终止

        $hMem = $this->kernel32->GlobalAlloc(self::GMEM_MOVEABLE, $byteCount);
        if ($hMem === null) {
            return;
        }
        $ptr = $this->kernel32->GlobalLock($hMem);
        if ($ptr !== null) {
            if (strlen($wide) > 0) {
                \FFI::memcpy($ptr, $wide, strlen($wide));
            }
            $ptr[strlen($wide)] = "\0";
            $ptr[strlen($wide) + 1] = "\0";
            $this->kernel32->GlobalUnlock($hMem);
        }

        if (!$this->user32->OpenClipboard(null)) {
            $this->kernel32->GlobalFree($hMem);
            return;
        }
        $this->user32->EmptyClipboard();
        $this->user32->SetClipboardData(self::CF_UNICODETEXT, $hMem);
        $this->user32->CloseClipboard();
    }

    public function clipboardGetText(): string
    {
        if (!$this->user32->OpenClipboard(null)) {
            return '';
        }
        $data = $this->user32->GetClipboardData(self::CF_UNICODETEXT);
        $this->user32->CloseClipboard();

        if ($data === null || \FFI::isNull($data)) {
            return '';
        }
        $ptr = $this->kernel32->GlobalLock($data);
        if ($ptr === null) {
            return '';
        }
        // 读取 wchar_t 数据直到双 \0
        $bytes = '';
        $i = 0;
        while (true) {
            $b1 = $ptr[$i];
            $b2 = $ptr[$i + 1];
            if ($b1 === "\0" && $b2 === "\0") {
                break;
            }
            $bytes .= $b1 . $b2;
            $i += 2;
            if ($i > 200000) {
                break;
            }
        }
        $this->kernel32->GlobalUnlock($data);
        return self::conv($bytes, 'UTF-8', 'UTF-16LE');
    }

    // ============================================================
    // Task 9: 控件创建通用机制
    // ============================================================

    public function controlCreate(string $className, string $text, int $style, int $exStyle, int $parentHwnd, int $id): int
    {
        $this->registerWindowClass();

        // 分配控件 ID
        if ($id === 0) {
            $id = $this->nextControlId++;
        }

        // 'Container' → 用已注册的 PhpUiWindow 类
        if ($className === 'Container') {
            $classBuf = $this->utf8ToWide(self::WINDOW_CLASS_NAME);
            $fullStyle = self::WS_CHILD | self::WS_VISIBLE | self::WS_CLIPCHILDREN | $style;
        } else {
            $classBuf = $this->utf8ToWide($className);
            $fullStyle = self::WS_CHILD | self::WS_VISIBLE | $style;
        }

        $textBuf = $this->utf8ToWide($text);
        $parent = $parentHwnd !== 0 ? $this->intToHwnd($parentHwnd) : null;
        $menu = $this->intToHwnd($id);

        // ComboBox 下拉列表高度由控件初始高度决定，创建时高度为 0 会导致
        // 下拉列表无法展开。CBS_DROPDOWNLIST 默认下拉约 8 项，此处给 200 像素。
        $initHeight = 0;
        $initWidth = 0;
        if ($className === 'ComboBox') {
            $initHeight = 200;
        }

        $hwnd = $this->user32->CreateWindowExW(
            $exStyle,
            \FFI::addr($classBuf[0]),
            $text === '' ? null : \FFI::addr($textBuf[0]),
            $fullStyle,
            0, 0, $initWidth, $initHeight,
            $parent,
            $menu,
            null, null
        );
        if ($hwnd === null || \FFI::isNull($hwnd)) {
            throw new \RuntimeException('CreateWindowExW failed for class "' . $className . '"');
        }

        // 设置默认字体
        $this->applyDefaultFont($hwnd);

        // Task 6：非 CLASSIC 主题应用现代字体（Segoe UI 9pt）覆盖默认字体。
        // hFont 是 gdi32 作用域 CData，转 int 后作为 WM_SETFONT 的 WPARAM。
        if ($this->hFont !== null) {
            $fontInt = $this->gdiPtrToInt($this->hFont);
            $this->user32->SendMessageW($hwnd, self::WM_SETFONT, $fontInt, 1);
        }

        // Task 3：非 CLASSIC 主题时对标准控件应用 "Explorer" 子样式，
        // 呈现资源管理器风格的浅色高亮与 Windows 11 圆角行选择，
        // 并使 Entry/TextArea/PasswordEntry/SpinBox/ListBox 等去掉 3D 边框后
        // 获得 Explorer 风格的扁平边框。
        // SetWindowTheme 在 uxtheme 作用域；hwnd 需从 user32→int→uxtheme 跨域转换，
        // "Explorer" 字符串也需在 uxtheme 作用域内构建 wchar_t[]。
        if ($this->uxtheme !== null && $this->currentTheme !== Theme::CLASSIC) {
            $explorerClasses = [
                'SysListView32', 'SysTreeView32',
                'Button', 'Edit', 'Static', 'ComboBox', 'ListBox',
                'msctls_updown32', 'msctls_progress32', 'msctls_trackbar32',
            ];
            if (in_array($className, $explorerClasses, true)) {
                try {
                    $hwndIntTmp = $this->hwndInt($hwnd);
                    $hwndUx = $this->intToPtrIn($this->uxtheme, $hwndIntTmp);
                    $subApp = $this->wideBufIn($this->uxtheme, 'Explorer');
                    $this->uxtheme->SetWindowTheme(
                        $hwndUx,
                        \FFI::addr($subApp[0]),
                        null
                    );
                } catch (\Throwable $e) {
                    // SetWindowTheme 不可用（旧系统），静默降级到经典外观
                }
            }
        }

        $hwndInt = $this->hwndInt($hwnd);
        $this->controlTypes[$hwndInt] = $className;

        return $hwndInt;
    }

    public function controlDestroy(int $hwnd): void
    {
        $this->user32->DestroyWindow($this->intToHwnd($hwnd));
        unset($this->controlTypes[$hwnd]);
    }

    public function controlSetText(int $hwnd, string $text): void
    {
        $buf = $this->utf8ToWide($text);
        $this->user32->SetWindowTextW($this->intToHwnd($hwnd), \FFI::addr($buf[0]));
    }

    public function controlGetText(int $hwnd): string
    {
        $h = $this->intToHwnd($hwnd);
        $len = (int) $this->user32->GetWindowTextLengthW($h);
        if ($len <= 0) {
            return '';
        }
        $buf = $this->user32->new('wchar_t[' . ($len + 1) . ']');
        $this->user32->GetWindowTextW($h, $buf, $len + 1);
        return $this->wideToUtf8($buf);
    }

    public function controlSetBounds(int $hwnd, int $x, int $y, int $width, int $height): void
    {
        $this->user32->SetWindowPos(
            $this->intToHwnd($hwnd), null,
            $x, $y, $width, $height,
            self::SWP_NOZORDER
        );
    }

    public function controlShow(int $hwnd): void
    {
        $this->user32->ShowWindow($this->intToHwnd($hwnd), self::SW_SHOW);
    }

    public function controlHide(int $hwnd): void
    {
        $this->user32->ShowWindow($this->intToHwnd($hwnd), self::SW_HIDE);
    }

    public function controlEnable(int $hwnd, bool $enabled): void
    {
        $this->user32->EnableWindow($this->intToHwnd($hwnd), $enabled ? 1 : 0);
    }

    public function controlIsChecked(int $hwnd): bool
    {
        $result = (int) $this->user32->SendMessageW(
            $this->intToHwnd($hwnd), self::BM_GETCHECK, 0, 0
        );
        return $result === self::BST_CHECKED;
    }

    public function controlSetChecked(int $hwnd, bool $checked): void
    {
        $this->user32->SendMessageW(
            $this->intToHwnd($hwnd), self::BM_SETCHECK,
            $checked ? self::BST_CHECKED : self::BST_UNCHECKED, 0
        );
    }

    public function controlAddString(int $hwnd, string $text): void
    {
        $buf = $this->utf8ToWide($text);
        $h = $this->intToHwnd($hwnd);
        $ptr = $this->ptrToInt(\FFI::addr($buf[0]));
        $type = $this->controlTypes[$hwnd] ?? '';
        $msg = ($type === 'ComboBox') ? self::CB_ADDSTRING : self::LB_ADDSTRING;
        $this->user32->SendMessageW($h, $msg, 0, $ptr);
    }

    public function controlRemoveString(int $hwnd, int $index): void
    {
        $h = $this->intToHwnd($hwnd);
        $type = $this->controlTypes[$hwnd] ?? '';
        $msg = ($type === 'ComboBox') ? self::CB_DELETESTRING : self::LB_DELETESTRING;
        $this->user32->SendMessageW($h, $msg, $index, 0);
    }

    public function controlClear(int $hwnd): void
    {
        $h = $this->intToHwnd($hwnd);
        $type = $this->controlTypes[$hwnd] ?? '';
        $msg = ($type === 'ComboBox') ? self::CB_RESETCONTENT : self::LB_RESETCONTENT;
        $this->user32->SendMessageW($h, $msg, 0, 0);
    }

    public function controlGetSelectedIndex(int $hwnd): int
    {
        $h = $this->intToHwnd($hwnd);
        $type = $this->controlTypes[$hwnd] ?? '';
        $msg = ($type === 'ComboBox') ? self::CB_GETCURSEL : self::LB_GETCURSEL;
        return (int) $this->user32->SendMessageW($h, $msg, 0, 0);
    }

    public function controlSetSelectedIndex(int $hwnd, int $index): void
    {
        $h = $this->intToHwnd($hwnd);
        $type = $this->controlTypes[$hwnd] ?? '';
        $msg = ($type === 'ComboBox') ? self::CB_SETCURSEL : self::LB_SETCURSEL;
        $this->user32->SendMessageW($h, $msg, $index, 0);
    }

    public function controlSetRange(int $hwnd, int $min, int $max): void
    {
        $h = $this->intToHwnd($hwnd);
        $type = $this->controlTypes[$hwnd] ?? '';
        if ($type === 'msctls_trackbar32') {
            $this->user32->SendMessageW($h, self::TBM_SETRANGEMIN, 1, $min);
            $this->user32->SendMessageW($h, self::TBM_SETRANGEMAX, 1, $max);
        } elseif ($type === 'msctls_progress32') {
            // PBM_SETRANGE: lParam = MAKELONG(min, max)
            $lParam = ($max << 16) | ($min & 0xFFFF);
            $this->user32->SendMessageW($h, self::PBM_SETRANGE, 0, $lParam);
        } elseif ($type === 'msctls_updown32') {
            // UDM_SETRANGE: lParam = MAKELONG(max, min)
            $lParamUD = ($min << 16) | ($max & 0xFFFF);
            $this->user32->SendMessageW($h, self::UDM_SETRANGE, 0, $lParamUD);
        }
    }

    public function controlSetValue(int $hwnd, int $value): void
    {
        $h = $this->intToHwnd($hwnd);
        $type = $this->controlTypes[$hwnd] ?? '';
        if ($type === 'msctls_trackbar32') {
            // Trackbar: wParam=1 表示重绘
            $this->user32->SendMessageW($h, self::TBM_SETPOS, 1, $value);
        } elseif ($type === 'msctls_progress32') {
            $this->user32->SendMessageW($h, self::PBM_SETPOS, $value, 0);
        } elseif ($type === 'msctls_updown32') {
            // UpDown: 低字为位置值
            $this->user32->SendMessageW($h, self::UDM_SETPOS, 0, $value & 0xFFFF);
        }
    }

    public function controlGetValue(int $hwnd): int
    {
        $h = $this->intToHwnd($hwnd);
        $type = $this->controlTypes[$hwnd] ?? '';
        if ($type === 'msctls_trackbar32') {
            return (int) $this->user32->SendMessageW($h, self::TBM_GETPOS, 0, 0);
        }
        if ($type === 'msctls_updown32') {
            $result = (int) $this->user32->SendMessageW($h, self::UDM_GETPOS, 0, 0);
            return $result & 0xFFFF;
        }
        // ProgressBar 无 GETPOS（旧版）；返回 0
        return 0;
    }

    /**
     * 启用/关闭 ProgressBar 不确定状态（marquee 滚动动画）。
     *
     * 实现：
     *   - enabled=true：通过 GWL_STYLE 追加 PBS_MARQUEE 样式，
     *     再发送 PBM_SETMARQUEE(wParam=1, lParam=updateMs) 启动动画。
     *   - enabled=false：发送 PBM_SETMARQUEE(wParam=0) 停止动画，
     *     并从 GWL_STYLE 移除 PBS_MARQUEE 样式。
     *
     * 注意：PBS_MARQUEE 仅 comctl32 6.0+（manifest 启用视觉样式后）支持，
     * 旧版回退为普通进度条无视觉效果，但不会报错。
     *
     * @param int  $hwnd       ProgressBar 控件句柄。
     * @param bool $enabled    true=启用滚动动画，false=恢复确定状态。
     * @param int  $updateMs   动画更新间隔（毫秒），仅 enabled=true 时生效。
     */
    public function progressBarSetMarquee(int $hwnd, bool $enabled, int $updateMs = 30): void
    {
        $h = $this->intToHwnd($hwnd);
        $style = (int) $this->user32->GetWindowLongPtrW($h, self::GWL_STYLE);
        if ($enabled) {
            $style |= self::PBS_MARQUEE;
        } else {
            $style &= ~self::PBS_MARQUEE;
        }
        $this->user32->SetWindowLongPtrW($h, self::GWL_STYLE, $style);
        // wParam=1 启动 / 0 关闭，lParam=更新间隔（毫秒）
        $this->user32->SendMessageW(
            $h,
            self::PBM_SETMARQUEE,
            $enabled ? 1 : 0,
            max(1, $updateMs)
        );
    }

    // ============================================================
    // Tab 标签页方法
    // ============================================================

    public function tabInsertItem(int $tabHwnd, int $index, string $text): void
    {
        $h = $this->intToHwnd($tabHwnd);
        $buf = $this->utf8ToWide($text);
        // 计算 wchar_t[] 长度（不含终止符）：sizeof(wchar_t[2])=4 字节
        $len = intdiv(\FFI::sizeof($buf), 2) - 1;

        $ti = $this->user32->new('TCITEMW');
        $ti->mask = self::TCIF_TEXT;
        $ti->dwState = 0;
        $ti->dwStateMask = 0;
        // LPWSTR 字段用 \FFI::addr 取 wchar_t[] 首元素地址
        $ti->pszText = \FFI::addr($buf[0]);
        $ti->cchTextMax = $len + 1;
        $ti->iImage = 0;
        $ti->lParam = 0;

        // index=-1 表示追加到末尾
        if ($index < 0) {
            $count = (int) $this->user32->SendMessageW(
                $h, self::TCM_GETITEMCOUNT, 0, 0
            );
            $index = $count;
        }
        $this->user32->SendMessageW(
            $h, self::TCM_INSERTITEMW, $index,
            $this->ptrToInt(\FFI::addr($ti))
        );
    }

    public function tabDeleteItem(int $tabHwnd, int $index): void
    {
        $this->user32->SendMessageW(
            $this->intToHwnd($tabHwnd), self::TCM_DELETEITEM, $index, 0
        );
    }

    public function tabGetSelected(int $tabHwnd): int
    {
        return (int) $this->user32->SendMessageW(
            $this->intToHwnd($tabHwnd), self::TCM_GETCURSEL, 0, 0
        );
    }

    public function tabSetSelected(int $tabHwnd, int $index): void
    {
        $this->user32->SendMessageW(
            $this->intToHwnd($tabHwnd), self::TCM_SETCURSEL, $index, 0
        );
    }

    public function tabGetItemCount(int $tabHwnd): int
    {
        return (int) $this->user32->SendMessageW(
            $this->intToHwnd($tabHwnd), self::TCM_GETITEMCOUNT, 0, 0
        );
    }

    // ============================================================
    // DateTimePicker
    // ============================================================

    public function dateTimePickerGetTime(int $hwnd): ?array
    {
        $h = $this->intToHwnd($hwnd);
        $st = $this->user32->new('SYSTEMTIME');
        $ret = (int) $this->user32->SendMessageW(
            $h, self::DTM_GETSYSTEMTIME, 0, $this->ptrToInt(\FFI::addr($st))
        );
        if ($ret !== self::GDT_VALID) {
            return null;
        }
        return [
            'year'   => (int) $st->wYear,
            'month'  => (int) $st->wMonth,
            'day'    => (int) $st->wDay,
            'hour'   => (int) $st->wHour,
            'minute' => (int) $st->wMinute,
            'second' => (int) $st->wSecond,
        ];
    }

    public function dateTimePickerSetTime(
        int $hwnd,
        int $year,
        int $month,
        int $day,
        int $hour,
        int $minute,
        int $second
    ): void {
        $h = $this->intToHwnd($hwnd);

        $st = $this->user32->new('SYSTEMTIME');
        $st->wYear = $year;
        $st->wMonth = $month;
        $st->wDay = $day;
        $st->wHour = $hour;
        $st->wMinute = $minute;
        $st->wSecond = $second;

        $this->user32->SendMessageW(
            $h, self::DTM_SETSYSTEMTIME, self::GDT_VALID, $this->ptrToInt(\FFI::addr($st))
        );
    }

    public function dateTimePickerSetFormat(int $hwnd, string $format): void
    {
        $h = $this->intToHwnd($hwnd);
        $buf = $this->utf8ToWide($format);
        $this->user32->SendMessageW(
            $h, self::DTM_SETFORMATW, 0, $this->ptrToInt(\FFI::addr($buf[0]))
        );
    }

    // ============================================================
    // Table（ListView 虚拟模式）方法
    // ============================================================

    /**
     * 设置 ListView 扩展样式（LVS_EX_FULLROWSELECT 等）。
     *
     * 通过 LVM_SETEXTENDEDLISTVIEWSTYLE 消息设置。
     *
     * @param int $hwnd    ListView 句柄。
     * @param int $exStyle 扩展样式位掩码（LVS_EX_FULLROWSELECT=0x20 | LVS_EX_GRIDLINES=0x01）。
     */
    public function tableSetExtendedStyle(int $hwnd, int $exStyle): void
    {
        $h = $this->intToHwnd($hwnd);
        // wParam=exStyle, lParam=exStyle 表示设置为新值（非掩码合并）
        $this->user32->SendMessageW(
            $h,
            self::LVM_SETEXTENDEDLISTVIEWSTYLE,
            $exStyle,
            $exStyle
        );
    }

    /**
     * 插入一列。
     *
     * @param int    $hwnd  ListView 句柄。
     * @param int    $index 列索引（0-based）。
     * @param string $text  列标题。
     * @param int    $width 列宽（像素）。
     */
    public function tableInsertColumn(int $hwnd, int $index, string $text, int $width): void
    {
        $h = $this->intToHwnd($hwnd);
        $col = $this->user32->new('LVCOLUMNW');
        $col->mask = self::LVCF_FMT | self::LVCF_WIDTH | self::LVCF_TEXT;
        $col->fmt = self::LVCFMT_LEFT;
        $col->cx = $width;
        $col->iSubItem = $index;

        $buf = $this->utf8ToWide($text);
        $col->pszText = \FFI::addr($buf[0]);
        $col->cchTextMax = mb_strlen($text, 'UTF-8') + 1;

        // LVM_INSERTCOLUMNW: wParam=列索引, lParam=LVCOLUMNW* 指针
        $this->user32->SendMessageW(
            $h,
            self::LVM_INSERTCOLUMNW,
            $index,
            $this->ptrToInt(\FFI::addr($col))
        );
    }

    /**
     * 设置指定列的宽度（LVM_SETCOLUMNWIDTH = 0x101E）。
     *
     * @param int $hwnd  ListView 句柄。
     * @param int $col   列索引（0-based）。
     * @param int $width 列宽（像素）。
     */
    public function tableSetColumnWidth(int $hwnd, int $col, int $width): void
    {
        $h = $this->intToHwnd($hwnd);
        // LVM_SETCOLUMNWIDTH: wParam=列索引, lParam=宽度（像素）
        $this->user32->SendMessageW(
            $h,
            self::LVM_SETCOLUMNWIDTH,
            $col,
            $width
        );
    }

    /**
     * 清空所有列与所有项。
     */
    public function tableClearColumns(int $hwnd): void
    {
        $h = $this->intToHwnd($hwnd);
        // 先删除所有项
        $this->user32->SendMessageW($h, self::LVM_DELETEALLITEMS, 0, 0);
        // 逆序删除列（正序删除会导致索引错位）
        $count = $this->user32->new('int');
        $count->cdata = 0;
        // LVM_GETITEMCOUNT 仅返回项数，列数需通过 Header_GetItemCount 获取，
        // 此处简化为从末尾 99 倒序尝试删除，遇到无效列自动停止。
        for ($i = 99; $i >= 0; $i--) {
            $ret = (int) $this->user32->SendMessageW(
                $h, self::LVM_DELETECOLUMN, $i, 0
            );
            // 返回 TRUE=1 成功，FALSE=0 失败（列不存在）
            // 继续尝试直到 i=0
        }
    }

    /**
     * 设置虚拟模式行数（LVM_SETITEMCOUNT）。
     *
     * LVS_OWNERDATA 模式下，ListView 据此知道总行数，
     * 滚动条范围与可见行通过 LVN_GETDISPINFO 回调取数据。
     */
    public function tableSetItemCount(int $hwnd, int $count): void
    {
        $h = $this->intToHwnd($hwnd);
        // LVM_SETITEMCOUNT: wParam=行数
        $this->user32->SendMessageW($h, self::LVM_SETITEMCOUNT, $count, 0);
    }

    /**
     * 刷新整张表（重绘所有可见行）。
     */
    public function tableRefresh(int $hwnd): void
    {
        $h = $this->intToHwnd($hwnd);
        // 先更新行数（避免 model 行数变化后视图不刷新）
        $this->user32->InvalidateRect($h, null, 1);
    }

    /**
     * 刷新指定行（LVM_REDRAWITEMS）。
     */
    public function tableRefreshRow(int $hwnd, int $row): void
    {
        $h = $this->intToHwnd($hwnd);
        // LVM_REDRAWITEMS: wParam=起始行, lParam=结束行
        $this->user32->SendMessageW($h, self::LVM_REDRAWITEMS, $row, $row);
        // 立即触发 WM_PAINT
        $this->user32->UpdateWindow($h);
    }

    /**
     * 选中指定行并滚动到可见。
     *
     * @param int $row 行索引，-1 取消选中。
     */
    public function tableSelectRow(int $hwnd, int $row): void
    {
        $h = $this->intToHwnd($hwnd);
        if ($row < 0) {
            // 取消当前选中：遍历所有选中项清除状态
            // LVM_GETNEXTITEM: wParam=-1, lParam=LVNI_SELECTED 查找下一选中项
            $cur = (int) $this->user32->SendMessageW(
                $h, self::LVM_GETNEXTITEM, -1, self::LVNI_SELECTED
            );
            while ($cur !== -1) {
                $item = $this->user32->new('LVITEMW');
                $item->mask = self::LVIF_STATE;
                $item->state = 0;
                $item->stateMask = self::LVIS_SELECTED;
                $item->iItem = $cur;
                $this->user32->SendMessageW(
                    $h, self::LVM_SETITEMSTATE, $cur,
                    $this->ptrToInt(\FFI::addr($item))
                );
                $cur = (int) $this->user32->SendMessageW(
                    $h, self::LVM_GETNEXTITEM, $cur, self::LVNI_SELECTED
                );
            }
            return;
        }

        // 先清除现有选中
        $this->tableSelectRow($hwnd, -1);

        // 设置新选中行
        $item = $this->user32->new('LVITEMW');
        $item->mask = self::LVIF_STATE;
        $item->state = self::LVIS_SELECTED | self::LVIS_FOCUSED;
        $item->stateMask = self::LVIS_SELECTED | self::LVIS_FOCUSED;
        $item->iItem = $row;
        $this->user32->SendMessageW(
            $h, self::LVM_SETITEMSTATE, $row,
            $this->ptrToInt(\FFI::addr($item))
        );

        // 滚动到可见
        $this->user32->SendMessageW($h, self::LVM_ENSUREVISIBLE, $row, 0);
    }

    /**
     * 获取当前选中行索引。
     *
     * @return int 行索引，-1 表示无选中。
     */
    public function tableGetSelectedRow(int $hwnd): int
    {
        $h = $this->intToHwnd($hwnd);
        return (int) $this->user32->SendMessageW(
            $h, self::LVM_GETNEXTITEM, -1, self::LVNI_SELECTED
        );
    }

    /**
     * 设置某行背景色（NM_CUSTOMDRAW 着色）。
     *
     * @param int      $row   行索引。
     * @param int|null $color COLORREF（0x00BBGGRR），null 清除。
     */
    public function tableSetRowBgColor(int $hwnd, int $row, ?int $color): void
    {
        if ($color === null) {
            unset($this->tableRowBgColors[$hwnd][$row]);
        } else {
            $this->tableRowBgColors[$hwnd][$row] = $color & 0xFFFFFF;
        }
        $this->tableRefreshRow($hwnd, $row);
    }

    /**
     * 设置某行文字颜色。
     *
     * @param int      $row   行索引。
     * @param int|null $color COLORREF，null 清除。
     */
    public function tableSetRowTextColor(int $hwnd, int $row, ?int $color): void
    {
        if ($color === null) {
            unset($this->tableRowTextColors[$hwnd][$row]);
        } else {
            $this->tableRowTextColors[$hwnd][$row] = $color & 0xFFFFFF;
        }
        $this->tableRefreshRow($hwnd, $row);
    }

    // ============================================================
    // ImageList 通用 API（Table / Tab 共用）
    // ============================================================

    /**
     * 创建 ImageList 并返回 int 句柄。
     *
     * 不关联到任何控件，由调用方通过 tableSetImageList / tabSetImageList 关联。
     * CData 由 $imageLists 保活，防止 GC 回收。
     *
     * @param int $cx       图像宽度（像素）。
     * @param int $cy       图像高度（像素）。
     * @param int $cInitial 初始容量（默认 4）。
     * @param int $cGrow    增长量（默认 4）。
     * @return int ImageList 句柄。
     */
    public function imageListCreate(
        int $cx,
        int $cy,
        int $cInitial = 4,
        int $cGrow = 4
    ): int {
        // ILC_COLOR32 = 0x00000020
        $himl = $this->comctl32->ImageList_Create($cx, $cy, 0x00000020, $cInitial, $cGrow);
        if ($himl === null || \FFI::isNull($himl)) {
            throw new \RuntimeException('ImageList_Create failed');
        }
        $id = $this->ptrToIntIn($this->comctl32, $himl);
        $this->imageLists[$id] = $himl;
        return $id;
    }

    /**
     * 将 Image 添加到 ImageList，返回图像索引。
     *
     * 内部流程：
     *   1. GpImage → HBITMAP（gdiplus 作用域，gdipImageToHbitmapInt）
     *   2. HBITMAP int → comctl32 作用域 HBITMAP CData
     *   3. ImageList_Add 加入列表，返回索引
     *   4. DeleteObject 释放临时 HBITMAP（ImageList 内部会复制）
     *
     * @param int                          $imageListId ImageList 句柄。
     * @param \Kingbes\Ui\Graphics\Image   $image       图像对象。
     * @return int 图像在 ImageList 中的索引（0-based），-1 表示失败。
     */
    public function imageListAddImage(int $imageListId, \Kingbes\Ui\Graphics\Image $image): int
    {
        $himl = $this->imageLists[$imageListId]
            ?? throw new \RuntimeException("Unknown ImageList handle: {$imageListId}");

        // GpImage → HBITMAP（int）
        $hbmInt = $this->gdipImageToHbitmapInt($image->getGpImage());
        if ($hbmInt === 0) {
            throw new \RuntimeException('gdipImageToHbitmapInt failed');
        }
        try {
            // int → comctl32 作用域 HBITMAP
            $hbmCData = $this->intToComctl32Bitmap($hbmInt);
            return (int) $this->comctl32->ImageList_Add($himl, $hbmCData, null);
        } finally {
            // ImageList_Add 会复制位图，原始 HBITMAP 可立即释放
            $this->deleteGdiObjectInt($hbmInt);
        }
    }

    /**
     * 销毁 ImageList 并从保活表中移除。
     */
    public function imageListDestroy(int $imageListId): void
    {
        $himl = $this->imageLists[$imageListId] ?? null;
        if ($himl === null) {
            return;
        }
        $this->comctl32->ImageList_Destroy($himl);
        unset($this->imageLists[$imageListId]);
    }

    /**
     * 将 ImageList 关联到 ListView（LVSIL_SMALL 槽位）。
     */
    public function tableSetImageList(int $hwnd, int $imageListId): void
    {
        $this->user32->SendMessageW(
            $this->intToHwnd($hwnd),
            self::LVM_SETIMAGELIST,
            self::LVSIL_SMALL,
            $imageListId
        );
    }

    // ============================================================
    // Button / Label 图像（BM_SETIMAGE / STM_SETIMAGE）
    // ============================================================

    /**
     * 读取窗口样式（GWL_STYLE）。
     *
     * 通用方法，供 Button 等控件追加/移除样式位（如 BS_BITMAP）。
     *
     * @param int $hwnd 窗口/控件句柄。
     * @return int 当前样式位掩码。
     */
    public function controlGetStyle(int $hwnd): int
    {
        return (int) $this->user32->GetWindowLongPtrW(
            $this->intToHwnd($hwnd),
            self::GWL_STYLE
        );
    }

    /**
     * 设置窗口样式（GWL_STYLE）。
     *
     * @param int $hwnd  窗口/控件句柄。
     * @param int $style 新样式位掩码。
     */
    public function controlSetStyle(int $hwnd, int $style): void
    {
        $this->user32->SetWindowLongPtrW(
            $this->intToHwnd($hwnd),
            self::GWL_STYLE,
            $style
        );
    }

    /**
     * 设置 Button 的位图图像（BM_SETIMAGE，IMAGE_BITMAP）。
     *
     * 按钮需有 BS_BITMAP(0x80) 样式才能正确显示位图；若按钮已用 BS_PUSHBUTTON
     * 创建，BM_SETIMAGE 仍会显示图像但行为可能略有差异。
     *
     * @param int $hwnd   Button 句柄。
     * @param int $hbmInt HBITMAP 的 int 句柄。
     */
    public function buttonSetImage(int $hwnd, int $hbmInt): void
    {
        $this->user32->SendMessageW(
            $this->intToHwnd($hwnd),
            self::BM_SETIMAGE,
            self::IMAGE_BITMAP,
            $hbmInt
        );
    }

    /**
     * 设置 Static（Label）控件的位图图像（STM_SETIMAGE，IMAGE_BITMAP）。
     *
     * 要求 Label 创建时使用 SS_BITMAP(0x0E) 样式，否则图像不会显示。
     *
     * @param int $hwnd   Static 控件句柄。
     * @param int $hbmInt HBITMAP 的 int 句柄。
     * @return int 旧图像的 int 句柄（需调用方释放），0 表示无旧图像。
     */
    public function labelSetImage(int $hwnd, int $hbmInt): int
    {
        $old = (int) $this->user32->SendMessageW(
            $this->intToHwnd($hwnd),
            self::STM_SETIMAGE,
            self::IMAGE_BITMAP,
            $hbmInt
        );
        return $old;
    }

    // ============================================================
    // Tab 图像（TCM_SETIMAGELIST / TCM_SETITEMW）
    // ============================================================

    /**
     * 将 ImageList 关联到 Tab 控件（TCM_SETIMAGELIST）。
     */
    public function tabSetImageList(int $hwnd, int $imageListId): void
    {
        $this->user32->SendMessageW(
            $this->intToHwnd($hwnd),
            self::TCM_SETIMAGELIST,
            0,
            $imageListId
        );
    }

    /**
     * 设置 Tab 某页签的图像索引（TCM_SETITEMW，TCIF_IMAGE）。
     *
     * @param int $hwnd       Tab 控件句柄。
     * @param int $pageIndex  页签索引（0-based）。
     * @param int $imageIndex ImageList 中的图像索引，-1 清除。
     */
    public function tabSetItemImage(int $hwnd, int $pageIndex, int $imageIndex): void
    {
        $ti = $this->user32->new('TCITEMW');
        $ti->mask = self::TCIF_IMAGE;
        $ti->iImage = $imageIndex;
        $this->user32->SendMessageW(
            $this->intToHwnd($hwnd),
            self::TCM_SETITEMW,
            $pageIndex,
            $this->ptrToInt(\FFI::addr($ti))
        );
    }

    // ============================================================
    // MenuItem 图像（SetMenuItemInfoW，MIIM_BITMAP）
    // ============================================================

    /**
     * 设置菜单项的位图图标（SetMenuItemInfoW，MIIM_BITMAP，按 ID 查找）。
     *
     * @param int $menuHwnd 菜单 HMENU 的 int 句柄。
     * @param int $itemId   菜单项 ID。
     * @param int $hbmInt   HBITMAP 的 int 句柄，0 清除图标。
     */
    public function menuSetItemBitmap(int $menuHwnd, int $itemId, int $hbmInt): void
    {
        $hmenu = $this->menus[$menuHwnd]
            ?? throw new \RuntimeException("Unknown menu handle: {$menuHwnd}");

        $mii = $this->user32->new('MENUITEMINFOW');
        $mii->cbSize = \FFI::sizeof($mii);
        $mii->fMask = self::MIIM_BITMAP;
        // hbmpItem：0 表示清除，否则转 user32 作用域 HBITMAP
        if ($hbmInt !== 0) {
            $mii->hbmpItem = $this->intToUser32Bitmap($hbmInt);
        } else {
            $mii->hbmpItem = null;
        }

        // fByPosition=FALSE（按 ID 查找，第 3 参数为菜单项 ID）
        $this->user32->SetMenuItemInfoW($hmenu, $itemId, 0, \FFI::addr($mii));
        // 重绘菜单栏（仅对菜单栏有效，弹出菜单无需重绘）
        $ownerHwnd = $this->getOwnerWindowHwnd($menuHwnd);
        if ($ownerHwnd !== 0) {
            $this->user32->DrawMenuBar($this->intToHwnd($ownerHwnd));
        }
    }

    /**
     * 查找菜单所属的顶级窗口 HWND（用于 DrawMenuBar）。
     *
     * 菜单栏通过 SetMenu 绑定到窗口，此处遍历已注册窗口查找匹配的 HMENU。
     * 找不到返回 0（DrawMenuBar(0) 会被系统忽略，不影响弹出菜单）。
     */
    private function getOwnerWindowHwnd(int $menuHwnd): int
    {
        foreach ($this->windows as $hwnd => $win) {
            if (method_exists($win, 'getMenu')) {
                $menu = $win->getMenu();
                if ($menu !== null && $menu->getHwnd() === $menuHwnd) {
                    return $hwnd;
                }
            }
        }
        return 0;
    }

    // ============================================================
    // 以下方法由后续批次实现
    // ============================================================

    public function menuCreateBar(): int
    {
        $hmenu = $this->user32->CreateMenu();
        if ($hmenu === null || \FFI::isNull($hmenu)) {
            throw new \RuntimeException('CreateMenu failed');
        }
        $id = $this->hmenuToInt($hmenu);
        // 保活 HMENU CData，防止 GC 回收后地址失效
        $this->menus[$id] = $hmenu;
        return $id;
    }

    public function menuCreatePopup(): int
    {
        $hmenu = $this->user32->CreatePopupMenu();
        if ($hmenu === null || \FFI::isNull($hmenu)) {
            throw new \RuntimeException('CreatePopupMenu failed');
        }
        $id = $this->hmenuToInt($hmenu);
        // 保活 HMENU CData
        $this->menus[$id] = $hmenu;
        return $id;
    }

    public function menuAppendItem(int $menuHwnd, string $text, int $id): void
    {
        $hmenu = $this->menus[$menuHwnd]
            ?? throw new \RuntimeException("Unknown menu handle: {$menuHwnd}");
        $textBuf = $this->utf8ToWide($text);
        $this->user32->AppendMenuW(
            $hmenu,
            self::MF_STRING,
            $id,
            \FFI::addr($textBuf[0])
        );
    }

    public function menuAppendSeparator(int $menuHwnd): void
    {
        $hmenu = $this->menus[$menuHwnd]
            ?? throw new \RuntimeException("Unknown menu handle: {$menuHwnd}");
        $this->user32->AppendMenuW(
            $hmenu,
            self::MF_SEPARATOR,
            0,
            null
        );
    }

    public function menuAppendSubmenu(int $menuHwnd, string $text, int $submenuHwnd): void
    {
        $hmenu = $this->menus[$menuHwnd]
            ?? throw new \RuntimeException("Unknown menu handle: {$menuHwnd}");
        // 确认子菜单 CData 存在（保活校验）
        if (!isset($this->menus[$submenuHwnd])) {
            throw new \RuntimeException("Unknown submenu handle: {$submenuHwnd}");
        }
        $textBuf = $this->utf8ToWide($text);
        // MF_POPUP 时 uIDNewItem 是子菜单 HMENU 的 int 值（UINT_PTR 接收）
        $this->user32->AppendMenuW(
            $hmenu,
            self::MF_STRING | self::MF_POPUP,
            $submenuHwnd,
            \FFI::addr($textBuf[0])
        );
    }

    public function menuSetEnabled(int $menuHwnd, int $id, bool $enabled): void
    {
        $hmenu = $this->menus[$menuHwnd]
            ?? throw new \RuntimeException("Unknown menu handle: {$menuHwnd}");
        // MF_BYCOMMAND=0 | MF_ENABLED=0 / MF_GRAYED=1
        $this->user32->EnableMenuItem(
            $hmenu,
            $id,
            self::MF_BYCOMMAND | ($enabled ? self::MF_ENABLED : self::MF_GRAYED)
        );
    }

    public function menuSetChecked(int $menuHwnd, int $id, bool $checked): void
    {
        $hmenu = $this->menus[$menuHwnd]
            ?? throw new \RuntimeException("Unknown menu handle: {$menuHwnd}");
        // MF_BYCOMMAND=0 | MF_CHECKED=0x8 / MF_UNCHECKED=0
        $this->user32->CheckMenuItem(
            $hmenu,
            $id,
            self::MF_BYCOMMAND | ($checked ? self::MF_CHECKED : self::MF_UNCHECKED)
        );
    }

    public function menuDestroy(int $menuHwnd): void
    {
        $hmenu = $this->menus[$menuHwnd] ?? null;
        if ($hmenu === null) {
            return;
        }
        $this->user32->DestroyMenu($hmenu);
        unset($this->menus[$menuHwnd]);
    }

    // ============================================================
    // 对话框方法（批次 5：Unicode W 系列，inModalDialog 守护）
    // ============================================================

    /**
     * 消息框（MessageBoxW，支持中文）。
     *
     * inModalDialog=true 期间，WindowProc 对所有消息调 DefWindowProcW
     * 默认处理（允许窗口重绘，不卡死），调用后恢复 false。
     *
     * type 常量：MB_OK=0x0000, MB_OKCANCEL=0x0001, MB_YESNO=0x0004,
     *           MB_ICONERROR=0x10, MB_ICONWARNING=0x30,
     *           MB_ICONINFORMATION=0x40, MB_ICONQUESTION=0x20
     * 返回值：IDOK=1, IDCANCEL=2, IDYES=6, IDNO=7
     */
    public function dialogMsgBox(int $parentHwnd, string $text, string $caption, int $type): int
    {
        $textBuf = $this->utf8ToWide($text);
        $captionBuf = $this->utf8ToWide($caption);
        $hwnd = $parentHwnd !== 0 ? $this->intToHwnd($parentHwnd) : null;

        $this->inModalDialog = true;
        try {
            $result = (int) $this->user32->MessageBoxW(
                $hwnd,
                \FFI::addr($textBuf[0]),
                \FFI::addr($captionBuf[0]),
                $type
            );
        } finally {
            $this->inModalDialog = false;
        }
        return $result;
    }

    /**
     * 打开文件对话框（GetOpenFileNameW）。
     *
     * OPENFILENAMEW 结构体大小由 FFI sizeof 获取（x64 = 152 字节）。
     * 过滤器缓冲为双 \0 结尾 UTF-16LE。lpstrFile 分配 wchar_t[260]。
     * Flags: OFN_EXPLORER=0x00080000 | OFN_FILEMUSTEXIST=0x00001000。
     */
    public function dialogOpenFile(int $parentHwnd, array $filters): ?string
    {
        return $this->openSaveFileName($parentHwnd, $filters, true);
    }

    /**
     * 保存文件对话框（GetSaveFileNameW）。
     * Flags: OFN_EXPLORER=0x00080000 | OFN_OVERWRITEPROMPT=0x00000002。
     */
    public function dialogSaveFile(int $parentHwnd, array $filters): ?string
    {
        return $this->openSaveFileName($parentHwnd, $filters, false);
    }

    /**
     * GetOpenFileNameW / GetSaveFileNameW 共用实现。
     */
    private function openSaveFileName(int $parentHwnd, array $filters, bool $open): ?string
    {
        $filterBuf = $this->buildOpenFileNameFilter($this->comdlg32, $filters);
        $fileBuf = $this->wideBufZero($this->comdlg32, 260);

        $ofn = $this->comdlg32->new('OPENFILENAMEW');
        $ofn->lStructSize = \FFI::sizeof($ofn);
        if ($parentHwnd !== 0) {
            $ofn->hwndOwner = $this->intToPtrIn($this->comdlg32, $parentHwnd);
        }
        $ofn->lpstrFilter = \FFI::addr($filterBuf[0]);
        $ofn->lpstrFile = \FFI::addr($fileBuf[0]);
        $ofn->nMaxFile = 260;
        // OFN_EXPLORER=0x00080000
        // 打开: OFN_FILEMUSTEXIST=0x00001000
        // 保存: OFN_OVERWRITEPROMPT=0x00000002
        $ofn->Flags = $open ? (0x00080000 | 0x00001000) : (0x00080000 | 0x00000002);

        $this->inModalDialog = true;
        try {
            $ok = $open
                ? (int) $this->comdlg32->GetOpenFileNameW(\FFI::addr($ofn))
                : (int) $this->comdlg32->GetSaveFileNameW(\FFI::addr($ofn));
        } finally {
            $this->inModalDialog = false;
        }

        if ($ok === 0) {
            return null; // 取消或失败
        }
        return $this->wideToUtf8($fileBuf);
    }

    /**
     * 打开文件夹对话框（SHBrowseForFolderW + SHGetPathFromIDListW）。
     *
     * BROWSEINFOW.ulFlags: BIF_RETURNONLYFSDIRS=0x0001 | BIF_NEWDIALOGSTYLE=0x0040
     * SHBrowseForFolderW 返回 PIDL，SHGetPathFromIDListW(pidl, buffer) 获取路径。
     * PIDL 需 CoTaskMemFree 释放（ole32.dll，跨作用域经 int 转换）。
     */
    public function dialogOpenFolder(int $parentHwnd): ?string
    {
        $titleBuf = $this->wideBufIn($this->shell32, '选择文件夹');
        $displayBuf = $this->wideBufZero($this->shell32, 260);

        $bi = $this->shell32->new('BROWSEINFOW');
        if ($parentHwnd !== 0) {
            $bi->hwndOwner = $this->intToPtrIn($this->shell32, $parentHwnd);
        }
        $bi->pidlRoot = null;
        $bi->pszDisplayName = \FFI::addr($displayBuf[0]);
        $bi->lpszTitle = \FFI::addr($titleBuf[0]);
        $bi->ulFlags = 0x0001 | 0x0040; // BIF_RETURNONLYFSDIRS | BIF_NEWDIALOGSTYLE
        $bi->lpfn = null;
        $bi->lParam = 0;
        $bi->iImage = 0;

        $this->inModalDialog = true;
        try {
            $pidl = $this->shell32->SHBrowseForFolderW(\FFI::addr($bi));
        } finally {
            $this->inModalDialog = false;
        }

        if ($pidl === null || \FFI::isNull($pidl)) {
            return null; // 用户取消
        }

        $pathBuf = $this->wideBufZero($this->shell32, 260);
        $ok = (int) $this->shell32->SHGetPathFromIDListW($pidl, $pathBuf);

        // 释放 PIDL：shell32 作用域 → int → ole32 作用域 void*
        $pidlInt = $this->ptrToIntIn($this->shell32, $pidl);
        $pidlOle = $this->intToPtrIn($this->ole32, $pidlInt);
        $this->ole32->CoTaskMemFree($pidlOle);

        if ($ok === 0) {
            return null;
        }
        return $this->wideToUtf8($pathBuf);
    }

    /**
     * 颜色对话框（ChooseColorW）。
     *
     * CHOOSECOLORW.lStructSize 由 FFI sizeof 获取（x64 = 72 字节）。
     * lpCustColors 指向 DWORD[16] 持久存储（owned=false 防 GC）。
     * Flags: CC_FULLOPEN=0x0002 | CC_RGBINIT=0x0001。
     * 返回 Color::fromColorRef(rgbResult) 或 null（取消）。
     */
    public function dialogChooseColor(int $parentHwnd): ?Color
    {
        // 自定义颜色数组（DWORD[16]，owned=false 持久化防 GC）
        $custColors = $this->comdlg32->new('DWORD[16]', false);
        for ($i = 0; $i < 16; $i++) {
            $custColors[$i] = 0xFFFFFF; // 白色
        }

        $cc = $this->comdlg32->new('CHOOSECOLORW');
        $cc->lStructSize = \FFI::sizeof($cc);
        if ($parentHwnd !== 0) {
            $cc->hwndOwner = $this->intToPtrIn($this->comdlg32, $parentHwnd);
        }
        $cc->hInstance = null;
        $cc->rgbResult = 0; // 初始黑色
        $cc->lpCustColors = \FFI::addr($custColors[0]);
        $cc->Flags = 0x0002 | 0x0001; // CC_FULLOPEN | CC_RGBINIT
        $cc->lCustData = 0;
        $cc->lpfnHook = null;
        $cc->lpTemplateName = null;

        $this->inModalDialog = true;
        try {
            $ok = (int) $this->comdlg32->ChooseColorW(\FFI::addr($cc));
        } finally {
            $this->inModalDialog = false;
        }

        if ($ok === 0) {
            return null; // 取消
        }
        return Color::fromColorRef((int) $cc->rgbResult);
    }

    /**
     * 字体对话框（ChooseFontW）。
     *
     * 分配 LOGFONTW，填入默认值（lfHeight=-14, lfFaceName="Segoe UI"）。
     * Flags: CF_SCREENFONTS=0x0001 | CF_INITTOLOGFONTSTRUCT=0x0040 | CF_EFFECTS=0x0100。
     * 返回 ['name' => string, 'size' => int, 'color' => Color] 或 null（取消）。
     * iPointSize 单位为 1/10 磅，size = iPointSize / 10。
     */
    public function dialogChooseFont(int $parentHwnd): ?array
    {
        // LOGFONTW 默认值
        $lf = $this->comdlg32->new('LOGFONTW');
        $lf->lfHeight = -14;
        $lf->lfWidth = 0;
        $lf->lfEscapement = 0;
        $lf->lfOrientation = 0;
        $lf->lfWeight = 400; // FW_NORMAL
        $lf->lfItalic = 0;
        $lf->lfUnderline = 0;
        $lf->lfStrikeOut = 0;
        $lf->lfCharSet = 1; // DEFAULT_CHARSET
        $lf->lfOutPrecision = 0;
        $lf->lfClipPrecision = 0;
        $lf->lfQuality = 0;
        $lf->lfPitchAndFamily = 0;
        // lfFaceName = "Segoe UI"（LF_FACESIZE=32）
        $faceWide = self::conv('Segoe UI', 'UTF-16LE', 'UTF-8');
        $faceChars = intdiv(strlen($faceWide), 2);
        for ($i = 0; $i < 32; $i++) {
            $lf->lfFaceName[$i] = $i < $faceChars
                ? (ord($faceWide[$i * 2]) | (ord($faceWide[$i * 2 + 1]) << 8))
                : 0;
        }

        $cf = $this->comdlg32->new('CHOOSEFONTW');
        $cf->lStructSize = \FFI::sizeof($cf);
        if ($parentHwnd !== 0) {
            $cf->hwndOwner = $this->intToPtrIn($this->comdlg32, $parentHwnd);
        }
        $cf->hDC = null;
        $cf->lpLogFont = \FFI::addr($lf);
        $cf->iPointSize = 0;
        $cf->Flags = 0x0001 | 0x0040 | 0x0100; // CF_SCREENFONTS | CF_INITTOLOGFONTSTRUCT | CF_EFFECTS
        $cf->rgbColors = 0; // 黑色
        $cf->lCustData = 0;
        $cf->lpfnHook = null;
        $cf->lpTemplateName = null;
        $cf->hInstance = null;
        $cf->lpszStyle = null;
        $cf->nFontType = 0;
        $cf->__alignment = 0;
        $cf->nSizeMin = 0;
        $cf->nSizeMax = 0;

        $this->inModalDialog = true;
        try {
            $ok = (int) $this->comdlg32->ChooseFontW(\FFI::addr($cf));
        } finally {
            $this->inModalDialog = false;
        }

        if ($ok === 0) {
            return null; // 取消
        }

        // 读取字体名（wchar_t[32] → UTF-8）
        $bytes = '';
        for ($i = 0; $i < 32; $i++) {
            $ch = $lf->lfFaceName[$i];
            if ($ch == 0) {
                break;
            }
            $bytes .= chr($ch & 0xFF) . chr(($ch >> 8) & 0xFF);
        }
        $fontName = self::conv($bytes, 'UTF-8', 'UTF-16LE');

        // iPointSize 单位为 1/10 磅
        $fontSize = (int) round((int) $cf->iPointSize / 10);
        $fontColor = Color::fromColorRef((int) $cf->rgbColors);

        return [
            'name' => $fontName,
            'size' => $fontSize,
            'color' => $fontColor,
        ];
    }

    public function areaCreate(int $parentHwnd): int
    {
        $this->registerWindowClass();

        $classBuf = $this->utf8ToWide(self::WINDOW_CLASS_NAME);
        $parent = $parentHwnd !== 0 ? $this->intToHwnd($parentHwnd) : null;

        // Area 复用 PhpUiWindow 类（已注册），作为 WS_CHILD 子窗口。
        // WindowProc 通过 $this->controlTypes 表区分 Area（'Area'）。
        $hwnd = $this->user32->CreateWindowExW(
            0,
            \FFI::addr($classBuf[0]),
            null,
            self::WS_CHILD | self::WS_VISIBLE,
            0, 0, 0, 0,
            $parent,
            null, null, null
        );
        if ($hwnd === null || \FFI::isNull($hwnd)) {
            throw new \RuntimeException('CreateWindowExW failed for Area');
        }

        $this->applyDefaultFont($hwnd);

        $hwndInt = $this->hwndInt($hwnd);
        $this->controlTypes[$hwndInt] = 'Area';
        return $hwndInt;
    }

    public function areaInvalidate(int $hwnd): void
    {
        $this->user32->InvalidateRect($this->intToHwnd($hwnd), null, 0);
    }

    /**
     * 设置 Area 的虚拟内容尺寸，启用/关闭滚动条。
     *
     * 实现：
     *   - contentW/contentH > 0：通过 GWL_STYLE 追加 WS_HSCROLL/WS_VSCROLL，
     *     存储内容尺寸到 areaScrollInfo，SetScrollInfo 设置滚动范围与页面大小。
     *   - contentW/contentH = 0：移除 WS_HSCROLL/WS_VSCROLL，清除 areaScrollInfo。
     *
     * @param int $hwnd     Area 控件句柄。
     * @param int $contentW 内容总宽度（像素）。
     * @param int $contentH 内容总高度（像素）。
     */
    public function areaSetScrollable(int $hwnd, int $contentW, int $contentH): void
    {
        $h = $this->intToHwnd($hwnd);

        if ($contentW <= 0 && $contentH <= 0) {
            // 关闭滚动
            $style = (int) $this->user32->GetWindowLongPtrW($h, self::GWL_STYLE);
            $style &= ~(self::WS_HSCROLL | self::WS_VSCROLL);
            $this->user32->SetWindowLongPtrW($h, self::GWL_STYLE, $style);
            // SWP_FRAMECHANGED 让样式变化立即生效
            $this->user32->SetWindowPos(
                $h, null, 0, 0, 0, 0,
                self::SWP_NOMOVE | self::SWP_NOSIZE | self::SWP_NOZORDER | self::SWP_FRAMECHANGED
            );
            unset($this->areaScrollInfo[$hwnd]);
            return;
        }

        // 启用滚动：追加 WS_HSCROLL/WS_VSCROLL
        $style = (int) $this->user32->GetWindowLongPtrW($h, self::GWL_STYLE);
        $needStyle = $style;
        if ($contentW > 0) {
            $needStyle |= self::WS_HSCROLL;
        } else {
            $needStyle &= ~self::WS_HSCROLL;
        }
        if ($contentH > 0) {
            $needStyle |= self::WS_VSCROLL;
        } else {
            $needStyle &= ~self::WS_VSCROLL;
        }
        if ($needStyle !== $style) {
            $this->user32->SetWindowLongPtrW($h, self::GWL_STYLE, $needStyle);
            $this->user32->SetWindowPos(
                $h, null, 0, 0, 0, 0,
                self::SWP_NOMOVE | self::SWP_NOSIZE | self::SWP_NOZORDER | self::SWP_FRAMECHANGED
            );
        }

        // 客户区尺寸（页面大小）
        $client = $this->controlClientSize($hwnd);

        // 保留原 x/y，初始化内容尺寸
        $prev = $this->areaScrollInfo[$hwnd] ?? ['w' => 0, 'h' => 0, 'x' => 0, 'y' => 0];
        $info = [
            'w' => max(0, $contentW),
            'h' => max(0, $contentH),
            'x' => $prev['x'],
            'y' => $prev['y'],
        ];

        // 夹取滚动位置到有效范围
        $maxX = max(0, $info['w'] - $client['w']);
        $maxY = max(0, $info['h'] - $client['h']);
        $info['x'] = max(0, min($info['x'], $maxX));
        $info['y'] = max(0, min($info['y'], $maxY));
        $this->areaScrollInfo[$hwnd] = $info;

        // 设置滚动条范围与页面大小
        if ($contentW > 0) {
            $si = $this->buildScrollInfo(0, $info['w'], $client['w'], $info['x']);
            $this->user32->SetScrollInfo($h, self::SB_HORZ, \FFI::addr($si), 1);
        }
        if ($contentH > 0) {
            $si = $this->buildScrollInfo(0, $info['h'], $client['h'], $info['y']);
            $this->user32->SetScrollInfo($h, self::SB_VERT, \FFI::addr($si), 1);
        }

        $this->user32->InvalidateRect($h, null, 1);
    }

    /**
     * 程序化滚动 Area 到指定内容坐标。
     */
    public function areaScrollTo(int $hwnd, int $x, int $y): void
    {
        $info = $this->areaScrollInfo[$hwnd] ?? null;
        if ($info === null) {
            return;
        }
        $client = $this->controlClientSize($hwnd);
        $maxX = max(0, $info['w'] - $client['w']);
        $maxY = max(0, $info['h'] - $client['h']);
        $info['x'] = max(0, min($x, $maxX));
        $info['y'] = max(0, min($y, $maxY));
        $this->areaScrollInfo[$hwnd] = $info;

        $h = $this->intToHwnd($hwnd);
        // 仅更新位置（SIF_POS）
        $si = $this->user32->new('SCROLLINFO');
        $si->cbSize = \FFI::sizeof($si);
        $si->fMask = self::SIF_POS;
        $si->nPos = $info['x'];
        $this->user32->SetScrollInfo($h, self::SB_HORZ, \FFI::addr($si), 1);
        $si->nPos = $info['y'];
        $this->user32->SetScrollInfo($h, self::SB_VERT, \FFI::addr($si), 1);

        $this->user32->InvalidateRect($h, null, 1);
    }

    /**
     * 获取 Area 当前滚动位置。
     *
     * @return array{x:int, y:int}
     */
    public function areaGetScrollPos(int $hwnd): array
    {
        $info = $this->areaScrollInfo[$hwnd] ?? null;
        if ($info === null) {
            return ['x' => 0, 'y' => 0];
        }
        return ['x' => $info['x'], 'y' => $info['y']];
    }

    /**
     * 构建 SCROLLINFO 结构体（SIF_RANGE|SIF_PAGE|SIF_POS）。
     *
     * @param int $min   最小值（一般为 0）。
     * @param int $max   最大值（内容尺寸 - 1 或内容尺寸，按惯例用 max(0, content - 1)）。
     * @param int $page  页面大小（可视区域尺寸，用于隐藏无效滚动范围）。
     * @param int $pos   当前位置。
     */
    private function buildScrollInfo(int $min, int $max, int $page, int $pos): \FFI\CData
    {
        $si = $this->user32->new('SCROLLINFO');
        $si->cbSize = \FFI::sizeof($si);
        $si->fMask = self::SIF_RANGE | self::SIF_PAGE | self::SIF_POS;
        $si->nMin = $min;
        $si->nMax = max($min, $max - 1); // nMax 用 content-1，page 自动夹取
        $si->nPage = max(0, $page);
        $si->nPos = $pos;
        return $si;
    }

    /**
     * 获取控件客户区尺寸（宽/高）。
     *
     * @return array{w:int, h:int}
     */
    private function controlClientSize(int $hwnd): array
    {
        $rect = $this->user32->new('RECT');
        $this->user32->GetClientRect($this->intToHwnd($hwnd), \FFI::addr($rect));
        return [
            'w' => (int) $rect->right,
            'h' => (int) $rect->bottom,
        ];
    }

    /**
     * 创建绘图上下文（BeginPaint + GDI+ Graphics）。
     *
     * 由 WindowProc WM_PAINT 调用，用户通过 Area onDraw 回调拿到 DrawContext。
     * PAINTSTRUCT CData 保活到 drawContextFree（EndPaint 配对）。
     */
    public function drawContextCreate(int $hwnd): mixed
    {
        $h = $this->intToHwnd($hwnd);
        $ps = $this->user32->new('PAINTSTRUCT');
        $this->user32->BeginPaint($h, \FFI::addr($ps));
        // HDC 跨作用域：user32 void* → int → DrawContext 内部按需转 gdi32/gdiplus
        $hdcInt = $this->ptrToInt($ps->hdc);
        return new DrawContext($this, $ps, $h, $hdcInt);
    }

    /**
     * 释放绘图上下文（恢复 GDI 对象栈 + GDI+ 释放 + EndPaint）。
     */
    public function drawContextFree(mixed $ctx): void
    {
        if ($ctx instanceof DrawContext) {
            $ctx->free();
        }
    }

    // ----------------------------------------------------------------
    // 绘图委托方法（转发到 DrawContext 对象方法）
    // ----------------------------------------------------------------

    public function drawLine(mixed $ctx, int $x1, int $y1, int $x2, int $y2): void
    {
        if ($ctx instanceof DrawContext) {
            $ctx->drawLine($x1, $y1, $x2, $y2);
        }
    }

    public function drawRect(mixed $ctx, int $x, int $y, int $width, int $height): void
    {
        if ($ctx instanceof DrawContext) {
            $ctx->drawRect($x, $y, $width, $height);
        }
    }

    public function drawEllipse(mixed $ctx, int $x, int $y, int $width, int $height): void
    {
        if ($ctx instanceof DrawContext) {
            $ctx->drawEllipse($x, $y, $width, $height);
        }
    }

    public function drawText(mixed $ctx, int $x, int $y, string $text): void
    {
        if ($ctx instanceof DrawContext) {
            $ctx->drawText($x, $y, $text);
        }
    }

    public function drawTextAttributed(mixed $ctx, int $x, int $y, int $attributedStringId): void
    {
        if ($ctx instanceof DrawContext) {
            $ctx->drawTextAttributed($x, $y, $attributedStringId);
        }
    }

    public function setPen(mixed $ctx, Color $color, int $width): void
    {
        if ($ctx instanceof DrawContext) {
            $ctx->setPen($color, $width);
        }
    }

    public function setBrush(mixed $ctx, Color $color): void
    {
        if ($ctx instanceof DrawContext) {
            $ctx->setBrush($color);
        }
    }

    public function setFont(mixed $ctx, string $name, int $size): void
    {
        if ($ctx instanceof DrawContext) {
            $ctx->setFont($name, $size);
        }
    }

    public function setColor(mixed $ctx, Color $color): void
    {
        if ($ctx instanceof DrawContext) {
            $ctx->setColor($color);
        }
    }
}
