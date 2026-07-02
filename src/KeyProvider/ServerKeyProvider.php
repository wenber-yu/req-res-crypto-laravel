<?php

declare(strict_types=1);

namespace Wenbo\ReqResCrypto\Laravel\KeyProvider;

use Illuminate\Database\ConnectionInterface;
use PDOException;
use SensitiveParameter;
use Wenbo\ReqResCrypto\Core\ServerKey;
use Wenbo\ReqResCrypto\Core\Exceptions\KeyException;
use Wenbo\ReqResCrypto\Core\ServerKeyProviderInterface;

/**
 * 统一密钥提供者，对上层完全透明。
 *
 * 内部根据 key_rotation.enabled 决定数据来源：
 * - 关闭：使用配置文件中的 bootstrap 密钥，getPreIssuedKey() 永远返回 null
 * - 开启：优先查数据库，数据库无记录时降级使用配置文件密钥
 *
 * 由于该类绑定为 singleton，在单次请求内缓存 current 和 pre_issued 两行。
 */
final class ServerKeyProvider implements ServerKeyProviderInterface
{
    /** @param array<string, mixed> $config  req-res-crypto 完整配置 */
    public function __construct(
        private array $config,
        private ConnectionInterface $db,
    ) {
    }

    // ─────────────────────── 请求级缓存 ──────────────────────────

    /** @var array<string, string>|null */
    private ?array $currentKeyRow = null;

    /** @var array<string, string>|null */
    private ?array $preIssuedKeyRow = null;

    // ─────────────────────── 接口实现 ────────────────────────────

    public function getCurrentKey(): ?ServerKey
    {
        if ($this->rotationEnabled()) {
            $row = $this->loadCurrentKeyRow();
            if ($row !== null) {
                return $this->rowToServerKey($row);
            }
        }

        return $this->configToServerKey();
    }

    public function getPreIssuedKey(): ?ServerKey
    {
        if (! $this->rotationEnabled()) {
            return null;
        }

        $row = $this->loadPreIssuedKeyRow();

        return $row !== null ? $this->rowToServerKey($row) : null;
    }

    // ─────────────────────── 缓存加载 ────────────────────────────

    private function loadCurrentKeyRow(): ?array
    {
        if ($this->currentKeyRow === null && $this->rotationEnabled()) {
            $this->currentKeyRow = $this->fetchRow('status', 'current');
        }

        return $this->currentKeyRow;
    }

    private function loadPreIssuedKeyRow(): ?array
    {
        if ($this->preIssuedKeyRow === null && $this->rotationEnabled()) {
            $this->preIssuedKeyRow = $this->fetchRow('status', 'pre_issued');
        }

        return $this->preIssuedKeyRow;
    }

    // ─────────────────────── 内部 ────────────────────────────────

    private function rotationEnabled(): bool
    {
        return (bool) ($this->config['key_rotation']['enabled'] ?? false);
    }

    private function table(): string
    {
        return $this->config['database']['table'] ?? 'req_res_crypto_public_keys';
    }

    /** @return array<string, string>|null */
    private function fetchRow(string $rowColumn, string $rowValue): ?array
    {
        try {
            $row = $this->db
                ->table($this->table())
                ->where($rowColumn, $rowValue)
                ->first();

            return $row !== null ? (array) $row : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function rowToServerKey(array $row): ServerKey
    {
        return new ServerKey(
            keyId: $row['key_id'] ?? '',
            signSecretKey: isset($row['sign_secret_key']) ? (hex2bin($row['sign_secret_key']) ?: '') : '',
            signPublicKey: isset($row['sign_public_key']) ? (hex2bin($row['sign_public_key']) ?: '') : '',
            exchangeSecretKey: isset($row['exchange_secret_key']) ? (hex2bin($row['exchange_secret_key']) ?: '') : '',
            exchangePublicKey: isset($row['exchange_public_key']) ? (hex2bin($row['exchange_public_key']) ?: '') : '',
        );
    }

    private function configToServerKey(): ?ServerKey
    {
        $keyId = $this->config['key_id'] ?? '';
        if ($keyId === '') {
            return null;
        }

        return new ServerKey(
            keyId: $keyId,
            signSecretKey: hex2bin($this->config['sign_secret_key'] ?? '') ?: '',
            signPublicKey: hex2bin($this->config['sign_public_key'] ?? '') ?: '',
            exchangeSecretKey: hex2bin($this->config['exchange_secret_key'] ?? '') ?: '',
            exchangePublicKey: hex2bin($this->config['exchange_public_key'] ?? '') ?: '',
        );
    }
}
