# PHP UI 文档

基于 PHP FFI 实现的跨平台 GUI 库文档。

## 文档目录

| 文档 | 内容 |
| --- | --- |
| [00-快速开始](00-getting-started.md) | 环境要求、安装、第一个窗口、常见问题 |
| [01-窗口与控件](01-window-and-controls.md) | Window、Control、所有控件（Button/Label/Entry/Table/Area 等） |
| [02-布局系统](02-layout.md) | HBox/VBox/Grid/Form/Group/Tab 容器使用 |
| [03-绘图与图像](03-drawing.md) | DrawContext、路径、渐变、变换、裁剪、Area 画布 |
| [04-菜单与对话框](04-menu-dialogs.md) | 菜单栏/弹出菜单、消息框、文件/颜色/字体对话框、剪贴板、屏幕 |
| [05-系统托盘与窗口图标](05-tray-icon.md) | TrayIcon、气球通知、托盘菜单、窗口图标（PNG/ICO） |
| [06-高级主题](06-advanced.md) | 定时器、queueMain、进程管理、多窗口、自定义平台、调试 |
| [07-API 速查](07-api-reference.md) | 所有类和方法的快速查阅表 |

## 阅读建议

- **新手**：按顺序阅读 00 → 01 → 02，然后看 examples 目录的示例
- **有 GUI 经验**：直接看 01 和 07-API 速查
- **Windows 桌面开发**：05-系统托盘 是 Windows 特有功能
- **贡献者**：06-高级主题 的"自定义平台后端"和"编码约定"

## 示例代码

文档中的代码片段可直接复制运行。完整示例位于项目根目录的 `examples/` 文件夹：

```
examples/
├── window_test.php             窗口基础
├── controls_test.php           基础控件
├── controls_advanced_test.php  高级控件
├── layout_test.php             布局容器
├── menu_test.php               菜单栏
├── dialogs_test.php            对话框
├── area_test.php               自定义绘图
├── graphics_advanced_test.php  高级绘图
├── table_test.php              表格
├── image_test.php              图片/图标
├── tray_test.php               系统托盘
├── process_test.php            进程管理
└── full_test.php               综合演示
```

运行示例：

```bash
php examples/window_test.php
```
