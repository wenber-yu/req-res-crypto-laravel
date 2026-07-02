<?php

declare(strict_types=1);

namespace Wenbo\ReqResCrypto\Laravel\KeyProvider;

use Illuminate\Database\ConnectionInterface;
use PDOException;
use Wenbo\ReqResCrypto\Core\ServerKey;
use Wenbo\ReqResCrypto\Core\Exceptions\KeyException;
use Wenbo\ReqResCrypto\Core\ServerKeyProviderInterface;

/**
 * 数据库密钥提供者，直接从密钥表查询，供 Artisan 命令使用。
 */
final readonly class DatabaseKeyProvider implements ServerKeyProviderInterface
{
	public function __construct(
		private ConnectionInterface $db,
		private string $table,
	) {
	}

	public function getCurrentKey(): ?ServerKey
	{
		return $this->fetchKeyByStatus('current');
	}

	public function getPreIssuedKey(): ?ServerKey
	{
		return $this->fetchKeyByStatus('pre_issued');
	}

	private function fetchKeyByStatus(string $status): ?ServerKey
	{
		try {
			$row = $this->db
				->table($this->table)
				->where('status', $status)
				->first();
		} catch (PDOException $e) {
			throw KeyException::databaseError();
		} catch (\Throwable $e) {
			throw KeyException::notFound($status);
		}

		if ($row === null) {
			return null;
		}

		return new ServerKey(
			keyId: $row->key_id,
			signSecretKey: hex2bin($row->sign_secret_key) ?: '',
			signPublicKey: hex2bin($row->sign_public_key) ?: '',
			exchangeSecretKey: hex2bin($row->exchange_secret_key) ?: '',
			exchangePublicKey: hex2bin($row->exchange_public_key) ?: '',
		);
	}
}
