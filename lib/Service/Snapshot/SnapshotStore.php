<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Snapshot;

use OCA\Versioniq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Stores instance snapshots as JSON under the lazy app config key
 * `snapshot.{id}`, with an index under `snapshot.index` (install-bulk-rollback
 * design D1). Keeps the newest 60 unnamed snapshots and at most 20 named ones;
 * a named snapshot stays until an admin deletes it. A missing or malformed
 * value reads as absent, never fatal.
 *
 * @spec openspec/changes/install-bulk-rollback/specs/migration-safety/spec.md
 * @psalm-api
 */
class SnapshotStore {
	public const UNNAMED_CAP = 60;
	public const NAMED_CAP = 20;
	private const KEY_PREFIX = 'snapshot.';
	private const INDEX_KEY = 'snapshot.index';
	private const JSON_DEPTH = 16;

	public function __construct(
		private IAppConfig $config,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Store a snapshot and prune the oldest unnamed ones past the cap.
	 *
	 * @throws \OverflowException when a named snapshot would pass the named cap
	 * @spec openspec/changes/install-bulk-rollback/specs/migration-safety/spec.md
	 */
	public function save(InstanceSnapshot $snapshot): void {
		$index = array_values(array_filter($this->index(), fn (array $line): bool => $line['id'] !== $snapshot->id));
		if ($snapshot->name !== null && count(array_filter($index, fn (array $line): bool => $line['name'] !== null)) >= self::NAMED_CAP) {
			throw new \OverflowException('At most ' . self::NAMED_CAP . ' named snapshots are kept; delete one first');
		}

		$this->config->setValueString(Application::APP_ID, self::KEY_PREFIX . $snapshot->id, json_encode($snapshot->toArray(), JSON_THROW_ON_ERROR), lazy: true);
		$index[] = $snapshot->summary();
		usort($index, fn (array $a, array $b): int => [$b['takenAt'], $b['id']] <=> [$a['takenAt'], $a['id']]);

		$this->writeIndex($this->pruned($index));
	}

	/**
	 * A snapshot by id, or null when absent or malformed.
	 *
	 * @spec openspec/changes/install-bulk-rollback/specs/migration-safety/spec.md
	 */
	public function get(string $id): ?InstanceSnapshot {
		if ($id === '' || $id === 'index') {
			return null;
		}
		$raw = $this->config->getValueString(Application::APP_ID, self::KEY_PREFIX . $id, '', lazy: true);
		if ($raw === '') {
			return null;
		}
		try {
			$payload = json_decode($raw, true, self::JSON_DEPTH, JSON_THROW_ON_ERROR);

			return is_array($payload) ? InstanceSnapshot::fromArray($payload) : null;
		} catch (\JsonException|\InvalidArgumentException $error) {
			$this->logger->warning('SnapshotStore: malformed snapshot, treating as absent', ['id' => $id, 'exception' => $error]);

			return null;
		}
	}

	/**
	 * The index, newest first.
	 *
	 * @return list<array{id: string, takenAt: string, reason: string, name: ?string, appCount: int}>
	 * @spec openspec/changes/install-bulk-rollback/specs/migration-safety/spec.md
	 */
	public function index(): array {
		$raw = $this->config->getValueString(Application::APP_ID, self::INDEX_KEY, '', lazy: true);
		if ($raw === '') {
			return [];
		}
		try {
			$lines = json_decode($raw, true, self::JSON_DEPTH, JSON_THROW_ON_ERROR);
		} catch (\JsonException $error) {
			$this->logger->warning('SnapshotStore: malformed snapshot index, treating as empty', ['exception' => $error]);

			return [];
		}

		return array_values(array_filter(array_map(self::indexLine(...), is_array($lines) ? $lines : [])));
	}

	/**
	 * The newest snapshot taken on or before a moment, or null.
	 *
	 * @spec openspec/changes/install-bulk-rollback/specs/migration-safety/spec.md
	 */
	public function latestAtOrBefore(\DateTimeImmutable $moment): ?InstanceSnapshot {
		foreach ($this->index() as $line) {
			$takenAt = \DateTimeImmutable::createFromFormat(DATE_ATOM, $line['takenAt']);
			if ($takenAt !== false && $takenAt <= $moment) {
				return $this->get($line['id']);
			}
		}

		return null;
	}

	/**
	 * Delete a snapshot; true when it existed.
	 *
	 * @spec openspec/changes/install-bulk-rollback/specs/migration-safety/spec.md
	 */
	public function delete(string $id): bool {
		$index = $this->index();
		$kept = array_values(array_filter($index, fn (array $line): bool => $line['id'] !== $id));
		if (count($kept) === count($index)) {
			return false;
		}
		$this->config->deleteKey(Application::APP_ID, self::KEY_PREFIX . $id);
		$this->writeIndex($kept);

		return true;
	}

	/**
	 * Drop the unnamed snapshots past the cap, oldest first; named ones stay.
	 *
	 * @param list<array{id: string, takenAt: string, reason: string, name: ?string, appCount: int}> $index Newest first.
	 * @return list<array{id: string, takenAt: string, reason: string, name: ?string, appCount: int}>
	 */
	private function pruned(array $index): array {
		$kept = [];
		$unnamed = 0;
		foreach ($index as $line) {
			if ($line['name'] === null && ++$unnamed > self::UNNAMED_CAP) {
				$this->config->deleteKey(Application::APP_ID, self::KEY_PREFIX . $line['id']);
				continue;
			}
			$kept[] = $line;
		}

		return $kept;
	}

	/**
	 * @param list<array{id: string, takenAt: string, reason: string, name: ?string, appCount: int}> $index
	 */
	private function writeIndex(array $index): void {
		$this->config->setValueString(Application::APP_ID, self::INDEX_KEY, json_encode($index, JSON_THROW_ON_ERROR), lazy: true);
	}

	/**
	 * @return array{id: string, takenAt: string, reason: string, name: ?string, appCount: int}|null
	 */
	private static function indexLine(mixed $line): ?array {
		if (!is_array($line) || !is_string($line['id'] ?? null) || !is_string($line['takenAt'] ?? null)) {
			return null;
		}

		return [
			'id' => $line['id'],
			'takenAt' => $line['takenAt'],
			'reason' => (string)($line['reason'] ?? ''),
			'name' => is_string($line['name'] ?? null) ? $line['name'] : null,
			'appCount' => (int)($line['appCount'] ?? 0),
		];
	}
}
