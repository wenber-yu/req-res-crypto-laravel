<?php

declare(strict_types=1);

namespace Wenbo\ReqResCrypto\Laravel\Tests\Commands;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Wenbo\ReqResCrypto\Laravel\Commands\RotateKeysCommand;

beforeEach(function () {
    $this->app['config']->set('req-res-crypto.key_rotation.enabled', true);
});

// 密钥轮换关闭时返回 FAILURE，不写入数据库
test('returns failure when key rotation is disabled', function () {
    $this->app['config']->set('req-res-crypto.key_rotation.enabled', false);

    $db = $this->createMock(ConnectionInterface::class);
    $db->expects($this->never())->method('table');

    $command = new RotateKeysCommand();
    $command->setLaravel($this->app);
    $command->setInput(new \Symfony\Component\Console\Input\ArrayInput([], $command->getDefinition()));
    $command->setOutput(new \Illuminate\Console\OutputStyle(
        new \Symfony\Component\Console\Input\ArrayInput([]),
        new \Symfony\Component\Console\Output\BufferedOutput(),
    ));

    $result = $command->handle($db);

    expect($result)->toBe(RotateKeysCommand::FAILURE);
});

// 正常生成密钥对并写入数据库，status 为 pre_issued
test('generates key pairs and inserts with pre_issued status', function () {
    $table = 'req_res_crypto_public_keys';

    $builder = $this->createMock(Builder::class);
    $builder->expects($this->once())
        ->method('insert')
        ->with($this->callback(function (array $data) {
            return $data['status'] === 'pre_issued'
                && $data['key_id'] !== ''
                && $data['sign_public_key'] !== ''
                && $data['sign_secret_key'] !== ''
                && $data['exchange_public_key'] !== ''
                && $data['exchange_secret_key'] !== ''
                && $data['activated_at'] === null
                && $data['expired_at'] === null;
        }));

    $db = $this->createMock(ConnectionInterface::class);
    $db->method('table')->with($table)->willReturn($builder);

    $command = new RotateKeysCommand();
    $command->setLaravel($this->app);
    $command->setInput(new \Symfony\Component\Console\Input\ArrayInput([], $command->getDefinition()));
    $command->setOutput(new \Illuminate\Console\OutputStyle(
        new \Symfony\Component\Console\Input\ArrayInput([]),
        new \Symfony\Component\Console\Output\BufferedOutput(),
    ));

    $result = $command->handle($db);

    expect($result)->toBe(RotateKeysCommand::SUCCESS);
});

// 验证 key_id 为 8 字符 hex
test('inserted key_id is 24 hex characters', function () {
    $table = 'req_res_crypto_public_keys';
    $captured = null;

    $builder = $this->createMock(Builder::class);
    $builder->method('insert')->willReturnCallback(function (array $data) use (&$captured) {
        $captured = $data;

        return true;
    });

    $db = $this->createMock(ConnectionInterface::class);
    $db->method('table')->with($table)->willReturn($builder);

    $command = new RotateKeysCommand();
    $command->setLaravel($this->app);
    $command->setInput(new \Symfony\Component\Console\Input\ArrayInput([], $command->getDefinition()));
    $command->setOutput(new \Illuminate\Console\OutputStyle(
        new \Symfony\Component\Console\Input\ArrayInput([]),
        new \Symfony\Component\Console\Output\BufferedOutput(),
    ));
    $command->handle($db);

    expect(strlen($captured['key_id']))->toBe(8);
    expect(ctype_xdigit($captured['key_id']))->toBeTrue();
});

// 验证公钥/私钥为 64 字符 hex（32 字节 Ed25519/X25519）
test('inserted keys are 64 hex characters', function () {
    $table = 'req_res_crypto_public_keys';
    $captured = null;

    $builder = $this->createMock(Builder::class);
    $builder->method('insert')->willReturnCallback(function (array $data) use (&$captured) {
        $captured = $data;

        return true;
    });

    $db = $this->createMock(ConnectionInterface::class);
    $db->method('table')->with($table)->willReturn($builder);

    $command = new RotateKeysCommand();
    $command->setLaravel($this->app);
    $command->setInput(new \Symfony\Component\Console\Input\ArrayInput([], $command->getDefinition()));
    $command->setOutput(new \Illuminate\Console\OutputStyle(
        new \Symfony\Component\Console\Input\ArrayInput([]),
        new \Symfony\Component\Console\Output\BufferedOutput(),
    ));
    $command->handle($db);

    expect(strlen($captured['sign_public_key']))->toBe(64);
    expect(strlen($captured['sign_secret_key']))->toBe(128);
    expect(strlen($captured['exchange_public_key']))->toBe(64);
    expect(strlen($captured['exchange_secret_key']))->toBe(64);
});

// 验证 issued_at 为当前时间，activated_at 为 null
test('activate_at is rotate_before_days after issued_at', function () {
    $table = 'req_res_crypto_public_keys';
    $captured = null;

    $builder = $this->createMock(Builder::class);
    $builder->method('insert')->willReturnCallback(function (array $data) use (&$captured) {
        $captured = $data;

        return true;
    });

    $db = $this->createMock(ConnectionInterface::class);
    $db->method('table')->with($table)->willReturn($builder);

    // 设置 rotate_before_days = 3
    $this->app['config']->set('req-res-crypto.key_rotation.rotate_before_days', 3);

    $command = new RotateKeysCommand();
    $command->setLaravel($this->app);
    $command->setInput(new \Symfony\Component\Console\Input\ArrayInput([], $command->getDefinition()));
    $command->setOutput(new \Illuminate\Console\OutputStyle(
        new \Symfony\Component\Console\Input\ArrayInput([]),
        new \Symfony\Component\Console\Output\BufferedOutput(),
    ));
    $command->handle($db);

    $issuedAt = strtotime($captured['issued_at']);
    $now = time();

    // issued_at 应该接近当前时间（±2秒）
    expect($issuedAt)->toBeGreaterThanOrEqual($now - 2);
    expect($issuedAt)->toBeLessThanOrEqual($now + 2);

    // activated_at 在插入时应为 null（由 ActivateKeyCommand 激活时填充）
    expect($captured['activated_at'])->toBeNull();

    // expired_at 在插入时应为 null
    expect($captured['expired_at'])->toBeNull();
});
