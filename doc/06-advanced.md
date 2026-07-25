# 高级主题

## App 静态门面

`Kingbes\Ui\App` 是应用入口，所有平台能力通过静态方法访问。

### 主要 API

| 方法 | 说明 |
| --- | --- |
| `App::run()` | 进入事件循环（阻塞） |
| `App::quit()` | 退出事件循环 |
| `App::onShouldQuit(callable)` | 注册退出确认回调（返回 false 阻止退出） |
| `App::timer(int $ms, callable): int` | 注册周期定时器，返回 timer ID |
| `App::clearTimer(int $id)` | 取消定时器 |
| `App::queueMain(\Closure $fn)` | 投递闭包到主线程，下一轮循环执行 |
| `App::platform(): PlatformInterface` | 获取平台实例（一般无需直接调用） |

### 事件循环

```php
$win->show();
App::run();  // 阻塞直到所有窗口关闭或调用 App::quit()
echo "应用已退出\n";
```

### 退出确认

```php
App::onShouldQuit(function (): bool {
    // 返回 false 阻止退出
    return Dialogs::msgBoxAsk($mainWin, "确定退出？");
});
```

---

## 定时器

基于 `hrtime(true)` 实现，不依赖 FFI SetTimer。

```php
// 周期性定时器
$timerId = App::timer(1000, function (int $id) {
    static $count = 0;
    $count++;
    echo "tick #{$count}\n";

    if ($count >= 10) {
        App::clearTimer($id);
    }
});

// 取消
App::clearTimer($timerId);
```

### 注意事项

- 最小间隔约 1ms（受 `usleep(1000)` 轮询限制）
- 回调异常不会中断主循环（仅触发 E_USER_WARNING）
- 回调内 `timer()` / `clearTimer()` 安全，不会破坏迭代
- 回调签名：`fn(int $timerId): void`

---

## queueMain

把闭包投递到主线程队列，下一轮事件循环执行。常用于：
- 工作线程更新 UI（本库非线程安全，仅作示意）
- 异步操作的回调
- 延迟到当前事件处理完毕后再执行

```php
App::queueMain(function () {
    echo "下一轮循环执行\n";
});

// 唤醒主循环
// WindowsPlatform 重写 wakeUpMainLoop() 用 PostMessageA(WM_NULL)
// PeekMessageA 轮询模式不需要唤醒
```

### 应用场景：分步处理

```php
function processStep(int $step): void
{
    if ($step >= 10) {
        echo "完成\n";
        return;
    }

    // 处理第 $step 步...
    echo "处理步骤 $step\n";

    // 投递下一步到队列
    App::queueMain(fn() => processStep($step + 1));
}

processStep(0);
```

---

## 进程管理

`Kingbes\Ui\Process` 用于启动子进程并捕获输出。

### 启动进程

```php
use Kingbes\Ui\Process;

$proc = Process::start(
    cmd: 'ping example.com',
    onLine: function (string $line) {
        echo "[stdout] $line";
    },
    onExit: function (int $code) {
        echo "[exit] code=$code";
    }
);
```

### 同步等待

```php
$proc = Process::start('dir');
$exitCode = $proc->wait();  // 阻塞直到进程退出
echo "退出码: $exitCode";
```

### 异步轮询

```php
$proc = Process::start('long-running-task', onLine: fn($line) => print($line));

// 在定时器中检查状态
App::timer(100, function ($id) use ($proc) {
    if (!$proc->isRunning()) {
        echo "退出码: " . $proc->getExitCode() . "\n";
        App::clearTimer($id);
    }
});
```

### 停止进程

```php
$proc->stop();
```

### API

| 方法 | 说明 |
| --- | --- |
| `Process::start(string $cmd, ?\Closure $onLine, ?\Closure $onExit): self` | 启动子进程 |
| `$proc->isRunning(): bool` | 是否仍在运行 |
| `$proc->getExitCode(): int` | 获取退出码（需 wait 后或 isRunning()=false） |
| `$proc->stop(): void` | 终止进程 |
| `$proc->wait(): int` | 阻塞等待，返回退出码 |

> `onLine` 回调在每行输出时触发（stdout 和 stderr 合并）。

---

## 多窗口

```php
use Kingbes\Ui\App;
use Kingbes\Ui\Window;

$main = new Window("主窗口", 800, 600);
$main->onClose = fn() => App::quit();

$about = new Window("关于", 400, 300);
$about->onClose = fn() => $about->hide();  // 仅隐藏，不退出

// 主窗口菜单"关于"打开子窗口
$aboutMenu = (new Menu(false))->addItem("关于");
$aboutMenu->onClick = fn() => $about->show();

$main->show();
App::run();
```

### 多窗口规则

- 所有窗口都在同一线程、同一事件循环
- 任意窗口的 `onClose` 调用 `App::quit()` 会退出整个应用
- 仅主窗口 `onClose` 调用 `App::quit()`，子窗口 `onClose` 调用 `$win->hide()` 可实现"关闭子窗口仅隐藏"
- 当所有窗口都关闭（GC）后事件循环自动退出

---

## 事件对象

### ResizeEvent

```php
$win->onResize = function ($event) {
    echo "新尺寸: {$event->width}x{$event->height}\n";
};
```

### MouseEvent

```php
$control->onMouseDown = function ($e) {
    echo "位置: {$e->x},{$e->y}\n";
    echo "按钮: {$e->button}\n";  // 'left' / 'right' / 'middle'
};
```

| 属性 | 类型 | 说明 |
| --- | --- | --- |
| `x` | int | 相对控件左上角的 x 坐标 |
| `y` | int | 相对控件左上角的 y 坐标 |
| `button` | string | 'left' / 'right' / 'middle' |
| `ctrl` | bool | Ctrl 键是否按下 |
| `shift` | bool | Shift 键是否按下 |
| `alt` | bool | Alt 键是否按下 |

### KeyEvent

```php
$control->onKeyDown = function ($e) {
    echo "键码: {$e->code}\n";
    if ($e->ctrl && $e->code === ord('S')) {
        save();
    }
};
```

| 属性 | 类型 | 说明 |
| --- | --- | --- |
| `code` | int | 虚拟键码（VK_*） |
| `ctrl` | bool | Ctrl 键是否按下 |
| `shift` | bool | Shift 键是否按下 |
| `alt` | bool | Alt 键是否按下 |

---

## 自定义平台后端

`PlatformInterface` 是平台后端契约，所有窗口/控件/菜单句柄统一用 `int` 传递。

### 实现新平台

```php
namespace Kingbes\Ui\Platform\Linux;

use Kingbes\Ui\Platform\AbstractPlatform;

class GtkPlatform extends AbstractPlatform
{
    // 实现所有 abstract 方法
}
```

### 平台映射

`App::PLATFORM_MAP` 按 `PHP_OS_FAMILY` 选择后端：

```php
private const PLATFORM_MAP = [
    'Windows' => \Kingbes\Ui\Platform\Windows\WindowsPlatform::class,
    'Linux'   => \Kingbes\Ui\Platform\Linux\GtkPlatform::class,
    'Darwin'  => \Kingbes\Ui\Platform\Mac\CocoaPlatform::class,
];
```

### 自定义平台注入

```php
App::setPlatform(new MyCustomPlatform());
App::run();
```

> 一般用于测试或 mock 平台。

### 跨 FFI 作用域转换

本库内部大量使用 `INT_TO_PTR` 联合体在不同 FFI 作用域（user32/gdi32/shell32）间转换句柄：

```php
// int → HWND CData
$caster = $this->user32->new('INT_TO_PTR');
$caster->i = $hwndInt;
$hwndC = $caster->p;  // HWND

// HWND CData → int
$caster = $this->user32->new('INT_TO_PTR');
$caster->p = $hwndC;
$hwndInt = (int) $caster->i;
```

> 静态 `\FFI::cast()` 在 PHP 8.5+ 已废弃，必须用 FFI 实例方法 `$ffi->cast(type, cdata)`，且必须用创建该 CData 的同一 FFI 实例。

---

## 编码约定

### GC 防护

Window/Control 实例必须注册到平台注册表防止被 GC 回收：

```php
// Window 构造器末尾
App::platform()->registerWindow($this->hwnd, $this);

// Control 子类构造器末尾必须调用
parent::__construct($parent);
```

### 句柄传递

对外只暴露 `int` 句柄，FFI CData 不跨作用域传递：

```php
// 对外
public function getHwnd(): int { return $this->hwnd; }

// 平台内部
$hwndC = $this->intToHwnd($hwnd);  // int → HWND CData
```

### 资源释放

- Window：`WM_DESTROY` 自动注销并销毁子控件
- Control：`destroy()` 显式销毁
- Image/DrawPath/GradientBrush：`free()` 显式释放或依赖 `__destruct`

---

## 调试技巧

### 开启错误显示

```bash
php -d ffi.enable=true -d display_errors=1 -d error_reporting=E_ALL script.php
```

### 检查 FFI 是否启用

```php
if (!extension_loaded('ffi')) {
    die("FFI 扩展未加载");
}
```

### 输出调试信息

回调内 `echo` / `print` 会输出到控制台（启动 PHP 的终端）。

### 自动退出测试

```bash
$env:PHP_UI_AUTO_EXIT='1'; php -d ffi.enable=true examples/xxx_test.php
```

设环境变量 `PHP_UI_AUTO_EXIT=1` 后，示例会运行自动测试序列并在几秒后退出，用于 CI/无人值守验证。

---

## 性能优化

### 大表格

Table 使用 ListView 虚拟模式（LVS_OWNERDATA），仅按需请求数据。即使 100 万行也不会卡顿，因为 Model 按行号即时返回数据。

```php
$model = new class implements TableModel {
    public function getRowCount(): int { return 1_000_000; }
    public function getColumnCount(): int { return 3; }
    public function getCellValue(int $row, int $col): string {
        return "Row $row, Col $col";  // 实时计算，不存储
    }
};
```

### 复杂绘图

`Area::onDraw` 每次重绘都遍历整个客户区。复杂场景考虑：
- 分层：静态部分缓存到 Image，动态部分每帧绘制
- 裁剪：用 `setClipRect` 限制绘制区域
- 节流：用 timer 控制重绘频率

### 定时器节流

```php
$last = 0;
App::timer(16, function ($id) use (&$last) {  // 60 FPS
    $now = hrtime(true);
    if ($now - $last < 16_000_000) return;  // 16ms
    $last = $now;
    // 实际逻辑
});
```

---

## 主题切换（跨平台）

`Kingbes\Ui\Theme` 提供 4 种主题预设，由 `App::setTheme()` 在应用启动前选定。各平台的实现：

- **Windows**：影响 ComCtl32 v6 视觉样式（manifest 激活）、Win10/11 标题栏深色模式（uxtheme `SetPreferredAppMode`）以及控件默认字体。
- **Linux GTK**：通过 `g_object_set_property` 修改 `GtkSettings` 单例的 `gtk-application-prefer-dark-theme` 布尔属性。`Theme::DARK` 设为 `true` 启用深色偏好；`Theme::LIGHT`/`CLASSIC`/`SYSTEM` 设为 `false`，由 GTK 跟随系统或保持浅色。
- **macOS Cocoa**：通过 `[NSApp setAppearance:]` 切换应用级 `NSAppearance`。`Theme::DARK` → `NSAppearanceNameDarkAqua`，`Theme::LIGHT` → `NSAppearanceNameAqua`，`Theme::SYSTEM`/`CLASSIC` → `nil`（跟随系统）。

> 说明：`setAppTheme()` 是跨平台 API，三个后端各有具体实现。`Window::setDarkMode()` 仅 Windows 有单窗口标题栏深色实现（`DwmSetWindowAttribute`），GTK 与 macOS 继承空实现——GTK 深色偏好只能全局设置，macOS 窗口外观跟随应用级 `NSAppearance`。

### 主题预设

| 常量 | 值 | 行为 |
| --- | --- | --- |
| `Theme::SYSTEM` | `'system'` | 默认值。跟随系统设置，启用 ComCtl32 v6 视觉样式，uxtheme 调用 `SetPreferredAppMode(Default)` |
| `Theme::CLASSIC` | `'classic'` | 经典外观。不加载 ComCtl32 v6 manifest、不调用 uxtheme，呈现 Windows 9x 时代原生控件外观，适用于远程桌面、低配环境或需要严格一致性输出的场景 |
| `Theme::DARK` | `'dark'` | 强制深色。启用 ComCtl32 v6，uxtheme 调用 `SetPreferredAppMode(ForceDark)`，控件默认字体为 "Segoe UI" 9pt |
| `Theme::LIGHT` | `'light'` | 强制浅色。启用 ComCtl32 v6，uxtheme 调用 `SetPreferredAppMode(ForceLight)`，控件默认字体为 "Segoe UI" 9pt |

### App::setTheme()

```php
use Kingbes\Ui\App;
use Kingbes\Ui\Theme;

App::setTheme(Theme::DARK);   // 必须在 App::run() 之前
App::setTheme('dark');        // 等价，直接传字符串也可
```

调用规则：

- **必须在 `App::run()` 之前、任何 Window/Control 创建之前调用**；否则平台已初始化，仅触发 `E_USER_WARNING` 并忽略
- 非法值抛 `InvalidArgumentException`
- 不调用时默认 `Theme::SYSTEM`
- 调用 `App::getTheme()` 可读取当前已设置的主题（字符串形式）

### Window::setDarkMode()

单独控制某个窗口的标题栏深色（链式调用）：

```php
$win = new Window("编辑器", 800, 600);
$win->setDarkMode(true);   // 标题栏变深色
$win->setDarkMode(false);  // 标题栏变浅色
```

底层调用 `DwmSetWindowAttribute(DWMWA_USE_IMMERSIVE_DARK_MODE)`。Windows 10 1809 之前的版本不支持该属性，调用会静默失败（不抛异常）。已通过 `App::setTheme(Theme::DARK)` 全局启用深色时，可对单个窗口用此方法反向覆盖为浅色。

### CLASSIC 主题兼容场景

`Theme::CLASSIC` 不加载 ComCtl32 v6 manifest，控件使用经典 3D 边框外观，适合：

- 远程桌面 / 终端服务下避免视觉样式渲染开销
- 截图自动化测试，需要稳定像素对比
- 与老旧第三方控件混排时减少视觉冲突

### 完整示例

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Kingbes\Ui\App;
use Kingbes\Ui\Theme;
use Kingbes\Ui\Window;
use Kingbes\Ui\Layout\VBox;
use Kingbes\Ui\Control\Button;
use Kingbes\Ui\Control\Label;

// 1. 在创建任何窗口/控件之前设置主题
App::setTheme(Theme::DARK);

$win = new Window("主题示例", 480, 320);
$win->onClose = fn() => App::quit();
$win->setMargined(12);

$root = new VBox($win);
$label = new Label($root, "当前主题: " . App::getTheme());
$root->add($label);

// 2. 单窗口反向覆盖为浅色标题栏
$btn = new Button($root, "浅色标题栏");
$btn->onClick = fn() => $win->setDarkMode(false);
$root->add($btn);

$win->setChild($root);
$win->show();
App::run();
```

### 示例文件

每种主题各有一个独立示例，直接运行即可对比效果：

```bash
php -d ffi.enable=true examples/theme_system.php    # 跟随系统（默认）
php -d ffi.enable=true examples/theme_classic.php   # 经典灰外观（不加载 ComCtl32 v6）
php -d ffi.enable=true examples/theme_dark.php      # 强制深色
php -d ffi.enable=true examples/theme_light.php     # 强制浅色
```

每个示例在代码顶部硬编码 `App::setTheme(Theme::XXX)`，展示同一组控件（Button/Entry/Checkbox/ComboBox/ProgressBar/Slider）在不同主题下的外观。运行后关闭窗口即可退出，再运行下一个对比。

---

## 完整应用示例

参见 `examples/full_test.php`，演示了：
- 多窗口管理
- 菜单栏 + 弹出菜单
- 所有控件类型
- 布局嵌套
- 对话框调用
- 自定义绘图
- 定时器
- 进程管理
