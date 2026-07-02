<?php

declare(strict_types=1);

namespace Wenbo\ReqResCrypto\Laravel\Tests\Middleware;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Wenbo\ReqResCrypto\Core\Exceptions\CryptoException;
use Wenbo\ReqResCrypto\Core\JsonSerializer;
use Wenbo\ReqResCrypto\Core\SerializerInterface;
use Wenbo\ReqResCrypto\Core\UnsealerInterface;
use Wenbo\ReqResCrypto\Laravel\Middleware\ReqResDecryptRequest;

beforeEach(function () {
    config(['req-res-crypto.decrypt_routes' => ['api/*']]);
});

function mockUnsealer(?string $return = null, ?\Throwable $throw = null): UnsealerInterface
{
    return new class($return, $throw) implements UnsealerInterface {
        public int $callCount = 0;

        public function __construct(
            private ?string $return,
            private ?\Throwable $throw,
        ) {
        }

        public function unseal(string $wire): mixed
        {
            $this->callCount++;
            if ($this->throw !== null) {
                throw $this->throw;
            }

            return $this->return;
        }

        public function getClientExchangePubKey(): ?string
        {
            return null;
        }
    };
}

function mockSerializer(): SerializerInterface
{
    return new JsonSerializer();
}

// 正常解密流程
test('decrypts request body for matching route', function () {
    $plaintext = '{"user":"alice"}';
    $binary = random_bytes(128);
    $encoded = base64_encode($binary);

    $unsealer = mockUnsealer(return: $plaintext);
    $middleware = new ReqResDecryptRequest($unsealer, mockSerializer());

    $request = Request::create('/api/users', 'POST', [], [], [], [], $encoded);
    $request->headers->set('Content-Type', 'application/octet-stream');

    $called = false;
    $middleware->handle($request, function (Request $req) use ($plaintext, &$called) {
        $called = true;
        expect($req->getContent())->toBe($plaintext);
        expect($req->header('Content-Type'))->toBe('application/json');

        return new Response('ok');
    });

    expect($called)->toBeTrue();
    expect($unsealer->callCount)->toBe(1);
});

// 空请求体跳过解密
test('skips decryption for empty request body', function () {
    $unsealer = mockUnsealer();
    $middleware = new ReqResDecryptRequest($unsealer, mockSerializer());

    $request = Request::create('/api/users', 'POST', [], [], [], [], '');

    $result = $middleware->handle($request, fn (Request $req) => new Response('processed'));

    expect($result->getContent())->toBe('processed');
    expect($unsealer->callCount)->toBe(0);
});

// 路由不匹配跳过解密
test('skips decryption when route does not match pattern', function () {
    $unsealer = mockUnsealer();
    $middleware = new ReqResDecryptRequest($unsealer, mockSerializer());

    $request = Request::create('/web/home', 'POST', [], [], [], [], base64_encode('xxx'));

    $result = $middleware->handle($request, fn (Request $req) => new Response('ok'));

    expect($result->getContent())->toBe('ok');
    expect($unsealer->callCount)->toBe(0);
});

// base64 解码失败跳过
test('skips decryption when base64 decode fails', function () {
    $unsealer = mockUnsealer();
    $middleware = new ReqResDecryptRequest($unsealer, mockSerializer());

    $request = Request::create('/api/users', 'POST', [], [], [], [], '!!!not-valid-base64!!!');

    $result = $middleware->handle($request, fn (Request $req) => new Response('ok'));

    expect($result->getContent())->toBe('ok');
    expect($unsealer->callCount)->toBe(0);
});

// CryptoException 时抛出，不透传
test('throws CryptoException when unseal fails', function () {
    $originalBody = base64_encode('corrupted-wire');
    $unsealer = mockUnsealer(throw: new CryptoException('decrypt failed'));
    $middleware = new ReqResDecryptRequest($unsealer, mockSerializer());

    $request = Request::create('/api/users', 'POST', [], [], [], [], $originalBody);

    $middleware->handle($request, fn (Request $req) => new Response('ok'));
})->throws(CryptoException::class, 'decrypt failed');

// 保持非 octet-stream 的 Content-Type
test('preserves non-octet-stream content type', function () {
    $plaintext = 'hello';
    $binary = random_bytes(90);
    $encoded = base64_encode($binary);

    $unsealer = mockUnsealer(return: $plaintext);
    $middleware = new ReqResDecryptRequest($unsealer, mockSerializer());

    $request = Request::create('/api/users', 'POST', [], [], [], [], $encoded);
    $request->headers->set('Content-Type', 'text/plain');

    $middleware->handle($request, function (Request $req) {
        expect($req->header('Content-Type'))->toBe('text/plain');
        return new Response('ok');
    });
});

// 空 body 跳过
test('skips decryption when body is null', function () {
    $unsealer = mockUnsealer();
    $middleware = new ReqResDecryptRequest($unsealer, mockSerializer());

    $request = Request::create('/api/data', 'POST', [], [], [], [], null);

    $result = $middleware->handle($request, fn () => new Response('ok'));
    expect($result->getContent())->toBe('ok');
    expect($unsealer->callCount)->toBe(0);
});
