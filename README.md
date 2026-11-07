# Openframe 插件框架

基于 **Laravel 13 + Inertia v3 + nwidart/laravel-modules** 的开源插件化应用框架，内置插件市场客户端：上传校验、一键安装/更新/卸载/回滚、菜单自动注册、操作日志，以及基于 **Ed25519** 的包签名分发体系。

## 架构分层（Open Core）

| 层 | 内容 | 仓库 |
|----|------|------|
| 基础框架 | 插件加载/安装/卸载/回滚/菜单注册 + 市场管理界面 | 本仓库（MIT） |
| 市场客户端 SDK | 包签名、三重验签、密钥管理、吊销列表 | [packages/openframe/plugin-market-sdk](packages/openframe/plugin-market-sdk)（MIT，独立发布） |
| 市场平台 | 商品目录、审核流水线、签名背书、支付结算 | 闭源商业服务（规划中） |

## 快速开始

```bash
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed
npm install && npm run build
php artisan serve
```

## 签名工作流

```bash
# 开发者：一次性生成密钥对（私钥永不出本机）
php artisan plugin:keygen --name=my-dev-key

# 打包模块并签名
php artisan plugin:pack Announcement --sign=storage/app/plugin-keys/my-dev-key.sk

# 调试验证
php artisan plugin:verify storage/app/plugins/samples/announcement-1.0.0.zip
```

三档验签模式（`PLUGIN_MARKET_SIGNING_MODE`）：`strict` 拒绝未签名包 / `permissive` 警告放行（默认）/ `off` 跳过。

## 文档

- [安装与配置](docs/installation.md)
- [签名分发与密钥管理](docs/signing-workflow.md)
- [插件开发指南](docs/plugin-development.md)
- [客户端 API 契约](docs/client-api-contract.md)
- [示例插件](examples/README.md)

## 测试

```bash
php artisan test --compact
vendor/bin/pint --dirty --format agent
```

## License

[MIT](LICENSE)
