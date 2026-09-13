<?php

/**
 * Versioniq consolidated schema, replacing the five incremental migrations.
 *
 * @category Migration
 * @package  OCA\Versioniq\Migration
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Versioniq\Migration;

use Closure;
use InvalidArgumentException;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Consolidated schema for Versioniq.
 *
 * @spec exclude Schema-only consolidation; behavioural requirements live with the services that read these tables.
 *
 * @psalm-suppress UnusedClass Loaded by the Nextcloud migration framework.
 *
 * @psalm-type ColumnSpec = array{0: string, 1: string, 2: array<string, mixed>}
 * @psalm-type IndexSpec = array{0: string, 1: list<string>}
 * @psalm-type TableSpec = array{columns: list<ColumnSpec>, primary: list<string>,
 *     indexes: list<IndexSpec>, uniqueIndexes: list<IndexSpec>}
 */
class Version1100Date20260908000000 extends SimpleMigrationStep {
	private const OLD_PREFIX = 'app_versions_';

	private const NEW_PREFIX = 'versioniq_';

	/**
	 * Table suffixes shared by the old and new prefixes.
	 *
	 * @var string[]
	 */
	private const TABLES = [
		'audit',
		'pats',
	];

	/**
	 * Full target schema, keyed by unprefixed table suffix.
	 *
	 * @var array<string, TableSpec>
	 */
	private const SCHEMA = [
		'audit' => [
			'columns' => [
				['id', Types::BIGINT, ['notnull' => true, 'autoincrement' => true]],
				['actor_uid', Types::STRING, ['notnull' => true, 'length' => 64]],
				['app_id', Types::STRING, ['notnull' => true, 'length' => 255]],
				['operation', Types::STRING, ['notnull' => true, 'length' => 32]],
				['from_version', Types::STRING, ['notnull' => false, 'length' => 64]],
				['to_version', Types::STRING, ['notnull' => false, 'length' => 64]],
				['source_id', Types::STRING, ['notnull' => false, 'length' => 255]],
				['status', Types::STRING, ['notnull' => true, 'length' => 16]],
				['message', Types::TEXT, ['notnull' => false]],
				['created_at', Types::DATETIME, ['notnull' => true]],
			],
			'primary' => ['id'],
			'indexes' => [
				['av_audit_app_created_idx', ['app_id', 'created_at']],
				['av_audit_created_idx', ['created_at']],
			],
			'uniqueIndexes' => [],
		],
		'pats' => [
			'columns' => [
				['id', Types::BIGINT, ['notnull' => true, 'autoincrement' => true]],
				['owner_uid', Types::STRING, ['notnull' => true, 'length' => 64]],
				['label', Types::STRING, ['notnull' => true, 'length' => 128]],
				['target_pattern', Types::STRING, ['notnull' => true, 'length' => 255]],
				['kind', Types::STRING, ['notnull' => true, 'length' => 32]],
				['encrypted_token', Types::TEXT, ['notnull' => true]],
				['token_hint', Types::STRING, ['notnull' => true, 'length' => 32]],
				['shared_with_admins', Types::BOOLEAN, ['notnull' => false, 'default' => false]],
				['last_validated_scopes', Types::TEXT, ['notnull' => false]],
				['expires_at', Types::DATETIME, ['notnull' => false]],
				['last_used_at', Types::DATETIME, ['notnull' => false]],
				['created_at', Types::DATETIME, ['notnull' => true]],
				['forge', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'github']],
				['warned_thresholds', Types::TEXT, ['notnull' => true, 'default' => '[]']],
			],
			'primary' => ['id'],
			'indexes' => [
				['av_pats_owner_idx', ['owner_uid']],
				['av_pats_target_idx', ['target_pattern']],
			],
			'uniqueIndexes' => [],
		],
	];

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $connection Database connection used for the rename.
	 * @param IConfig $config System config, read for the table prefix.
	 *
	 * @spec exclude Schema-only consolidation; behavioural requirements live with the services that read these tables.
	 */
	public function __construct(
		private readonly IDBConnection $connection,
		private readonly IConfig $config,
	) {
	}//end __construct()

	/**
	 * Rename the legacy tables before the schema comparison runs.
	 *
	 * The app id moved from app_versions to versioniq, but the physical tables kept the
	 * old prefix. This renames them in place so the data survives; the schema
	 * declared above then matches what a fresh install creates.
	 *
	 * Indexes are deliberately NOT renamed here. The schema comparison that
	 * follows drops any index the target schema does not declare and creates
	 * the ones it does, which reconciles them on every platform. PostgreSQL
	 * sequences and primary-key constraints are not covered by that comparison,
	 * so they are renamed explicitly.
	 *
	 * @param IOutput $output The output interface
	 * @param Closure(): ISchemaWrapper $schemaClosure The schema closure
	 * @param array<string,mixed> $options Migration options
	 *
	 * @return void
	 *
	 * @spec exclude Schema-only consolidation; behavioural requirements live with the services that read these tables.
	 */
	public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$schema = $schemaClosure();
		$renamed = 0;

		foreach (self::TABLES as $suffix) {
			$old = self::OLD_PREFIX . $suffix;
			$new = self::NEW_PREFIX . $suffix;

			if ($schema->hasTable($old) === false || $schema->hasTable($new) === true) {
				continue;
			}

			$this->renameTable(oldName: $old, newName: $new);
			$renamed++;
		}

		if ($renamed > 0) {
			$output->info(sprintf('Renamed %d legacy %s* tables to %s*', $renamed, self::OLD_PREFIX, self::NEW_PREFIX));
		}
	}//end preSchemaChange()

	/**
	 * Rename one table, and on PostgreSQL its sequences and primary key too.
	 *
	 * @param string $oldName Unprefixed current table name
	 * @param string $newName Unprefixed target table name
	 *
	 * @return void
	 *
	 * @spec exclude Schema-only consolidation; behavioural requirements live with the services that read these tables.
	 */
	private function renameTable(string $oldName, string $newName): void {
		// Identifiers cannot be bound as parameters. Both names come from the
		// private constants above, and this guard keeps that guarantee local.
		if (preg_match('/^[a-z0-9_]+$/', $oldName) !== 1 || preg_match('/^[a-z0-9_]+$/', $newName) !== 1) {
			throw new InvalidArgumentException('Refusing to rename a table with a non-identifier name.');
		}

		$provider = $this->connection->getDatabaseProvider();

		if ($provider === IDBConnection::PLATFORM_MYSQL || $provider === IDBConnection::PLATFORM_MARIADB) {
			$this->connection->executeStatement(sprintf('RENAME TABLE `*PREFIX*%s` TO `*PREFIX*%s`', $oldName, $newName));
			return;
		}

		$this->connection->executeStatement(sprintf('ALTER TABLE "*PREFIX*%s" RENAME TO "*PREFIX*%s"', $oldName, $newName));

		if ($provider !== IDBConnection::PLATFORM_POSTGRES) {
			return;
		}

		$this->renamePostgresArtefacts(oldName: $oldName, newName: $newName);
	}//end renameTable()

	/**
	 * Move the sequences and primary-key constraint a PostgreSQL rename leaves behind.
	 *
	 * @param string $oldName Unprefixed previous table name
	 * @param string $newName Unprefixed current table name
	 *
	 * @return void
	 *
	 * @spec exclude Schema-only consolidation; behavioural requirements live with the services that read these tables.
	 */
	private function renamePostgresArtefacts(string $oldName, string $newName): void {
		$prefix = $this->config->getSystemValueString('dbtableprefix', 'oc_');

		$sequences = $this->fetchNames(
			sql: 'SELECT sequencename AS name FROM pg_sequences WHERE schemaname = current_schema() AND sequencename LIKE ?',
			params: [$prefix . $oldName . '\_%']
		);

		foreach ($sequences as $sequence) {
			$target = $prefix . $newName . substr($sequence, strlen($prefix . $oldName));
			$this->connection->executeStatement(sprintf('ALTER SEQUENCE "%s" RENAME TO "%s"', $sequence, $target));
		}

		$constraints = $this->fetchNames(
			sql: 'SELECT conname AS name FROM pg_constraint WHERE conrelid = ?::regclass AND contype = \'p\'',
			params: [$prefix . $newName]
		);

		foreach ($constraints as $constraint) {
			if (str_starts_with($constraint, $prefix . $oldName) === false) {
				continue;
			}

			$target = $prefix . $newName . substr($constraint, strlen($prefix . $oldName));
			$this->connection->executeStatement(
				sprintf('ALTER TABLE "%s" RENAME CONSTRAINT "%s" TO "%s"', $prefix . $newName, $constraint, $target)
			);
		}
	}//end renamePostgresArtefacts()

	/**
	 * Run a query whose single selected column is aliased to "name".
	 *
	 * @param string $sql The query to run
	 * @param array<int,string> $params Positional parameters
	 *
	 * @return list<string>
	 *
	 * @spec exclude Schema-only consolidation; behavioural requirements live with the services that read these tables.
	 */
	private function fetchNames(string $sql, array $params): array {
		$names = [];

		foreach ($this->connection->executeQuery($sql, $params)->fetchAll() as $row) {
			$names[] = (string)$row['name'];
		}

		return $names;
	}//end fetchNames()

	/**
	 * Create any missing table, column or index of the target schema.
	 *
	 * Idempotent by construction: an install that already carries a table keeps
	 * it and gains only what it is missing, so this is safe both on a fresh
	 * install and on one that has just been renamed above.
	 *
	 * @param IOutput $output The output interface
	 * @param Closure(): ISchemaWrapper $schemaClosure The schema closure
	 * @param array<string,mixed> $options Migration options
	 *
	 * @return null|ISchemaWrapper
	 *
	 * @spec exclude Schema-only consolidation; behavioural requirements live with the services that read these tables.
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		foreach (self::SCHEMA as $suffix => $definition) {
			$this->applyTable(schema: $schema, name: self::NEW_PREFIX . $suffix, definition: $definition);
		}

		return $schema;
	}//end changeSchema()

	/**
	 * Reconcile one table against its declaration.
	 *
	 * @param ISchemaWrapper $schema The schema being built
	 * @param string $name Prefixed table name
	 * @param TableSpec $definition The declared shape of the table
	 *
	 * @return void
	 *
	 * @spec exclude Schema-only consolidation; behavioural requirements live with the services that read these tables.
	 */
	private function applyTable(ISchemaWrapper $schema, string $name, array $definition): void {
		if ($schema->hasTable($name) === false) {
			$schema->createTable($name);
		}

		$table = $schema->getTable($name);

		foreach ($definition['columns'] as [$column, $type, $columnOptions]) {
			if ($table->hasColumn($column) === true) {
				continue;
			}

			$table->addColumn($column, $type, $columnOptions);
		}

		if ($table->hasPrimaryKey() === false) {
			$table->setPrimaryKey($definition['primary']);
		}

		foreach ($definition['indexes'] as [$index, $columns]) {
			if ($table->hasIndex($index) === true) {
				continue;
			}

			$table->addIndex($columns, $index);
		}

		foreach ($definition['uniqueIndexes'] as [$index, $columns]) {
			if ($table->hasIndex($index) === true) {
				continue;
			}

			$table->addUniqueIndex($columns, $index);
		}
	}//end applyTable()
}//end class
