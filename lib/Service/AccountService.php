<?php

declare(strict_types=1);

namespace OCA\Flowline\Service;

use OCA\Flowline\Db\Account;
use OCA\Flowline\Db\AccountMapper;
use OCP\Security\ICrypto;

/**
 * Comptes FlowLINE liés : liaison/déliaison, chiffrement du jeton,
 * cache de `/me` exposé au front (jamais le jeton).
 */
class AccountService {
	public const REQUIRED_SCOPES = ['machines:read', 'sessions:read'];

	/**
	 * Instancié par le conteneur Nextcloud (injection de dépendances).
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 */
	public function __construct(
		private AccountMapper $mapper,
		private FlowlineClient $client,
		private ICrypto $crypto,
	) {
	}

	/**
	 * Lie (ou relie) le compte Nextcloud à un compte FlowLINE.
	 *
	 * @throws FlowlineApiException format invalide, jeton refusé, scopes
	 *                              manquants ou API injoignable
	 */
	public function link(string $userId, string $token): void {
		$token = trim($token);
		if (preg_match('/^flapi_[A-Za-z0-9_-]{20,}$/', $token) !== 1) {
			throw new FlowlineApiException(FlowlineApiException::REASON_INVALID_FORMAT, 'Invalid token format');
		}

		$me = $this->client->me($token);
		$scopes = $this->stringList($me['scopes'] ?? null);
		foreach (self::REQUIRED_SCOPES as $required) {
			if (!in_array($required, $scopes, true)) {
				throw new FlowlineApiException(FlowlineApiException::REASON_FORBIDDEN, 'Missing scope: ' . $required);
			}
		}

		$json = json_encode($me, JSON_THROW_ON_ERROR);
		$now = (new \DateTime())->format('Y-m-d H:i:s');
		$account = $this->mapper->findByUser($userId);
		if ($account === null) {
			$account = new Account();
			$account->setUserId($userId);
			$account->setCreatedAt($now);
			$account->setLastEventAt(null);
			$account->setTokenEncrypted($this->crypto->encrypt($token));
			$account->setAccountJson($json);
			$account->setUpdatedAt($now);
			$this->mapper->insert($account);
		} else {
			$account->setTokenEncrypted($this->crypto->encrypt($token));
			$account->setAccountJson($json);
			$account->setUpdatedAt($now);
			$this->mapper->update($account);
		}
	}

	public function unlink(string $userId): void {
		$account = $this->mapper->findByUser($userId);
		if ($account !== null) {
			$this->mapper->delete($account);
		}
	}

	/**
	 * @return array<string, mixed>|null null si aucun compte lié
	 */
	public function getPublicAccount(string $userId): ?array {
		$account = $this->mapper->findByUser($userId);
		return $account === null ? null : $this->publicAccount($account);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function publicAccount(Account $account): array {
		$data = $this->jsonObject($account->getAccountJson());

		return [
			'username' => $this->stringField($data, 'username'),
			'display_name' => $this->stringField($data, 'display_name'),
			'email' => $this->stringField($data, 'email'),
			'plan_key' => $this->stringField($data, 'plan_key'),
			'plan_name' => $this->stringField($data, 'plan_name'),
			'is_admin' => ($data['is_admin'] ?? false) === true,
			'is_manager' => ($data['is_manager'] ?? false) === true,
			'scopes' => $this->stringList($data['scopes'] ?? null),
			'support_module_url' => $this->stringField($data, 'support_module_url'),
			'linked_at' => $account->getCreatedAt(),
			'updated_at' => $account->getUpdatedAt(),
		];
	}

	/**
	 * Décode le cache JSON `/me` (objet attendu ; `[]` sinon).
	 *
	 * @return array<array-key, mixed>
	 */
	private function jsonObject(string $json): array {
		/** @var mixed $decoded */
		$decoded = json_decode($json, true);
		return is_array($decoded) ? $decoded : [];
	}

	/**
	 * @param array<array-key, mixed> $data
	 */
	private function stringField(array $data, string $key): string {
		return (string)($data[$key] ?? '');
	}

	/**
	 * @return list<string>
	 */
	private function stringList(mixed $value): array {
		if (!is_array($value)) {
			return [];
		}
		$result = [];
		// Éléments non typés (JSON) : filtrés par is_string.
		/** @psalm-suppress MixedAssignment */
		foreach ($value as $item) {
			if (is_string($item)) {
				$result[] = $item;
			}
		}
		return $result;
	}
}
