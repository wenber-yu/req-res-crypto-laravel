<?php

declare(strict_types=1);

namespace Wenbo\ReqResCrypto\Laravel\Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            \Wenbo\ReqResCrypto\Laravel\ReqResCryptoServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('req-res-crypto.database.table', 'req_res_crypto_public_keys');
        $app['config']->set('req-res-crypto.database.connection', 'testing');
        $app['config']->set('req-res-crypto.encrypt_routes', ['api/*']);
        $app['config']->set('req-res-crypto.decrypt_routes', ['api/*']);
        $app['config']->set('req-res-crypto.key_rotation.rotate_before_days', 7);
    }
}
