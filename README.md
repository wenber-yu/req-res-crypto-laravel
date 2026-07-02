---
AIGC:
    Label: "1"
    ContentProducer: 001191440300708461136T1XGW3
    ProduceID: 4b09a840a57cfeeadfc224571fb8d841_08522b2373f611f1897e5254002afed2
    ReservedCode1: 05WXvufsBnb80yJxI2BZ6Hfc5nz9ArzvERxNSx5C6oxcaf4XChTICSJSNZ7V4otzfTqJ0my9nichgqbWL4rEdtIXw/paDQBMGNCu08O2ufER/eUZ1m3svPbIRcOgiNDVjSslwJG++k8GBkVxabsEDdRy3R7NdCb2Vhir1pbl5q6u6DRrhyVWaEsAuBw=
    ContentPropagator: 001191440300708461136T1XGW3
    PropagateID: 4b09a840a57cfeeadfc224571fb8d841_08522b2373f611f1897e5254002afed2
    ReservedCode2: 05WXvufsBnb80yJxI2BZ6Hfc5nz9ArzvERxNSx5C6oxcaf4XChTICSJSNZ7V4otzfTqJ0my9nichgqbWL4rEdtIXw/paDQBMGNCu08O2ufER/eUZ1m3svPbIRcOgiNDVjSslwJG++k8GBkVxabsEDdRy3R7NdCb2Vhir1pbl5q6u6DRrhyVWaEsAuBw=
---

# req-res-crypto-laravel

Laravel 适配层，为 [req-res-crypto-core](https://github.com/wenber-yu/req-res-crypto-core) 提供开箱即用的中间件、Facade、Artisan 命令和数据库密钥管理。

依赖：PHP >= 8.3，Laravel 11 / 12。

## 安装

```bash
composer require wenber-yu/req-res-crypto-laravel
```

Laravel 自动发现机制会自动注册 `ReqResCryptoServiceProvider`。

## 配置发布

```bash
php artisan vendor:publish --tag=req-res-crypto-config
php artisan vendor:publish --tag=req-res-crypto-migrations
```

发布后生成：
- `config/req-res-crypto.php` — 配置文件
- `database/migrations/xxxx_create_req_res_crypto_public_keys_table.php` — 数据库迁移

### 配置说明（`config/req-res-crypto.php`）

| 配置项 | 环境变量 | 默认值 | 说明 |
| --- | --- | --- | --- |
| `key_id` | `REQ_RES_CRYPTO_KEY_ID` | `''` | 当前密钥 ID（bootstrap） |
| `sign_secret_key` | `REQ_RES_CRYPTO_SIGN_SECRET_KEY` | `''` | 服务端 Ed25519 签名私钥（hex） |
| `sign_public_key` | `REQ_RES_CRYPTO_SIGN_PUBLIC_KEY` | `''` | 服务端 Ed25519 签名公钥（hex） |
| `exchange_secret_key` | `REQ_RES_CRYPTO_EXCHANGE_SECRET_KEY` | `''` | 服务端 X25519 交换私钥（hex） |
| `exchange_public_key` | `REQ_RES_CRYPTO_EXCHANGE_PUBLIC_KEY` | `''` | 服务端 X25519 交换公钥（hex） |
| `key_rotation.enabled` | `REQ_RES_CRYPTO_KEY_ROTATION_ENABLED` | `false` | 是否启用数据库密钥轮换 |
| `key_rotation.rotate_before_days` | — | `7` | 新密钥提前多少天发布为 pre_issued |
| `key_rotation.activate_after_days` | — | `7` | 新密钥发布多少天后自动激活 |
| `time_window` | — | `300` | 防重放时间容差（秒） |
| `nonce_cache_store` | `REQ_RES_CRYPTO_NONCE_STORE` | `file` | Nonce 去重使用的 Cache Store |
| `nonce_ttl` | — | `600` | Nonce 过期时间（秒） |
| `encrypt_routes` | — | `['api/*']` | 需要加密响应的路由模式 |
| `decrypt_routes` | — | `['api/*']` | 需要解密请求的路由模式 |
| `database.connection` | `REQ_RES_CRYPTO_DB_CONNECTION` | `mysql` | 密钥表所在数据库连接 |
| `database.table` | — | `req_res_crypto_public_keys` | 密钥数据表名 |
| `skip_routes` | — | `[]` | 跳过加解密的路由模式（支持 `*` / `**` 通配符） |
| `skip_header` | `REQ_RES_CRYPTO_SKIP_HEADER` | `X-Skip-Req-Res-Crypto` | 前端声明跳过加密的请求头名称 |
| `crontab.enabled` | `REQ_RES_CRYPTO_CRONTAB_ENABLED` | `false` | 是否启用定时自动轮换 |
| `crontab.rule` | — | `0 2 * * *` | Crontab 执行规则

> **统一设计**：无论 `key_rotation.enabled` 是否开启，顶层 API（中间件、Facade）使用方式完全一致。
> - 关闭时：全部从 bootstrap 配置密钥工作，无需数据库。
> - 开启时：优先从数据库读取密钥，DB 无记录时降级使用 bootstrap 密钥。

### 数据库迁移

```bash
php artisan migrate
```

创建 `req_res_crypto_public_keys` 表（表名可通过 `config('req-res-crypto.database.table')` 自定义）：

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `id` | bigint (PK) | 自增主键 |
| `key_id` | varchar(32) UNIQUE | 72 位 hex，12 字节随机数 |
| `sign_public_key` | text | Ed25519 签名公钥（hex） |
| `sign_secret_key` | text | Ed25519 签名私钥（hex） |
| `exchange_public_key` | text | X25519 交换公钥（hex） |
| `exchange_secret_key` | text | X25519 交换私钥（hex） |
| `status` | enum(pre_issued, current, expired) | 密钥状态 |
| `issued_at` | timestamp | 密钥生成时间 |
| `activated_at` | timestamp | 激活时间 |
| `expired_at` | timestamp | 过期时间 |

## 中间件

服务提供者自动注册两个中间件别名：`req-res-decrypt` 和 `req-res-encrypt`。

### 解密请求：`ReqResDecryptRequest`

对匹配 `decrypt_routes` 的路由自动解析 base64 编码的 wire format，解密后替换 `$request->content`。

**注册方式**（在 `bootstrap/app.php` 或 `Kernel.php` 中）：

```php
// 全局注册（Laravel 11+）
->withMiddleware(function (Middleware $middleware) {
    $middleware->append(\Wenbo\ReqResCrypto\Laravel\Middleware\ReqResDecryptRequest::class);
})

// 路由组注册
Route::middleware('req-res-decrypt')->group(function () {
    Route::post('/api/orders', [OrderController::class, 'store']);
});
```

**行为**：
- 若请求 body 不是有效的 base64 → 透传原始请求
- 若 base64 解码成功但解密失败 → 透传原始请求（不抛异常）
- 解密成功后自动将 `Content-Type: application/octet-stream` 替换为 `application/json`
- 仅对匹配 `decrypt_routes` 的路由生效

### 加密响应：`ReqResEncryptResponse`

对匹配 `encrypt_routes` 的路由自动加密响应体并回写为 base64 格式。

```php
// 路由组注册
Route::middleware('req-res-encrypt')->group(function () {
    Route::get('/api/user', [UserController::class, 'show']);
});
```

**行为**：
- 从 `req_res_crypto_client_pubkey` request attribute 获取客户端 X25519 公钥（由解密中间件从 wire 提取）
- 获取服务端当前活跃密钥，计算共享密钥后加密响应
- 响应 `Content-Type` 自动设置为 `application/octet-stream`
- 响应体为 `base64(wire_format)`

**密钥轮换通知**：当数据库中存在 `pre_issued` 状态的密钥时，中间件自动在响应头附加 JSON 格式的轮换通知：

```
X-Req-Res-Crypto-Key-Rotate: {"key_id":"<新key_id>","sign_public_key":"<新签名公钥>","exchange_public_key":"<新交换公钥>"}
```

客户端 SDK 检测到此头后可自动缓存新公钥，实现无缝密钥轮换过渡。

### 解密 + 加密组合

```php
Route::middleware(['req-res-decrypt', 'req-res-encrypt'])->group(function () {
    Route::post('/api/orders', [OrderController::class, 'store']);
    Route::get('/api/user', [UserController::class, 'show']);
});
```

### 注解方式

除了通过路由别名注册中间件，还可以使用 PHP Attribute 直接在控制器方法上标记加解密：

#### 可用的 Attribute

| Attribute | 中间件等价 |
| --- | --- |
| `#[ReqResDecrypt]` | `req-res-decrypt` |
| `#[ReqResEncrypt]` | `req-res-encrypt` |

#### 使用方法

1. 在 `bootstrap/app.php`（或 `app/Http/Kernel.php`，视 Laravel 版本而定）中注册注解中间件：

```php
use Wenbo\ReqResCrypto\Laravel\Middleware\ReqResAnnotationMiddleware;

// 注册为全局中间件或路由中间件
$middleware->alias('req-res-crypto', ReqResAnnotationMiddleware::class);
```

> **注意**：如果使用 `req-res-crypto` 别名，需要将该中间件注册到需要注解驱动的路由组中；如果希望所有路由都支持注解检测，可以注册为全局中间件。

2. 在控制器上使用 Attribute：

```php
use Wenbo\ReqResCrypto\Laravel\Attributes\ReqResDecrypt;
use Wenbo\ReqResCrypto\Laravel\Attributes\ReqResEncrypt;

class OrderController extends Controller
{
    #[ReqResDecrypt]
    #[ReqResEncrypt]
    public function store(Request $request): JsonResponse
    {
        // $request 已被自动解密
        $data = $request->all();

        // 返回的 JsonResponse 将被自动加密
        return response()->json(['order_id' => 123]);
    }
}
```

#### 注解与中间件对比

| 方式 | 适用场景 |
| --- | --- |
| 路由中间件 | 按路由前缀批量控制，粒度在路由组级别 |
| 注解方式 | 按方法精细控制，粒度在控制器方法级别 |

两种方式可以共存，注解中间件仅对标记了 `#[ReqResDecrypt]` / `#[ReqResEncrypt]` 的方法生效。

## 跳过加解密

三种方式可让特定路由跳过加解密处理，优先级从高到低：

### 方式一：`#[SkipReqResCrypto]` 注解（推荐）

在控制器类或方法上标注，该路由完全跳过加解密：

```php
use Wenbo\ReqResCrypto\Laravel\Attributes\SkipReqResCrypto;

// 整个控制器跳过
#[SkipReqResCrypto]
class HealthController extends Controller
{
    public function check(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }
}

// 单个方法跳过
class ApiController extends Controller
{
    #[SkipReqResCrypto]
    public function publicEndpoint(): JsonResponse
    {
        return response()->json(['data' => 'public']);
    }
}
```

此注解仅在通过 `ReqResAnnotationMiddleware` 注解驱动模式时生效。

### 方式二：`skip_routes` 路径模式

在配置中按 URL 模式批量跳过（注解中间件模式下生效）：

```php
// config/req-res-crypto.php
'skip_routes' => [
    '/health',
    '/api/public/**',   // /api/public 下所有路径
    '/api/docs/*',      // /api/docs 下单层路径
],
```

### 方式三：`skip_header` 请求头

前端在请求中携带 `X-Skip-Req-Res-Crypto: 1` 头，**仅在路由命中 skip_routes 或 SkipReqResCrypto 注解时**才接受明文，否则返回 400。此机制仅在注解中间件模式下生效。

```typescript
// 前端：声明发送明文
fetch('/api/health', {
  headers: { 'X-Skip-Req-Res-Crypto': '1' },
});
```

> **安全机制**：skip_header 是"白名单确认"机制，不是"无条件跳过"。前端声明跳过 + 后端路由不在白名单 = 直接拒绝。

## Facade

`ReqResCrypto` Facade 提供便捷的密钥信息访问：

```php
use Wenbo\ReqResCrypto\Laravel\Facades\ReqResCrypto;

ReqResCrypto::keyId();             // 服务端 Key ID
ReqResCrypto::signPublicKey();     // 服务端签名公钥（hex）
ReqResCrypto::exchangePublicKey(); // 服务端交换公钥（hex）
```

## Artisan 命令

### 生成新密钥（预发布）

```bash
php artisan req-res-crypto:keys:rotate
```

生成全新的 Ed25519 和 X25519 密钥对，以 `pre_issued` 状态写入数据库。`activated_at` = 当前时间 + `rotate_before_days` 天。

### 激活密钥

```bash
# 激活最早的、已到达 activated_at 的 pre_issued 密钥
php artisan req-res-crypto:keys:activate

# 激活指定 Key ID
php artisan req-res-crypto:keys:activate a1b2c3d4e5f6g7h8i9j0k1l2
```

在数据库事务中执行：
1. 将当前 `current` 状态密钥设为 `expired`
2. 将目标 `pre_issued` 密钥设为 `current`

## 前端对接

### Laravel 路由配置

```php
// routes/api.php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CryptoController;

// 公开端点：获取服务端公钥
Route::get('/crypto/public-key', [CryptoController::class, 'publicKey']);

// 加密通信路由
Route::middleware(['req-res-decrypt', 'req-res-encrypt'])->group(function () {
    Route::post('/orders', [OrderController::class, 'store']);
    Route::get('/user', [UserController::class, 'show']);
});
```

### CryptoController 示例

```php
namespace App\Http\Controllers;

use Wenbo\ReqResCrypto\Laravel\Facades\ReqResCrypto;

class CryptoController extends Controller
{
    public function publicKey(): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'key_id' => ReqResCrypto::keyId(),
            'sign_public_key' => ReqResCrypto::signPublicKey(),
            'exchange_public_key' => ReqResCrypto::exchangePublicKey(),
        ]);
    }
}
```

### 前端请求示例

```typescript
// 1. 获取服务端公钥
const pubKey = await fetch('/api/crypto/public-key').then(r => r.json());
// => { key_id: "...", sign_public_key: "...", exchange_public_key: "..." }

// 2. 发送加密请求（客户端公钥已嵌入 wire，无需额外请求头）
await fetch('/api/orders', {
  method: 'POST',
  headers: { 'Content-Type': 'application/octet-stream' },
  body: base64EncryptedWire, // base64(wire_format)
});
```

### 协议约定

| 约定 | 值 |
| --- | --- |
| 请求 `Content-Type` | `application/octet-stream` |
| 请求 body | `base64(wire_format)` — 客户端 X25519 公钥已嵌入 wire |
| 响应 `Content-Type` | `application/octet-stream` |
| 响应 body | `base64(wire_format)` |
| 响应头 `X-Req-Res-Crypto-Key-Rotate`（可选） | JSON：`{"key_id":"...","sign_public_key":"...","exchange_public_key":"..."}` — 密钥轮换通知 |

前端加密和解密的完整 TypeScript 实现参见 [req-res-crypto-js 客户端](https://github.com/wenber-yu/req-res-crypto-js)。

## 相关包

| 包 | 说明 |
| --- | --- |
| [req-res-crypto-js](https://github.com/wenber-yu/req-res-crypto-js) | 前端（浏览器）客户端 |
| [req-res-crypto-core](https://github.com/wenber-yu/req-res-crypto-core) | 核心加解密库（零框架依赖） |
| [req-res-crypto-hyperf](https://github.com/wenber-yu/req-res-crypto-hyperf) | Hyperf 适配包 |
