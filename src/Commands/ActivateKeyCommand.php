<?php

declare(strict_types=1);

namespace Wenbo\ReqResCrypto\Laravel\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;

final class ActivateKeyCommand extends Command
{
    protected $signature = 'req-res-crypto:keys:activate {key_id? : The key ID to activate. If omitted, activates the oldest pre_issued key past its activate_at}';
    protected $description = 'Activate a pre_issued key and expire the current one';

    public function handle(ConnectionInterface $db): int
    {
        $table = config('req-res-crypto.database.table');
        $keyId = $this->argument('key_id');

        $now = Carbon::now()->toDateTimeString();

        if ($keyId !== null) {
            $row = $db->table($table)->where('key_id', $keyId)->first();
            if ($row === null) {
                $this->error("KeyID '{$keyId}' not found.");

                return self::FAILURE;
            }
            if ($row->status !== 'pre_issued') {
                $this->error("KeyID '{$keyId}' is not in pre_issued status.");

                return self::FAILURE;
            }
        } else {
            $row = $db->table($table)
                ->where('status', 'pre_issued')
                ->where(function ($q) use ($now) {
                    $q->whereNull('activated_at')
                      ->orWhere('activated_at', '<=', $now);
                })
                ->orderBy('issued_at')
                ->first();

            if ($row === null) {
                $this->info('No pre_issued key ready for activation.');

                return self::SUCCESS;
            }
        }

        $db->transaction(function () use ($db, $table, $row, $now) {
            $db->table($table)
                ->where('status', 'current')
                ->update([
                    'status'     => 'expired',
                    'expired_at' => $now,
                    'updated_at' => $now,
                ]);

            $db->table($table)
                ->where('id', $row->id)
                ->update([
                    'status'       => 'current',
                    'activated_at' => $now,
                    'updated_at'   => $now,
                ]);
        });

        $this->info("Activated KeyID: {$row->key_id}");

        return self::SUCCESS;
    }
}
