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

use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * How large the tables an upgrade's migration steps change are: the row count
 * of each, read-only, and a size class the page and the CLI turn into advice.
 * No time estimate: it depends on the database engine, indexes, disk and load.
 *
 * Only names matching `^[a-z0-9_]+$` are queried, at most {@see MAX_TABLES}
 * per preview; a table that does not exist yet is `new`; a count that fails,
 * or a table past the cap or with another name, is `unknown`. See design.md
 * D3 of the `safety-upgrade-migration-preview` change.
 *
 * @spec openspec/changes/safety-upgrade-migration-preview/specs/migration-safety/spec.md
 * @psalm-api
 */
class TableSizeProbe {
	public const CLASS_NEW = 'new';
	public const CLASS_SMALL = 'small';
	public const CLASS_LARGE = 'large';
	public const CLASS_VERY_LARGE = 'very_large';
	public const CLASS_UNKNOWN = 'unknown';

	/** The most tables one preview counts. */
	public const MAX_TABLES = 20;

	/** From this many rows a table is large. */
	public const LARGE_FROM = 100000;

	/** Above this many rows a table is very large. */
	public const VERY_LARGE_ABOVE = 1000000;

	private const TABLE_NAME = '/^[a-z0-9_]+$/';

	public function __construct(
		private IDBConnection $db,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The rows and size class of each table, in the order given, each once.
	 *
	 * @spec openspec/changes/safety-upgrade-migration-preview/specs/migration-safety/spec.md
	 * @param list<string> $tables Table names without the configured prefix.
	 * @return list<array{name: string, rows: int|null, sizeClass: string}>
	 */
	public function probe(array $tables): array {
		$result = [];
		$queried = 0;
		foreach (array_values(array_unique($tables)) as $table) {
			if (preg_match(self::TABLE_NAME, $table) !== 1 || $queried >= self::MAX_TABLES) {
				$result[] = ['name' => $table, 'rows' => null, 'sizeClass' => self::CLASS_UNKNOWN];
				continue;
			}

			$queried++;
			$result[] = $this->measure($table);
		}

		return $result;
	}

	/**
	 * The size class of a row count.
	 *
	 * @spec openspec/changes/safety-upgrade-migration-preview/specs/migration-safety/spec.md
	 */
	public static function sizeClass(int $rows): string {
		if ($rows > self::VERY_LARGE_ABOVE) {
			return self::CLASS_VERY_LARGE;
		}

		return $rows >= self::LARGE_FROM ? self::CLASS_LARGE : self::CLASS_SMALL;
	}

	/**
	 * @return array{name: string, rows: int|null, sizeClass: string}
	 */
	private function measure(string $table): array {
		try {
			if (!$this->db->tableExists($table)) {
				return ['name' => $table, 'rows' => null, 'sizeClass' => self::CLASS_NEW];
			}

			$query = $this->db->getQueryBuilder();
			$query->select($query->func()->count('*', 'row_count'))->from($table);
			$statement = $query->executeQuery();
			$count = $statement->fetchOne();
			$statement->closeCursor();
		} catch (\Throwable $error) {
			$this->logger->warning('TableSizeProbe: could not count a table', ['table' => $table, 'exception' => $error::class]);

			return ['name' => $table, 'rows' => null, 'sizeClass' => self::CLASS_UNKNOWN];
		}

		if (!is_numeric($count)) {
			return ['name' => $table, 'rows' => null, 'sizeClass' => self::CLASS_UNKNOWN];
		}

		$rows = (int)$count;

		return ['name' => $table, 'rows' => $rows, 'sizeClass' => self::sizeClass($rows)];
	}
}
