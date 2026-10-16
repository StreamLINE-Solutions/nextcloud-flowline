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
 * Sessions du compte FlowLINE lié, pour la console.
 *
 * @psalm-suppress UnusedClass
 */
class SessionsController extends FlowlineOCSController {
	public function __construct(
		IRequest $request,
		private AccountService $accountService,
		ConfigService $config,
		IUserSession $userSession,
	) {
		parent::__construct($request, $config, $userSession);
	}

	/**
	 * List the sessions of the linked FlowLINE account
	 *
	 * @param string $active "1" active sessions only, "0" ended sessions only, empty for all
	 * @param int $page Page number (1-based)
	 * @param int $pageSize Items per page (1-500)
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_UNAUTHORIZED|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND|Http::STATUS_TOO_MANY_REQUESTS|Http::STATUS_BAD_GATEWAY, array{total: int, sessions: list<mixed>}|array{error: string, message?: string}, array{}>
	 *
	 * 200: Sessions returned (never includes the API token)
	 * 401: Not authenticated
	 * 403: FlowLINE is not enabled for this user, or the token was rejected
	 * 404: No FlowLINE account linked
	 * 429: FlowLINE API rate limit reached
	 * 502: FlowLINE API unreachable or invalid response
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/sessions')]
	public function index(string $active = '', int $page = 1, int $pageSize = 50): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->error('unauthenticated', Http::STATUS_UNAUTHORIZED);
		}
		if (!$this->config->isUserAllowed($user)) {
			return $this->error('forbidden', Http::STATUS_FORBIDDEN);
		}

		$filter = match ($active) {
			'1', 'true' => true,
			'0', 'false' => false,
			default => null,
		};

		try {
			$payload = $this->accountService->getSessions($user->getUID(), $filter, $page, $pageSize);
		} catch (FlowlineApiException $e) {
			return $this->apiError($e);
		}

		return new DataResponse($payload);
	}
}
