<?php

declare(strict_types=1);

namespace Wenbo\ReqResCrypto\Laravel\Tests\Middleware;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Route;
use SensitiveParameter;
use Wenbo\ReqResCrypto\Core\JsonSerializer;
use Wenbo\ReqResCrypto\Core\SealerInterface;
use Wenbo\ReqResCrypto\Core\SerializerInterface;
use Wenbo\ReqResCrypto\Core\ServerKey;
use Wenbo\ReqResCrypto\Core\ServerKeyProviderInterface;
use Wenbo\ReqResCrypto\Core\UnsealerInterface;
use Wenbo\ReqResCrypto\Laravel\Attributes\ReqResDecrypt;
use Wenbo\ReqResCrypto\Laravel\Attributes\ReqResEncrypt;
use Wenbo\ReqResCrypto\Laravel\Middleware\ReqResAnnotationMiddleware;

// -------- 测试用的控制器类（带注解） --------

#[ReqResDecrypt]
#[ReqResEncrypt]
class TestAnnotatedController
{
    public function store()
    {
    }
}

class TestNonAnnotatedController
{
    public function store()
    {
    }
}

// -------- Mocks --------

function createMocksForAnnotation(
    ?string $unsealerReturn = null,
    ?string $sealerReturn = null,
): array {
    $unsealer = new class($unsealerReturn) implements UnsealerInterface {
        public int $callCount = 0;

        public function __construct(private ?string $return) {}

        public function unseal(string $wire): mixed
        {
            $this->callCount++;
            return $this->return;
        }

        public function getClientExchangePubKey(): ?string
        {
            return random_bytes(32);
        }
    };

    $sealer = new class($sealerReturn) implements SealerInterface {
        public int $callCount = 0;

        public function __construct(private ?string $return) {}

        public function seal(string $exchangePublicKey, #[SensitiveParameter] string $exchangeSecretKey, string $theirExchangePubKey, mixed $plaintext): string
        {
            $this->callCount++;
            return $this->return ?? '';
        }
    };

    $keyProvider = new class implements ServerKeyProviderInterface {
        public function getCurrentKey(): ?ServerKey
        {
            return new ServerKey(
                keyId: 'current_key_hex',
                signSecretKey: 'sign_secret_hex',
                signPublicKey: 'sign_pubkey_hex',
                exchangeSecretKey: 'secret_hex',
                exchangePublicKey: hex2bin('aabbccddeeff00112233445566778899aabbccddeeff00112233445566778899'),
            );
        }

        public function getPreIssuedKey(): ?ServerKey
        {
            return null;
        }
    };

    return [$unsealer, $sealer, $keyProvider, new JsonSerializer()];
}

function makeAnnotatedRoute(string $controllerClass, string $method = 'store'): Route
{
    return new Route(['POST'], '/api/test', ['uses' => [$controllerClass, $method]]);
}

// -------- 测试 --------

// 带注解的类 → 自动加解密
test('decrypts and encrypts for method with both annotations on class', function () {
    $plaintext = '{"user":"alice"}';
    $binary = random_bytes(128);
    $encoded = base64_encode($binary);
    $encrypted = random_bytes(100);
    $encodedResponse = base64_encode($encrypted);

    [$unsealer, $sealer, $keyProvider, $serializer] = createMocksForAnnotation(
        unsealerReturn: $plaintext,
        sealerReturn: $encrypted,
    );
    $middleware = new ReqResAnnotationMiddleware($unsealer, $sealer, $keyProvider, $serializer);

    $route = makeAnnotatedRoute(TestAnnotatedController::class);
    $request = Request::create('/api/test', 'POST', [], [], [], [], $encoded);
    $request->setRouteResolver(fn () => $route);
    $request->headers->set('Content-Type', 'application/octet-stream');

    $response = new Response('{"result":"ok"}', 200);

    $called = false;
    $result = $middleware->handle($request, function (Request $req) use ($plaintext, $response, &$called) {
        $called = true;
        expect($req->getContent())->toBe($plaintext);
        return $response;
    });

    expect($called)->toBeTrue();
    expect($unsealer->callCount)->toBe(1);
    expect($sealer->callCount)->toBe(1);
    expect($result->getContent())->toBe($encodedResponse);
    expect($result->headers->get('Content-Type'))->toBe('application/octet-stream');
});

// 无注解的类 → 跳过
test('skips both decrypt and encrypt for class without annotations', function () {
    [$unsealer, $sealer, $keyProvider, $serializer] = createMocksForAnnotation();
    $middleware = new ReqResAnnotationMiddleware($unsealer, $sealer, $keyProvider, $serializer);

    $route = makeAnnotatedRoute(TestNonAnnotatedController::class);
    $request = Request::create('/api/test', 'POST', [], [], [], [], base64_encode(random_bytes(100)));
    $request->setRouteResolver(fn () => $route);
    $request->headers->set('Content-Type', 'application/octet-stream');

    $originalContent = '<html>ok</html>';
    $response = new Response($originalContent, 200);

    $result = $middleware->handle($request, fn () => $response);

    expect($unsealer->callCount)->toBe(0);
    expect($sealer->callCount)->toBe(0);
    expect($result->getContent())->toBe($originalContent);
});

// 空请求体跳过解密
test('skips decryption when body is empty with annotation', function () {
    [$unsealer, $sealer, $keyProvider, $serializer] = createMocksForAnnotation();
    $middleware = new ReqResAnnotationMiddleware($unsealer, $sealer, $keyProvider, $serializer);

    $route = makeAnnotatedRoute(TestDecryptOnlyController::class);
    $request = Request::create('/api/test', 'POST', [], [], [], [], '');
    $request->setRouteResolver(fn () => $route);

    $result = $middleware->handle($request, fn (Request $req) => new Response('processed'));

    expect($unsealer->callCount)->toBe(0);
    expect($sealer->callCount)->toBe(0);
    expect($result->getContent())->toBe('processed');
});

// 无效路由（非 Route 实例）
test('skips when route is not a Route instance', function () {
    [$unsealer, $sealer, $keyProvider, $serializer] = createMocksForAnnotation();
    $middleware = new ReqResAnnotationMiddleware($unsealer, $sealer, $keyProvider, $serializer);

    $request = Request::create('/api/test', 'POST');
    // 不设置 route resolver，模拟无路由场景
    $request->setRouteResolver(fn () => null);

    $response = new Response('ok', 200);
    $result = $middleware->handle($request, fn () => $response);

    expect($unsealer->callCount)->toBe(0);
    expect($sealer->callCount)->toBe(0);
    expect($result->getContent())->toBe('ok');
});

// 空响应体跳过加密
test('skips encryption for empty response body with annotation', function () {
    [$unsealer, $sealer, $keyProvider, $serializer] = createMocksForAnnotation(sealerReturn: random_bytes(64));
    $middleware = new ReqResAnnotationMiddleware($unsealer, $sealer, $keyProvider, $serializer);

    $route = makeAnnotatedRoute(TestAnnotatedController::class);
    $request = Request::create('/api/test', 'GET');
    $request->setRouteResolver(fn () => $route);

    $response = new Response('', 204);
    $result = $middleware->handle($request, fn () => $response);

    expect($sealer->callCount)->toBe(0);
    expect($result->getContent())->toBe('');
});

// 仅解密注解（不加密响应）
#[ReqResDecrypt]
class TestDecryptOnlyController
{
    public function store() {}
}

test('only decrypts when only decrypt annotation present', function () {
    $plaintext = '{"user":"bob"}';
    $binary = random_bytes(128);
    $encoded = base64_encode($binary);

    [$unsealer, $sealer, $keyProvider, $serializer] = createMocksForAnnotation(unsealerReturn: $plaintext);
    $middleware = new ReqResAnnotationMiddleware($unsealer, $sealer, $keyProvider, $serializer);

    $route = makeAnnotatedRoute(TestDecryptOnlyController::class);
    $request = Request::create('/api/test', 'POST', [], [], [], [], $encoded);
    $request->setRouteResolver(fn () => $route);

    $originalResponse = new Response('{"result":"ok"}', 200);
    $result = $middleware->handle($request, function (Request $req) use ($plaintext, $originalResponse) {
        expect($req->getContent())->toBe($plaintext);
        return $originalResponse;
    });

    expect($unsealer->callCount)->toBe(1);
    expect($sealer->callCount)->toBe(0);
    expect($result->getContent())->toBe('{"result":"ok"}');
});

// 仅加密注解（不解密请求）
#[ReqResEncrypt]
class TestEncryptOnlyController
{
    public function store() {}
}

test('only encrypts when only encrypt annotation present', function () {
    $encrypted = random_bytes(100);
    $encodedResponse = base64_encode($encrypted);

    [$unsealer, $sealer, $keyProvider, $serializer] = createMocksForAnnotation(sealerReturn: $encrypted);
    $middleware = new ReqResAnnotationMiddleware($unsealer, $sealer, $keyProvider, $serializer);

    $route = makeAnnotatedRoute(TestEncryptOnlyController::class);
    $request = Request::create('/api/test', 'POST');
    $request->setRouteResolver(fn () => $route);

    $originalContent = '{"result":"ok"}';
    $response = new Response($originalContent, 200);
    $result = $middleware->handle($request, fn () => $response);

    expect($unsealer->callCount)->toBe(0);
    expect($sealer->callCount)->toBe(0); // 没有客户端公钥 attribute
    expect($result->getContent())->toBe($originalContent);
});
