<?php

declare(strict_types=1);

namespace OCA\Flowline\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Table des comptes FlowLINE liés : jeton API chiffré + cache de /me.
 *
 * Exécutée par le système de migrations Nextcloud.
 *
 * @psalm-suppress UnusedClass
 */
class Version000200Date20261016120000 extends SimpleMigrationStep {
	/**
	 * La classe Table (Doctrine DBAL) est fournie par le serveur, absente des
	 * stubs OCP utilisés par l'analyse statique.
	 *
	 * @param Closure():ISchemaWrapper $schemaClosure
	 *
	 * @psalm-suppress UndefinedDocblockClass
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		if (!$schema->hasTable('flowline_accounts')) {
			$table = $schema->createTable('flowline_accounts');
			$table->addColumn('id', 'bigint', [
				'autoincrement' => true,
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('user_id', 'string', [
				'length' => 64,
				'notnull' => true,
			]);
			$table->addColumn('token_encrypted', 'text', [
				'notnull' => true,
			]);
			$table->addColumn('account_json', 'text', [
				'notnull' => true,
			]);
			$table->addColumn('last_event_at', 'string', [
				'length' => 19,
				'notnull' => false,
			]);
			$table->addColumn('created_at', 'datetime', [
				'notnull' => true,
			]);
			$table->addColumn('updated_at', 'datetime', [
				'notnull' => true,
			]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['user_id'], 'flowline_accounts_uid');
		}

		return $schema;
	}
}
