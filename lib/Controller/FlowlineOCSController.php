<?php

declare(strict_types=1);

namespace OCA\Flowline\Controller;

use OCA\Flowline\AppInfo\Application;
use OCA\Flowline\Service\ConfigService;
use OCA\Flowline\Service\FlowlineApiException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Base des contrôleurs OCS de la console FlowLINE.
 *
 * Regroupe la construction des réponses d'erreur : `error` est un code stable
 * pour le front (messages adaptés côté Vue), le statut HTTP est choisi pour
 * l'UI (relier à nouveau, réessayer plus tard, etc.).
 *
 * Le nom DOIT se terminer par « OCSController » : l'extracteur OpenAPI
 * détecte le type d'un contrôleur via le suffixe de son parent direct et
 * ignore ses routes sinon (voir `generate-spec.php` de openapi-extractor).
 */
abstract class FlowlineOCSController extends OCSController {
	public function __construct(
		IRequest $request,
		protected ConfigService $config,
		protected IUserSession $userSession,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Réponse d'erreur OCS.
	 *
	 * @template S of Http::STATUS_BAD_REQUEST|Http::STATUS_UNAUTHORIZED|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND|Http::STATUS_CONFLICT|Http::STATUS_TOO_MANY_REQUESTS|Http::STATUS_BAD_GATEWAY
	 * @param S $status
	 * @return DataResponse<S, array{error: string, message?: string}, array{}>
	 */
	protected function error(string $reason, int $status, string $message = ''): DataResponse {
		$payload = ['error' => $reason];
		if ($message !== '') {
			$payload['message'] = $message;
		}

		return new DataResponse($payload, $status);
	}

	/**
	 * Traduit une erreur d'appel à l'API FlowLINE en réponse OCS.
	 *
	 * Seules les raisons atteignables via les proxys OCS sont mappées ici
	 * (les erreurs de formulaire, p. ex. format de jeton, restent locales).
	 *
	 * @return DataResponse<Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND|Http::STATUS_TOO_MANY_REQUESTS|Http::STATUS_BAD_GATEWAY, array{error: string, message?: string}, array{}>
	 */
	protected function apiError(FlowlineApiException $e): DataResponse {
		$status = match ($e->getReason()) {
			FlowlineApiException::REASON_NOT_LINKED => Http::STATUS_NOT_FOUND,
			FlowlineApiException::REASON_RATE_LIMITED => Http::STATUS_TOO_MANY_REQUESTS,
			FlowlineApiException::REASON_INVALID_TOKEN,
			FlowlineApiException::REASON_FORBIDDEN => Http::STATUS_FORBIDDEN,
			default => Http::STATUS_BAD_GATEWAY,
		};

		return $this->error($e->getReason(), $status, $e->getMessage());
	}
}
