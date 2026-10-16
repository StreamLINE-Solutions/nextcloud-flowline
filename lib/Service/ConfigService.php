<?php

declare(strict_types=1);

namespace OCA\Flowline\Service;

use OCA\Flowline\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;

/**
 * Configuration de l'app (réglages admin) + contrôle d'accès utilisateur.
 */
class ConfigService {
	public const DEFAULT_API_URL = 'https://api-falcon.my-vth.ch';

	private const KEY_API_URL = 'api_url';

	/**
	 * ⚠ Ne pas utiliser `enabled` : clé réservée par le cœur Nextcloud
	 * (flag legacy `<app>/enabled` 'yes'/'no', lu en string par
	 * AppConfig::getAppInstalledVersions → conflit de type sinon).
	 */
	private const KEY_ENABLED = 'app_enabled';
	private const KEY_RESTRICTED_GROUPS = 'restricted_groups';

	/**
	 * Instancié par le conteneur Nextcloud (injection de dépendances).
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private IGroupManager $groupManager,
	) {
	}

	public function getApiUrl(): string {
		$url = trim($this->appConfig->getValueString(Application::APP_ID, self::KEY_API_URL, self::DEFAULT_API_URL));
		return rtrim($url, '/');
	}

	/**
	 * @throws \InvalidArgumentException URL invalide
	 */
	public function setApiUrl(string $url): void {
		$url = rtrim(trim($url), '/');
		if ($url === '') {
			$url = self::DEFAULT_API_URL;
		}
		if (preg_match('#^https?://[^\s]+$#i', $url) !== 1) {
			throw new \InvalidArgumentException('Invalid API URL: ' . $url);
		}
		$this->appConfig->setValueString(Application::APP_ID, self::KEY_API_URL, $url);
	}

	public function isEnabled(): bool {
		return $this->appConfig->getValueBool(Application::APP_ID, self::KEY_ENABLED, true);
	}

	public function setEnabled(bool $enabled): void {
		$this->appConfig->setValueBool(Application::APP_ID, self::KEY_ENABLED, $enabled);
	}

	/**
	 * @return list<string>
	 */
	public function getRestrictedGroups(): array {
		$result = [];
		// Éléments non typés (config) : filtrés par is_string.
		/** @psalm-suppress MixedAssignment */
		foreach ($this->appConfig->getValueArray(Application::APP_ID, self::KEY_RESTRICTED_GROUPS, []) as $group) {
			if (is_string($group) && $group !== '') {
				$result[] = $group;
			}
		}
		return array_values(array_unique($result));
	}

	/**
	 * @param array<array-key, mixed> $groups
	 */
	public function setRestrictedGroups(array $groups): void {
		$clean = [];
		// Éléments non typés (formulaire) : filtrés par is_string.
		/** @psalm-suppress MixedAssignment */
		foreach ($groups as $group) {
			if (is_string($group) && $group !== '') {
				$clean[] = $group;
			}
		}
		$this->appConfig->setValueArray(Application::APP_ID, self::KEY_RESTRICTED_GROUPS, array_values(array_unique($clean)));
	}

	/**
	 * App activée et utilisateur autorisé (aucune restriction ou membre d'un
	 * des groupes autorisés ; les admins passent toujours).
	 */
	public function isUserAllowed(IUser $user): bool {
		if (!$this->isEnabled()) {
			return false;
		}
		$groups = $this->getRestrictedGroups();
		if ($groups === []) {
			return true;
		}
		if ($this->groupManager->isAdmin($user->getUID())) {
			return true;
		}
		foreach ($groups as $groupId) {
			if ($this->groupManager->isInGroup($user->getUID(), $groupId)) {
				return true;
			}
		}
		return false;
	}
}
