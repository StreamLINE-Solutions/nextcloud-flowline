<?php

declare(strict_types=1);

namespace OCA\Flowline\Notification;

use OCA\Flowline\AppInfo\Application;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/**
 * Rend les notifications FlowLINE créées par `PollEventsJob`
 * (`session_started` / `session_ended`).
 *
 * Les paramètres du sujet portent le nom de la machine (repli : l'id du
 * module) — la traduction est faite ici, au moment de l'affichage, dans la
 * langue de l'utilisateur.
 */
class Notifier implements INotifier {
	/**
	 * Instancié par le conteneur Nextcloud (injection de dépendances).
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 */
	public function __construct(
		private IFactory $l10nFactory,
		private IURLGenerator $url,
	) {
	}

	public function getID(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return 'FlowLINE';
	}

	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== Application::APP_ID) {
			throw new UnknownNotificationException();
		}

		$l = $this->l10nFactory->get(Application::APP_ID, $languageCode);
		$parameters = $notification->getSubjectParameters();
		$device = trim((string)($parameters['device'] ?? ''));
		if ($device === '') {
			$device = (string)($parameters['device_id'] ?? '');
		}

		$subject = match ($notification->getSubject()) {
			'session_started' => $l->t('Support session started on %s', [$device]),
			'session_ended' => $l->t('Support session ended on %s', [$device]),
			default => throw new UnknownNotificationException(),
		};

		$notification->setParsedSubject($subject)
			->setRichSubject($subject)
			->setLink($this->url->linkToRouteAbsolute('flowline.page.index'))
			->setIcon($this->url->getAbsoluteURL($this->url->imagePath(Application::APP_ID, 'app.svg')));

		return $notification;
	}
}
