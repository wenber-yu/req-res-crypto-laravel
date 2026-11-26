<?php

declare(strict_types=1);

namespace Wenbo\ReqResCrypto\Laravel\NonceStore;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Log;
use Throwable;
use Wenbo\ReqResCrypto\Core\NonceStoreInterface;

final readonly class CacheNonceStore implements NonceStoreInterface
{
    private const KEY_PREFIX = 'req_res_nonce:';

    public function __construct(
        private CacheRepository $cache,
    ) {
    }

    public function exists(string $nonce): bool
    {
        try {
            return $this->cache->has(self::key($nonce));
        } catch (Throwable $e) {
            Log::warning('req-res-crypto: nonce 存在性检查失败，防重放已降级', [
                'reason' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function store(string $nonce, int $ttlSeconds): bool
    {
        try {
            // Cache::add() 是原子的"不存在则写入"，避免竞态条件
            return $this->cache->add(self::key($nonce), 1, $ttlSeconds);
        } catch (Throwable $e) {
            Log::warning('req-res-crypto: nonce 写入失败，防重放已降级', [
                'reason' => $e->getMessage(),
            ]);

            // 缓存不可用时降级：视为首次写入，不阻断请求
            return true;
        }
    }

    /**
     * PSR-16 要求 key 只能包含 A-Za-z0-9_.，因此对二进制 nonce 做 hex 编码。
     */
    private static function key(string $nonce): string
    {
        return self::KEY_PREFIX.bin2hex($nonce);
    }
}
