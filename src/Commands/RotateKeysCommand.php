<?php

declare(strict_types=1);

namespace Wenbo\ReqResCrypto\Laravel\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Wenbo\ReqResCrypto\Core\KeyPair;

final class RotateKeysCommand extends Command
{
    protected $signature = 'req-res-crypto:keys:rotate';
    protected $description = 'Generate new key pairs and insert as pre_issued';

    public function handle(ConnectionInterface $db): int
    {
        if (!config('req-res-crypto.key_rotation.enabled', false)) {
            $this->info('Key rotation is disabled.');

            return self::FAILURE;
        }

        $table = config('req-res-crypto.database.table');

        $signKeyPair = KeyPair::generate();
        $exchangeKeyPair = KeyPair::generate();
        $keyId = bin2hex(random_bytes(4));

        $now = Carbon::now();
        $issuedAt = $now->toDateTimeString();

        $db->table($table)->insert([
            'key_id'                => $keyId,
            'sign_public_key'       => bin2hex($signKeyPair->signPublicKey),
            'sign_secret_key'       => bin2hex($signKeyPair->signSecretKey),
            'exchange_public_key'   => bin2hex($exchangeKeyPair->exchangePublicKey),
            'exchange_secret_key'   => bin2hex($exchangeKeyPair->exchangeSecretKey),
            'status'                => 'pre_issued',
            'issued_at'             => $issuedAt,
            'activated_at'          => null,
            'expired_at'            => null,
            'created_at'            => $now->toDateTimeString(),
            'updated_at'            => $now->toDateTimeString(),
        ]);

        $this->info("Rotated keys — KeyID: {$keyId}");
        $this->info("  sign_public_key: " . bin2hex($signKeyPair->signPublicKey));
        $this->info("  exchange_public_key: " . bin2hex($exchangeKeyPair->exchangePublicKey));

        return self::SUCCESS;
    }
}
