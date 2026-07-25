# 绘图与图像

## DrawContext 绘图上下文

`Kingbes\Ui\Graphics\DrawContext` 由 Area 控件在 `onDraw` 回调中提供，封装 GDI+ Graphics 对象。

```php
use Kingbes\Ui\Control\Area;
use Kingbes\Ui\Graphics\Color;

$area = new Area($parent);
$area->onDraw = function ($ctx) {
    // ctx 是 DrawContext 实例
    $ctx->setBrush(Color::rgb(255, 200, 0));
    $ctx->fillRect(10, 10, 100, 60);
};
```

> **重要**：`$ctx` 仅在 `onDraw` 回调内有效，回调结束后会自动释放 GDI 资源。请勿在回调外保存或使用 `$ctx`。

---

## 基础图形

### 线条

```php
$ctx->setPen(Color::rgb(255, 0, 0), 2);  // 颜色，宽度
$ctx->drawLine(0, 0, 200, 100);
```

### 矩形

```php
$ctx->setBrush(Color::rgb(0, 255, 0));
$ctx->setPen(Color::rgb(0, 0, 0), 1);

$ctx->drawRect(10, 10, 80, 40);   // 描边矩形
$ctx->fillRect(100, 10, 80, 40);  // 填充矩形
$ctx->strokeRect(200, 10, 80, 40); // 仅描边
```

### 椭圆

```php
$ctx->drawEllipse(10, 10, 80, 60);    // 描边
$ctx->fillEllipse(100, 10, 80, 60);   // 填充
$ctx->strokeEllipse(200, 10, 80, 60); // 仅描边
```

### 圆弧

```php
// 圆心矩形 + 起始角 + 扫掠角（度）
$ctx->drawArc(10, 10, 100, 100, 0, 180);  // 上半圆
```

### 贝塞尔曲线

```php
// 起点(10,10) → 控制点1(50,0) → 控制点2(100,100) → 终点(150,50)
$ctx->drawBezier(10, 10, 50, 0, 100, 100, 150, 50);
```

---

## 文本

### 普通文本

```php
$ctx->setFont('Segoe UI', 14);
$ctx->setColor(Color::black());
$ctx->drawText(10, 10, "Hello World");

// 多次调用 drawText 可换行（手动控制 y 坐标）
$ctx->drawText(10, 30, "第二行");
```

### 富文本（AttributedString）

`AttributedString` 支持多段不同字体/大小/颜色的文本。

```php
use Kingbes\Ui\Graphics\AttributedString;
use Kingbes\Ui\Graphics\Color;

$as = new AttributedString();
$as->append("普通文本 ", 'Segoe UI', 14, Color::black());
$as->append("红色粗体 ", 'Segoe UI', 16, Color::red());
$as->append("蓝色小字", 'Segoe UI', 10, Color::blue());

$area->onDraw = function ($ctx) use ($as) {
    $as->draw($ctx, 10, 10);
    // 或：$ctx->drawTextAttributed(10, 10, $as->getId());
};

// 度量
[$w, $h] = $as->measure($ctx);
```

---

## 路径系统（DrawPath）

`DrawPath` 用于构建复杂形状，支持填充和描边。

```php
use Kingbes\Ui\Graphics\DrawPath;

$path = $ctx->createPath(DrawPath::FILL_WINDING);

$path->moveTo(10, 10);
$path->lineTo(100, 10);
$path->lineTo(100, 100);
$path->lineTo(10, 100);
$path->closeFigure();  // 闭合

// 圆弧路径
$path->arcTo(50, 50, 80, 80, 0, 180);

// 三次贝塞尔
$path->bezierTo(50, 0, 100, 100, 150, 50);

// 二次贝塞尔
$path->quadTo(50, 0, 100, 100);

// 填充和描边
$ctx->setBrush(Color::rgb(255, 200, 0));
$ctx->fillPath($path);

$ctx->setPen(Color::rgb(255, 0, 0), 2);
$ctx->strokePath($path);

$path->free();  // 释放路径资源（或依赖 __destruct）
```

### 填充规则

| 常量 | 说明 |
| --- | --- |
| `DrawPath::FILL_ALTERNATE` | 交替填充（奇偶规则，默认） |
| `DrawPath::FILL_WINDING` | 缠绕填充（非零规则） |

---

## 渐变画笔（GradientBrush）

线性渐变画笔，支持两色或多色停止点。

```php
use Kingbes\Ui\Graphics\GradientBrush;
use Kingbes\Ui\Graphics\Color;

// 两色渐变（起点颜色 → 终点颜色）
$brush = new GradientBrush(
    0, 0, 200, 100,                       // 起点(x1,y1) → 终点(x2,y2)
    Color::rgb(255, 0, 0),                // 起点颜色
    Color::rgb(0, 0, 255)                 // 终点颜色
);
$ctx->setGradientBrush($brush);
$ctx->fillRect(0, 0, 200, 100);
$ctx->setGradientBrush(null);  // 恢复默认画笔

// 多色停止点（0.0 ~ 1.0）
$brush->setStops([
    [0.0, Color::rgb(255, 0, 0)],
    [0.5, Color::rgb(0, 255, 0)],
    [1.0, Color::rgb(0, 0, 255)],
]);

$brush->free();  // 释放
```

---

## 变换矩阵

支持平移、缩放、旋转，配合状态栈使用。

```php
// 保存当前状态
$state = $ctx->save();

$ctx->translate(100, 100);   // 平移原点到 (100,100)
$ctx->rotate(45);             // 顺时针旋转 45 度
$ctx->scale(2, 2);            // 放大 2 倍

// 在变换后的坐标系下绘制
$ctx->fillRect(-25, -25, 50, 50);

// 恢复到保存的状态
$ctx->restore($state);
```

> `save()` 返回状态句柄（int），`restore($state)` 恢复到该状态。状态栈可嵌套。

---

## 裁剪

```php
// 矩形裁剪
$ctx->setClipRect(10, 10, 100, 100);
$ctx->fillRect(0, 0, 500, 500);  // 只有 (10,10,100,100) 区域可见

// 路径裁剪
$path = $ctx->createPath();
$path->moveTo(0, 0);
$path->lineTo(100, 0);
$path->lineTo(100, 100);
$path->closeFigure();
$ctx->setClipPath($path);

// 重置裁剪
$ctx->resetClip();
```

---

## 图像绘制

### Image 类

```php
use Kingbes\Ui\Graphics\Image;

$img = Image::fromFile('C:/path/to/photo.png');
echo $img->getWidth() . "x" . $img->getHeight();
```

支持格式：BMP / PNG / JPEG / GIF / TIFF（由 GDI+ 加载）。

### 绘制

```php
// 原始尺寸
$ctx->drawImage($img, 10, 10);

// 缩放绘制
$ctx->drawImageScaled($img, 10, 10, 200, 150);

// 裁剪绘制（源矩形 → 目标矩形）
$ctx->drawImageCropped(
    $img,
    10, 10, 100, 100,         // 目标位置和尺寸
    50, 50, 200, 200          // 源图像的子矩形
);
```

### 释放

```php
$img->free();  // 主动释放（或依赖 __destruct）
```

---

## 颜色（Color）

`Color` 是不可变值对象，使用 readonly 属性。

### 创建

```php
use Kingbes\Ui\Graphics\Color;

$c1 = Color::rgb(255, 0, 0);              // 不透明红色
$c2 = Color::rgba(0, 255, 0, 128);        // 半透明绿色
$c3 = Color::fromColorRef(0x0000FF);      // 从 COLORREF（BGR）创建
$ref = $c1->toColorRef();                  // 转 COLORREF
[$r, $g, $b, $a] = $c1->toArray();
```

### 预定义颜色

```php
Color::black() / white() / red() / green() / lime() / blue() / navy()
Color::yellow() / cyan() / magenta() / gray() / grey() / silver()
Color::maroon() / purple() / olive() / teal() / orange() / transparent()
```

---

## 完整示例：综合绘图

```php
use Kingbes\Ui\Control\Area;
use Kingbes\Ui\Graphics\Color;
use Kingbes\Ui\Graphics\DrawPath;
use Kingbes\Ui\Graphics\GradientBrush;
use Kingbes\Ui\Graphics\AttributedString;
use Kingbes\Ui\Graphics\Image;

$area = new Area($win);
$area->onDraw = function ($ctx) {
    // 1. 渐变背景
    $bg = new GradientBrush(0, 0, 400, 300, Color::rgb(135, 206, 235), Color::rgb(255, 223, 0));
    $ctx->setGradientBrush($bg);
    $ctx->fillRect(0, 0, 400, 300);
    $ctx->setGradientBrush(null);

    // 2. 太阳（带光线）
    $sunCenter = [320, 60];
    $ctx->setBrush(Color::rgb(255, 200, 0));
    $ctx->fillEllipse(300, 40, 40, 40);

    $ctx->setPen(Color::rgb(255, 200, 0), 2);
    for ($i = 0; $i < 8; $i++) {
        $angle = $i * M_PI / 4;
        $ctx->drawLine(
            $sunCenter[0] + cos($angle) * 25,
            $sunCenter[1] + sin($angle) * 25,
            $sunCenter[0] + cos($angle) * 40,
            $sunCenter[1] + sin($angle) * 40
        );
    }

    // 3. 路径：房屋
    $house = $ctx->createPath(DrawPath::FILL_WINDING);
    $house->moveTo(100, 200);
    $house->lineTo(200, 200);
    $house->lineTo(200, 150);
    $house->lineTo(150, 100);
    $house->lineTo(100, 150);
    $house->closeFigure();
    $ctx->setBrush(Color::rgb(210, 180, 140));
    $ctx->fillPath($house);

    $ctx->setPen(Color::rgb(100, 50, 0), 2);
    $ctx->strokePath($house);

    // 4. 富文本标题
    $title = new AttributedString();
    $title->append("我的", 'Segoe UI', 18, Color::rgb(255, 255, 255));
    $title->append("绘图", 'Segoe UI', 24, Color::rgb(255, 100, 100));
    $title->draw($ctx, 20, 20);
};

$win->setChild($area);
```

---

## Area 滚动

```php
$area = new Area($win);
$area->setSize(2000, 1500);  // 设置虚拟内容尺寸，启用滚动条

$area->onDraw = function ($ctx) {
    // 在内容坐标系（0,0 ~ 2000,1500）绘制
    $ctx->fillRect(0, 0, 2000, 1500);
    // ... 框架自动应用滚动偏移
};

// 程序化滚动
$area->scrollTo(500, 300);

// 获取当前滚动位置
$pos = $area->getScrollPos();  // ['x' => int, 'y' => int]

// 鼠标坐标自动转换为内容坐标系
$area->onMouseDown = function ($e) {
    echo "点击内容坐标: {$e->x},{$e->y}\n";
};
```

> 滚动时 `onDraw` 收到的 DrawContext 已应用滚动偏移，按内容坐标系绘制即可。

---

## 性能提示

1. **重绘最小化**：`onDraw` 整个客户区都会重绘，复杂场景考虑分层缓存到 Image
2. **避免在 onDraw 中创建大量对象**：路径、画笔等应在外部预创建，回调内仅使用
3. **及时释放资源**：`Image` / `DrawPath` / `GradientBrush` 用完调用 `free()` 或依赖析构
4. **触发重绘**：调用 `$area->invalidate()` 而非直接调 `onDraw`
