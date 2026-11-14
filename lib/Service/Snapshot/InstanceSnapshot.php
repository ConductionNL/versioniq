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
 * What every managed app ran at one moment: per app id the installed
 * version, the bound source and whether it was enabled (install-bulk-rollback
 * design D1).
 *
 * @spec openspec/changes/install-bulk-rollback/specs/migration-safety/spec.md
 * @psalm-api
 */
final class InstanceSnapshot {
	/** The moments a snapshot is taken (design D2). */
	public const REASONS = ['daily', 'before_auto_update', 'manual', 'before_rollback'];

	/**
	 * @param array<string, array{version: string, sourceId: ?string, enabled: bool}> $apps
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $takenAt,
		public readonly string $takenBy,
		public readonly string $reason,
		public readonly array $apps,
		public readonly ?string $name = null,
	) {
		if ($id === '' || $takenAt === '' || $takenBy === '') {
			throw new \InvalidArgumentException('A snapshot needs an id, a time and who took it');
		}
		if (!in_array($reason, self::REASONS, true)) {
			throw new \InvalidArgumentException('Unknown snapshot reason: ' . $reason);
		}
	}

	/**
	 * The snapshot of the apps `InstallerService::getInstalledApps()` lists:
	 * every app with a state other than `notInstalled` and a known version.
	 *
	 * @param list<array<string, mixed>> $installedApps The app cards.
	 * @spec openspec/changes/install-bulk-rollback/specs/migration-safety/spec.md
	 */
	public static function fromInstalledApps(string $id, string $takenAt, string $takenBy, string $reason, array $installedApps, ?string $name = null): self {
		$apps = [];
		foreach ($installedApps as $card) {
			$appId = (string)($card['id'] ?? '');
			$version = $card['installedVersion'] ?? null;
			if ($appId === '' || ($card['state'] ?? 'notInstalled') === 'notInstalled' || !is_string($version) || $version === '') {
				continue;
			}
			$apps[$appId] = [
				'version' => $version,
				'sourceId' => isset($card['boundSourceId']) && is_string($card['boundSourceId']) ? $card['boundSourceId'] : null,
				'enabled' => ($card['state'] ?? '') === 'enabled',
			];
		}
		ksort($apps);

		return new self($id, $takenAt, $takenBy, $reason, $apps, $name);
	}

	/**
	 * @param array<array-key, mixed> $payload
	 * @throws \InvalidArgumentException on a payload that is not a snapshot
	 */
	public static function fromArray(array $payload): self {
		$apps = [];
		foreach ((array)($payload['apps'] ?? []) as $appId => $app) {
			if (!is_string($appId) || !is_array($app) || !is_string($app['version'] ?? null)) {
				throw new \InvalidArgumentException('Malformed app entry in snapshot');
			}
			$apps[$appId] = [
				'version' => $app['version'],
				'sourceId' => is_string($app['sourceId'] ?? null) ? $app['sourceId'] : null,
				'enabled' => (bool)($app['enabled'] ?? false),
			];
		}
		$name = $payload['name'] ?? null;

		return new self(
			(string)($payload['id'] ?? ''),
			(string)($payload['takenAt'] ?? ''),
			(string)($payload['takenBy'] ?? ''),
			(string)($payload['reason'] ?? ''),
			$apps,
			is_string($name) && $name !== '' ? $name : null,
		);
	}

	/**
	 * @return array{id: string, takenAt: string, takenBy: string, reason: string, name: ?string, apps: array<string, array{version: string, sourceId: ?string, enabled: bool}>}
	 */
	public function toArray(): array {
		return [
			'id' => $this->id,
			'takenAt' => $this->takenAt,
			'takenBy' => $this->takenBy,
			'reason' => $this->reason,
			'name' => $this->name,
			'apps' => $this->apps,
		];
	}

	/**
	 * The index line of this snapshot.
	 *
	 * @return array{id: string, takenAt: string, reason: string, name: ?string, appCount: int}
	 */
	public function summary(): array {
		return [
			'id' => $this->id,
			'takenAt' => $this->takenAt,
			'reason' => $this->reason,
			'name' => $this->name,
			'appCount' => count($this->apps),
		];
	}
}
