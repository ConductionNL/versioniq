<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Installer;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IMigrationStep;
use OCP\Migration\IOutput;
use OCP\Server;
use Psr\Log\LoggerInterface;

/**
 * Dumps and restores the database tables an app's own migrations created.
 *
 * The tables are found by running the app's migration steps against a schema
 * that starts empty and is never written to the database, then reading the
 * table names that schema ends with (safety-rollback-backup-and-preflight D3).
 * Tables are never picked by name prefix, so another app's tables that share a
 * prefix are left alone.
 *
 * Rows are kept as JSON: a value that is not valid UTF-8 (a blob) is stored
 * as `{"__base64": "..."}` and decoded again on restore.
 *
 * @spec openspec/specs/migration-safety/spec.md#requirement-a-real-install-keeps-a-restorable-backup-of-the-app-folder-and-its-tables
 * @psalm-api
 */
class AppTableDumper {
	/** The marker of a value stored as base64. */
	public const BASE64_KEY = '__base64';

	/** @var Closure(): ISchemaWrapper */
	private Closure $emptySchema;

	/** @var Closure(class-string): mixed */
	private Closure $loadStep;

	/**
	 * @param (Closure(): ISchemaWrapper)|null $emptySchema A schema that starts empty and is never applied; defaults to the server's schema wrapper over an empty schema.
	 * @param (Closure(class-string): mixed)|null $loadStep Builds a migration step from its class; defaults to the server container.
	 */
	public function __construct(
		private IDBConnection $db,
		private LoggerInterface $logger,
		?Closure $emptySchema = null,
		?Closure $loadStep = null,
	) {
		$this->loadStep = $loadStep ?? static fn (string $class): mixed => Server::get($class);
		$this->emptySchema = $emptySchema ?? static fn (): ISchemaWrapper => new \OC\DB\SchemaWrapper(
			Server::get(\OC\DB\Connection::class),
			new \Doctrine\DBAL\Schema\Schema(),
		);
	}

	/**
	 * The tables the migrations of the app in `$appPath` create, that exist now.
	 *
	 * @spec openspec/specs/migration-safety/spec.md#requirement-a-real-install-keeps-a-restorable-backup-of-the-app-folder-and-its-tables
	 * @return list<string> Table names without the instance prefix.
	 */
	public function tablesOf(string $appPath, string $namespace): array {
		$steps = [];
		foreach ($this->migrationClasses($appPath, $namespace) as $class) {
			try {
				$step = ($this->loadStep)($class);
			} catch (\Throwable $error) {
				$this->logger->warning('AppTableDumper: a migration step could not be loaded', ['class' => $class, 'message' => $error->getMessage()]);
				continue;
			}
			if ($step instanceof IMigrationStep) {
				$steps[] = $step;
			}
		}

		return array_values(array_filter(
			$this->tablesCreatedBy($steps, ($this->emptySchema)()),
			fn (string $table): bool => $this->db->tableExists($table),
		));
	}

	/**
	 * Runs the steps' schema changes, in order, on `$schema` and returns the
	 * tables it ends with. A step that fails is logged and skipped.
	 *
	 * @spec openspec/specs/migration-safety/spec.md#requirement-a-real-install-keeps-a-restorable-backup-of-the-app-folder-and-its-tables
	 * @param list<IMigrationStep> $steps
	 * @return list<string>
	 */
	public function tablesCreatedBy(array $steps, ISchemaWrapper $schema): array {
		$output = self::silentOutput();
		foreach ($steps as $step) {
			try {
				$changed = $step->changeSchema($output, static fn (): ISchemaWrapper => $schema, ['tablePrefix' => '']);
				if ($changed instanceof ISchemaWrapper) {
					$schema = $changed;
				}
			} catch (\Throwable $error) {
				$this->logger->warning('AppTableDumper: a migration step could not be read', ['step' => $step::class, 'message' => $error->getMessage()]);
			}
		}

		$tables = array_values(array_unique($schema->getTableNamesWithoutPrefix()));
		sort($tables);

		return $tables;
	}

	/**
	 * Every row of every table, keyed by table.
	 *
	 * @spec openspec/specs/migration-safety/spec.md#requirement-a-real-install-keeps-a-restorable-backup-of-the-app-folder-and-its-tables
	 * @param list<string> $tables
	 * @return array<string, list<array<string, mixed>>>
	 */
	public function dumpTables(array $tables): array {
		$dump = [];
		foreach ($tables as $table) {
			$result = $this->db->getQueryBuilder()->select('*')->from($table)->executeQuery();
			$rows = [];
			while (($row = $result->fetch()) !== false) {
				$rows[] = array_map(self::encodeValue(...), $row);
			}
			$result->closeCursor();
			$dump[$table] = $rows;
		}

		return $dump;
	}

	/**
	 * Replaces the rows of every dumped table, all in one transaction: a
	 * failure leaves every table as it was.
	 *
	 * @spec openspec/specs/migration-safety/spec.md#requirement-restoring-a-broken-install-puts-back-files-and-tables
	 * @param array<string, list<array<string, mixed>>> $dump
	 * @return int The number of rows written.
	 */
	public function restore(array $dump): int {
		$written = 0;
		$this->db->beginTransaction();
		try {
			foreach ($dump as $table => $rows) {
				$this->db->getQueryBuilder()->delete($table)->executeStatement();
				foreach ($rows as $row) {
					$insert = $this->db->getQueryBuilder()->insert($table);
					foreach ($row as $column => $value) {
						$insert->setValue($column, $insert->createNamedParameter(self::decodeValue($value)));
					}
					$insert->executeStatement();
					$written++;
				}
			}
			$this->db->commit();
		} catch (\Throwable $error) {
			$this->db->rollBack();
			throw $error;
		}

		return $written;
	}

	/**
	 * The migration classes of the app, in the order the server runs them.
	 *
	 * @return list<class-string>
	 */
	private function migrationClasses(string $appPath, string $namespace): array {
		$files = glob(rtrim($appPath, '/') . '/lib/Migration/Version*.php');
		if ($files === false) {
			return [];
		}
		$names = array_map(static fn (string $file): string => basename($file, '.php'), $files);
		usort($names, 'strnatcmp');

		/** @var list<class-string> */
		return array_map(static fn (string $name): string => rtrim($namespace, '\\') . '\\Migration\\' . $name, $names);
	}

	private static function encodeValue(mixed $value): mixed {
		if (is_resource($value)) {
			$value = (string)stream_get_contents($value);
		}
		if (is_string($value) && !mb_check_encoding($value, 'UTF-8')) {
			return [self::BASE64_KEY => base64_encode($value)];
		}

		return $value;
	}

	private static function decodeValue(mixed $value): mixed {
		if (is_array($value) && isset($value[self::BASE64_KEY]) && is_string($value[self::BASE64_KEY])) {
			return base64_decode($value[self::BASE64_KEY], true);
		}

		return $value;
	}

	private static function silentOutput(): IOutput {
		return new class implements IOutput {
			/** @spec openspec/specs/migration-safety/spec.md#requirement-a-real-install-keeps-a-restorable-backup-of-the-app-folder-and-its-tables */
			public function debug(string $message): void {
			}

			/** @spec openspec/specs/migration-safety/spec.md#requirement-a-real-install-keeps-a-restorable-backup-of-the-app-folder-and-its-tables */
			public function info($message) {
			}

			/** @spec openspec/specs/migration-safety/spec.md#requirement-a-real-install-keeps-a-restorable-backup-of-the-app-folder-and-its-tables */
			public function warning($message) {
			}

			/** @spec openspec/specs/migration-safety/spec.md#requirement-a-real-install-keeps-a-restorable-backup-of-the-app-folder-and-its-tables */
			public function startProgress($max = 0) {
			}

			/** @spec openspec/specs/migration-safety/spec.md#requirement-a-real-install-keeps-a-restorable-backup-of-the-app-folder-and-its-tables */
			public function advance($step = 1, $description = '') {
			}

			/** @spec openspec/specs/migration-safety/spec.md#requirement-a-real-install-keeps-a-restorable-backup-of-the-app-folder-and-its-tables */
			public function finishProgress() {
			}
		};
	}
}
