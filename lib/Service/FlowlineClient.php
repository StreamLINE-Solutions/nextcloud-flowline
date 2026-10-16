<?php

declare(strict_types=1);

namespace OCA\Flowline\Service;

use OCA\Flowline\AppInfo\Application;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;

/**
 * Client de l'API FlowLINE v1 (`/api/v1/…`).
 *
 * Tous les appels sont faits côté serveur avec le jeton `flapi_…` de
 * l'utilisateur ; le jeton n'est jamais exposé au front.
 *
 * La surface couvre l'API v1 au complet ; les méthodes sont consommées
 * progressivement par les étapes suivantes de l'intégration.
 */
class FlowlineClient {
	private const TIMEOUT = 10;
	private const CONNECT_TIMEOUT = 5;

	/**
	 * Instancié par le conteneur Nextcloud (injection de dépendances).
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 */
	public function __construct(
		private IClientService $clientService,
		private ConfigService $config,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @return array<array-key, mixed>
	 * @throws FlowlineApiException
	 */
	public function me(string $token): array {
		return $this->request('GET', '/me', $token);
	}

	/**
	 * @return array<array-key, mixed>
	 * @throws FlowlineApiException
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 */
	public function machines(string $token, int $page = 1, int $pageSize = 500): array {
		return $this->request('GET', '/machines', $token, [
			'query' => ['current' => $page, 'pageSize' => $pageSize],
		]);
	}

	/**
	 * @return array<array-key, mixed>
	 * @throws FlowlineApiException
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 */
	public function sessions(string $token, ?bool $active = null, int $page = 1, int $pageSize = 100): array {
		$query = ['current' => $page, 'pageSize' => $pageSize];
		if ($active !== null) {
			$query['active'] = $active ? '1' : '0';
		}
		return $this->request('GET', '/sessions', $token, ['query' => $query]);
	}

	/**
	 * @return array<array-key, mixed>
	 * @throws FlowlineApiException
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 */
	public function events(string $token, string $since, int $limit = 100): array {
		return $this->request('GET', '/events', $token, [
			'query' => ['since' => $since, 'limit' => $limit],
		]);
	}

	/**
	 * @return array<array-key, mixed>
	 * @throws FlowlineApiException
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 */
	public function invitations(string $token): array {
		return $this->request('GET', '/invitations', $token);
	}

	/**
	 * @return array<array-key, mixed>
	 * @throws FlowlineApiException
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 */
	public function openInvitation(string $token, string $deviceId): array {
		return $this->request('POST', '/invitations', $token, [
			'json' => ['device_id' => $deviceId],
		]);
	}

	/**
	 * @return array<array-key, mixed>
	 * @throws FlowlineApiException
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 */
	public function endInvitation(string $token, string $deviceId): array {
		return $this->request('DELETE', '/invitations/' . rawurlencode($deviceId), $token);
	}

	/**
	 * @param array<string, mixed> $options
	 * @return array<array-key, mixed>
	 * @throws FlowlineApiException
	 */
	private function request(string $method, string $path, string $token, array $options = []): array {
		$url = $this->config->getApiUrl() . '/api/v1' . $path;
		$client = $this->clientService->newClient();

		$options['headers'] = [
			'Authorization' => 'Bearer ' . $token,
			'Accept' => 'application/json',
		];
		$options['timeout'] = self::TIMEOUT;
		$options['connect_timeout'] = self::CONNECT_TIMEOUT;
		$options['http_errors'] = false;

		try {
			$response = match ($method) {
				'GET' => $client->get($url, $options),
				'POST' => $client->post($url, $options),
				'DELETE' => $client->delete($url, $options),
				default => throw new \InvalidArgumentException('Unsupported HTTP method: ' . $method),
			};
		} catch (FlowlineApiException $e) {
			throw $e;
		} catch (\Throwable $e) {
			$this->logger->warning('FlowLINE API unreachable: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			throw new FlowlineApiException(FlowlineApiException::REASON_NETWORK, 'FlowLINE API unreachable');
		}

		$status = $response->getStatusCode();
		$data = $this->decodeJson((string)$response->getBody());

		if ($status >= 400) {
			throw new FlowlineApiException(
				$this->reasonForStatus($status),
				$this->extractError($data) ?? ('HTTP ' . $status),
				$status,
			);
		}
		if ($data === null) {
			throw new FlowlineApiException(FlowlineApiException::REASON_API_ERROR, 'Invalid API response');
		}

		return $data;
	}

	private function reasonForStatus(int $status): string {
		return match ($status) {
			401 => FlowlineApiException::REASON_INVALID_TOKEN,
			403 => FlowlineApiException::REASON_FORBIDDEN,
			404 => FlowlineApiException::REASON_NOT_FOUND,
			409 => FlowlineApiException::REASON_CONFLICT,
			429 => FlowlineApiException::REASON_RATE_LIMITED,
			default => FlowlineApiException::REASON_API_ERROR,
		};
	}

	/**
	 * Décode un corps JSON (objet attendu).
	 *
	 * @return array<array-key, mixed>|null null si le corps n'est pas un objet JSON
	 */
	private function decodeJson(string $body): ?array {
		/** @var mixed $decoded */
		$decoded = json_decode($body, true);
		return is_array($decoded) ? $decoded : null;
	}

	/**
	 * Message d'erreur renvoyé par l'API (`detail` ou `detail.error`).
	 *
	 * @param array<array-key, mixed>|null $data
	 */
	private function extractError(?array $data): ?string {
		if ($data === null) {
			return null;
		}
		/** @var mixed $detail */
		$detail = $data['detail'] ?? null;
		if (is_string($detail)) {
			return $detail;
		}
		if (is_array($detail)) {
			/** @var mixed $error */
			$error = $detail['error'] ?? null;
			if (is_string($error)) {
				return $error;
			}
		}
		return null;
	}
}
