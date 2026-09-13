<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 */

/**
 * Psalm stubs for Nextcloud server-internal and transitive classes.
 *
 * These classes exist at runtime inside the Nextcloud server but are NOT part of
 * the public `nextcloud/ocp` package this app analyses against. The Versioniq
 * installer deliberately reuses the server's app-installer internals (archive
 * extraction, code-signing verification, repair-step execution) because the
 * public OCP API exposes no equivalent for installing a *specific* app version.
 * Declaring them here lets Psalm type-check the call sites without pulling the
 * whole server into the analysis path.
 *
 * This file is never autoloaded — it is wired into Psalm via the <stubs> section
 * of psalm.xml only.
 */

namespace {
	class OC {
		/** @var string */
		public static $SERVERROOT;

		/** @var array<int, array{path: string, url: string, writable: bool}> */
		public static $APPSROOTS;
	}

	class OC_App {
		/**
		 * @return array<int, array<string, mixed>>
		 */
		public static function listAllApps(): array {
		}

		/**
		 * @param array<string, mixed> $info
		 */
		public static function checkAppDependencies(\OCP\IConfig $config, \OCP\IL10N $l, array $info, bool $ignoreMax = false): void {
		}

		public static function registerAutoloading(string $app, string $path): void {
		}

		/**
		 * @param array<array-key, mixed> $steps
		 */
		public static function executeRepairSteps(string $appId, array $steps): void {
		}

		public static function setAppTypes(string $appId): void {
		}
	}

	class PEAR_Error {
		public function getMessage(): string {
		}
	}
}

namespace OC\Archive {
	class Archive {
		public function __construct(string $source) {
		}

		public function extract(string $dest): bool {
		}

		public function getError(): \PEAR_Error|false {
		}
	}

	class TAR extends Archive {
	}

	class ZIP extends Archive {
	}
}

namespace OC\Files {
	class FilenameValidator {
		public function isForbidden(string $path): bool {
		}
	}
}

namespace OC\AppFramework\Bootstrap {
	class Coordinator {
		public function runLazyRegistration(string $appId): void {
		}
	}
}

namespace OC\DB {
	class Connection {
	}

	class MigrationService {
		public function __construct(string $appName, Connection $connection) {
		}

		public function setOutput(\OCP\Migration\IOutput $output): void {
		}

		public function migrate(string $to = 'latest', bool $schemaOnly = false): void {
		}
	}
}

namespace phpseclib\File {
	class X509 {
		public function loadCA(string $cert): mixed {
		}

		/**
		 * @return array<string, mixed>|false
		 */
		public function loadX509(string $cert): array|false {
		}

		public function loadCRL(string $crl): mixed {
		}

		public function validateSignature(): bool {
		}

		public function getRevoked(string $serial): mixed {
		}
	}
}

namespace Doctrine\DBAL\Schema {
	class Table {
		/**
		 * @param array<string, mixed> $options
		 */
		public function addColumn(string $name, string $type, array $options = []): void {
		}

		public function hasColumn(string $name): bool {
		}

		public function hasPrimaryKey(): bool {
		}

		public function hasIndex(string $name): bool {
		}

		/**
		 * @param list<string> $columns
		 */
		public function setPrimaryKey(array $columns): void {
		}

		/**
		 * @param list<string> $columns
		 */
		public function addIndex(array $columns, ?string $name = null): void {
		}

		/**
		 * @param list<string> $columns
		 */
		public function addUniqueIndex(array $columns, ?string $name = null): void {
		}

		/**
		 * Reads back a column that already exists on the table.
		 *
		 * Migrations use this to adjust a column they are not creating, e.g.
		 * giving `shared_with_admins` a default. Without it here, Psalm reports
		 * `UndefinedMethod` against the real DBAL API, and the only way to keep
		 * the run green is to baseline a finding that describes the STUB rather
		 * than the code.
		 */
		public function getColumn(string $name): Column {
		}
	}

	class Column {
		/**
		 * Returns $this in the real API, so a migration can chain.
		 */
		public function setDefault(mixed $default): self {
		}

		public function setNotnull(bool $notnull): self {
		}
	}
}

namespace Doctrine\DBAL {
	/**
	 * The parameter-type constants `OCP\DB\QueryBuilder\IQueryBuilder` derives
	 * its PARAM_* constants from.
	 *
	 * doctrine/dbal is a runtime dependency of the Nextcloud SERVER, not of an
	 * app, so it is absent from this app's vendor tree — but `nextcloud/ocp`'s
	 * IQueryBuilder references these constants at class-definition time. Any
	 * unit test that so much as type-hints IQueryBuilder therefore dies with
	 * `Class "Doctrine\DBAL\ParameterType" not found` before a single assertion
	 * runs.
	 *
	 * That is exactly what had happened: tests/unit/Repair was missing from the
	 * testsuite list in phpunit-unit-only.xml, so its tests never ran and this
	 * gap stayed invisible. Adding the directory surfaced seven errors at once.
	 *
	 * The values mirror doctrine/dbal 3.x, where these are plain int constants.
	 */
	final class ParameterType {
		public const NULL = 0;
		public const INTEGER = 1;
		public const STRING = 2;
		public const LARGE_OBJECT = 3;
		public const BOOLEAN = 5;
		public const BINARY = 16;
		public const ASCII = 17;
	}

	/**
	 * The array-parameter counterpart, used by IQueryBuilder's PARAM_*_ARRAY.
	 *
	 * Same reason, same source: doctrine/dbal 3.x integer constants.
	 */
	final class ArrayParameterType {
		public const INTEGER = 101;
		public const STRING = 102;
		public const ASCII = 117;
		public const BINARY = 116;
	}
}
