# 系统托盘与窗口图标

> **平台限制**：系统托盘为 Windows 特有功能，仅在 Windows 平台可用。

## TrayIcon 系统托盘

`Kingbes\Ui\TrayIcon` 封装 `Shell_NotifyIconW`，提供托盘图标、提示文本、气球通知和右键菜单。

### 创建托盘

```php
use Kingbes\Ui\TrayIcon;
use Kingbes\Ui\Window;

$win = new Window("主窗口", 640, 480);
$win->onClose = fn() => App::quit();

// 创建托盘图标（关联到窗口，用于接收回调消息）
$tray = new TrayIcon($win, "我的应用");
$tray->setIconFromIconId(TrayIcon::IDI_APPLICATION);
```

### 构造器参数

```php
new TrayIcon(Window $window, string $tooltip = '')
```

| 参数 | 类型 | 说明 |
| --- | --- | --- |
| `$window` | Window | 关联窗口，用于接收 WM_TRAYICON 回调消息 |
| `$tooltip` | string | 鼠标悬停提示文本（最多 128 字符） |

---

## 图标设置

支持三种图标加载方式：

### 1. 从 .ico 文件加载

```php
$tray->setIconFromFile('C:/path/to/icon.ico');
```

### 2. 从预定义系统图标加载

```php
$tray->setIconFromIconId(TrayIcon::IDI_APPLICATION);
```

预定义系统图标常量：

| 常量 | 说明 |
| --- | --- |
| `TrayIcon::IDI_APPLICATION` | 默认应用图标 |
| `TrayIcon::IDI_HAND` | 错误/停止（红色 X） |
| `TrayIcon::IDI_QUESTION` | 询问（蓝色 ?） |
| `TrayIcon::IDI_EXCLAMATION` | 警告（黄色 !） |
| `TrayIcon::IDI_ASTERISK` | 信息（蓝色 i） |

> 系统图标由系统共享，不需要 DestroyIcon。

### 3. 从 Image 对象加载（PNG/JPEG/BMP/GIF/TIFF）

```php
use Kingbes\Ui\Graphics\Image;

$img = Image::fromFile('C:/path/to/logo.png');  // 支持 alpha 透明通道
$tray->setIconFromImage($img);
```

> 内部通过 GDI+ `GdipCreateHICONFromBitmap` 转换为 HICON，析构时自动 DestroyIcon。

---

## 提示文本

```php
$tray->setTooltip("我的应用 - 运行中");
```

鼠标悬停在托盘图标上时显示，最多 128 字符。

---

## 气球通知

```php
$tray->showBalloon(
    title: "通知标题",
    message: "这是通知内容",
    type: TrayIcon::BALLOON_INFO,
    timeoutMs: 5000
);
```

### 气球类型

| 常量 | 说明 | 图标 |
| --- | --- | --- |
| `TrayIcon::BALLOON_NONE` | 无图标 | 无 |
| `TrayIcon::BALLOON_INFO` | 信息 | 蓝色 i |
| `TrayIcon::BALLOON_WARNING` | 警告 | 黄色 ! |
| `TrayIcon::BALLOON_ERROR` | 错误 | 红色 X |

`timeoutMs` 是建议超时时间（系统可能调整），用户也可提前点击关闭。

---

## 事件回调

### 鼠标事件

```php
// 左键点击（抬起时触发）
$tray->onClick = function () {
    echo "托盘被左键点击\n";
};

// 左键双击
$tray->onDoubleClick = function () {
    echo "托盘被双击\n";
};

// 右键点击（默认弹出 contextMenu，若已设置）
$tray->onRightClick = function () {
    echo "托盘被右键点击\n";
};
```

### 气球事件

```php
// 用户点击气球通知
$tray->onBalloonClick = function () {
    echo "气球被点击，可打开详情窗口\n";
};

// 气球超时消失（用户未点击）
$tray->onBalloonTimeout = function () {
    echo "气球超时消失\n";
};
```

### 通知消息常量

底层 lParam 值（一般无需直接使用）：

| 常量 | 值 | 说明 |
| --- | --- | --- |
| `TrayIcon::NIN_BALLOONSHOW` | 0x0402 | 气球显示 |
| `TrayIcon::NIN_BALLOONHIDE` | 0x0403 | 气球隐藏（托盘图标被删除） |
| `TrayIcon::NIN_BALLOONTIMEOUT` | 0x0404 | 气球超时 |
| `TrayIcon::NIN_BALLOONUSERCLICK` | 0x0405 | 用户点击气球 |

---

## 右键上下文菜单

```php
use Kingbes\Ui\Menu\Menu;

$menu = new Menu(false);  // false = 弹出菜单

$showItem = $menu->addItem("显示窗口");
$showItem->onClick = fn() => $win->show();

$hideItem = $menu->addItem("隐藏窗口");
$hideItem->onClick = fn() => $win->hide();

$menu->addSeparator();

$notifyItem = $menu->addItem("发送通知");
$notifyItem->onClick = fn() => $tray->showBalloon(
    "提示",
    "这是来自菜单的通知",
    TrayIcon::BALLOON_INFO,
    3000
);

$menu->addSeparator();

$exitItem = $menu->addItem("退出");
$exitItem->onClick = fn() => App::quit();

// 关联到托盘
$tray->setContextMenu($menu);
```

右键点击托盘图标时自动弹出菜单。

### 菜单命令分发

托盘菜单命令通过 `WM_COMMAND` 分发。框架内部在 `dispatchWmCommand` 中：
1. 先在窗口菜单栏（`Window::getMenu()`）查找菜单项
2. 找不到再遍历所有 `TrayIcon` 的 `contextMenu` 查找

> 因此托盘菜单项的 onClick 回调能正确触发。

---

## 资源管理

`TrayIcon` 析构时会自动：
1. 调用 `Shell_NotifyIconW(NIM_DELETE)` 移除托盘图标
2. 若 `ownsIcon=true`（从 Image 加载的图标），调用 `DestroyIcon` 释放

```php
// 手动销毁
$tray->remove();  // 仅从托盘移除，不销毁对象

// 重新添加
$tray->addOrUpdate();

// 修改图标/提示后需调用 addOrUpdate（setIcon* 方法内部已自动调用）
$tray->setTooltip("新提示");  // 内部自动 NIM_MODIFY
```

---

## 常见模式

### 模式 1：关闭窗口隐藏到托盘

```php
$win->onClose = function () use (&$win, $tray) {
    $win->hide();  // 隐藏而非退出
    $tray->showBalloon(
        "提示",
        "应用已最小化到托盘，右键托盘退出",
        TrayIcon::BALLOON_INFO,
        3000
    );
};

// 托盘左键点击切换显示/隐藏
$tray->onClick = function () use (&$win) {
    if ($win->isFocused()) {
        $win->hide();
    } else {
        $win->show();
    }
};
```

### 模式 2：多托盘图标

```php
$tray1 = new TrayIcon($win, "图标 1");
$tray1->setIconFromIconId(TrayIcon::IDI_APPLICATION);

$tray2 = new TrayIcon($win, "图标 2");
$tray2->setIconFromIconId(TrayIcon::IDI_ASTERISK);

// 每个托盘有独立的 trayId，事件独立分发
```

### 模式 3：动态切换图标

```php
$icons = [
    TrayIcon::IDI_APPLICATION,
    TrayIcon::IDI_HAND,
    TrayIcon::IDI_QUESTION,
    TrayIcon::IDI_EXCLAMATION,
    TrayIcon::IDI_ASTERISK,
];
$idx = 0;

$tray->onDoubleClick = function () use ($tray, $icons, &$idx) {
    $idx = ($idx + 1) % count($icons);
    $tray->setIconFromIconId($icons[$idx]);
};
```

---

## 窗口图标

`Window` 类提供三种设置图标的方法，**跨平台支持**（Windows 完整实现）。

### 1. 从 .ico 文件加载

```php
$win->setIconFromFile('C:/path/to/app.ico');
```

### 2. 从预定义系统图标加载

```php
use Kingbes\Ui\TrayIcon;

$win->setIconFromId(TrayIcon::IDI_APPLICATION);
// 可选：IDI_APPLICATION / IDI_HAND / IDI_QUESTION / IDI_EXCLAMATION / IDI_ASTERISK
```

### 3. 从 Image 对象加载（PNG/JPEG/BMP/GIF/TIFF）

```php
use Kingbes\Ui\Graphics\Image;

$img = Image::fromFile('C:/path/to/logo.png');
$win->setIconFromImage($img);
```

### 显示位置

窗口图标会同时设置：
- **ICON_BIG**：Alt+Tab 切换窗口时显示的大图标
- **ICON_SMALL**：标题栏左上角和任务栏显示的小图标

### 资源管理

- 从 Image 加载的图标由窗口持有，下次设置前或窗口销毁时自动 DestroyIcon
- 系统图标共享，无需释放

### 完整示例：自定义图标应用

```php
<?php
use Kingbes\Ui\App;
use Kingbes\Ui\Window;
use Kingbes\Ui\TrayIcon;
use Kingbes\Ui\Graphics\Image;
use Kingbes\Ui\Menu\Menu;

$win = new Window("我的应用", 800, 600);

// 加载自定义图标（PNG，支持透明通道）
$appIcon = Image::fromFile(__DIR__ . '/assets/app.png');
$win->setIconFromImage($appIcon);

// 托盘也使用同一图标
$tray = new TrayIcon($win, "我的应用");
$tray->setIconFromImage($appIcon);

// 托盘菜单
$menu = new Menu(false);
$menu->addItem("显示")->onClick = fn() => $win->show();
$menu->addItem("退出")->onClick = fn() => App::quit();
$tray->setContextMenu($menu);

// 关闭到托盘
$win->onClose = fn() => $win->hide();

$win->show();
App::run();
```
