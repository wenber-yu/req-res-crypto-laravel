<?php

declare(strict_types=1);

namespace Wenbo\ReqResCrypto\Laravel\Tests\NonceStore;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Wenbo\ReqResCrypto\Laravel\NonceStore\CacheNonceStore;

// exists 返回 true 当缓存命中
test('exists returns true when cache has nonce', function () {
    $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

    $cache = $this->createMock(CacheRepository::class);
    $cache->expects($this->once())
        ->method('has')
        ->with('req_res_nonce:' . $nonce)
        ->willReturn(true);

    $store = new CacheNonceStore($cache);

    expect($store->exists($nonce))->toBeTrue();
});

// exists 返回 false 当缓存未命中
test('exists returns false when cache does not have nonce', function () {
    $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

    $cache = $this->createMock(CacheRepository::class);
    $cache->method('has')->willReturn(false);

    $store = new CacheNonceStore($cache);

    expect($store->exists($nonce))->toBeFalse();
});

// store 返回 true 首次写入（使用 add 原子写入）
test('store uses add for atomic write and returns true', function () {
    $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
    $ttl = 300;

    $cache = $this->createMock(CacheRepository::class);
    $cache->expects($this->once())
        ->method('add')
        ->with('req_res_nonce:' . $nonce, 1, $ttl)
        ->willReturn(true);

    $store = new CacheNonceStore($cache);
    $result = $store->store($nonce, $ttl);

    expect($result)->toBeTrue();
});

// store 返回 false 当 nonce 已存在
test('store returns false when nonce already exists', function () {
    $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

    $cache = $this->createMock(CacheRepository::class);
    $cache->expects($this->once())
        ->method('add')
        ->with('req_res_nonce:' . $nonce, 1, 300)
        ->willReturn(false);

    $store = new CacheNonceStore($cache);

    expect($store->store($nonce, 300))->toBeFalse();
});

// 不同 nonce 之间隔离
test('different nonces are stored independently', function () {
    $nonceA = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
    $nonceB = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

    $stored = [];

    $cache = $this->createMock(CacheRepository::class);
    $cache->method('add')->willReturnCallback(function (string $key) use (&$stored) {
        if (isset($stored[$key])) {
            return false;
        }
        $stored[$key] = true;
        return true;
    });
    $cache->method('has')->willReturnCallback(function (string $key) use (&$stored): bool {
        return isset($stored[$key]);
    });

    $store = new CacheNonceStore($cache);
    expect($store->store($nonceA, 300))->toBeTrue();
    expect($store->store($nonceB, 300))->toBeTrue();
    // 重复写入应返回 false
    expect($store->store($nonceA, 300))->toBeFalse();

    expect($store->exists($nonceA))->toBeTrue();
    expect($store->exists($nonceB))->toBeTrue();
});
