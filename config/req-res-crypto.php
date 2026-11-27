<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | 密钥对配置
    |--------------------------------------------------------------------------
    |
    | 当前服务端的 Ed25519 签名密钥对和 X25519 交换密钥对。
    | key_id：8 字符 hex（取签名公钥前 4 字节）。
    | 生产环境应通过环境变量或密钥管理服务注入。
    | sign_* 用于对公钥下发接口的响应签名（ServerKeysPayload），客户端验签后才使用交换公钥。
    |
    */

    'key_id' => env('REQ_RES_CRYPTO_KEY_ID', ''),

    'sign_secret_key' => env('REQ_RES_CRYPTO_SIGN_SECRET_KEY', ''),
    'sign_public_key' => env('REQ_RES_CRYPTO_SIGN_PUBLIC_KEY', ''),
    'exchange_secret_key' => env('REQ_RES_CRYPTO_EXCHANGE_SECRET_KEY', ''),
    'exchange_public_key' => env('REQ_RES_CRYPTO_EXCHANGE_PUBLIC_KEY', ''),

    /*
    |--------------------------------------------------------------------------
    | 密钥轮换
    |--------------------------------------------------------------------------
    |
    | enabled: 是否启用数据库密钥轮换。启用后密钥从数据库读取，配置文件中的
    |         密钥作为初始引导（bootstrap），轮换表无记录时自动降级使用。
    | rotate_before_days: 新密钥提前多少天发布为 pre_issued
    | activate_after_days: 新密钥发布多少天后自动激活
    |
    */

    'key_rotation' => [
        'enabled' => env('REQ_RES_CRYPTO_KEY_ROTATION_ENABLED', false),
        'rotate_before_days' => 7,
        'activate_after_days' => 7,
    ],

    /*
    |--------------------------------------------------------------------------
    | 防重放
    |--------------------------------------------------------------------------
    |
    | time_window: 允许的时间偏差（秒），默认 ±300 秒（5 分钟）
    | nonce_cache_store: Nonce 去重使用的 Laravel Cache store
    | nonce_ttl: 超过时间窗口后 nonce 自动过期（秒）
    |
    */

    'time_window' => 300,
    'nonce_cache_store' => env('REQ_RES_CRYPTO_NONCE_STORE', 'file'),
    'nonce_ttl' => 600,

    /*
    |--------------------------------------------------------------------------
    | 公钥下发接口（开箱即用）
    |--------------------------------------------------------------------------
    |
    | enabled: 是否自动注册公钥接口
    | path: 路由路径
    | ttl: 响应有效期（秒）
    | sign: 是否用服务端 Ed25519 私钥对响应签名
    |
    */

    'public_key' => [
        'enabled' => env('REQ_RES_CRYPTO_PUBLIC_KEY_ENABLED', true),
        'path' => '/req-res-crypto/public-key',
        'ttl' => 3600,
        'sign' => env('REQ_RES_CRYPTO_PUBLIC_KEY_SIGN', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | 会话握手（前向保密）
    |--------------------------------------------------------------------------
    |
    | enabled: 是否自动注册握手接口
    | path: 路由路径
    | session_ttl: 会话临时私钥在缓存中的存活时间（秒）
    | session_cache_store: 会话存储使用的 Cache store，留空则复用 nonce_cache_store
    |
    */

    'handshake' => [
        'enabled' => env('REQ_RES_CRYPTO_HANDSHAKE_ENABLED', true),
        'path' => '/req-res-crypto/handshake',
        'session_ttl' => (int) env('REQ_RES_CRYPTO_SESSION_TTL', 600),
    ],

    'session_cache_store' => env('REQ_RES_CRYPTO_SESSION_CACHE_STORE', ''),

    /*
    |--------------------------------------------------------------------------
    | 加密强制校验（Guard）
    |--------------------------------------------------------------------------
    |
    | 命中 decrypt_routes 的请求必须携带 v2/v3 密文，否则返回 400。
    | enabled: 关闭后恢复旧行为（明文请求静默透传）
    |
    */

    'enforce' => [
        'enabled' => env('REQ_RES_CRYPTO_ENFORCE', true),
        'allow_empty_body' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | 异常归一
    |--------------------------------------------------------------------------
    */

    'exceptions' => [
        'enabled' => env('REQ_RES_CRYPTO_EXCEPTIONS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | 中间件
    |--------------------------------------------------------------------------
    |
    | encrypt_routes: 需要加密响应的路由模式（支持通配符）
    | decrypt_routes: 需要解密请求的路由模式
    |
    */

    'encrypt_routes' => ['api/*'],
    'decrypt_routes' => ['api/*'],

    /*
    |--------------------------------------------------------------------------
    | 跳过加解密的路由
    |--------------------------------------------------------------------------
    |
    | 支持通配符：* 匹配单段路径，** 递归匹配多段。
    | 例如：['/api/health', '/api/public/**']
    | 注意 ** 不匹配父路径本身，/api/public/** 不会放行 /api/public。
    |
    | 也可用 #[SkipReqResCrypto] 属性标记控制器类或方法。
    |
    */
    'skip_routes' => [
        // 公钥接口与握手接口必须在无密钥状态下可访问
        '/req-res-crypto/public-key',
        '/req-res-crypto/handshake',
    ],

    /*
    |--------------------------------------------------------------------------
    | 跳过加解密的 Header
    |--------------------------------------------------------------------------
    |
    | 前端发送此 Header 声明本次请求不发加密数据。
    | 后端只有在路由命中 skip_routes 或 #[SkipReqResCrypto] 注解时才接受明文。
    | 留空则完全禁用 header 跳过机制。
    |
    */
    'skip_header' => env('REQ_RES_CRYPTO_SKIP_HEADER', 'X-Skip-Req-Res-Crypto'),

    /*
    |--------------------------------------------------------------------------
    | 数据库
    |--------------------------------------------------------------------------
    |
    | 密钥轮换使用的数据库连接和表名。
    |
    */

    'database' => [
        'connection' => env('REQ_RES_CRYPTO_DB_CONNECTION', 'mysql'),
        'table' => 'req_res_crypto_public_keys',
    ],

    /*
    |--------------------------------------------------------------------------
    | Crontab（定时自动轮换）
    |--------------------------------------------------------------------------
    |
    | enabled: 是否启用定时自动轮换（仅 key_rotation.enabled=true 时生效）
    | rule: cron 表达式
    |
    */

    'crontab' => [
        'enabled' => env('REQ_RES_CRYPTO_CRONTAB_ENABLED', false),
        'rule'    => '0 2 * * *',
    ],

];
