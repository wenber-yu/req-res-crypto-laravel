<?php

declare(strict_types=1);

namespace Wenbo\ReqResCrypto\Laravel\Tests\Commands;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use stdClass;
use Wenbo\ReqResCrypto\Laravel\Commands\ActivateKeyCommand;

function makeRow(string $keyId, string $status, int $id = 1): stdClass
{
    $row = new stdClass();
    $row->id = $id;
    $row->key_id = $keyId;
    $row->status = $status;

    return $row;
}

// 无 key_id 时自动激活最早的 pre_issued 键
test('auto-activates oldest pre_issued key when no key_id given', function () {
    $table = 'req_res_crypto_public_keys';
    $row = makeRow('abc123abc123abc123abc123', 'pre_issued');

    $builder = $this->createMock(Builder::class);
    $builder->method('where')->willReturnSelf();
    $builder->method('orderBy')->willReturnSelf();
    $builder->method('first')->willReturn($row);
    $builder->method('update')->willReturn(1);

    $db = $this->createMock(ConnectionInterface::class);
    $db->method('table')->with($table)->willReturn($builder);
    $db->method('transaction')->willReturnCallback(function (callable $cb) use ($db) {
        $cb($db);
    });

    $command = new ActivateKeyCommand();
    $command->setLaravel($this->app);
    $command->setInput(new \Symfony\Component\Console\Input\ArrayInput([], $command->getDefinition()));
    $command->setOutput(new \Illuminate\Console\OutputStyle(
        new \Symfony\Component\Console\Input\ArrayInput([]),
        new \Symfony\Component\Console\Output\BufferedOutput(),
    ));

    $result = $command->handle($db);

    expect($result)->toBe(ActivateKeyCommand::SUCCESS);
});

// 指定 key_id 激活
test('activates specific key by key_id', function () {
    $table = 'req_res_crypto_public_keys';
    $row = makeRow('target111target111target', 'pre_issued');

    $builder = $this->createMock(Builder::class);
    $builder->method('where')->willReturnSelf();
    $builder->method('orderBy')->willReturnSelf();
    $builder->method('first')->willReturn($row);
    $builder->method('update')->willReturn(1);

    $db = $this->createMock(ConnectionInterface::class);
    $db->method('table')->with($table)->willReturn($builder);
    $db->method('transaction')->willReturnCallback(function (callable $cb) use ($db) {
        $cb($db);
    });

    $command = new ActivateKeyCommand();
    $command->setLaravel($this->app);
    $command->setInput(new \Symfony\Component\Console\Input\ArrayInput([
        'key_id' => 'target111target111target',
    ], $command->getDefinition()));
    $command->setOutput(new \Illuminate\Console\OutputStyle(
        new \Symfony\Component\Console\Input\ArrayInput([]),
        new \Symfony\Component\Console\Output\BufferedOutput(),
    ));

    $result = $command->handle($db);

    expect($result)->toBe(ActivateKeyCommand::SUCCESS);
});

// key_id 不存在时返回 FAILURE
test('returns failure when key_id not found', function () {
    $table = 'req_res_crypto_public_keys';

    $builder = $this->createMock(Builder::class);
    $builder->method('where')->willReturnSelf();
    $builder->method('first')->willReturn(null);

    $db = $this->createMock(ConnectionInterface::class);
    $db->method('table')->with($table)->willReturn($builder);
    // transaction 不应被调用
    $db->expects($this->never())->method('transaction');

    $command = new ActivateKeyCommand();
    $command->setLaravel($this->app);
    $command->setInput(new \Symfony\Component\Console\Input\ArrayInput([
        'key_id' => 'nonexistent000000000',
    ], $command->getDefinition()));
    $command->setOutput(new \Illuminate\Console\OutputStyle(
        new \Symfony\Component\Console\Input\ArrayInput([]),
        new \Symfony\Component\Console\Output\BufferedOutput(),
    ));

    $result = $command->handle($db);

    expect($result)->toBe(ActivateKeyCommand::FAILURE);
});

// key 非 pre_issued 状态时返回 FAILURE
test('returns failure when key is not pre_issued', function () {
    $table = 'req_res_crypto_public_keys';
    $row = makeRow('expired111expired11ex', 'expired');

    $builder = $this->createMock(Builder::class);
    $builder->method('where')->willReturnSelf();
    $builder->method('first')->willReturn($row);

    $db = $this->createMock(ConnectionInterface::class);
    $db->method('table')->with($table)->willReturn($builder);
    $db->expects($this->never())->method('transaction');

    $command = new ActivateKeyCommand();
    $command->setLaravel($this->app);
    $command->setInput(new \Symfony\Component\Console\Input\ArrayInput([
        'key_id' => 'expired111expired11ex',
    ], $command->getDefinition()));
    $command->setOutput(new \Illuminate\Console\OutputStyle(
        new \Symfony\Component\Console\Input\ArrayInput([]),
        new \Symfony\Component\Console\Output\BufferedOutput(),
    ));

    $result = $command->handle($db);

    expect($result)->toBe(ActivateKeyCommand::FAILURE);
});

// 无 pre_issued 键可激活时返回 SUCCESS
test('returns success when no pre_issued key available', function () {
    $table = 'req_res_crypto_public_keys';

    $builder = $this->createMock(Builder::class);
    $builder->method('where')->willReturnSelf();
    $builder->method('orderBy')->willReturnSelf();
    $builder->method('first')->willReturn(null);

    $db = $this->createMock(ConnectionInterface::class);
    $db->method('table')->with($table)->willReturn($builder);
    $db->expects($this->never())->method('transaction');

    $command = new ActivateKeyCommand();
    $command->setLaravel($this->app);
    $command->setInput(new \Symfony\Component\Console\Input\ArrayInput([], $command->getDefinition()));
    $command->setOutput(new \Illuminate\Console\OutputStyle(
        new \Symfony\Component\Console\Input\ArrayInput([]),
        new \Symfony\Component\Console\Output\BufferedOutput(),
    ));

    $result = $command->handle($db);

    expect($result)->toBe(ActivateKeyCommand::SUCCESS);
});

// 事务中 current → expired, pre_issued → current
test('transaction expires current key and activates pre_issued', function () {
    $table = 'req_res_crypto_public_keys';
    $row = makeRow('prekey111prekey111pre', 'pre_issued');
    $updates = [];

    $builder = $this->createMock(Builder::class);
    $builder->method('where')->willReturnSelf();
    $builder->method('orderBy')->willReturnSelf();
    $builder->method('first')->willReturn($row);
    $builder->method('update')->willReturnCallback(function (array $data) use (&$updates) {
        $updates[] = $data;

        return 1;
    });

    $db = $this->createMock(ConnectionInterface::class);
    $db->method('table')->with($table)->willReturn($builder);
    $db->method('transaction')->willReturnCallback(function (callable $cb) use ($db) {
        $cb($db);
    });

    $command = new ActivateKeyCommand();
    $command->setLaravel($this->app);
    $command->setInput(new \Symfony\Component\Console\Input\ArrayInput([], $command->getDefinition()));
    $command->setOutput(new \Illuminate\Console\OutputStyle(
        new \Symfony\Component\Console\Input\ArrayInput([]),
        new \Symfony\Component\Console\Output\BufferedOutput(),
    ));

    $result = $command->handle($db);

    expect($result)->toBe(ActivateKeyCommand::SUCCESS);
    // 应有两次 update：第一次 expire current，第二次 activate pre_issued
    expect(count($updates))->toBe(2);
    expect($updates[0]['status'])->toBe('expired');
    expect($updates[1]['status'])->toBe('current');
});
