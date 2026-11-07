# 安装与配置

## 环境要求

- PHP ^8.3（需 `ext-sodium`、`ext-zip`）
- Laravel ^13.0、nwidart/laravel-modules ^13.3
- Node.js（前端构建）

## 安装 SDK（独立使用）

如果你的 Laravel 项目只想复用包签名/验签能力（不需要完整插件市场）：

```bash
composer require openframe/plugin-market-sdk
php artisan vendor:publish --tag=plugin-market-sdk-config
```

发布的 `config/plugin-market.php`：

```php
'signing' => [
    'mode' => env('PLUGIN_MARKET_SIGNING_MODE', 'permissive'),
    'keys' => [],                                             // keyId => base64 公钥
    'keys_path' => storage_path('app/plugin-keys'),           // {keyId}.pub 扫描目录
    'revocations_path' => storage_path('app/plugin-revocations.json'),
],
```

## 验签模式

| mode | 行为 |
|------|------|
| `strict` | 未签名或验签失败的包一律拒绝安装 |
| `permissive` | 验签失败只产生警告，包仍可安装（企业内部市场场景） |
| `off` | 完全跳过验签（仅限本地调试） |

未知值一律按 strict 处理（fail closed）。

## 在代码中调用

```php
use Openframe\PluginMarketSdk\Validation\PluginArchiveValidator;

$validator = app(PluginArchiveValidator::class);
$manifest = $validator->validate($zipPath);

// permissive 模式下检查警告
if ($validator->signatureWarnings() !== []) {
    Log::warning('插件包签名警告', $validator->signatureWarnings());
}
```

签名相关的 5 个错误码见 [签名分发](signing-workflow.md#error-codes)。
