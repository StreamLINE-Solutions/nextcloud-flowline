<?php

declare(strict_types=1);

namespace OCA\Flowline\Controller;

use OCA\Flowline\Service\AccountService;
use OCA\Flowline\Service\ConfigService;
use OCA\Flowline\Service\FlowlineApiException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Fenêtres d'autorisation support du compte FlowLINE lié, pour la console.
 *
 * Une fenêtre concerne un module support sans compte (auto-autorisé au
 * Connect) : le technicien l'ouvre avec l'identifiant affiché par le module,
 * puis la termine (`quit` envoyé au module).
 *
 * @psalm-suppress UnusedClass
 */
class InvitationsController extends FlowlineOCSController {
	public function __construct(
		IRequest $request,
		private AccountService $accountService,
		ConfigService $config,
		IUserSession $userSession,
	) {
		parent::__construct($request, $config, $userSession);
	}

	/**
	 * List the active support windows of the linked FlowLINE account
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_UNAUTHORIZED|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND|Http::STATUS_TOO_MANY_REQUESTS|Http::STATUS_BAD_GATEWAY, array{invitations: list<mixed>}|array{error: string, message?: string}, array{}>
	 *
	 * 200: Active support windows returned
	 * 401: Not authenticated
	 * 403: FlowLINE is not enabled for this user, or the token was rejected
	 * 404: No FlowLINE account linked
	 * 429: FlowLINE API rate limit reached
	 * 502: FlowLINE API unreachable or invalid response
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/invitations')]
	public function index(): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->error('unauthenticated', Http::STATUS_UNAUTHORIZED);
		}
		if (!$this->config->isUserAllowed($user)) {
			return $this->error('forbidden', Http::STATUS_FORBIDDEN);
		}

		try {
			$payload = $this->accountService->getInvitations($user->getUID());
		} catch (FlowlineApiException $e) {
			return $this->apiError($e);
		}

		return new DataResponse($payload);
	}

	/**
	 * Open (or reopen) a support window for a device
	 *
	 * @param string $deviceId Device id shown by the support module
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_BAD_REQUEST|Http::STATUS_UNAUTHORIZED|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND|Http::STATUS_CONFLICT|Http::STATUS_TOO_MANY_REQUESTS|Http::STATUS_BAD_GATEWAY, array{id: string, window: bool, expires_at: string, reason: string}|array{error: string, message?: string}, array{}>
	 *
	 * 200: Window opened (`window` false when the device already has an account)
	 * 400: Invalid device id
	 * 401: Not authenticated
	 * 403: FlowLINE is not enabled for this user, the token was rejected, or the token lacks the invitations permission
	 * 404: No FlowLINE account linked
	 * 409: Another account already has an active window on this device
	 * 429: FlowLINE API rate limit reached (too many active windows)
	 * 502: FlowLINE API unreachable or invalid response
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/invitations')]
	public function create(string $deviceId = ''): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->error('unauthenticated', Http::STATUS_UNAUTHORIZED);
		}
		if (!$this->config->isUserAllowed($user)) {
			return $this->error('forbidden', Http::STATUS_FORBIDDEN);
		}

		try {
			$payload = $this->accountService->openInvitation($user->getUID(), $deviceId);
		} catch (FlowlineApiException $e) {
			return $this->openError($e);
		}

		return new DataResponse($payload);
	}

	/**
	 * End the support window of a device
	 *
	 * @param string $deviceId Device id shown by the support module
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_BAD_REQUEST|Http::STATUS_UNAUTHORIZED|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND|Http::STATUS_TOO_MANY_REQUESTS|Http::STATUS_BAD_GATEWAY, array{id: string, ended: bool}|array{error: string, message?: string}, array{}>
	 *
	 * 200: Window ended (`quit` is sent to the module at its next heartbeat)
	 * 400: Invalid device id
	 * 401: Not authenticated
	 * 403: FlowLINE is not enabled for this user, the token was rejected, or the token lacks the invitations permission
	 * 404: No FlowLINE account linked, or no active window for this device
	 * 429: FlowLINE API rate limit reached
	 * 502: FlowLINE API unreachable or invalid response
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/invitations/{deviceId}')]
	public function destroy(string $deviceId = ''): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->error('unauthenticated', Http::STATUS_UNAUTHORIZED);
		}
		if (!$this->config->isUserAllowed($user)) {
			return $this->error('forbidden', Http::STATUS_FORBIDDEN);
		}

		try {
			$payload = $this->accountService->endInvitation($user->getUID(), $deviceId);
		} catch (FlowlineApiException $e) {
			return $this->endError($e);
		}

		return new DataResponse($payload);
	}

	/**
	 * Erreurs d'ouverture de fenêtre : 400 identifiant invalide, 409 fenêtre
	 * détenue par un autre compte, puis mapping commun des erreurs d'API.
	 *
	 * @return DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND|Http::STATUS_CONFLICT|Http::STATUS_TOO_MANY_REQUESTS|Http::STATUS_BAD_GATEWAY, array{error: string, message?: string}, array{}>
	 */
	private function openError(FlowlineApiException $e): DataResponse {
		return match ($e->getReason()) {
			FlowlineApiException::REASON_INVALID_FORMAT => $this->error($e->getReason(), Http::STATUS_BAD_REQUEST, $e->getMessage()),
			FlowlineApiException::REASON_CONFLICT => $this->error($e->getReason(), Http::STATUS_CONFLICT, $e->getMessage()),
			default => $this->apiError($e),
		};
	}

	/**
	 * Erreurs de fin de fenêtre : 400 identifiant invalide, puis mapping
	 * commun (l'API ne renvoie pas de conflit sur DELETE).
	 *
	 * @return DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND|Http::STATUS_TOO_MANY_REQUESTS|Http::STATUS_BAD_GATEWAY, array{error: string, message?: string}, array{}>
	 */
	private function endError(FlowlineApiException $e): DataResponse {
		if ($e->getReason() === FlowlineApiException::REASON_INVALID_FORMAT) {
			return $this->error($e->getReason(), Http::STATUS_BAD_REQUEST, $e->getMessage());
		}

		return $this->apiError($e);
	}
}
