<?php

declare(strict_types=1);

namespace OCA\Flowline\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Compte FlowLINE lié à un utilisateur Nextcloud.
 *
 * Le jeton API personnel n'est jamais stocké en clair : `tokenEncrypted`
 * contient la valeur chiffrée via ICrypto (clé d'instance Nextcloud).
 *
 * Les propriétés sont hydratées par QBMapper et accédées via les méthodes
 * magiques d'Entity (invisibles pour l'analyse statique).
 *
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getTokenEncrypted()
 * @method void setTokenEncrypted(string $tokenEncrypted)
 * @method string getAccountJson()
 * @method void setAccountJson(string $accountJson)
 * @method string|null getLastEventAt()
 * @method void setLastEventAt(string|null $lastEventAt)
 * @method string|null getCreatedAt()
 * @method void setCreatedAt(string|null $createdAt)
 * @method string|null getUpdatedAt()
 * @method void setUpdatedAt(string|null $updatedAt)
 *
 * @psalm-suppress PossiblyUnusedProperty
 * @psalm-suppress PropertyNotSetInConstructor
 */
class Account extends Entity {
	protected string $userId = '';
	protected string $tokenEncrypted = '';
	protected string $accountJson = '';
	protected ?string $lastEventAt = null;
	protected ?string $createdAt = null;
	protected ?string $updatedAt = null;
}
