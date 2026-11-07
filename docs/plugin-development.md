# 插件开发指南

## 目录结构

每个插件是一个独立的 Laravel Modules 模块，打包时整体压缩：

```
Announcement/
├── module.json                       # 模块清单（必需）
├── composer.json
├── app/
│   ├── Providers/
│   │   └── AnnouncementServiceProvider.php
│   └── Http/Controllers/...
├── config/
├── database/
├── resources/views/
└── routes/
    ├── web.php
    └── api.php
```

约束：

- 包内**必须且只能有一个**根目录，目录名为 StudlyCase（字母开头，2-64 位）
- 条目路径必须使用正斜杠，禁止 `..` 与反斜杠（防路径穿越）

## module.json 规范

```json
{
    "name": "Announcement",
    "alias": "announcement",
    "version": "1.0.0",
    "description": "公告管理模块",
    "authors": [{"name": "Your Name"}],
    "required_php": "^8.3",
    "required_modules": [],
    "providers": ["Modules\\Announcement\\Providers\\AnnouncementServiceProvider"],
    "menus": [
        {"label": "公告管理", "route": "announcement.index", "icon": "megaphone"}
    ]
}
```

| 字段 | 必填 | 说明 |
|------|------|------|
| name | 是 | 与根目录名一致 |
| alias | 是 | 小写字母开头的 2-64 位标识，市场唯一键 |
| version | 是 | 语义化版本 `x.y.z`，更新必须单调递增 |
| description | 是 | 模块描述 |
| providers | 是 | 至少声明一个 ServiceProvider，文件必须真实存在于包内 |
| required_php | 否 | 运行环境校验 |
| required_modules | 否 | 依赖的模块 alias，须已安装且启用 |
| menus | 否 | 安装后自动注册到侧边栏 |

## 打包与签名

```bash
# 打包（在框架根目录执行）
php artisan plugin:pack Announcement

# 打包 + 签名（推荐）
php artisan plugin:pack Announcement --sign=storage/app/plugin-keys/my-dev-key.sk

# 产物位于 storage/app/plugins/samples/
```

打包/上传时框架会自动执行：

1. 签名三重校验（按 signing.mode）
2. manifest 结构与一致性校验
3. PHP 版本与依赖模块校验
4. `php -l` 语法检查全部 Provider 与路由文件（防止坏包 500 整站）

## 本地调试

```bash
php artisan plugin:verify storage/app/plugins/samples/announcement-1.0.0.zip
```

完整可运行的示例见 [examples/announcement](../examples/announcement)。
