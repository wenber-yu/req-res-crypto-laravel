<?php

declare(strict_types=1);

namespace Wenbo\ReqResCrypto\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static string keyId()
 * @method static string signPublicKey()
 * @method static string exchangePublicKey()
 *
 * @see \Wenbo\ReqResCrypto\Laravel\ReqResCryptoManager
 */
final class ReqResCrypto extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'req-res-crypto';
    }
}
