<?php

declare(strict_types=1);

namespace Wenbo\ReqResCrypto\Laravel\Middleware;

use Closure;
use Illuminate\Http\Request;
use Wenbo\ReqResCrypto\Core\SealerInterface;
use Wenbo\ReqResCrypto\Core\SerializerInterface;
use Wenbo\ReqResCrypto\Core\ServerKeyProviderInterface;
use Wenbo\ReqResCrypto\Core\SessionSealer;
use Wenbo\ReqResCrypto\Core\SessionStoreInterface;

final readonly class ReqResEncryptResponse
{
    public function __construct(
        private SealerInterface $sealer,
        private ServerKeyProviderInterface $keyProvider,
        private SerializerInterface $serializer,
        private ?SessionStoreInterface $sessionStore = null,
        private ?SessionSealer $sessionSealer = null,
    ) {
    }

    public function handle(Request $request, Closure $next): mixed
    {
        $response = $next($request);

        if (! $this->shouldEncrypt($request)) {
            return $response;
        }

        $content = $response->getContent();
        if ($content === false || $content === '') {
            return $response;
        }

        // 仅加密 JSON 响应；文件下载、SSE、XML 等原样返回，避免 unserialize 抛错
        $contentType = strtolower((string) $response->headers->get('Content-Type'));
        if (! str_contains($contentType, 'json')) {
            return $response;
        }

        // 客户端 X25519 公钥由解密中间件从 wire 提取，存入 request attribute
        $theirExchangePubKey = $request->attributes->get('req_res_crypto_client_pubkey', '');
        if ($theirExchangePubKey === '') {
            return $response;
        }

        // 一次调用获取当前密钥所有字段，无需多次查询
        $currentKey = $this->keyProvider->getCurrentKey();
        if ($currentKey === null) {
            return $response;
        }

        // 使用统一序列化器解码响应体，与 core 包保持一致
        $data = $this->serializer->unserialize($content);

        // v3 会话模式：用握手时保存的临时私钥加密响应
        $sessionId = $request->attributes->get('req_res_crypto_session_id', '');
        if (is_string($sessionId) && strlen($sessionId) === 16) {
            return $this->sealSessionResponse($response, $sessionId, $theirExchangePubKey, $data);
        }

        // v2：使用静态交换密钥
        if ($currentKey->exchangeSecretKey === '' || $currentKey->exchangePublicKey === '') {
            return $response;
        }

        $payload = $this->sealer->seal(
            bin2hex($currentKey->exchangePublicKey),
            $currentKey->exchangeSecretKey,
            $theirExchangePubKey,
            $data,
        );

        $response->setContent(
            base64_encode($payload)
        );

        $response->header('Content-Type', 'application/octet-stream');

        // 检查是否有 pre_issued 密钥，通知客户端即将轮换
        $response = $this->attachKeyRotationHeader($response);

        return $response;
    }

    /**
     * 用会话临时私钥加密响应；会话已失效时返回 409 明文，客户端会重新握手并重试。
     */
    private function sealSessionResponse(
        mixed $response,
        string $sessionId,
        string $clientEphemeralPublicKey,
        mixed $data,
    ): mixed {
        $serverEphemeralSecretKey = $this->sessionStore?->get($sessionId);

        if ($serverEphemeralSecretKey === null || $this->sessionSealer === null) {
            $response->setContent($this->serializer->serialize([
                'code' => 'REQ_RES_CRYPTO_SESSION_EXPIRED',
                'message' => '会话不存在或已过期，请重新握手',
            ]));
            $response->setStatusCode(409);
            $response->header('Content-Type', 'application/json');
            $response->header('X-Req-Res-Crypto-Session-Expired', '1');

            return $response;
        }

        $ciphertext = $this->sessionSealer->sealResponse(
            $serverEphemeralSecretKey,
            $clientEphemeralPublicKey,
            $sessionId,
            $data,
        );

        $response->setContent(base64_encode($ciphertext));
        $response->header('Content-Type', 'application/octet-stream');

        return $response;
    }

    private function shouldEncrypt(Request $request): bool
    {
        $patterns = config('req-res-crypto.encrypt_routes', ['api/*']);

        foreach ($patterns as $pattern) {
            if ($request->is($pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 检测 pre_issued 密钥，存在时附加 X-Req-Res-Crypto-Key-Rotate 响应头。
     */
    private function attachKeyRotationHeader(mixed $response): mixed
    {
        $preIssued = $this->keyProvider->getPreIssuedKey();
        if ($preIssued === null) {
            return $response;
        }

        $response->header('X-Req-Res-Crypto-Key-Rotate', $this->serializer->serialize([
            'key_id' => $preIssued->keyId,
            'sign_public_key' => bin2hex($preIssued->signPublicKey),
            'exchange_public_key' => bin2hex($preIssued->exchangePublicKey),
        ]));

        return $response;
    }
}
