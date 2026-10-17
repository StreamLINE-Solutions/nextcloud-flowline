<?php

declare(strict_types=1);

// Bootstrap autonome : les tests unitaires ne dépendent que des stubs OCP
// (nextcloud/ocp) et de PHPUnit — pas d'une instance Nextcloud complète.
require_once __DIR__ . '/../vendor/autoload.php';

// Certains stubs OCP passent par le conteneur global `\OC::$server`
// (ex. `Response::getHeaders()` → `Server::get(IRequest::class)`). On fournit
// le strict minimum pour ces chemins : les services non simulés lèvent une
// erreur explicite.
if (!class_exists('OC', false)) {
	class OC {
		/** @var object */
		public static $server;
	}

	OC::$server = new class {
		public function get(string $serviceName): mixed {
			return match ($serviceName) {
				\OCP\IRequest::class => new class {
					public function getId(): string {
						return 'flowline-test';
					}
				},
				\OCP\IUserSession::class => new class {
					public function getUser(): ?\OCP\IUser {
						return null;
					}
				},
				default => throw new \RuntimeException(
					'Service non simulé dans le bootstrap de test : ' . $serviceName,
				),
			};
		}
	};
}
