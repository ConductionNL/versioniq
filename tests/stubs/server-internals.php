<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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

		/** @var ?string Ends with '/'; null until a server boots, as in unit tests. */
		public static $configDir;

		/** @var mixed */
		public static $server;

		/** @var array<int, array{path: string, url: string, writable: bool}> */
		public static $APPSROOTS;
	}

	class OC_App {
		/**
		 * What listAllApps() returns; unit tests set it.
		 *
		 * @var array<int, array<string, mixed>>
		 */
		public static array $allApps = [];

		/**
		 * Receives the calls of these stubs that a unit test records: the
		 * stub's name and its arguments.
		 *
		 * @var ?\Closure(string, array<int, mixed>): void
		 */
		public static ?\Closure $recorder = null;

		/**
		 * @param array<int, mixed> $args
		 */
		public static function record(string $call, array $args): void {
			if (self::$recorder !== null) {
				(self::$recorder)($call, $args);
			}
		}

		/**
		 * @return array<int, array<string, mixed>>
		 */
		public static function listAllApps(): array {
			return self::$allApps;
		}

		/**
		 * @param array<string, mixed> $info
		 */
		public static function checkAppDependencies(\OCP\IConfig $config, \OCP\IL10N $l, array $info, bool $ignoreMax = false): void {
		}

		public static function registerAutoloading(string $app, string $path): void {
			self::record('OC_App::registerAutoloading', [$app, $path]);
		}

		/**
		 * @param array<array-key, mixed> $steps
		 */
		public static function executeRepairSteps(string $appId, array $steps): void {
			self::record('OC_App::executeRepairSteps', [$appId, $steps]);
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
	/**
	 * Under the unit-only bootstrap, TAR extracts gzip tarballs and ZIP extracts
	 * zip files with PHP's own Phar and Zip extensions.
	 */
	class Archive {
		public function __construct(
			protected string $source,
		) {
		}

		public function extract(string $dest): bool {
			return false;
		}

		public function getError(): \PEAR_Error|false {
			return false;
		}
	}

	class TAR extends Archive {
		public function extract(string $dest): bool {
			if (@file_get_contents($this->source, false, null, 0, 2) !== "\x1f\x8b") {
				return false;
			}
			try {
				return (new \PharData($this->source))->extractTo($dest, null, true);
			} catch (\Throwable) {
				return false;
			}
		}
	}

	class ZIP extends Archive {
		public function extract(string $dest): bool {
			$zip = new \ZipArchive();
			if ($zip->open($this->source) !== true) {
				return false;
			}
			$result = $zip->extractTo($dest);
			$zip->close();

			return $result;
		}
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
			\OC_App::record('Coordinator::runLazyRegistration', [$appId]);
		}
	}
}

namespace OC\DB {
	class Connection {
	}

	class MigrationService {
		public function __construct(string $appName, Connection $connection) {
			\OC_App::record('MigrationService::__construct', [$appName]);
		}

		public function setOutput(\OCP\Migration\IOutput $output): void {
			\OC_App::record('MigrationService::setOutput', []);
		}

		public function migrate(string $to = 'latest', bool $schemaOnly = false): void {
			\OC_App::record('MigrationService::migrate', [$to, $schemaOnly]);
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

// Nextcloud 35 bundles phpseclib 3 instead of 2; same calls, new namespace.
namespace phpseclib3\File {
	/**
	 * Under the unit-only bootstrap every call goes to `$handler`, which a test
	 * sets: it receives the instance, the method name and the arguments.
	 */
	class X509 {
		/** @var ?\Closure(self, string, array<int, mixed>): mixed */
		public static ?\Closure $handler = null;

		public function loadCA(string $cert): mixed {
			return $this->handle(__FUNCTION__, [$cert]);
		}

		/**
		 * @return array<string, mixed>|false
		 */
		public function loadX509(string $cert): array|false {
			/** @var array<string, mixed>|false */
			return $this->handle(__FUNCTION__, [$cert]);
		}

		public function loadCRL(string $crl): mixed {
			return $this->handle(__FUNCTION__, [$crl]);
		}

		public function validateSignature(): bool {
			return (bool)$this->handle(__FUNCTION__, []);
		}

		public function getRevoked(string $serial): mixed {
			return $this->handle(__FUNCTION__, [$serial]);
		}

		/**
		 * @param array<int, mixed> $args
		 */
		private function handle(string $method, array $args): mixed {
			return self::$handler === null ? null : (self::$handler)($this, $method, $args);
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

namespace Doctrine\DBAL\Types {
	/**
	 * The type-name constants IQueryBuilder derives PARAM_BOOL and its date and
	 * time PARAM_* constants from.
	 *
	 * Same reason as ParameterType above: without this class, creating a test
	 * double of IQueryBuilder resolves its constants and dies with
	 * `Class "Doctrine\DBAL\Types\Types" not found`, which is why the mapper
	 * tests could not run in this suite. The values mirror doctrine/dbal 3.x,
	 * where these are plain string constants.
	 */
	final class Types {
		public const BOOLEAN = 'boolean';
		public const DATE_MUTABLE = 'date';
		public const DATE_IMMUTABLE = 'date_immutable';
		public const DATETIME_MUTABLE = 'datetime';
		public const DATETIME_IMMUTABLE = 'datetime_immutable';
		public const DATETIMETZ_MUTABLE = 'datetimetz';
		public const DATETIMETZ_IMMUTABLE = 'datetimetz_immutable';
		public const TIME_MUTABLE = 'time';
		public const TIME_IMMUTABLE = 'time_immutable';
	}
}

namespace Doctrine\DBAL\Query\Expression {
	/**
	 * The comparison-operator constants IExpressionBuilder derives EQ, NEQ,
	 * LT, LTE, GT and GTE from; without it a test double of
	 * IExpressionBuilder cannot be created. Values mirror doctrine/dbal 3.x.
	 */
	class ExpressionBuilder {
		public const EQ = '=';
		public const NEQ = '<>';
		public const LT = '<';
		public const LTE = '<=';
		public const GT = '>';
		public const GTE = '>=';
	}
}
