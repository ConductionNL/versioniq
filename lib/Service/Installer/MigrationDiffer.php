<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Service\Installer;

use OCP\IDBConnection;

/**
 * Compares the database migration step files (`lib/Migration/Version*.php`)
 * shipped by an installed app copy against a target app copy, naming the
 * steps present in the installed copy but absent from the target — the
 * concrete schema risk a downgrade carries; see "Migration diff on
 * downgrade".
 *
 * The installed set is read from the filesystem (not the `oc_migrations` DB
 * table): a file diff is symmetric with the target (also a filesystem read
 * of an extracted archive) and does not pull in noise from steps shipped by
 * even older versions the DB table would still list — see design.md
 * "Rejected alternatives".
 *
 * @spec openspec/specs/migration-safety/spec.md
 * @psalm-api
 */
class MigrationDiffer {
	private const MIGRATION_GLOB = '/lib/Migration/Version*.php';

	/** Nextcloud records a step as its class name without this prefix. */
	private const CLASS_PREFIX = 'Version';

	public function __construct(
		private ?IDBConnection $db = null,
	) {
	}

	/**
	 * The target's migration steps Nextcloud has not recorded as run for the
	 * app, sorted, with `estimated` false; null when the target cannot be read.
	 *
	 * Nextcloud's `MigrationService` records a step in the `migrations` table
	 * under `app` and `version`, the class name without its `Version` prefix
	 * (`Version1000Date20260101000000` is recorded as `1000Date20260101000000`).
	 * A step that ran once, was orphaned by a downgrade and ships again is
	 * recorded and so not listed. When the table cannot be read the list falls
	 * back to the target steps the installed copy lacks, with `estimated` true.
	 * The read never writes. See design.md D1 of the
	 * `safety-upgrade-migration-preview` change.
	 *
	 * @spec openspec/changes/safety-upgrade-migration-preview/specs/migration-safety/spec.md
	 * @return array{steps: list<string>, estimated: bool}|null
	 */
	public function pending(string $appId, ?string $targetAppPath, ?string $installedAppPath = null): ?array {
		$target = $this->targetSteps($targetAppPath);
		if ($target === null) {
			return null;
		}

		$recorded = $this->recordedVersions($appId);
		if ($recorded === null) {
			return $this->estimatedPending($target, $installedAppPath);
		}

		return ['steps' => self::unrecorded($target, $recorded), 'estimated' => false];
	}

	/**
	 * The migration step basenames of the target copy, or null when there is no target folder.
	 *
	 * @return list<string>|null
	 */
	private function targetSteps(?string $targetAppPath): ?array {
		if ($targetAppPath === null || !is_dir($targetAppPath)) {
			return null;
		}

		return $this->migrationBasenames($targetAppPath);
	}

	/**
	 * The target steps whose version the migrations table does not record, sorted.
	 *
	 * @param list<string> $target
	 * @param array<string, mixed> $recorded Recorded versions as keys.
	 * @return list<string>
	 */
	private static function unrecorded(array $target, array $recorded): array {
		$steps = array_values(array_filter(
			$target,
			static fn (string $step): bool => !isset($recorded[self::recordedVersion($step)])
		));
		sort($steps);

		return $steps;
	}

	/**
	 * The file fallback: the target steps the installed copy lacks.
	 *
	 * @param list<string> $target
	 * @return array{steps: list<string>, estimated: bool}
	 */
	private function estimatedPending(array $target, ?string $installedAppPath): array {
		$installed = ($installedAppPath === null || $installedAppPath === '') ? [] : ($this->migrationBasenames($installedAppPath) ?? []);
		$steps = array_values(array_diff($target, $installed));
		sort($steps);

		return ['steps' => $steps, 'estimated' => true];
	}

	/** The `version` Nextcloud records for a step basename. */
	private static function recordedVersion(string $step): string {
		return str_starts_with($step, self::CLASS_PREFIX) ? substr($step, strlen(self::CLASS_PREFIX)) : $step;
	}

	/**
	 * The versions recorded as run for the app, as a set; null when the
	 * `migrations` table cannot be read.
	 *
	 * @return array<string, true>|null
	 */
	private function recordedVersions(string $appId): ?array {
		if ($this->db === null) {
			return null;
		}

		try {
			$query = $this->db->getQueryBuilder();
			$query->select('version')
				->from('migrations')
				->where($query->expr()->eq('app', $query->createNamedParameter($appId)));
			$statement = $query->executeQuery();
			$versions = $statement->fetchAll(\PDO::FETCH_COLUMN);
			$statement->closeCursor();
		} catch (\Throwable) {
			return null;
		}

		$recorded = [];
		foreach (array_filter($versions, 'is_scalar') as $version) {
			$recorded[(string)$version] = true;
		}

		return $recorded;
	}

	/**
	 * Returns the migration step basenames present in `$installedAppPath`
	 * but absent from `$targetAppPath`, sorted; `null` when either side
	 * could not be read (unreadable archive layout) — a diff failure must
	 * degrade to a generic warning, never block an acknowledged downgrade.
	 *
	 * @spec openspec/specs/migration-safety/spec.md
	 * @return list<string>|null
	 */
	public function diff(?string $installedAppPath, ?string $targetAppPath): ?array {
		if ($installedAppPath === null || $installedAppPath === '' || $targetAppPath === null || $targetAppPath === '') {
			return null;
		}

		$installed = $this->migrationBasenames($installedAppPath);
		$target = $this->migrationBasenames($targetAppPath);
		if ($installed === null || $target === null) {
			return null;
		}

		$diff = array_values(array_diff($installed, $target));
		sort($diff);

		return $diff;
	}

	/**
	 * @return list<string>|null null when the migration directory exists but could not be enumerated
	 */
	private function migrationBasenames(string $appPath): ?array {
		try {
			$dir = rtrim($appPath, '/') . '/lib/Migration';
			if (!is_dir($dir)) {
				// No migrations directory is a legitimate empty set, not a failure.
				return [];
			}

			$files = glob(rtrim($appPath, '/') . self::MIGRATION_GLOB);
			if ($files === false) {
				return null;
			}

			return array_map(
				static fn (string $file): string => basename($file, '.php'),
				$files
			);
		} catch (\Throwable) {
			return null;
		}
	}
}
