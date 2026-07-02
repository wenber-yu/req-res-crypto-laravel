<?php

declare(strict_types=1);

namespace Wenbo\ReqResCrypto\Laravel;

use Wenbo\ReqResCrypto\Core\ServerKeyProviderInterface;

/**
 * Facade 后端驱动，所有方法委托给 ServerKeyProviderInterface。
 */
final readonly class ReqResCryptoDriver
{
    public function __construct(
        private ServerKeyProviderInterface $keyProvider,
    ) {
    }

    public function keyId(): string
    {
        return $this->keyProvider->getCurrentKey()?->keyId ?? '';
    }

    public function signPublicKey(): string
    {
        return $this->keyProvider->getCurrentKey()?->signPublicKey ?? '';
    }

    public function exchangePublicKey(): string
    {
        return $this->keyProvider->getCurrentKey()?->exchangePublicKey ?? '';
    }
}
