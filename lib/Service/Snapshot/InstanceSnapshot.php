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

	public readonly string $id;
	public readonly string $takenAt;
	public readonly string $takenBy;
	public readonly string $reason;
	public readonly ?string $name;

	/**
	 * @param array{id?: string, takenAt?: string, takenBy?: string, reason?: string, name?: ?string} $meta
	 *        Who took the snapshot, when, why and, optionally, its name.
	 * @param array<string, array{version: string, sourceId: ?string, enabled: bool}> $apps
	 * @throws \InvalidArgumentException without an id, a time or who took it, or with an unknown reason
	 */
	public function __construct(array $meta, public readonly array $apps) {
		$this->id = (string)($meta['id'] ?? '');
		$this->takenAt = (string)($meta['takenAt'] ?? '');
		$this->takenBy = (string)($meta['takenBy'] ?? '');
		$this->reason = (string)($meta['reason'] ?? '');
		$this->name = self::stringOrNull($meta['name'] ?? null);
		$this->assertValid();
	}

	/**
	 * The snapshot of the apps `InstallerService::getInstalledApps()` lists:
	 * every app with a state other than `notInstalled` and a known version.
	 *
	 * @param array{id?: string, takenAt?: string, takenBy?: string, reason?: string, name?: ?string} $meta
	 * @param list<array<string, mixed>> $installedApps The app cards.
	 * @spec openspec/changes/install-bulk-rollback/specs/migration-safety/spec.md
	 */
	public static function fromInstalledApps(array $meta, array $installedApps): self {
		$apps = [];
		foreach ($installedApps as $card) {
			$apps = array_replace($apps, self::installedEntry($card));
		}
		ksort($apps);

		return new self($meta, $apps);
	}

	/**
	 * @param array<array-key, mixed> $payload
	 * @throws \InvalidArgumentException on a payload that is not a snapshot
	 * @spec openspec/changes/install-bulk-rollback/specs/migration-safety/spec.md
	 */
	public static function fromArray(array $payload): self {
		$apps = [];
		foreach ((array)($payload['apps'] ?? []) as $appId => $app) {
			$apps[$appId] = self::storedApp($appId, $app);
		}
		$name = self::stringOrNull($payload['name'] ?? null);
		if ($name === '') {
			$name = null;
		}

		return new self(
			[
				'id' => (string)($payload['id'] ?? ''),
				'takenAt' => (string)($payload['takenAt'] ?? ''),
				'takenBy' => (string)($payload['takenBy'] ?? ''),
				'reason' => (string)($payload['reason'] ?? ''),
				'name' => $name,
			],
			$apps,
		);
	}

	/**
	 * The installed version on an app card: the card's version when its state
	 * is not `notInstalled` and the version is a non-empty string, else null.
	 *
	 * @param array<array-key, mixed> $card An app card of `InstallerService::getInstalledApps()`.
	 * @spec openspec/changes/install-bulk-rollback/specs/migration-safety/spec.md
	 */
	public static function installedVersion(array $card): ?string {
		$version = $card['installedVersion'] ?? null;
		if (($card['state'] ?? 'notInstalled') === 'notInstalled' || !is_string($version)) {
			return null;
		}

		return $version === '' ? null : $version;
	}

	/**
	 * The value when it is a string, else null.
	 * @spec openspec/changes/install-bulk-rollback/specs/migration-safety/spec.md
	 */
	public static function stringOrNull(mixed $value): ?string {
		return is_string($value) ? $value : null;
	}

	/**
	 * One app card as a snapshot entry keyed by app id, or [] for an app without an id or an installed version.
	 *
	 * @param array<array-key, mixed> $card
	 * @return array<string, array{version: string, sourceId: ?string, enabled: bool}>
	 */
	private static function installedEntry(array $card): array {
		$appId = (string)($card['id'] ?? '');
		$version = self::installedVersion($card);
		if ($appId === '' || $version === null) {
			return [];
		}

		return [$appId => [
			'version' => $version,
			'sourceId' => self::stringOrNull($card['boundSourceId'] ?? null),
			'enabled' => ($card['state'] ?? '') === 'enabled',
		]];
	}

	/**
	 * One stored app entry.
	 *
	 * @throws \InvalidArgumentException on an entry without a string app id and version
	 * @return array{version: string, sourceId: ?string, enabled: bool}
	 */
	private static function storedApp(mixed $appId, mixed $app): array {
		if (!is_string($appId) || !is_array($app) || !is_string($app['version'] ?? null)) {
			throw new \InvalidArgumentException('Malformed app entry in snapshot');
		}

		return [
			'version' => $app['version'],
			'sourceId' => self::stringOrNull($app['sourceId'] ?? null),
			'enabled' => (bool)($app['enabled'] ?? false),
		];
	}

	/**
	 * @throws \InvalidArgumentException without an id, a time or who took it, or with an unknown reason
	 */
	private function assertValid(): void {
		if ($this->id === '' || $this->takenAt === '' || $this->takenBy === '') {
			throw new \InvalidArgumentException('A snapshot needs an id, a time and who took it');
		}
		if (!in_array($this->reason, self::REASONS, true)) {
			throw new \InvalidArgumentException('Unknown snapshot reason: ' . $this->reason);
		}
	}

	/**
	 * @return array{id: string, takenAt: string, takenBy: string, reason: string, name: ?string, apps: array<string, array{version: string, sourceId: ?string, enabled: bool}>}
	 * @spec openspec/changes/install-bulk-rollback/specs/migration-safety/spec.md
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
	 * @spec openspec/changes/install-bulk-rollback/specs/migration-safety/spec.md
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
