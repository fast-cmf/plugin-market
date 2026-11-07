# 签名分发与密钥管理

## 信任模型（两层签名）

| 层 | 签名者 | 证明 | 私钥位置 |
|----|--------|------|----------|
| 开发者签名 | 插件作者 | 包是我发的，未被中途篡改 | 开发者本机，永不上传 |
| 平台背书 | 官方市场 | 此包通过了审核 | 平台 KMS（规划中） |

## 开发者工作流

```bash
# 1. 生成本地密钥对（Ed25519，libsodium 内置）
php artisan plugin:keygen --name=my-dev-key
#   私钥：storage/app/plugin-keys/my-dev-key.sk  —— 切勿上传或提交仓库
#   公钥：storage/app/plugin-keys/{keyId}.pub
#   key_id = 公钥 SHA-256 指纹前 8 位

# 2. 打包并签名（一步完成）
php artisan plugin:pack Announcement --sign=storage/app/plugin-keys/my-dev-key.sk

# 3. 调试验证
php artisan plugin:verify storage/app/plugins/samples/announcement-1.0.0.zip

# 已有 zip 也可以单独签名
php artisan plugin:sign path/to/package.zip --key=storage/app/plugin-keys/my-dev-key.sk
```

公钥与 key_id 需在市场注册绑定；私钥泄露时通过吊销列表使 key_id 失效。

## 包内签名清单

`signature.json` 位于模块根目录内（保持包「单一根目录」布局）：

```json
{
    "algorithm": "ed25519",
    "key_id": "76a29265",
    "package": "announcement-1.0.0.zip",
    "sha256": "<规范化内容哈希>",
    "signed_at": "2026-11-07T10:00:00+08:00",
    "developer_signature": "<base64 Ed25519 detached>",
    "endorsement": {
        "key_id": "market-2026",
        "signature": "<base64>",
        "review_id": "REV-2026"
    }
}
```

要点：

- **规范化内容哈希**：对包内全部条目（除 signature.json）按条目名排序后做 SHA-256，与 zip 元数据无关——重压缩/时间戳变化不影响校验，篡改任何文件必然失配
- **被签内容**：前 5 个字段构成 canonical JSON（键排序）；平台背书额外覆盖 `developer_signature`
- 背书签名无效视为篡改（`INVALID_SIGNATURE`）；背书密钥未知仅降级为「未背书」

## 三重校验顺序

1. 开发者签名验证（sodium detached）
2. 包内容 SHA-256 与 `sha256` 字段比对（`hash_equals`）
3. 存在 `endorsement` 时验证平台背书

## Error codes

`PluginMarketException` 错误码常量：

| 常量 | 值 | 含义 |
|------|-----|------|
| `UNSIGNED_PACKAGE` | 1001 | 包内无 signature.json |
| `TAMPERED_PACKAGE` | 1002 | 内容哈希失配 |
| `UNKNOWN_KEY` | 1003 | 签名密钥未注册 |
| `REVOKED_KEY` | 1004 | 签名密钥已吊销 |
| `INVALID_SIGNATURE` | 1005 | 签名或清单被篡改 |

## 密钥注册与吊销

- **注册**：将 `{keyId}.pub` 文件放入 `signing.keys_path` 目录，或在配置 `signing.keys` 数组中登记 `keyId => base64公钥`
- **吊销**：更新 `signing.revocations_path` 指向的本地 JSON 文件（条目缓存一天）：

```json
{
    "revoked": [
        {"type": "key", "value": "76a29265", "reason": "key compromised"}
    ]
}
```

`RevocationProvider` 接口已预留，可接入市场平台 `GET /api/security/revocations` 的 HTTP 实现。
