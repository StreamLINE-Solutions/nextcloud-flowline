<?php

declare(strict_types=1);

namespace OCA\Flowline\Controller;

use OCA\Flowline\AppInfo\Application;
use OCA\Flowline\Service\AccountService;
use OCA\Flowline\Service\ConfigService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * API OCS consommée par la console FlowLINE (front Vue).
 *
 * Routes exposées via les attributs (enregistrement dynamique par Nextcloud).
 *
 * @psalm-suppress UnusedClass
 */
class AccountController extends OCSController {
	public function __construct(
		IRequest $request,
		private AccountService $accountService,
		private ConfigService $config,
		private IUserSession $userSession,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Get the linked FlowLINE account of the current user
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_UNAUTHORIZED|Http::STATUS_FORBIDDEN, array{linked: bool, account: array<string, mixed>|null, api_url: string}|array{error: string}, array{}>
	 *
	 * 200: Account information returned (never includes the API token)
	 * 401: Not authenticated
	 * 403: FlowLINE is not enabled for this user
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/account')]
	public function show(): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['error' => 'unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}
		if (!$this->config->isUserAllowed($user)) {
			return new DataResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		$account = $this->accountService->getPublicAccount($user->getUID());

		return new DataResponse([
			'linked' => $account !== null,
			'account' => $account,
			'api_url' => $this->config->getApiUrl(),
		]);
	}
}
