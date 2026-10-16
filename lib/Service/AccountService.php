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
	 * Machines visibles par le compte lié (parc, fenêtres support, parc partagé).
	 *
	 * @return array{total: int, machines: list<mixed>}
	 * @throws FlowlineApiException compte non lié, jeton refusé ou API injoignable
	 */
	public function getMachines(string $userId, int $page = 1, int $pageSize = 500): array {
		$payload = $this->client->machines($this->requireToken($userId), $page, $pageSize);
		/** @var mixed $machines */
		$machines = $payload['machines'] ?? null;

		return [
			'total' => (int)($payload['total'] ?? 0),
			'machines' => is_array($machines) ? array_values($machines) : [],
		];
	}

	/**
	 * Sessions du compte lié (toutes, actives ou terminées).
	 *
	 * @return array{total: int, sessions: list<mixed>}
	 * @throws FlowlineApiException compte non lié, jeton refusé ou API injoignable
	 */
	public function getSessions(string $userId, ?bool $active = null, int $page = 1, int $pageSize = 50): array {
		$payload = $this->client->sessions($this->requireToken($userId), $active, $page, $pageSize);
		/** @var mixed $sessions */
		$sessions = $payload['sessions'] ?? null;

		return [
			'total' => (int)($payload['total'] ?? 0),
			'sessions' => is_array($sessions) ? array_values($sessions) : [],
		];
	}

	/**
	 * Fenêtres d'autorisation support actives du compte lié.
	 *
	 * @return array{invitations: list<mixed>}
	 * @throws FlowlineApiException compte non lié, jeton refusé ou API injoignable
	 */
	public function getInvitations(string $userId): array {
		$payload = $this->client->invitations($this->requireToken($userId));
		/** @var mixed $invitations */
		$invitations = $payload['invitations'] ?? null;

		return ['invitations' => is_array($invitations) ? array_values($invitations) : []];
	}

	/**
	 * Ouvre (ou rouvre) une fenêtre support pour un poste.
	 *
	 * `window` = false (raison `device_has_account`) quand le poste est déjà
	 * lié à un compte : ses écritures sont déjà authentifiées, la fenêtre ne
	 * le concerne pas.
	 *
	 * @return array{id: string, window: bool, expires_at: string, reason: string}
	 * @throws FlowlineApiException identifiant invalide, compte non lié, jeton refusé ou API injoignable
	 */
	public function openInvitation(string $userId, string $deviceId): array {
		$deviceId = $this->normalizeDeviceId($deviceId);
		$payload = $this->client->openInvitation($this->requireToken($userId), $deviceId);

		return [
			'id' => (string)($payload['id'] ?? $deviceId),
			'window' => ($payload['window'] ?? false) === true,
			'expires_at' => (string)($payload['expires_at'] ?? ''),
			'reason' => (string)($payload['reason'] ?? ''),
		];
	}

	/**
	 * Termine la fenêtre support d'un poste (`quit` envoyé au module).
	 *
	 * @return array{id: string, ended: bool}
	 * @throws FlowlineApiException identifiant invalide, compte non lié, jeton refusé ou API injoignable
	 */
	public function endInvitation(string $userId, string $deviceId): array {
		$deviceId = $this->normalizeDeviceId($deviceId);
		$payload = $this->client->endInvitation($this->requireToken($userId), $deviceId);

		return [
			'id' => (string)($payload['id'] ?? $deviceId),
			'ended' => ($payload['ended'] ?? false) === true,
		];
	}

	/**
	 * Identifiant de poste : espaces retirés (« 159 627 370 » → « 159627370 »),
	 * borné comme l'API (1-64 caractères).
	 *
	 * @throws FlowlineApiException REASON_INVALID_FORMAT
	 */
	private function normalizeDeviceId(string $deviceId): string {
		$normalized = preg_replace('/\s+/', '', $deviceId) ?? '';
		if ($normalized === '' || strlen($normalized) > 64) {
			throw new FlowlineApiException(FlowlineApiException::REASON_INVALID_FORMAT, 'Invalid device id');
		}

		return $normalized;
	}

	/**
	 * Jeton en clair du compte lié (jamais exposé au front).
	 *
	 * @throws FlowlineApiException REASON_NOT_LINKED si aucun compte,
	 *                              REASON_INVALID_TOKEN si le jeton est illisible
	 */
	private function requireToken(string $userId): string {
		$account = $this->mapper->findByUser($userId);
		if ($account === null) {
			throw new FlowlineApiException(FlowlineApiException::REASON_NOT_LINKED, 'No FlowLINE account linked');
		}

		try {
			return $this->crypto->decrypt($account->getTokenEncrypted());
		} catch (\Exception) {
			// Clé de chiffrement remplacée ou données corrompues : relier à nouveau.
			throw new FlowlineApiException(FlowlineApiException::REASON_INVALID_TOKEN, 'Stored token is unreadable');
		}
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
