<?php

declare(strict_types=1);

use Wenbo\ReqResCrypto\Core\KeyPair;
use Wenbo\ReqResCrypto\Core\ServerKey;
use Wenbo\ReqResCrypto\Core\ServerKeysPayload;
use Wenbo\ReqResCrypto\Core\Signer;

test('public key endpoint returns a signed payload', function () {
    $current = KeyPair::generate();

    config([
        'req-res-crypto.key_rotation.enabled' => false,
        'req-res-crypto.key_id' => $current->keyId(),
        'req-res-crypto.sign_secret_key' => bin2hex($current->signSecretKey),
        'req-res-crypto.sign_public_key' => bin2hex($current->signPublicKey),
        'req-res-crypto.exchange_secret_key' => bin2hex($current->exchangeSecretKey),
        'req-res-crypto.exchange_public_key' => bin2hex($current->exchangePublicKey),
    ]);

    $response = $this->getJson('/req-res-crypto/public-key');

    $response->assertOk();
    $data = $response->json();

    expect($data['key_id'])->toBe($current->keyId());
    expect($data['exchange_public_key'])->toBe(bin2hex($current->exchangePublicKey));
    expect($data['pre_issued'])->toBeNull();
    expect($data['signature'])->not->toBeNull();

    $serverKey = new ServerKey(
        $current->keyId(),
        $current->signSecretKey,
        $current->signPublicKey,
        $current->exchangeSecretKey,
        $current->exchangePublicKey,
    );
    $payload = ServerKeysPayload::build($serverKey, null, $data['issued_at'], $data['expires_at']);

    (new Signer('', $current->signPublicKey))->verify($payload, hex2bin($data['signature']));
});

test('public key endpoint returns 503 when no key configured', function () {
    config([
        'req-res-crypto.key_rotation.enabled' => false,
        'req-res-crypto.key_id' => '',
    ]);

    $this->getJson('/req-res-crypto/public-key')->assertStatus(503);
});
