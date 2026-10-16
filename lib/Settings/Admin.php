<?php

declare(strict_types=1);

namespace OCA\Flowline\Settings;

use OCA\Flowline\AppInfo\Application;
use OCA\Flowline\Service\ConfigService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Settings\ISettings;

/**
 * Réglages admin : URL de l'API, restriction par groupes, activation.
 *
 * Enregistrée via `appinfo/info.xml`.
 *
 * @psalm-suppress UnusedClass
 */
class Admin implements ISettings {
	public function __construct(
		private ConfigService $config,
		private IGroupManager $groupManager,
		private IURLGenerator $url,
		private IRequest $request,
	) {
	}

	public function getForm(): TemplateResponse {
		$groups = [];
		foreach ($this->groupManager->search('') as $group) {
			$groups[] = [
				'id' => $group->getGID(),
				'name' => $group->getDisplayName(),
			];
		}
		$status = (string)$this->request->getParam('flowline', '');

		return new TemplateResponse(Application::APP_ID, 'settings/admin', [
			'apiUrl' => $this->config->getApiUrl(),
			'defaultApiUrl' => ConfigService::DEFAULT_API_URL,
			'webRdvUrl' => $this->config->getWebclientRdvUrl(),
			'defaultWebRdvUrl' => ConfigService::DEFAULT_WEB_RDV_URL,
			'webRelayUrl' => $this->config->getWebclientRelayUrl(),
			'defaultWebRelayUrl' => ConfigService::DEFAULT_WEB_RELAY_URL,
			'enabled' => $this->config->isEnabled(),
			'restrictedGroups' => $this->config->getRestrictedGroups(),
			'groups' => $groups,
			'status' => $status,
			'saveUrl' => $this->url->linkToRoute('flowline.settings.adminsave'),
		]);
	}

	public function getSection(): string {
		return 'flowline';
	}

	public function getPriority(): int {
		return 10;
	}
}
