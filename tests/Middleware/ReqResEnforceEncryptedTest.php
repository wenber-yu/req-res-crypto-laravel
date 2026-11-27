<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Wenbo\ReqResCrypto\Laravel\Middleware\ReqResEnforceEncrypted;

beforeEach(function () {
    config([
        'req-res-crypto.decrypt_routes' => ['api/*'],
        'req-res-crypto.enforce.enabled' => true,
        'req-res-crypto.enforce.allow_empty_body' => true,
    ]);
});

test('rejects plaintext request on enforced route', function () {
    $middleware = new ReqResEnforceEncrypted();

    $request = Request::create('/api/users', 'POST', [], [], [], [], '{"amount":100}');

    $result = $middleware->handle($request, fn () => new Response('ok'));

    expect($result->getStatusCode())->toBe(400);
    expect($result->getData(true)['code'])->toBe('REQ_RES_CRYPTO_PLAINTEXT_FORBIDDEN');
});

test('allows valid v2 wire', function () {
    $middleware = new ReqResEnforceEncrypted();
    $body = base64_encode(chr(2).random_bytes(64));

    $request = Request::create('/api/users', 'POST', [], [], [], [], $body);

    $result = $middleware->handle($request, fn () => new Response('ok'));

    expect($result->getContent())->toBe('ok');
});

test('allows valid v3 wire', function () {
    $middleware = new ReqResEnforceEncrypted();
    $body = base64_encode(chr(3).random_bytes(64));

    $request = Request::create('/api/users', 'POST', [], [], [], [], $body);

    $result = $middleware->handle($request, fn () => new Response('ok'));

    expect($result->getContent())->toBe('ok');
});

test('allows empty body', function () {
    $middleware = new ReqResEnforceEncrypted();
    $request = Request::create('/api/users', 'GET');

    $result = $middleware->handle($request, fn () => new Response('ok'));

    expect($result->getContent())->toBe('ok');
});

test('skips routes outside decrypt patterns', function () {
    $middleware = new ReqResEnforceEncrypted();
    $request = Request::create('/web/home', 'POST', [], [], [], [], '{"plain":true}');

    $result = $middleware->handle($request, fn () => new Response('ok'));

    expect($result->getContent())->toBe('ok');
});

test('passes through when enforce disabled', function () {
    config(['req-res-crypto.enforce.enabled' => false]);

    $middleware = new ReqResEnforceEncrypted();
    $request = Request::create('/api/users', 'POST', [], [], [], [], '{"plain":true}');

    $result = $middleware->handle($request, fn () => new Response('ok'));

    expect($result->getContent())->toBe('ok');
});
