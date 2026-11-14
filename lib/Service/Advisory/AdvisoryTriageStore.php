<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Advisory;

use DateTimeImmutable;
use Exception;
use OCA\Versioniq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Stores the triage of advisories (dismissals and owners) next to the
 * advisory snapshot, never inside it (advisories-triage, design D1).
 *
 * One app config key, `advisory.triage`, holds a JSON map keyed
 * `{appId}|{advisoryId}` (a `|` because `:server` already holds a colon).
 * The sweep keeps writing the raw snapshot; {@see AdvisoryTriage::apply()}
 * reads both. An entry carries `lastSeenAt`, stamped while its advisory is
 * still in the snapshot, so an entry for an advisory that left the feed is
 * pruned once it has been unseen for longer than {@see PRUNE_AFTER_DAYS}.
 *
 * @spec openspec/changes/advisories-triage/specs/security-advisory-correlation/spec.md#requirement-an-admin-dismisses-an-advisory-with-a-recorded-reason
 * @spec openspec/changes/advisories-triage/specs/security-advisory-correlation/spec.md#requirement-an-admin-assigns-an-advisory-to-an-owner
 *
 * @psalm-api
 */
class AdvisoryTriageStore {
	/**
	 * App config key holding the JSON-encoded triage map.
	 */
	public const KEY = 'advisory.triage';

	/**
	 * Days an entry may go unseen in the snapshot before it is pruned.
	 */
	public const PRUNE_AFTER_DAYS = 30;

	public function __construct(
		private IAppConfig $config,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The key of one app's advisory in the triage map.
	 *
	 * @spec openspec/changes/advisories-triage/specs/security-advisory-correlation/spec.md#requirement-an-admin-dismisses-an-advisory-with-a-recorded-reason
	 */
	public static function key(string $appId, string $advisoryId): string {
		return $appId . '|' . $advisoryId;
	}

	/**
	 * The triage map; empty when nothing is stored or what is stored is not a
	 * JSON object, so a damaged value hides nothing it should not.
	 *
	 * @spec openspec/changes/advisories-triage/specs/security-advisory-correlation/spec.md#requirement-an-admin-dismisses-an-advisory-with-a-recorded-reason
	 * @return array<string, array<string, mixed>>
	 */
	public function read(): array {
		$raw = $this->config->getValueString(Application::APP_ID, self::KEY, '');
		if ($raw === '') {
			return [];
		}

		try {
			$decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException $error) {
			$this->logger->warning('AdvisoryTriageStore: stored triage is not valid JSON; reading it as empty', [
				'message' => $error->getMessage(),
			]);

			return [];
		}

		if (is_array($decoded) === false || ($decoded !== [] && array_is_list($decoded) === true)) {
			return [];
		}

		return array_filter($decoded, static fn (mixed $entry): bool => is_array($entry));
	}

	/**
	 * Store the whole triage map.
	 *
	 * @spec openspec/changes/advisories-triage/specs/security-advisory-correlation/spec.md#requirement-an-admin-dismisses-an-advisory-with-a-recorded-reason
	 * @param array<string, array<string, mixed>> $triage
	 * @throws \JsonException When the map cannot be encoded.
	 */
	public function write(array $triage): void {
		$this->config->setValueString(Application::APP_ID, self::KEY, json_encode((object)$triage, JSON_THROW_ON_ERROR));
	}

	/**
	 * Mark the entries whose advisory is in the current snapshot as seen now,
	 * then drop the entries unseen for longer than {@see PRUNE_AFTER_DAYS}.
	 * An entry never stamped counts from when it was made.
	 *
	 * @spec openspec/changes/advisories-triage/specs/security-advisory-correlation/spec.md#requirement-an-admin-dismisses-an-advisory-with-a-recorded-reason
	 * @param list<string> $seenKeys The `{appId}|{advisoryId}` keys of the snapshot.
	 * @param string $now ISO 8601 time of the sweep.
	 * @return int How many entries were pruned.
	 * @throws \JsonException When the map cannot be encoded.
	 */
	public function stampAndPrune(array $seenKeys, string $now): int {
		$triage = $this->read();
		if ($triage === []) {
			return 0;
		}

		$seen = array_flip($seenKeys);
		$cutoff = (new DateTimeImmutable($now))->modify('-' . self::PRUNE_AFTER_DAYS . ' days');
		$kept = [];
		foreach ($triage as $key => $entry) {
			if (isset($seen[$key]) === true) {
				$entry['lastSeenAt'] = $now;
				$kept[$key] = $entry;
				continue;
			}

			$lastSeen = self::time($entry['lastSeenAt'] ?? $entry['assignedAt'] ?? ($entry['dismissal']['at'] ?? null));
			if ($lastSeen === null || $lastSeen >= $cutoff) {
				$kept[$key] = $entry;
			}
		}

		$this->write($kept);

		return count($triage) - count($kept);
	}

	/**
	 * Drop the entries unseen for longer than {@see PRUNE_AFTER_DAYS}, stamping nothing.
	 *
	 * @spec openspec/changes/advisories-triage/specs/security-advisory-correlation/spec.md#requirement-an-admin-dismisses-an-advisory-with-a-recorded-reason
	 * @return int How many entries were pruned.
	 * @throws \JsonException When the map cannot be encoded.
	 */
	public function prune(string $now): int {
		return $this->stampAndPrune([], $now);
	}

	/**
	 * A stored time, or null when it is missing or not a time.
	 */
	private static function time(mixed $value): ?DateTimeImmutable {
		if (is_string($value) === false || $value === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (Exception) {
			return null;
		}
	}
}
