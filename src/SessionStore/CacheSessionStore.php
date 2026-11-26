<?php

declare(strict_types=1);

namespace Wenbo\ReqResCrypto\Laravel\SessionStore;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Wenbo\ReqResCrypto\Core\SessionStoreInterface;

/**
 * 基于 Laravel Cache 的会话临时私钥存储。
 *
 * value 使用 base64 编码，兼容 file / redis / database 等驱动的二进制处理差异。
 */
final readonly class CacheSessionStore implements SessionStoreInterface
{
    private const KEY_PREFIX = 'req_res_session_';

    public function __construct(
        private CacheRepository $cache,
    ) {}

    public function put(string $sessionId, string $serverEphemeralSecretKey, int $ttlSeconds): void
    {
        $this->cache->put(
            self::key($sessionId),
            base64_encode($serverEphemeralSecretKey),
            max(1, $ttlSeconds),
        );
    }

    public function get(string $sessionId): ?string
    {
        $value = $this->cache->get(self::key($sessionId));

        if (! is_string($value) || $value === '') {
            return null;
        }

        $decoded = base64_decode($value, true);

        return $decoded === false ? null : $decoded;
    }

    public function forget(string $sessionId): void
    {
        $this->cache->forget(self::key($sessionId));
    }

    private static function key(string $sessionId): string
    {
        return self::KEY_PREFIX.bin2hex($sessionId);
    }
}
