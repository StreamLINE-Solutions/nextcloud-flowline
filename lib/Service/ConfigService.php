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

	/** Endpoints WebSocket du client web (défauts production). */
	public const DEFAULT_WEB_RDV_URL = 'wss://falcon.my-vth.ch/ws/id';
	public const DEFAULT_WEB_RELAY_URL = 'wss://falcon.my-vth.ch/ws/relay';

	private const KEY_API_URL = 'api_url';
	private const KEY_WEB_RDV_URL = 'webclient_rdv_url';
	private const KEY_WEB_RELAY_URL = 'webclient_relay_url';

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
		$this->assertApiUrlSafe($url);
		$this->appConfig->setValueString(Application::APP_ID, self::KEY_API_URL, $url);
	}

	/**
	 * Refuse les cibles loopback, link-local et réservées (SSRF, T13 0.6.3).
	 *
	 * Les plages privées RFC1918 et ULA restent autorisées : une API
	 * auto-hébergée sur le LAN est un cas d'usage légitime. La résolution DNS
	 * est refaite à chaque requête par FlowlineClient (défense contre le
	 * rebinding), donc cette méthode est aussi publique.
	 *
	 * @throws \InvalidArgumentException hôte interdit
	 */
	public function assertApiUrlSafe(?string $url = null): void {
		$url ??= $this->getApiUrl();
		$host = parse_url($url, PHP_URL_HOST);
		if (!is_string($host) || $host === '') {
			throw new \InvalidArgumentException('Invalid API URL: ' . $url);
		}
		if (parse_url($url, PHP_URL_USER) !== null) {
			throw new \InvalidArgumentException('API URL must not contain credentials');
		}
		$host = trim($host, '[]');
		$lower = strtolower($host);
		if ($lower === 'localhost' || str_ends_with($lower, '.localhost')
			|| in_array($lower, ['metadata.google.internal', 'metadata.goog', 'instance-data'], true)) {
			throw new \InvalidArgumentException('API URL host not allowed: ' . $host);
		}
		if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
			$this->assertPublicIp($host);
			return;
		}
		$records = @dns_get_record($host, DNS_A | DNS_AAAA);
		if ($records === false) {
			// DNS indisponible : ne pas bloquer l'enregistrement (l'appel
			// échouera de toute façon côté requête).
			return;
		}
		foreach ($records as $record) {
			$ip = $record['ip'] ?? $record['ipv6'] ?? '';
			if (is_string($ip) && $ip !== '') {
				$this->assertPublicIp($ip);
			}
		}
	}

	/**
	 * @throws \InvalidArgumentException IP loopback/link-local/réservée
	 */
	private function assertPublicIp(string $ip): void {
		// IPv4 mappée en IPv6 (::ffff:169.254.169.254) : vérifier la partie v4.
		if (str_starts_with(strtolower($ip), '::ffff:')) {
			$v4 = substr($ip, 7);
			if (filter_var($v4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
				$this->assertPublicIp($v4);
				return;
			}
		}
		// NO_RES_RANGE rejette loopback, link-local, 0.0.0.0/8, multicast,
		// 169.254/16, ::1, fe80::/10… ; NO_PRIV_RANGE n'est PAS utilisé
		// (RFC1918/ULA autorisés pour une API auto-hébergée).
		if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE) === false) {
			throw new \InvalidArgumentException('API URL host not allowed: ' . $ip);
		}
		// CGNAT 100.64.0.0/10 : non couvert par NO_RES_RANGE, utilisé par
		// certains endpoints de métadonnées cloud (Alibaba 100.100.100.200).
		$long = ip2long($ip);
		if ($long !== false && $long >= ip2long('100.64.0.0') && $long <= ip2long('100.127.255.255')) {
			throw new \InvalidArgumentException('API URL host not allowed: ' . $ip);
		}
	}

	public function isEnabled(): bool {
		return $this->appConfig->getValueBool(Application::APP_ID, self::KEY_ENABLED, true);
	}

	/**
	 * Endpoint WS du serveur de rendez-vous (client web).
	 */
	public function getWebclientRdvUrl(): string {
		return $this->webSocketUrl(self::KEY_WEB_RDV_URL, self::DEFAULT_WEB_RDV_URL);
	}

	/**
	 * Endpoint WS du relais (client web).
	 */
	public function getWebclientRelayUrl(): string {
		return $this->webSocketUrl(self::KEY_WEB_RELAY_URL, self::DEFAULT_WEB_RELAY_URL);
	}

	/**
	 * @throws \InvalidArgumentException URL invalide (schéma ws:// ou wss:// attendu)
	 */
	public function setWebclientRdvUrl(string $url): void {
		$this->appConfig->setValueString(
			Application::APP_ID,
			self::KEY_WEB_RDV_URL,
			$this->cleanWebSocketUrl($url, self::DEFAULT_WEB_RDV_URL),
		);
	}

	/**
	 * @throws \InvalidArgumentException URL invalide (schéma ws:// ou wss:// attendu)
	 */
	public function setWebclientRelayUrl(string $url): void {
		$this->appConfig->setValueString(
			Application::APP_ID,
			self::KEY_WEB_RELAY_URL,
			$this->cleanWebSocketUrl($url, self::DEFAULT_WEB_RELAY_URL),
		);
	}

	private function webSocketUrl(string $key, string $default): string {
		$url = trim($this->appConfig->getValueString(Application::APP_ID, $key, $default));

		return $url === '' ? $default : rtrim($url, '/');
	}

	/**
	 * @throws \InvalidArgumentException
	 */
	private function cleanWebSocketUrl(string $url, string $default): string {
		$url = rtrim(trim($url), '/');
		if ($url === '') {
			return $default;
		}
		if (preg_match('#^wss?://[^\s]+$#i', $url) !== 1) {
			throw new \InvalidArgumentException('Invalid WebSocket URL: ' . $url);
		}

		return $url;
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
