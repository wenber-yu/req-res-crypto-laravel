<?php

declare(strict_types=1);

use Wenbo\ReqResCrypto\Core\HandshakePayload;
use Wenbo\ReqResCrypto\Core\KeyPair;
use Wenbo\ReqResCrypto\Core\SessionStoreInterface;
use Wenbo\ReqResCrypto\Core\Signer;

function configureHandshakeKeyPair(KeyPair $keyPair): void
{
    config([
        'req-res-crypto.key_rotation.enabled' => false,
        'req-res-crypto.key_id' => $keyPair->keyId(),
        'req-res-crypto.sign_secret_key' => bin2hex($keyPair->signSecretKey),
        'req-res-crypto.sign_public_key' => bin2hex($keyPair->signPublicKey),
        'req-res-crypto.exchange_secret_key' => bin2hex($keyPair->exchangeSecretKey),
        'req-res-crypto.exchange_public_key' => bin2hex($keyPair->exchangePublicKey),
        'req-res-crypto.handshake.enabled' => true,
        'req-res-crypto.handshake.session_ttl' => 600,
    ]);
}

test('handshake endpoint returns a signed ephemeral key and stores the session', function () {
    $keyPair = KeyPair::generate();
    configureHandshakeKeyPair($keyPair);

    $response = $this->getJson('/req-res-crypto/handshake');

    $response->assertOk();
    $data = $response->json();

    expect($data['key_id'])->toBe($keyPair->keyId());
    expect($data['sig_version'])->toBe(3);
    expect(strlen($data['session_id']))->toBe(32);
    expect(strlen($data['server_ephemeral_public_key']))->toBe(64);
    expect($data['expires_at'])->toBeGreaterThan($data['issued_at']);

    $payload = HandshakePayload::build(
        hex2bin($data['session_id']),
        $data['key_id'],
        hex2bin($data['server_ephemeral_public_key']),
        $data['issued_at'],
        $data['expires_at'],
    );

    (new Signer('', $keyPair->signPublicKey))->verify($payload, hex2bin($data['signature']));

    $sessionSecret = app(SessionStoreInterface::class)->get(hex2bin($data['session_id']));
    expect($sessionSecret)->not->toBeNull();
    expect(strlen($sessionSecret))->toBe(32);
});

test('handshake endpoint returns 404 when disabled', function () {
    $keyPair = KeyPair::generate();
    configureHandshakeKeyPair($keyPair);
    config(['req-res-crypto.handshake.enabled' => false]);

    $this->getJson('/req-res-crypto/handshake')->assertStatus(404);
});
