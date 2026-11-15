<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Service\Lkg;

use OCA\Versioniq\Service\Settings\AppConfigJsonRecords;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Reads and writes the per-app last-known-good version record stored under
 * app config key `lkg.{appId}`. JSON; a missing or malformed value is
 * treated as "no record yet" (logged, never fatal) — mirrors
 * {@see \OCA\Versioniq\Service\Pin\PinStore}.
 *
 * `set()` is called exactly once per successful finalize, from
 * {@see \OCA\Versioniq\Service\Installer\InstallFinalizer::finalize()} —
 * the single choke point shared by both installers — so a failed or
 * reverted install never touches the record; see "Last-known-good version
 * record".
 *
 * @spec openspec/specs/migration-safety/spec.md
 * @psalm-api
 */
class LkgStore {
	/** @use AppConfigJsonRecords<Lkg> */
	use AppConfigJsonRecords;

	private const KEY_PREFIX = 'lkg.';
	private const JSON_DEPTH = 16;
	private const MALFORMED_LOG = 'LkgStore: malformed last-known-good JSON, treating as absent';
	private const INVALID_LOG = 'LkgStore: invalid last-known-good payload, treating as absent';

	public function __construct(
		private IAppConfig $config,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Reads the persisted last-known-good record for an app (null if none/invalid); see
	 * "Last-known-good version record".
	 *
	 * @spec openspec/specs/migration-safety/spec.md
	 */
	public function get(string $appId): ?Lkg {
		return $this->readRecord($appId);
	}

	/**
	 * Persists the last-known-good record after a successful finalize; see
	 * "Last-known-good version record".
	 *
	 * @spec openspec/specs/migration-safety/spec.md
	 */
	public function set(string $appId, Lkg $lkg): void {
		$this->writeRecord($appId, $lkg->toArray());
	}

	/**
	 * @param array<array-key, mixed> $payload
	 */
	private function hydrate(array $payload): Lkg {
		return Lkg::fromArray($payload);
	}
}
