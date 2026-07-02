<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | 密钥对配置
    |--------------------------------------------------------------------------
    |
    | 当前服务端的 Ed25519 签名密钥对和 X25519 交换密钥对。
    | 生产环境应通过环境变量或密钥管理服务注入。
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
    |
    | 也可用 #[SkipReqResCrypto] 属性标记控制器类或方法。
    |
    */
    'skip_routes' => [],

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
