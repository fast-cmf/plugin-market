# 示例插件

## announcement

公告管理插件，演示完整的插件结构：ServiceProvider、Blade 视图、路由、菜单注册、签名分发流程。

- 源码：[announcement/](announcement/)（与框架内置样例模块同源）
- 打包签名：

```bash
# 在框架根目录执行
php artisan plugin:pack Announcement --sign=storage/app/plugin-keys/my-dev-key.sk

# 产物：storage/app/plugins/samples/announcement-1.0.0.zip（含 signature.json）

php artisan plugin:verify storage/app/plugins/samples/announcement-1.0.0.zip
```

密钥生成见 [签名分发与密钥管理](../docs/signing-workflow.md)。
