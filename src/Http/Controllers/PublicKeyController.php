<?php

declare(strict_types=1);

namespace Wenbo\ReqResCrypto\Laravel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Wenbo\ReqResCrypto\Core\ServerKeyProviderInterface;
use Wenbo\ReqResCrypto\Core\ServerKeysPayload;
use Wenbo\ReqResCrypto\Core\Signer;

/**
 * 开箱即用的服务端公钥下发接口。
 *
 * 路由：GET /req-res-crypto/public-key
 * 可通过 req-res-crypto.public_key.enabled=false 关闭。
 */
final class PublicKeyController
{
    public function __construct(
        private readonly ServerKeyProviderInterface $keyProvider,
    ) {
    }

    public function __invoke(): JsonResponse
    {
        if (! (bool) config('req-res-crypto.public_key.enabled', true)) {
            return response()->json(['error' => 'Not Found'], 404);
        }

        $current = $this->keyProvider->getCurrentKey();
        if ($current === null) {
            return response()->json(['error' => 'No active key'], 503);
        }

        $preIssued = $this->keyProvider->getPreIssuedKey();
        $ttl = max(1, (int) config('req-res-crypto.public_key.ttl', 3600));
        $issuedAt = time();
        $expiresAt = $issuedAt + $ttl;

        $signature = null;
        if (
            (bool) config('req-res-crypto.public_key.sign', true)
            && $current->signSecretKey !== ''
            && $current->signPublicKey !== ''
        ) {
            $payload = ServerKeysPayload::build($current, $preIssued, $issuedAt, $expiresAt);
            $signature = bin2hex(
                (new Signer($current->signSecretKey, $current->signPublicKey))->sign($payload),
            );
        }

        return response()->json([
            'key_id' => $current->keyId,
            'sign_public_key' => bin2hex($current->signPublicKey),
            'exchange_public_key' => bin2hex($current->exchangePublicKey),
            'pre_issued' => $preIssued === null ? null : [
                'key_id' => $preIssued->keyId,
                'sign_public_key' => bin2hex($preIssued->signPublicKey),
                'exchange_public_key' => bin2hex($preIssued->exchangePublicKey),
            ],
            'issued_at' => $issuedAt,
            'expires_at' => $expiresAt,
            'sig_version' => ServerKeysPayload::SIG_VERSION,
            'signature' => $signature,
        ]);
    }
}
