<?php

declare(strict_types=1);

namespace Wenbo\ReqResCrypto\Laravel\NonceStore;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Wenbo\ReqResCrypto\Core\NonceStoreInterface;

final readonly class CacheNonceStore implements NonceStoreInterface
{
    public function __construct(
        private CacheRepository $cache,
    ) {
    }

    public function exists(string $nonce): bool
    {
        try {
            return $this->cache->has('req_res_nonce:' . $nonce);
        } catch (\Throwable) {
            return false;
        }
    }

    public function store(string $nonce, int $ttlSeconds): bool
    {
        try {
            // Cache::add() 是原子的"不存在则写入"，避免竞态条件
            return $this->cache->add('req_res_nonce:' . $nonce, 1, $ttlSeconds);
        } catch (\Throwable) {
            // 缓存不可用时降级：视为首次写入，不阻断请求
            return true;
        }
    }
}
