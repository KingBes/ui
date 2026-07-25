# 菜单与对话框

## Menu 菜单系统

`Kingbes\Ui\Menu\Menu` 类支持菜单栏（Bar）和弹出菜单（Popup）两种形式。

### 菜单栏

```php
use Kingbes\Ui\Menu\Menu;
use Kingbes\Ui\Control\Label;

// 创建菜单栏
$menuBar = new Menu(true);  // true = 菜单栏

// 文件菜单（子菜单）
$fileMenu = new Menu(false);
$fileMenu->addItem("新建")->onClick = fn() => print("新建");
$fileMenu->addItem("打开")->onClick = fn() => print("打开");
$fileMenu->addSeparator();
$exitItem = $fileMenu->addItem("退出");
$exitItem->onClick = fn() => App::quit();

// 把子菜单加到菜单栏
$menuBar->addSubmenu("文件", $fileMenu);

// 编辑菜单
$editMenu = new Menu(false);
$editMenu->addItem("复制");
$editMenu->addItem("粘贴");

$menuBar->addSubmenu("编辑", $editMenu);

// 挂到窗口
$win->setMenu($menuBar);
```

### MenuItem API

```php
$item = $menu->addItem("文本");

// 状态
$item->setEnabled(false);
$item->setEnabled(true);
$item->setChecked(true);
$item->isChecked();

// 子菜单
$submenu = new Menu(false);
$item->setSubmenu($submenu);

// 图标
use Kingbes\Ui\Graphics\Image;
$item->setImage(Image::fromFile('icon.png'));

// 事件
$item->onClick = fn() => print("clicked");

// 属性
echo $item->getText();
echo $item->getId();       // 内部 ID（用于事件分发）
```

### 弹出菜单（上下文菜单）

弹出菜单通常用于右键菜单，可独立使用或作为 TrayIcon 的右键菜单：

```php
$ctxMenu = new Menu(false);
$ctxMenu->addItem("复制");
$ctxMenu->addItem("粘贴");
$ctxMenu->addSeparator();
$ctxMenu->addItem("删除");
```

> TrayIcon 的右键菜单用法见 [05-tray-icon.md](05-tray-icon.md)。

### 分隔符

```php
$menu->addSeparator();
```

### 销毁

```php
$menu->destroy();
```

> 销毁窗口时会自动销毁挂在其上的菜单栏。

### 完整示例：带勾选状态的菜单

```php
$viewMenu = new Menu(false);

$showToolbar = $viewMenu->addItem("显示工具栏");
$showToolbar->setChecked(true);
$showToolbar->onClick = function () use ($showToolbar) {
    $showToolbar->setChecked(!$showToolbar->isChecked());
    echo "工具栏: " . ($showToolbar->isChecked() ? "显示" : "隐藏") . "\n";
};

$showStatus = $viewMenu->addItem("显示状态栏");
$showStatus->setChecked(true);
$showStatus->onClick = function () use ($showStatus) {
    $showStatus->setChecked(!$showStatus->isChecked());
};

$viewMenu->addSeparator();

// 单选效果（手动互斥）
$themeLight = $viewMenu->addItem("浅色主题");
$themeDark = $viewMenu->addItem("深色主题");
$themeLight->setChecked(true);

$themeLight->onClick = function () use ($themeLight, $themeDark) {
    $themeLight->setChecked(true);
    $themeDark->setChecked(false);
};
$themeDark->onClick = function () use ($themeLight, $themeDark) {
    $themeDark->setChecked(true);
    $themeLight->setChecked(false);
};

$menuBar = new Menu(true);
$menuBar->addSubmenu("视图", $viewMenu);
$win->setMenu($menuBar);
```

---

## Dialogs 对话框

`Kingbes\Ui\Dialogs` 是静态类，提供模态对话框。

> 所有对话框都是**模态**的：调用后阻塞当前线程，直到用户响应。对话框期间窗口消息循环会继续运行（用 `inModalDialog` 标记防止重入）。

### 消息框

```php
use Kingbes\Ui\Dialogs;

// 简单提示
Dialogs::msgBox($win, "保存成功");

// 自定义标题
Dialogs::msgBox($win, "操作完成", "提示");

// 错误框（红色 X 图标）
Dialogs::msgBoxError($win, "文件不存在");

// 警告框（黄色 ! 图标）
Dialogs::msgBoxWarn($win, "磁盘空间不足");

// 询问框（是/否按钮，返回 bool）
if (Dialogs::msgBoxAsk($win, "确定删除？", "询问")) {
    // 用户点击"是"
    deleteFile();
}
```

### 文件对话框

#### 打开文件

```php
// 基础用法（默认过滤器："所有文件|*.*"）
$file = Dialogs::openFile($win);
if ($file !== null) {
    echo "选择了: $file\n";
}

// 多个过滤器（格式："描述|通配符"，多组用 ";" 分隔）
$files = Dialogs::openFile($win, [
    "文本文件|*.txt;*.md",
    "图片|*.png;*.jpg;*.jpeg",
    "所有文件|*.*",
]);

if ($file === null) {
    echo "用户取消\n";
}
```

#### 保存文件

```php
$file = Dialogs::saveFile($win, [
    "PHP 文件|*.php",
    "所有文件|*.*",
]);

if ($file !== null) {
    file_put_contents($file, "<?php\n");
}
```

#### 选择文件夹

```php
$folder = Dialogs::openFolder($win, "选择项目目录");
if ($folder !== null) {
    echo "选择了文件夹: $folder\n";
}
```

### 颜色对话框

```php
use Kingbes\Ui\Graphics\Color;

$color = Dialogs::chooseColor($win);
if ($color !== null) {
    echo "选择了: RGB({$color->red}, {$color->green}, {$color->blue})\n";
    echo "COLORREF: 0x" . dechex($color->toColorRef()) . "\n";
}
```

### 字体对话框

```php
$font = Dialogs::chooseFont($win);
if ($font !== null) {
    echo "字体名: {$font['name']}\n";
    echo "字号: {$font['size']}\n";
    echo "粗体: " . ($font['bold'] ? '是' : '否') . "\n";
    echo "斜体: " . ($font['italic'] ? '是' : '否') . "\n";
}
```

返回的字体数组结构：

| 键 | 类型 | 说明 |
| --- | --- | --- |
| `name` | string | 字体名（如 "微软雅黑"） |
| `size` | int | 字号（点） |
| `bold` | bool | 是否粗体 |
| `italic` | bool | 是否斜体 |
| `underline` | bool | 是否下划线 |
| `strikeout` | bool | 是否删除线 |

---

## 剪贴板

```php
use Kingbes\Ui\Clipboard;

// 写入
Clipboard::setText("复制的文本");

// 读取
$text = Clipboard::getText();
echo $text;
```

> 仅支持文本格式，不支持图像或文件。

---

## 屏幕

```php
use Kingbes\Ui\Screen;

$size = Screen::size();
echo "屏幕: {$size->width}x{$size->height}\n";

// 简便方法
echo Screen::width() . "x" . Screen::height();
```

> 多显示器场景下返回主屏幕尺寸。

---

## 完整示例：记事本式应用

```php
<?php
use Kingbes\Ui\App;
use Kingbes\Ui\Window;
use Kingbes\Ui\Layout\VBox;
use Kingbes\Ui\Control\TextArea;
use Kingbes\Ui\Menu\Menu;
use Kingbes\Ui\Dialogs;

$win = new Window("简易记事本", 600, 400);
$win->onClose = fn() => App::quit();

$root = new VBox($win);
$editor = new TextArea($root);
$root->add($editor);

// 文件菜单
$fileMenu = new Menu(false);
$fileMenu->addItem("打开")->onClick = function () use ($win, $editor) {
    $file = Dialogs::openFile($win, ["文本文件|*.txt", "所有文件|*.*"]);
    if ($file !== null && is_file($file)) {
        $editor->setText(file_get_contents($file));
        $win->setTitle(basename($file) . " - 简易记事本");
    }
};
$fileMenu->addItem("保存")->onClick = function () use ($win, $editor) {
    $file = Dialogs::saveFile($win, ["文本文件|*.txt", "所有文件|*.*"]);
    if ($file !== null) {
        file_get_contents($file, $editor->getText());
        Dialogs::msgBox($win, "保存成功");
    }
};
$fileMenu->addSeparator();
$fileMenu->addItem("退出")->onClick = fn() => App::quit();

// 编辑菜单
$editMenu = new Menu(false);
$editMenu->addItem("复制")->onClick = fn() => Clipboard::setText($editor->getText());
$editMenu->addItem("粘贴")->onClick = fn() => $editor->setText(Clipboard::getText());

$menuBar = new Menu(true);
$menuBar->addSubmenu("文件", $fileMenu);
$menuBar->addSubmenu("编辑", $editMenu);
$win->setMenu($menuBar);

$win->setChild($root);
$win->show();
App::run();
```
