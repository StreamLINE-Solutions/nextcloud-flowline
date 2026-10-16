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
 * Machines du compte FlowLINE lié, pour la console.
 *
 * @psalm-suppress UnusedClass
 */
class MachinesController extends FlowlineOCSController {
	public function __construct(
		IRequest $request,
		private AccountService $accountService,
		ConfigService $config,
		IUserSession $userSession,
	) {
		parent::__construct($request, $config, $userSession);
	}

	/**
	 * List the machines visible to the linked FlowLINE account
	 *
	 * @param int $page Page number (1-based)
	 * @param int $pageSize Items per page (1-500)
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_UNAUTHORIZED|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND|Http::STATUS_TOO_MANY_REQUESTS|Http::STATUS_BAD_GATEWAY, array{total: int, machines: list<mixed>}|array{error: string, message?: string}, array{}>
	 *
	 * 200: Machines returned (never includes the API token)
	 * 401: Not authenticated
	 * 403: FlowLINE is not enabled for this user, or the token was rejected
	 * 404: No FlowLINE account linked
	 * 429: FlowLINE API rate limit reached
	 * 502: FlowLINE API unreachable or invalid response
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/machines')]
	public function index(int $page = 1, int $pageSize = 500): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->error('unauthenticated', Http::STATUS_UNAUTHORIZED);
		}
		if (!$this->config->isUserAllowed($user)) {
			return $this->error('forbidden', Http::STATUS_FORBIDDEN);
		}

		try {
			$payload = $this->accountService->getMachines($user->getUID(), $page, $pageSize);
		} catch (FlowlineApiException $e) {
			return $this->apiError($e);
		}

		return new DataResponse($payload);
	}
}
