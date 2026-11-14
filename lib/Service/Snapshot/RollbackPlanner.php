<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Snapshot;

/**
 * The plan to move the instance back to a snapshot (install-bulk-rollback
 * design D3): one step per app, worked out locally from the snapshot, the apps
 * installed now and the pins, without a call to any source.
 *
 * | kind        | when |
 * | unchanged   | same version |
 * | downgrade   | the snapshot holds an older version |
 * | upgrade     | the snapshot holds a newer version |
 * | pinned      | the app is pinned to a version other than the target; skipped unless the admin moves the pin |
 * | removed     | in the snapshot, not installed now; left alone |
 * | added       | installed now, not in the snapshot; left alone |
 *
 * Downgrade and upgrade steps come first, ordered by the app's newest change
 * after the snapshot, newest first, so the last change is undone first; apps
 * without a recorded change follow in alphabetical order. The other steps
 * follow, by kind and then app id.
 *
 * @spec openspec/changes/install-bulk-rollback/specs/migration-safety/spec.md
 * @psalm-type RollbackStep = array{appId: string, kind: 'unchanged'|'downgrade'|'upgrade'|'pinned'|'removed'|'added', from: ?string, to: ?string, sourceId: ?string, pinnedTo: ?string}
 * @psalm-api
 */
final class RollbackPlanner {
	private const TRAILING_ORDER = ['pinned' => 0, 'removed' => 1, 'added' => 2, 'unchanged' => 3];

	/**
	 * @param list<array<string, mixed>> $installedApps The app cards of `InstallerService::getInstalledApps()`.
	 * @param array<string, string> $pins Pinned version by app id.
	 * @param array<string, string> $lastChangedAt Time (ISO 8601) of each app's newest audit row after the snapshot.
	 * @return list<RollbackStep>
	 * @spec openspec/changes/install-bulk-rollback/specs/migration-safety/spec.md
	 */
	public function plan(InstanceSnapshot $snapshot, array $installedApps, array $pins = [], array $lastChangedAt = []): array {
		$current = self::currentVersions($installedApps);
		$steps = [];
		foreach ($snapshot->apps as $appId => $app) {
			$steps[] = self::step((string)$appId, $current[$appId] ?? null, $app, $pins[$appId] ?? null);
		}
		foreach ($current as $appId => $version) {
			if (!isset($snapshot->apps[$appId])) {
				$steps[] = ['appId' => $appId, 'kind' => 'added', 'from' => $version, 'to' => null, 'sourceId' => null, 'pinnedTo' => null];
			}
		}

		return self::ordered($steps, $lastChangedAt);
	}

	/**
	 * @param list<array<string, mixed>> $installedApps
	 * @return array<string, string> Installed version by app id.
	 */
	private static function currentVersions(array $installedApps): array {
		$current = [];
		foreach ($installedApps as $card) {
			$version = $card['installedVersion'] ?? null;
			if (($card['state'] ?? 'notInstalled') !== 'notInstalled' && is_string($version) && $version !== '' && is_string($card['id'] ?? null)) {
				$current[$card['id']] = $version;
			}
		}

		return $current;
	}

	/**
	 * @param array{version: string, sourceId: ?string, enabled: bool} $target
	 * @return RollbackStep
	 */
	private static function step(string $appId, ?string $from, array $target, ?string $pinnedTo): array {
		$to = $target['version'];
		$kind = match (true) {
			$from === null => 'removed',
			version_compare($to, $from, '==') => 'unchanged',
			$pinnedTo !== null && !version_compare($pinnedTo, $to, '==') => 'pinned',
			version_compare($to, $from, '<') => 'downgrade',
			default => 'upgrade',
		};

		return ['appId' => $appId, 'kind' => $kind, 'from' => $from, 'to' => $to, 'sourceId' => $target['sourceId'], 'pinnedTo' => $pinnedTo];
	}

	/**
	 * @param list<RollbackStep> $steps
	 * @param array<string, string> $lastChangedAt
	 * @return list<RollbackStep>
	 */
	private static function ordered(array $steps, array $lastChangedAt): array {
		usort($steps, static function (array $a, array $b) use ($lastChangedAt): int {
			return self::sortKey($a, $lastChangedAt) <=> self::sortKey($b, $lastChangedAt);
		});

		return $steps;
	}

	/**
	 * A key that sorts moves first (newest change first, then unrecorded ones
	 * by app id), then the other kinds.
	 *
	 * @param RollbackStep $step
	 * @param array<string, string> $lastChangedAt
	 * @return array{0: int, 1: int, 2: int, 3: string}
	 */
	private static function sortKey(array $step, array $lastChangedAt): array {
		if ($step['kind'] !== 'downgrade' && $step['kind'] !== 'upgrade') {
			return [2, self::TRAILING_ORDER[$step['kind']], 0, $step['appId']];
		}
		$changed = isset($lastChangedAt[$step['appId']]) ? strtotime($lastChangedAt[$step['appId']]) : false;
		if ($changed === false) {
			return [1, 0, 0, $step['appId']];
		}

		return [0, 0, -$changed, $step['appId']];
	}
}
