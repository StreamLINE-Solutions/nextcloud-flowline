<?php

declare(strict_types=1);

namespace OCA\Flowline\Service;

/**
 * Erreur d'un appel à l'API FlowLINE (ou de validation d'un jeton).
 *
 * `getReason()` fournit un code stable utilisé par l'UI pour choisir le
 * message affiché à l'utilisateur.
 */
class FlowlineApiException extends \Exception {
	public const REASON_INVALID_FORMAT = 'invalid_format';
	public const REASON_INVALID_TOKEN = 'invalid_token';
	public const REASON_NOT_LINKED = 'not_linked';
	public const REASON_FORBIDDEN = 'forbidden';
	public const REASON_NOT_FOUND = 'not_found';
	public const REASON_CONFLICT = 'conflict';
	public const REASON_RATE_LIMITED = 'rate_limited';
	public const REASON_NETWORK = 'network';
	public const REASON_API_ERROR = 'api_error';

	public function __construct(
		private string $reason,
		string $message = '',
		private int $apiStatus = 0,
	) {
		parent::__construct($message !== '' ? $message : $reason);
	}

	public function getReason(): string {
		return $this->reason;
	}

	/**
	 * Statut HTTP renvoyé par l'API (0 si erreur locale ou réseau).
	 * Utilisé par les tests et les journaux.
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 */
	public function getApiStatus(): int {
		return $this->apiStatus;
	}
}
