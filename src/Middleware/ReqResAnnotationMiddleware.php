<?php

declare(strict_types=1);

namespace Wenbo\ReqResCrypto\Laravel\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Wenbo\ReqResCrypto\Core\PathMatcher;
use Wenbo\ReqResCrypto\Core\SealerInterface;
use Wenbo\ReqResCrypto\Core\SerializerInterface;
use Wenbo\ReqResCrypto\Core\ServerKeyProviderInterface;
use Wenbo\ReqResCrypto\Core\UnsealerInterface;
use Wenbo\ReqResCrypto\Laravel\Attributes\ReqResDecrypt;
use Wenbo\ReqResCrypto\Laravel\Attributes\ReqResEncrypt;
use Wenbo\ReqResCrypto\Laravel\Attributes\SkipReqResCrypto;

/**
 * 注解驱动的加解密中间件。
 *
 * 通过反射读取当前路由对应控制器方法上的 #[ReqResDecrypt] / #[ReqResEncrypt] 属性，
 * 执行对应的解密/加密逻辑。可与全局中间件共存，互不干扰。
 *
 * 注册方式：
 *   Route::middleware('req-res-crypto')->group(function () { ... });
 */
final readonly class ReqResAnnotationMiddleware
{
    public function __construct(
        private UnsealerInterface $unsealer,
        private SealerInterface $sealer,
        private ServerKeyProviderInterface $keyProvider,
        private SerializerInterface $serializer,
    ) {
    }

    public function handle(Request $request, Closure $next): mixed
    {
        $route = $request->route();

        $shouldDecrypt = false;
        $shouldEncrypt = false;

        if ($route instanceof Route) {
            [$class, $method] = $this->resolveController($route);
            if ($class !== null && $method !== null) {
                $shouldDecrypt = $this->hasAttribute($class, $method, ReqResDecrypt::class);
                $shouldEncrypt = $this->hasAttribute($class, $method, ReqResEncrypt::class);
            }
        }

        // 路由是否命中 skip_routes 配置
        $pathSkipped = $this->isPathSkipped($request);

        // #[SkipReqResCrypto] 优先级最高
        $routeSkipped = $route instanceof Route && $this->routeHasSkipAttribute($route);
        if ($pathSkipped || $routeSkipped) {
            $shouldDecrypt = false;
            $shouldEncrypt = false;
        }

        // 前端声明跳过加密的 header
        $skipHeader = (string) config('req-res-crypto.skip_header', '');
        if ($skipHeader !== '' && $request->header($skipHeader) === '1') {
            if ($pathSkipped || $routeSkipped) {
                $shouldDecrypt = false;
                $shouldEncrypt = false;
            } elseif ($shouldDecrypt || $shouldEncrypt) {
                abort(400, 'Plaintext request is not allowed for this endpoint.');
            }
        }

        if ($shouldDecrypt) {
            $request = $this->decryptRequest($request);
        }

        $response = $next($request);

        if ($shouldEncrypt) {
            $response = $this->encryptResponse($request, $response);
        }

        return $response;
    }

    /**
     * 从 Route 解析出控制器类和方法名。
     *
     * @return array{string|null, string|null}
     */
    private function resolveController(Route $route): array
    {
        $uses = $route->getAction('uses');

        return match (true) {
            is_string($uses) && str_contains($uses, '@') => explode('@', $uses, 2),
            is_array($uses) && count($uses) === 2 => $uses,
            default => [$route->getControllerClass(), $route->getActionMethod()],
        };
    }

    private function hasAttribute(string $class, string $method, string $attributeClass): bool
    {
        if (! class_exists($class)) {
            return false;
        }

        try {
            $refMethod = new \ReflectionMethod($class, $method);
            $refClass = new \ReflectionClass($class);

            return ! empty($refMethod->getAttributes($attributeClass))
                || ! empty($refClass->getAttributes($attributeClass));
        } catch (\ReflectionException) {
            return false;
        }
    }

    private function isPathSkipped(Request $request): bool
    {
        $patterns = (array) config('req-res-crypto.skip_routes', []);
        if ($patterns === []) {
            return false;
        }

        $path = '/' . ltrim($request->path(), '/');

        return PathMatcher::matchesAny($path, $patterns);
    }

    private function routeHasSkipAttribute(Route $route): bool
    {
        [$class, $method] = $this->resolveController($route);

        if ($class === null || $method === null) {
            return false;
        }

        return $this->hasAttribute($class, $method, SkipReqResCrypto::class);
    }

    private function decryptRequest(Request $request): Request
    {
        $content = $request->getContent();
        if ($content === '' || $content === null) {
            return $request;
        }

        $binary = base64_decode($content, true);
        if ($binary === false) {
            return $request;
        }

        $plaintext = $this->unsealer->unseal($binary);

        if (! is_string($plaintext)) {
            $plaintext = $this->serializer->serialize($plaintext);
        }

        $clientPubkey = $this->unsealer->getClientExchangePubKey();
        if ($clientPubkey !== null) {
            $request->attributes->set('req_res_crypto_client_pubkey', $clientPubkey);
        }

        $contentProp = new \ReflectionProperty($request, 'content');
        $contentProp->setValue($request, $plaintext);

        if ($request->header('Content-Type') === 'application/octet-stream') {
            $request->headers->set('Content-Type', 'application/json');
        }

        return $request;
    }

    private function encryptResponse(Request $request, mixed $response): mixed
    {
        $content = $response->getContent();
        if ($content === false || $content === '') {
            return $response;
        }

        $data = $this->serializer->unserialize($content);

        $currentKey = $this->keyProvider->getCurrentKey();
        if ($currentKey === null || $currentKey->exchangeSecretKey === '' || $currentKey->exchangePublicKey === '') {
            return $response;
        }

        $theirExchangePubKey = $request->attributes->get('req_res_crypto_client_pubkey', '');
        if ($theirExchangePubKey === '') {
            return $response;
        }

        $payload = $this->sealer->seal(
            bin2hex($currentKey->exchangePublicKey),
            $currentKey->exchangeSecretKey,
            $theirExchangePubKey,
            $data,
        );

        $response->setContent(base64_encode($payload));
        $response->header('Content-Type', 'application/octet-stream');

        return $this->attachKeyRotationHeader($response);
    }

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
