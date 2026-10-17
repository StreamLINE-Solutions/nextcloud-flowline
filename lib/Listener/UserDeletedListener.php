<?php

declare(strict_types=1);

namespace OCA\Flowline\Listener;

use OCA\Flowline\Service\AccountService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserDeletedEvent;

/**
 * Supprime le compte FlowLINE lié quand l'utilisateur Nextcloud est supprimé
 * (le jeton chiffré et le curseur d'événements ne doivent pas survivre au
 * compte, et le job de notifications ne doit plus sonder ce compte).
 *
 * @template-implements IEventListener<UserDeletedEvent>
 */
class UserDeletedListener implements IEventListener {
	/**
	 * Instancié par le conteneur Nextcloud (injection de dépendances).
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 */
	public function __construct(
		private AccountService $accounts,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof UserDeletedEvent) {
			return;
		}

		$this->accounts->unlink($event->getUser()->getUID());
	}
}
