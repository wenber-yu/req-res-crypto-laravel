<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $table = config('req-res-crypto.database.table', 'req_res_crypto_public_keys');

        Schema::create($table, function (Blueprint $table) {
            $table->id();
            $table->string('key_id', 32)->unique();
            $table->text('sign_public_key');
            $table->text('sign_secret_key');
            $table->text('exchange_public_key');
            $table->text('exchange_secret_key');
            $table->enum('status', ['pre_issued', 'current', 'expired'])->default('pre_issued')->index();
            $table->timestamp('issued_at');
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        $table = config('req-res-crypto.database.table', 'req_res_crypto_public_keys');

        Schema::dropIfExists($table);
    }
};
