<?php

declare(strict_types=1);

namespace Wenbo\ReqResCrypto\Laravel\Tests\Middleware;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use SensitiveParameter;
use Wenbo\ReqResCrypto\Core\JsonSerializer;
use Wenbo\ReqResCrypto\Core\SealerInterface;
use Wenbo\ReqResCrypto\Core\SerializerInterface;
use Wenbo\ReqResCrypto\Core\ServerKey;
use Wenbo\ReqResCrypto\Core\ServerKeyProviderInterface;
use Wenbo\ReqResCrypto\Laravel\Middleware\ReqResEncryptResponse;

beforeEach(function () {
    config(['req-res-crypto.encrypt_routes' => ['api/*']]);
});

/**
 * @return array{0: SealerInterface, 1: ServerKeyProviderInterface, 2: SerializerInterface}
 */
function createMocks(?string $sealerReturn = null, ?\Throwable $sealerThrow = null): array
{
    $sealer = new class($sealerReturn, $sealerThrow) implements SealerInterface {
        public int $callCount = 0;

        public function __construct(
            private ?string $return,
            private ?\Throwable $throw,
        ) {
        }

        public function seal(string $exchangePublicKey, #[SensitiveParameter] string $exchangeSecretKey, string $theirExchangePubKey, mixed $plaintext): string
        {
            $this->callCount++;
            if ($this->throw !== null) {
                throw $this->throw;
            }

            return $this->return ?? '';
        }
    };

    $keyProvider = new class implements ServerKeyProviderInterface {
        public function getCurrentKey(): ?ServerKey
        {
            return new ServerKey(
                keyId: 'current_key_id_hex_24ch',
                signSecretKey: 'sign_secret_key_hex',
                signPublicKey: 'sign_pub_hex_32bytes_padded______',
                exchangeSecretKey: 'exchange_secret_hex_string',
                exchangePublicKey: hex2bin('aabbccddeeff00112233445566778899aabbccddeeff00112233445566778899'),
            );
        }

        public function getPreIssuedKey(): ?ServerKey
        {
            return null;
        }
    };

    return [$sealer, $keyProvider, new JsonSerializer()];
}

// 正常加密流程
test('encrypts response body for matching route', function () {
    $plaintext = '{"user":"alice"}';
    $encrypted = random_bytes(128);
    $encoded = base64_encode($encrypted);

    [$sealer, $keyProvider, $serializer] = createMocks(sealerReturn: $encrypted);
    $middleware = new ReqResEncryptResponse($sealer, $keyProvider, $serializer);

    $request = Request::create('/api/users', 'GET');
    // 设置客户端公钥（由解密中间件从 wire 提取）
    $request->attributes->set('req_res_crypto_client_pubkey', random_bytes(32));

    $response = new Response($plaintext, 200, ['Content-Type' => 'application/json']);

    $result = $middleware->handle($request, fn () => $response);

    expect($sealer->callCount)->toBe(1);
    expect($result->getContent())->toBe($encoded);
    expect($result->headers->get('Content-Type'))->toBe('application/octet-stream');
    expect($result->getStatusCode())->toBe(200);
});

// 空响应体跳过加密
test('skips encryption for empty response body', function () {
    [$sealer, $keyProvider, $serializer] = createMocks();
    $middleware = new ReqResEncryptResponse($sealer, $keyProvider, $serializer);

    $request = Request::create('/api/users', 'GET');
    $response = new Response('', 204);

    $result = $middleware->handle($request, fn () => $response);

    expect($sealer->callCount)->toBe(0);
    expect($result->getContent())->toBe('');
});

// 路由不匹配跳过加密
test('skips encryption when route does not match pattern', function () {
    [$sealer, $keyProvider, $serializer] = createMocks();
    $middleware = new ReqResEncryptResponse($sealer, $keyProvider, $serializer);

    $request = Request::create('/web/home', 'GET');
    $response = new Response('<html>...</html>', 200);

    $result = $middleware->handle($request, fn () => $response);

    expect($sealer->callCount)->toBe(0);
    expect($result->getContent())->toBe('<html>...</html>');
    expect($result->headers->get('Content-Type'))->not->toBe('application/octet-stream');
});

// false 响应体跳过
test('skips encryption when getContent returns false', function () {
    [$sealer, $keyProvider, $serializer] = createMocks();
    $middleware = new ReqResEncryptResponse($sealer, $keyProvider, $serializer);

    $request = Request::create('/api/data', 'GET');
    $response = \Mockery::mock(Response::class);
    $response->shouldReceive('getContent')->andReturn(false);

    $result = $middleware->handle($request, fn () => $response);

    expect($sealer->callCount)->toBe(0);
    expect($result)->toBe($response);
});

// Sealer 异常传播
test('propagates sealer exception without catching', function () {
    [$sealer, $keyProvider, $serializer] = createMocks(sealerThrow: new \RuntimeException('Crypto failure'));
    $middleware = new ReqResEncryptResponse($sealer, $keyProvider, $serializer);

    $request = Request::create('/api/data', 'GET');
    // 设置客户端公钥
    $request->attributes->set('req_res_crypto_client_pubkey', random_bytes(32));
    $response = new Response('{"test":true}', 200);

    expect(fn () => $middleware->handle($request, fn () => $response))
        ->toThrow(\RuntimeException::class, 'Crypto failure');
});

// 无客户端公钥时跳过加密
test('skips encryption when no client pubkey attribute', function () {
    [$sealer, $keyProvider, $serializer] = createMocks();
    $middleware = new ReqResEncryptResponse($sealer, $keyProvider, $serializer);

    $request = Request::create('/api/data', 'GET');
    // 不设置 req_res_crypto_client_pubkey
    $response = new Response('secret', 200);

    $result = $middleware->handle($request, fn () => $response);

    expect($sealer->callCount)->toBe(0);
    expect($result->getContent())->toBe('secret');
});
