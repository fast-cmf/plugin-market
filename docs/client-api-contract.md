# 客户端 API 契约

框架客户端与市场平台之间的接口契约。**契约一经发布保持稳定**，平台内部重构不影响已装用户。

## 端点

### 1. 目录与搜索

```
GET /api/plugins?query=&category=
```

返回插件目录列表（名称、别名、描述、版本、评分、截图）。

### 2. 版本列表 / 更新检查

```
GET /api/plugins/{slug}/versions
```

返回该插件的全部版本（含版本号、更新日志、发布时间、兼容性元数据）。客户端据此判断是否有可用更新。

### 3. 授权下载

```
GET /api/plugins/{slug}/download/{version}
```

携带有效 License 凭证返回插件 zip（含完整签名链）；未授权返回 402/403。

### 4. License 激活

```
POST /api/licenses/activate
```

请求体：License Key + 站点指纹。平台绑定站点并返回签名凭证文档（阶段 4 的 License SDK 消费此端点）。

### 5. 吊销列表

```
GET /api/security/revocations
```

返回当前被吊销的签名密钥列表。客户端 SDK 的 `RevocationProvider` 接口对接此端点，本地 JSON 文件为其离线替代形态。

## 签名链语义

下载接口返回的 zip 必须携带：

1. 开发者签名（`developer_signature`）
2. 平台背书（`endorsement`，审核通过后由签名服务生成）

客户端三重校验顺序与错误码见 [签名分发](signing-workflow.md)。

## 演进约定

- 新增字段必须向后兼容（客户端忽略未知字段）
- 破坏性变更走版本化（`/api/v2/...`）
- 所有响应包含 `schema_version` 字段
