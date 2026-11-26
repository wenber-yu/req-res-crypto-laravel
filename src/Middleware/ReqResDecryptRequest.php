<?php

declare(strict_types=1);

namespace Wenbo\ReqResCrypto\Laravel\Middleware;

use Closure;
use Illuminate\Http\Request;
use Wenbo\ReqResCrypto\Core\Exceptions\SessionException;
use Wenbo\ReqResCrypto\Core\SerializerInterface;
use Wenbo\ReqResCrypto\Core\UnsealerInterface;

final readonly class ReqResDecryptRequest
{
    public function __construct(
        private UnsealerInterface $unsealer,
        private SerializerInterface $serializer,
    ) {
    }

    public function handle(Request $request, Closure $next): mixed
    {
        if (! $this->shouldDecrypt($request)) {
            return $next($request);
        }

        $content = $request->getContent();
        if ($content === '' || $content === null) {
            return $next($request);
        }

        $binary = base64_decode($content, true);
        if ($binary === false) {
            return $next($request);
        }

        // 解密失败说明请求被篡改或密钥不匹配，
        // CryptoException 自然传播，由全局异常处理器返回错误响应，绝不透传。
        try {
            $unsealed = $this->unsealer->unseal($binary);
        } catch (SessionException $e) {
            // 会话不存在/已过期：返回 409，客户端据此重新握手后重试一次
            return response()->json([
                'code' => SessionException::CODE_EXPIRED,
                'message' => $e->getMessage(),
            ], 409)->header('X-Req-Res-Crypto-Session-Expired', '1');
        }

        // 兼容 unseal 返回非字符串类型（如对象/数组），使用统一序列化器
        $plaintext = $unsealed->data;
        if (! is_string($plaintext)) {
            $plaintext = $this->serializer->serialize($plaintext);
        }

        // 客户端交换公钥随返回值交出，供响应加密使用
        $request->attributes->set(
            'req_res_crypto_client_pubkey',
            $unsealed->clientExchangePublicKey,
        );

        // v3 会话：记录 session_id，响应中间件用同一会话加密
        if ($unsealed->sessionId !== null) {
            $request->attributes->set('req_res_crypto_session_id', $unsealed->sessionId);
        }

        // 用反射直接设置 content 属性，避免 initialize() 重置 headers
        $contentProp = new \ReflectionProperty($request, 'content');
        $contentProp->setValue($request, $plaintext);

        if ($request->header('Content-Type') === 'application/octet-stream') {
            $request->headers->set('Content-Type', 'application/json');
        }

        // 密文长度与明文长度不同，必须同步更新
        $request->headers->set('Content-Length', (string) strlen($plaintext));

        return $next($request);
    }

    private function shouldDecrypt(Request $request): bool
    {
        $patterns = config('req-res-crypto.decrypt_routes', ['api/*']);

        foreach ($patterns as $pattern) {
            if ($request->is($pattern)) {
                return true;
            }
        }

        return false;
    }
}
