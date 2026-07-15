# Aether WordPress 插件

Aether（原 zeroY）是一款极简的 WordPress 可视化编辑器，支持纯 HTML 编辑。无需 API Token，安装即可使用。

## 📦 安装

### 方式一：WordPress 后台安装

1. 下载 `wordexpress-aether.zip`
2. 进入 WordPress 后台 → 插件 → 新增插件 → 上传插件
3. 选择下载的 ZIP 文件并点击"立即安装"
4. 激活插件

### 方式二：手动安装

1. 解压 ZIP 文件
2. 将 `wordexpress-aether` 文件夹上传到 `/wp-content/plugins/`
3. 进入 WordPress 后台 → 插件，找到 Aether 并激活

## ✨ 主要功能

### 1. 可视化页面编辑器

- 在文章/页面编辑页面顶部点击"使用 aether 编辑器"按钮
- 支持纯 HTML 代码编辑
- 实时预览编辑效果
- 自动保存草稿

**使用步骤：**

1. 创建或编辑一篇文章/页面
2. 在标题下方会出现黄色提示框，点击"前往设置"
3. 进入 Aether 管理页面后，点击"使用 aether 编辑器"
4. 开始编辑你的页面内容

### 2. 联系表单 `[aether_contact_form]`

在任意页面或文章中插入联系表单：

```
[aether_contact_form]
```

**自定义参数：**

| 参数 | 说明 | 默认值 |
|------|------|--------|
| `title` | 表单标题 | "Contact Us" |
| `placeholder_name` | 姓名字段占位符 | "Your Name" |
| `placeholder_email` | 邮箱字段占位符 | "Your Email" |
| `placeholder_subject` | 主题字段占位符 | "Subject" |
| `placeholder_message` | 消息字段占位符 | "Your Message" |
| `button_text` | 提交按钮文字 | "Send Message" |

**示例：**

```
[aether_contact_form 
    title="联系我们"
    placeholder_name="请输入您的姓名"
    placeholder_email="请输入您的邮箱"
    button_text="发送消息"]
```

**表单配置：**

1. 进入 WordPress 后台 → Aether → 联系表单
2. 配置接收邮箱、发件人名称、成功提示消息
3. 可开启 Honeypet 反垃圾保护
4. 查看所有表单提交记录

**表单功能特性：**

- ✅ 邮件通知（发送到管理员邮箱）
- ✅ 数据库存储提交记录
- ✅ Honeypet 反垃圾保护
- ✅ 表单验证（邮箱格式、必填项检查）
- ✅ 成功/错误消息提示
- ✅ 后台管理提交记录（标记已读、删除）

### 3. 设置页面

进入 Aether 管理页面，包含以下选项卡：

| 选项卡 | 功能 |
|--------|------|
| **动态模板** | 管理页面模板（首页、文章页、404页等） |
| **AI 设置** | AI 辅助编辑设置 |
| **性能** | 速度优化、CSS 编译策略 |
| **设计系统** | 导入/导出设计系统 HTML |
| **表单提交** | 查看联系表单提交记录 |

### 4. 图片优化

- 自动压缩上传图片
- 支持 WebP 格式转换
- 可开关图片优化功能

### 5. CSS 优化

- 自动生成页面级 CSS
- 减少重复样式代码
- 提升页面加载速度

## 🔧 配置说明

### 联系表单配置

1. 进入 **Aether → 联系表单**
2. 勾选"启用"
3. 填写"接收邮箱"（默认为站点管理员邮箱）
4. 填写"发件人名称"
5. 自定义"成功消息"
6. 可选：开启 Honeypet 反垃圾保护
7. 点击"保存设置"

### 编辑器设置

1. 进入 **Aether 管理页面**
2. 在"动态模板"选项卡中配置模板
3. 在"性能"选项卡中调整优化设置
4. 在"设计系统"选项卡中导入设计系统

## 📁 文件结构

```
wordexpress-aether/
├── aether.php                    # 主插件文件
├── uninstall.php                 # 卸载脚本
├── .htaccess                     # Apache 重写规则
├── dist/                         # 前端构建资源
│   └── 1.1.45/
│       ├── assets/               # JS/CSS/图片
│       └── manifest.json         # 资源清单
├── includes/                     # 核心代码
│   ├── admin/                    # 后台管理
│   │   ├── class-admin-bar-aether-edit.php
│   │   └── ...
│   ├── api/                      # REST API 路由
│   │   ├── class-api-routes-settings.php
│   │   └── ...
│   ├── core/                     # 核心服务
│   │   ├── class-autoloader.php
│   │   └── ...
│   ├── editor/                   # 编辑器逻辑
│   │   ├── class-editor-ui.php
│   │   └── ...
│   └── services/                 # 业务服务
│       ├── class-contact-form-service.php  # 联系表单
│       └── ...
└── templates/                    # 模板文件
    ├── header-aether.php
    ├── footer-aether.php
    └── ...
```

## ❓ 常见问题

### Q: 为什么没有 API Token 输入页面？

A: 这是免费版，无需 API Token 即可使用所有基础功能。如需高级 AI 功能，请购买专业版。

### Q: 联系表单收不到邮件怎么办？

A: 检查以下几点：
1. 确认"接收邮箱"填写正确
2. 检查服务器是否支持 `mail()` 函数
3. 查看垃圾邮件文件夹
4. 考虑安装 SMTP 插件（如 WP Mail SMTP）

### Q: 编辑器按钮不显示？

A: 确保：
1. 插件已激活
2. 当前用户有编辑权限
3. 文章类型在允许列表中

### Q: 如何卸载插件？

A: 在 WordPress 后台：
1. 进入"插件"页面
2. 找到 Aether 插件
3. 点击"停用"
4. 点击"删除"

⚠️ 卸载不会删除数据库中的表单提交记录。

## 🐛 故障排除

### REST API 无法访问

如果看到"服务器配置问题"提示：

1. 进入 WordPress 后台 → 设置 → 固定链接
2. 点击"保存更改"（即使不做修改）
3. 检查 `.htaccess` 文件是否包含 WordPress 重写规则
4. 如果是 Nginx 服务器，检查 `try_files` 规则

### 图片优化不生效

1. 进入 Aether → 性能
2. 确认"图片优化"已开启
3. 重新上传图片测试

## 📄 许可证

GPL v2 或更高版本

## 🤝 技术支持

如有问题，请：
1. 查看本文档
2. 检查 WordPress 错误日志
3. 联系技术支持

---

**版本：** 1.0.0  
**作者：** Aether Team  
**更新日期：** 2026-07-15
