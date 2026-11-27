<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Wenbo\ReqResCrypto\Core\Exceptions\ReplayException;
use Wenbo\ReqResCrypto\Core\Exceptions\SessionException;
use Wenbo\ReqResCrypto\Laravel\Middleware\ReqResCryptoException;

beforeEach(function () {
    config(['req-res-crypto.exceptions.enabled' => true]);
});

test('renders replay exception as 409 with stable code', function () {
    $middleware = new ReqResCryptoException();

    $result = $middleware->handle(
        Request::create('/api/users', 'POST'),
        fn () => throw ReplayException::duplicateNonce(),
    );

    expect($result->getStatusCode())->toBe(409);
    expect($result->getData(true)['code'])->toBe('REQ_RES_CRYPTO_REPLAY');
    expect($result->headers->get('X-Req-Res-Crypto-Error'))->toBe('REQ_RES_CRYPTO_REPLAY');
});

test('renders session exception with retry header', function () {
    $middleware = new ReqResCryptoException();

    $result = $middleware->handle(
        Request::create('/api/users', 'POST'),
        fn () => throw SessionException::notFound(),
    );

    expect($result->getStatusCode())->toBe(409);
    expect($result->getData(true)['code'])->toBe(SessionException::CODE_EXPIRED);
    expect($result->headers->get('X-Req-Res-Crypto-Session-Expired'))->toBe('1');
});

test('rethrows when normalization disabled', function () {
    config(['req-res-crypto.exceptions.enabled' => false]);

    $middleware = new ReqResCryptoException();

    $middleware->handle(
        Request::create('/api/users', 'POST'),
        fn () => throw ReplayException::duplicateNonce(),
    );
})->throws(ReplayException::class);
