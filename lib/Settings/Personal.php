<?php

declare(strict_types=1);

namespace OCA\Flowline\Settings;

use OCA\Flowline\AppInfo\Application;
use OCA\Flowline\Service\AccountService;
use OCA\Flowline\Service\ConfigService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Settings\ISettings;

/**
 * Réglages personnels : liaison du compte FlowLINE (jeton API).
 *
 * Enregistrée via `appinfo/info.xml`.
 *
 * @psalm-suppress UnusedClass
 */
class Personal implements ISettings {
	public function __construct(
		private AccountService $accountService,
		private ConfigService $config,
		private IUserSession $userSession,
		private IURLGenerator $url,
		private IRequest $request,
	) {
	}

	public function getForm(): TemplateResponse {
		$user = $this->userSession->getUser();
		$account = $user !== null ? $this->accountService->getPublicAccount($user->getUID()) : null;
		$status = (string)$this->request->getParam('flowline', '');

		return new TemplateResponse(Application::APP_ID, 'settings/personal', [
			'linked' => $account !== null,
			'account' => $account,
			'apiUrl' => $this->config->getApiUrl(),
			'enabled' => $this->config->isEnabled(),
			'allowed' => $user !== null && $this->config->isUserAllowed($user),
			'status' => $status,
			'linkUrl' => $this->url->linkToRoute('flowline.settings.link'),
			'unlinkUrl' => $this->url->linkToRoute('flowline.settings.unlink'),
		]);
	}

	public function getSection(): string {
		return 'flowline';
	}

	public function getPriority(): int {
		return 10;
	}
}
