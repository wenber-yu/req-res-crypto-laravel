<?php

declare(strict_types=1);

namespace Wenbo\ReqResCrypto\Laravel\Middleware;

use Closure;
use Illuminate\Http\Request;
use Wenbo\ReqResCrypto\Core\SealerInterface;
use Wenbo\ReqResCrypto\Core\SerializerInterface;
use Wenbo\ReqResCrypto\Core\ServerKeyProviderInterface;

final readonly class ReqResEncryptResponse
{
    public function __construct(
        private SealerInterface $sealer,
        private ServerKeyProviderInterface $keyProvider,
        private SerializerInterface $serializer,
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

        // 客户端 X25519 公钥由解密中间件从 wire 提取，存入 request attribute
        $theirExchangePubKey = $request->attributes->get('req_res_crypto_client_pubkey', '');
        if ($theirExchangePubKey === '') {
            return $response;
        }

        // 一次调用获取当前密钥所有字段，无需多次查询
        $currentKey = $this->keyProvider->getCurrentKey();
        if ($currentKey === null || $currentKey->exchangeSecretKey === '' || $currentKey->exchangePublicKey === '') {
            return $response;
        }

        // 使用统一序列化器解码响应体，与 core 包保持一致
        $data = $this->serializer->unserialize($content);

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
