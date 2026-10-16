<?php

declare(strict_types=1);

namespace OCA\Flowline\Controller;

use OCA\Flowline\AppInfo\Application;
use OCA\Flowline\Service\AccountService;
use OCA\Flowline\Service\ConfigService;
use OCA\Flowline\Service\FlowlineApiException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;

/**
 * Traitement des formulaires de réglages (liaison/déliaison, admin) —
 * POST + redirection avec un statut dans l'URL.
 *
 * Routes exposées via les attributs (enregistrement dynamique par Nextcloud).
 *
 * @psalm-suppress UnusedClass
 */
#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
class SettingsController extends Controller {
	public function __construct(
		IRequest $request,
		private AccountService $accountService,
		private ConfigService $config,
		private IUserSession $userSession,
		private IURLGenerator $url,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/settings/link')]
	public function link(string $token = ''): RedirectResponse {
		$status = 'error';
		$user = $this->userSession->getUser();
		if ($user === null) {
			$status = 'error';
		} elseif (!$this->config->isEnabled()) {
			$status = 'disabled';
		} elseif (!$this->config->isUserAllowed($user)) {
			$status = 'restricted';
		} else {
			try {
				$this->accountService->link($user->getUID(), $token);
				$status = 'linked';
			} catch (FlowlineApiException $e) {
				$status = match ($e->getReason()) {
					FlowlineApiException::REASON_INVALID_FORMAT => 'invalid_format',
					FlowlineApiException::REASON_INVALID_TOKEN => 'invalid_token',
					FlowlineApiException::REASON_FORBIDDEN => 'missing_scope',
					FlowlineApiException::REASON_NETWORK => 'network',
					default => 'error',
				};
			}
		}

		return new RedirectResponse($this->personalSettingsUrl($status));
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/settings/unlink')]
	public function unlink(): RedirectResponse {
		$user = $this->userSession->getUser();
		if ($user !== null) {
			$this->accountService->unlink($user->getUID());
		}

		return new RedirectResponse($this->personalSettingsUrl('unlinked'));
	}

	#[FrontpageRoute(verb: 'POST', url: '/settings/admin')]
	public function adminSave(string $apiUrl = '', array $groups = [], bool $enabled = false): RedirectResponse {
		$status = 'saved';
		try {
			$this->config->setApiUrl($apiUrl);
		} catch (\InvalidArgumentException) {
			$status = 'invalid_url';
		}
		$this->config->setRestrictedGroups($groups);
		$this->config->setEnabled($enabled);

		return new RedirectResponse($this->adminSettingsUrl($status));
	}

	private function personalSettingsUrl(string $status): string {
		return $this->url->linkToRoute('settings.PersonalSettings.index', ['section' => 'flowline'])
			. '?flowline=' . rawurlencode($status);
	}

	private function adminSettingsUrl(string $status): string {
		return $this->url->linkToRoute('settings.AdminSettings.index', ['section' => 'flowline'])
			. '?flowline=' . rawurlencode($status);
	}
}
