<?php

declare(strict_types=1);

namespace Wenbo\ReqResCrypto\Laravel;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Wenbo\ReqResCrypto\Laravel\Commands\RotateKeysCommand;
use Wenbo\ReqResCrypto\Laravel\Commands\ActivateKeyCommand;
use Wenbo\ReqResCrypto\Laravel\Commands\GenerateKeyCommand;
use Wenbo\ReqResCrypto\Laravel\Middleware\ReqResEncryptResponse;
use Wenbo\ReqResCrypto\Laravel\Middleware\ReqResDecryptRequest;
use Wenbo\ReqResCrypto\Laravel\Middleware\ReqResAnnotationMiddleware;
use Wenbo\ReqResCrypto\Laravel\Middleware\ReqResEnforceEncrypted;
use Wenbo\ReqResCrypto\Laravel\Middleware\ReqResCryptoException;
use Wenbo\ReqResCrypto\Laravel\KeyProvider\DatabaseKeyProvider;
use Wenbo\ReqResCrypto\Laravel\KeyProvider\ServerKeyProvider;
use Wenbo\ReqResCrypto\Laravel\NonceStore\CacheNonceStore;
use Wenbo\ReqResCrypto\Laravel\SessionStore\CacheSessionStore;
use Wenbo\ReqResCrypto\Core\HandshakeService;
use Wenbo\ReqResCrypto\Core\Sealer;
use Wenbo\ReqResCrypto\Core\Unsealer;
use Wenbo\ReqResCrypto\Core\KeyDerivation;
use Wenbo\ReqResCrypto\Core\KeyExchange;
use Wenbo\ReqResCrypto\Core\JsonSerializer;
use Wenbo\ReqResCrypto\Core\SealerInterface;
use Wenbo\ReqResCrypto\Core\SessionSealer;
use Wenbo\ReqResCrypto\Core\SessionStoreInterface;
use Wenbo\ReqResCrypto\Core\UnsealerInterface;
use Wenbo\ReqResCrypto\Core\ServerKeyProviderInterface;
use Wenbo\ReqResCrypto\Core\NonceStoreInterface;
use Wenbo\ReqResCrypto\Core\SerializerInterface;

final class ReqResCryptoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/req-res-crypto.php', 'req-res-crypto');

        // 绑定序列化器
        $this->app->singleton(SerializerInterface::class, JsonSerializer::class);

        // 绑定密钥交换
        $this->app->singleton(KeyExchange::class);

        // 绑定 Nonce 存储
        $this->app->singleton(NonceStoreInterface::class, function ($app) {
            return new CacheNonceStore(
                $app['cache']->store(config('req-res-crypto.nonce_cache_store', 'file')),
            );
        });

        // 绑定会话临时私钥存储（握手 / 前向保密）
        $this->app->singleton(SessionStoreInterface::class, function ($app) {
            $store = config('req-res-crypto.session_cache_store')
                ?: config('req-res-crypto.nonce_cache_store', 'file');

            return new CacheSessionStore($app['cache']->store($store));
        });

        // 绑定统一密钥提供者（内部自动处理 rotation 开关）
        // 使用 scoped 保证按请求隔离缓存，Octane 下也不会跨请求串数据
        $this->app->scoped(ServerKeyProviderInterface::class, function ($app) {
            return new ServerKeyProvider(
                config('req-res-crypto'),
                $app['db']->connection(config('req-res-crypto.database.connection', 'mysql')),
            );
        });

        // 保留 DatabaseKeyProvider 的独立绑定，供 Artisan 命令使用
        $this->app->singleton(DatabaseKeyProvider::class, function ($app) {
            return new DatabaseKeyProvider(
                $app['db']->connection(config('req-res-crypto.database.connection', 'mysql')),
                config('req-res-crypto.database.table', 'req_res_crypto_public_keys'),
            );
        });

        // 绑定 Sealer
        $this->app->singleton(SealerInterface::class, function ($app) {
            return new Sealer(
                $app->make(KeyExchange::class),
                $app->make(SerializerInterface::class),
            );
        });

        // 绑定会话加密器 / 握手服务
        $this->app->singleton(KeyDerivation::class, KeyDerivation::class);
        $this->app->singleton(SessionSealer::class, function ($app) {
            return new SessionSealer(
                $app->make(KeyDerivation::class),
                $app->make(SerializerInterface::class),
            );
        });
        $this->app->scoped(HandshakeService::class, function ($app) {
            return new HandshakeService(
                $app->make(ServerKeyProviderInterface::class),
                $app->make(SessionStoreInterface::class),
            );
        });

        // 绑定 Unsealer
        $this->app->singleton(UnsealerInterface::class, function ($app) {
            return new Unsealer(
                $app->make(KeyExchange::class),
                $app->make(ServerKeyProviderInterface::class),
                $app->make(NonceStoreInterface::class),
                $app->make(SerializerInterface::class),
                (int) config('req-res-crypto.time_window', 300),
                (int) config('req-res-crypto.nonce_ttl', 600),
                $app->make(SessionStoreInterface::class),
                $app->make(KeyDerivation::class),
            );
        });

        // 绑定 Facade
        $this->app->singleton('req-res-crypto', ReqResCryptoManager::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/req-res-crypto.php' => config_path('req-res-crypto.php'),
            ], 'req-res-crypto-config');

            $this->publishes([
                __DIR__ . '/../database/migrations/' => database_path('migrations'),
            ], 'req-res-crypto-migrations');

            $this->commands([
                RotateKeysCommand::class,
                ActivateKeyCommand::class,
                GenerateKeyCommand::class,
            ]);
        }

        // 注册定时自动轮换调度
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            if (config('req-res-crypto.crontab.enabled', false)) {
                $schedule->command('req-res-crypto:keys:rotate')
                    ->cron(config('req-res-crypto.crontab.rule', '0 2 * * *'));
            }
        });

        /** @var \Illuminate\Routing\Router $router */
        $router = $this->app['router'];

        $router->aliasMiddleware('req-res-encrypt', ReqResEncryptResponse::class);
        $router->aliasMiddleware('req-res-decrypt', ReqResDecryptRequest::class);
        $router->aliasMiddleware('req-res-crypto', ReqResAnnotationMiddleware::class);
        $router->aliasMiddleware('req-res-enforce', ReqResEnforceEncrypted::class);
        $router->aliasMiddleware('req-res-exceptions', ReqResCryptoException::class);

        // 开箱即用的公钥下发接口（默认 GET /req-res-crypto/public-key）
        if (config('req-res-crypto.public_key.enabled', true)) {
            $router->get(
                (string) config('req-res-crypto.public_key.path', '/req-res-crypto/public-key'),
                \Wenbo\ReqResCrypto\Laravel\Http\Controllers\PublicKeyController::class,
            );
        }

        // 开箱即用的会话握手接口（默认 GET /req-res-crypto/handshake）
        if (config('req-res-crypto.handshake.enabled', true)) {
            $router->get(
                (string) config('req-res-crypto.handshake.path', '/req-res-crypto/handshake'),
                \Wenbo\ReqResCrypto\Laravel\Http\Controllers\HandshakeController::class,
            );
        }
    }
}
