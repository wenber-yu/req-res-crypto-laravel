<?php

declare(strict_types=1);

namespace Wenbo\ReqResCrypto\Laravel;

use Illuminate\Support\Manager as LaravelManager;
use Wenbo\ReqResCrypto\Core\ServerKeyProviderInterface;

final class ReqResCryptoManager extends LaravelManager
{
    public function getDefaultDriver(): string
    {
        return 'default';
    }

    protected function createDefaultDriver(): ReqResCryptoDriver
    {
        return new ReqResCryptoDriver(
            $this->container->make(ServerKeyProviderInterface::class),
        );
    }
}
