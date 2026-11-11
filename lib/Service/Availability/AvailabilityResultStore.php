<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Availability;

use OCA\Versioniq\Service\Settings\StoredSnapshot;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Stores the last availability sweep, on the AdvisoryResultStore pattern: the
 * snapshot as JSON under one app config key, the unix time of the sweep under
 * another. `checkedAt: null` means no sweep has completed, which the page and
 * the CLI must say out loud rather than read as "nothing to update".
 *
 * @spec openspec/specs/pending-updates/spec.md#requirement-the-installed-version-and-pending-updates-are-swept-into-a-snapshot
 *
 * @psalm-api
 */
class AvailabilityResultStore {
	/** The sweep time is held under `availability.results.checkedAt`; see {@see StoredSnapshot}. */
	private const KEY = 'availability.results';

	public function __construct(
		private IAppConfig $config,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Saves a snapshot; one that cannot be encoded keeps the previous one.
	 *
	 * @spec openspec/specs/pending-updates/spec.md#requirement-the-installed-version-and-pending-updates-are-swept-into-a-snapshot
	 * @param array<string, array<string, mixed>> $updates
	 */
	public function save(array $updates, int $checkedAt): void {
		try {
			$encoded = json_encode($updates, JSON_THROW_ON_ERROR);
		} catch (\JsonException $error) {
			$this->logger->error('AvailabilityResultStore: could not encode the snapshot; keeping the previous one', [
				'message' => $error->getMessage(),
			]);

			return;
		}

		$this->snapshot()->write($encoded, $checkedAt);
	}

	/**
	 * @spec openspec/specs/pending-updates/spec.md#requirement-the-installed-version-and-pending-updates-are-swept-into-a-snapshot
	 * @return array{updates: array<string, mixed>, checkedAt: ?int}
	 */
	public function read(): array {
		$snapshot = $this->snapshot();
		$raw = $snapshot->raw();
		if ($raw === '') {
			return ['updates' => [], 'checkedAt' => null];
		}

		try {
			$updates = StoredSnapshot::decodeMap($raw);
		} catch (\JsonException $error) {
			$this->logger->warning('AvailabilityResultStore: the stored snapshot is not valid JSON; reporting it as never checked', [
				'message' => $error->getMessage(),
			]);

			return ['updates' => [], 'checkedAt' => null];
		}

		if ($updates === null) {
			return ['updates' => [], 'checkedAt' => null];
		}

		return ['updates' => $updates, 'checkedAt' => $snapshot->checkedAt()];
	}

	private function snapshot(): StoredSnapshot {
		return new StoredSnapshot($this->config, self::KEY);
	}
}
