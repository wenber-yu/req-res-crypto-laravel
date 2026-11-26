<?php

declare(strict_types=1);

namespace Wenbo\ReqResCrypto\Laravel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Throwable;
use Wenbo\ReqResCrypto\Core\HandshakeService;

/**
 * 开箱即用的会话握手接口。
 *
 * 路由：GET /req-res-crypto/handshake
 * 可通过 req-res-crypto.handshake.enabled=false 关闭。
 */
final class HandshakeController
{
    public function __construct(
        private readonly HandshakeService $handshakeService,
    ) {}

    public function __invoke(): JsonResponse
    {
        if (! (bool) config('req-res-crypto.handshake.enabled', true)) {
            return response()->json(['error' => 'Not Found'], 404);
        }

        try {
            $handshake = $this->handshakeService->issue(
                (int) config('req-res-crypto.handshake.session_ttl', 600),
            );
        } catch (Throwable) {
            return response()->json([
                'code' => 'REQ_RES_CRYPTO_HANDSHAKE_UNAVAILABLE',
                'message' => '会话握手暂不可用',
            ], 503);
        }

        return response()->json($handshake->toArray());
    }
}
