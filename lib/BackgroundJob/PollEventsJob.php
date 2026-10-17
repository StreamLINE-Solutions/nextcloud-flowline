<?php

declare(strict_types=1);

namespace OCA\Flowline\BackgroundJob;

use OCA\Flowline\AppInfo\Application;
use OCA\Flowline\Db\Account;
use OCA\Flowline\Db\AccountMapper;
use OCA\Flowline\Service\ConfigService;
use OCA\Flowline\Service\FlowlineApiException;
use OCA\Flowline\Service\FlowlineClient;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Notification\IManager as INotificationManager;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;

/**
 * Sonde les événements FlowLINE de chaque compte lié et crée les
 * notifications Nextcloud correspondantes (`session_started` /
 * `session_ended`).
 *
 * Tourne à chaque tick du cron Nextcloud (intervalle : 5 minutes).
 * Le curseur `last_event_at` du compte est avancé à `next_since` après
 * chaque lot ; le premier passage après la liaison pose le curseur
 * **sans notifier** (l'historique antérieur à la liaison ne doit pas
 * produire de notifications).
 *
 * @psalm-suppress UnusedClass instanciée par le cron (référencée dans `appinfo/info.xml`)
 */
class PollEventsJob extends TimedJob {
	/** Intervalle entre deux exécutions (secondes). */
	private const INTERVAL = 300;

	/** Taille de lot demandée à l'API. */
	private const BATCH_LIMIT = 100;

	/** Nombre maximal d'appels au premier passage (vidage de l'historique). */
	private const MAX_BACKLOG_CALLS = 10;

	/**
	 * Instancié par le conteneur Nextcloud (injection de dépendances).
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 */
	public function __construct(
		ITimeFactory $time,
		private AccountMapper $mapper,
		private FlowlineClient $client,
		private ConfigService $config,
		private ICrypto $crypto,
		private INotificationManager $notifications,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(self::INTERVAL);
	}

	protected function run(mixed $argument): void {
		if (!$this->config->isEnabled()) {
			return;
		}

		foreach ($this->mapper->findAllAccounts() as $account) {
			try {
				$this->pollAccount($account);
			} catch (FlowlineApiException $e) {
				// Jeton révoqué, API injoignable… : compte ignoré, réessai au
				// prochain tick (la console invite à relier à nouveau si besoin).
				$this->logger->debug(
					'FlowLINE event poll skipped for ' . $account->getUserId() . ': ' . $e->getMessage(),
					['app' => Application::APP_ID, 'reason' => $e->getReason()],
				);
			} catch (\Throwable $e) {
				$this->logger->warning(
					'FlowLINE event poll failed for ' . $account->getUserId() . ': ' . $e->getMessage(),
					['app' => Application::APP_ID],
				);
			}
		}
	}

	/**
	 * Un compte : récupère les événements depuis le curseur, notifie les
	 * sessions démarrées / terminées et avance le curseur.
	 *
	 * @throws FlowlineApiException jeton illisible ou API en erreur
	 */
	private function pollAccount(Account $account): void {
		$token = $this->decryptToken($account);
		$cursor = $account->getLastEventAt();

		$payload = $this->client->events($token, $cursor ?? '', self::BATCH_LIMIT);
		$events = $this->eventList($payload);
		$next = $this->nextSince($payload, $cursor);

		if ($cursor === null) {
			// Premier passage : poser le curseur sans notifier. Si le lot est
			// plein, vider l'historique en appels bornés (le curseur final est
			// celui du dernier appel).
			$calls = 1;
			while ($next !== null && count($events) === self::BATCH_LIMIT && $calls < self::MAX_BACKLOG_CALLS) {
				$payload = $this->client->events($token, $next, self::BATCH_LIMIT);
				$events = $this->eventList($payload);
				$next = $this->nextSince($payload, $next);
				$calls++;
			}
		} else {
			foreach ($events as $event) {
				$this->notify($account->getUserId(), $event);
			}
		}

		if ($next !== null && $next !== $cursor) {
			$account->setLastEventAt($next);
			$this->mapper->update($account);
		}
	}

	/**
	 * Jeton en clair du compte (jamais journalisé).
	 *
	 * @throws FlowlineApiException jeton illisible (clé d'instance remplacée,
	 *                              données corrompues) → compte ignoré
	 */
	private function decryptToken(Account $account): string {
		try {
			return $this->crypto->decrypt($account->getTokenEncrypted());
		} catch (\Exception) {
			throw new FlowlineApiException(FlowlineApiException::REASON_INVALID_TOKEN, 'Stored token is unreadable');
		}
	}

	/**
	 * Notification Nextcloud pour `session_started` / `session_ended`
	 * (les autres types d'événements sont ignorés).
	 *
	 * @param array<array-key, mixed> $event
	 */
	private function notify(string $userId, array $event): void {
		/** @var mixed $type */
		$type = $event['type'] ?? null;
		if ($type !== 'session_started' && $type !== 'session_ended') {
			return;
		}

		$notification = $this->notifications->createNotification();
		$notification->setApp(Application::APP_ID)
			->setUser($userId)
			->setDateTime($this->eventTime($event))
			->setObject('session', (string)($event['session_id'] ?? ''))
			->setSubject($type, [
				'device' => (string)($event['hostname'] ?? ''),
				'device_id' => (string)($event['device_id'] ?? ''),
			]);

		$this->notifications->notify($notification);
	}

	/**
	 * Date de l'événement (`at`, UTC sans fuseau) — maintenant si illisible.
	 *
	 * @param array<array-key, mixed> $event
	 */
	private function eventTime(array $event): \DateTime {
		/** @var mixed $at */
		$at = $event['at'] ?? null;
		if (is_string($at) && $at !== '') {
			try {
				return new \DateTime($at, new \DateTimeZone('UTC'));
			} catch (\Exception) {
				// Format inattendu : on retombe sur maintenant.
			}
		}

		return new \DateTime();
	}

	/**
	 * Liste d'événements du payload (éléments non-objet filtrés).
	 *
	 * @param array<array-key, mixed> $payload
	 * @return list<array<array-key, mixed>>
	 */
	private function eventList(array $payload): array {
		/** @var mixed $events */
		$events = $payload['events'] ?? null;
		if (!is_array($events)) {
			return [];
		}

		$result = [];
		// Éléments non typés (JSON) : filtrés par is_array.
		/** @psalm-suppress MixedAssignment */
		foreach ($events as $event) {
			if (is_array($event)) {
				$result[] = $event;
			}
		}

		return $result;
	}

	/**
	 * Curseur suivant renvoyé par l'API (`next_since`), sinon repli.
	 *
	 * @param array<array-key, mixed> $payload
	 */
	private function nextSince(array $payload, ?string $fallback): ?string {
		/** @var mixed $next */
		$next = $payload['next_since'] ?? null;
		if (is_string($next) && $next !== '') {
			return $next;
		}

		return $fallback;
	}
}
