<?php

declare(strict_types=1);

namespace Wenbo\ReqResCrypto\Laravel\Tests\KeyProvider;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use stdClass;
use Wenbo\ReqResCrypto\Core\Exceptions\KeyException;
use Wenbo\ReqResCrypto\Laravel\KeyProvider\DatabaseKeyProvider;

// 正常获取 current 密钥整行
test('returns current key with all fields', function () {
    $table = 'req_res_crypto_public_keys';
    $expectedKeyId = 'abc123ab';
    $expectedSignPub = bin2hex(random_bytes(32));
    $expectedExchangePub = bin2hex(random_bytes(32));
    $expectedSignSecret = bin2hex(random_bytes(64));
    $expectedExchangeSecret = bin2hex(random_bytes(32));

    $row = new stdClass();
    $row->key_id = $expectedKeyId;
    $row->sign_public_key = $expectedSignPub;
    $row->sign_secret_key = $expectedSignSecret;
    $row->exchange_public_key = $expectedExchangePub;
    $row->exchange_secret_key = $expectedExchangeSecret;

    $builder = $this->createMock(Builder::class);
    $builder->method('where')->willReturnSelf();
    $builder->method('first')->willReturn($row);

    $db = $this->createMock(ConnectionInterface::class);
    $db->method('table')->with($table)->willReturn($builder);

    $provider = new DatabaseKeyProvider($db, $table);
    $result = $provider->getCurrentKey();

    expect($result)->not->toBeNull();
    expect($result->keyId)->toBe($expectedKeyId);
    expect($result->signPublicKey)->toBe(hex2bin($expectedSignPub));
    expect($result->exchangePublicKey)->toBe(hex2bin($expectedExchangePub));
    expect($result->signSecretKey)->toBe(hex2bin($expectedSignSecret));
    expect($result->exchangeSecretKey)->toBe(hex2bin($expectedExchangeSecret));
});

// 获取 pre_issued 密钥
test('returns pre_issued key', function () {
    $table = 'req_res_crypto_public_keys';
    $expectedKeyId = 'pre123pre';

    $row = new stdClass();
    $row->key_id = $expectedKeyId;
    $row->sign_public_key = bin2hex(random_bytes(32));
    $row->sign_secret_key = bin2hex(random_bytes(64));
    $row->exchange_public_key = bin2hex(random_bytes(32));
    $row->exchange_secret_key = bin2hex(random_bytes(32));

    $builder = $this->createMock(Builder::class);
    $builder->method('where')->willReturnSelf();
    $builder->method('first')->willReturn($row);

    $db = $this->createMock(ConnectionInterface::class);
    $db->method('table')->with($table)->willReturn($builder);

    $provider = new DatabaseKeyProvider($db, $table);
    $result = $provider->getPreIssuedKey();

    expect($result)->not->toBeNull();
    expect($result->keyId)->toBe($expectedKeyId);
});

// 未找到时返回 null
test('returns null when key not found', function () {
    $table = 'req_res_crypto_public_keys';

    $builder = $this->createMock(Builder::class);
    $builder->method('where')->willReturnSelf();
    $builder->method('first')->willReturn(null);

    $db = $this->createMock(ConnectionInterface::class);
    $db->method('table')->with($table)->willReturn($builder);

    $provider = new DatabaseKeyProvider($db, $table);
    $result = $provider->getCurrentKey();

    expect($result)->toBeNull();
});

// 数据库异常时抛出 KeyException
test('throws KeyException on database error', function () {
    $table = 'req_res_crypto_public_keys';

    $builder = $this->createMock(Builder::class);
    $builder->method('where')->willReturnSelf();
    $builder->method('first')->willThrowException(new \PDOException('connection lost'));

    $db = $this->createMock(ConnectionInterface::class);
    $db->method('table')->with($table)->willReturn($builder);

    $provider = new DatabaseKeyProvider($db, $table);

    expect(fn () => $provider->getCurrentKey())
        ->toThrow(KeyException::class);
});
