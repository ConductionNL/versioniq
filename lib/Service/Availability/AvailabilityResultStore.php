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

use OCA\Versioniq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Stores the last availability sweep, on the AdvisoryResultStore pattern: the
 * snapshot as JSON under one app config key, the unix time of the sweep under
 * another. `checkedAt: null` means no sweep has completed, which the page and
 * the CLI must say out loud rather than read as "nothing to update".
 *
 * @spec openspec/specs/pending-updates/spec.md#requirement-the-installed-version-and-pending-updates-are-swept-into-a-snapshot
 */
class AvailabilityResultStore {
	private const KEY = 'availability.results';

	private const KEY_CHECKED_AT = 'availability.results.checkedAt';

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

		$this->config->setValueString(Application::APP_ID, self::KEY, $encoded);
		$this->config->setValueInt(Application::APP_ID, self::KEY_CHECKED_AT, $checkedAt);
	}

	/**
	 * @spec openspec/specs/pending-updates/spec.md#requirement-the-installed-version-and-pending-updates-are-swept-into-a-snapshot
	 * @return array{updates: array<array-key, mixed>, checkedAt: ?int}
	 */
	public function read(): array {
		$raw = $this->config->getValueString(Application::APP_ID, self::KEY, '');
		if ($raw === '') {
			return ['updates' => [], 'checkedAt' => null];
		}

		try {
			$decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException $error) {
			$this->logger->warning('AvailabilityResultStore: the stored snapshot is not valid JSON; reporting it as never checked', [
				'message' => $error->getMessage(),
			]);

			return ['updates' => [], 'checkedAt' => null];
		}

		if (!is_array($decoded)) {
			return ['updates' => [], 'checkedAt' => null];
		}

		$checkedAt = $this->config->getValueInt(Application::APP_ID, self::KEY_CHECKED_AT, 0);

		return ['updates' => $decoded, 'checkedAt' => $checkedAt > 0 ? $checkedAt : null];
	}
}
