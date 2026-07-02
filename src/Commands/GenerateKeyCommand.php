<?php

declare(strict_types=1);

namespace Wenbo\ReqResCrypto\Laravel\Commands;

use Illuminate\Console\Command;
use Wenbo\ReqResCrypto\Core\KeyPair;

final class GenerateKeyCommand extends Command
{
    protected $signature = 'req-res-crypto:keys:generate';
    protected $description = 'Generate key pairs and print .env snippet';

    public function handle(): int
    {
        $signKp = KeyPair::generate();
        $exchangeKp = KeyPair::generate();

        $this->newLine();
        $this->line('<comment># Copy the following into your .env file:</comment>');
        $this->newLine();
        $this->line('REQ_RES_CRYPTO_KEY_ID=' . $signKp->keyId());
        $this->line('REQ_RES_CRYPTO_SIGN_SECRET_KEY=' . bin2hex($signKp->signSecretKey));
        $this->line('REQ_RES_CRYPTO_SIGN_PUBLIC_KEY=' . bin2hex($signKp->signPublicKey));
        $this->line('REQ_RES_CRYPTO_EXCHANGE_SECRET_KEY=' . bin2hex($exchangeKp->exchangeSecretKey));
        $this->line('REQ_RES_CRYPTO_EXCHANGE_PUBLIC_KEY=' . bin2hex($exchangeKp->exchangePublicKey));
        $this->newLine();
        $this->info('Done. Keys generated — paste the snippet above into your .env file.');

        return self::SUCCESS;
    }
}
