<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Service\Pin;

use OCA\Versioniq\AppInfo\Application;
use OCA\Versioniq\Service\Audit\AuditedVersions;
use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\Audit\AuditSubject;
use OCA\Versioniq\Service\Settings\AppConfigJsonRecords;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Reads and writes per-app pins stored under app config key `pin.{appId}`.
 * Pins are JSON; a missing or malformed value is treated as "not pinned"
 * (logged, never fatal) — mirrors
 * {@see \OCA\Versioniq\Service\Source\SourceBindingStore}.
 *
 * `set()`/`clear()`/`markDrift()` are the only write paths and each records
 * exactly one audit entry (`pin` / `unpin` / `pin_drift`), so every caller —
 * the pin API, the install-path guard's override handling, and the drift
 * handler — gets audit coverage for free.
 *
 * @psalm-api
 */
class PinStore {
	/** @use AppConfigJsonRecords<Pin> */
	use AppConfigJsonRecords;

	private const KEY_PREFIX = 'pin.';
	private const JSON_DEPTH = 16;
	private const MALFORMED_LOG = 'PinStore: malformed pin JSON, treating as unpinned';
	private const INVALID_LOG = 'PinStore: invalid pin payload, treating as unpinned';

	public function __construct(
		private IAppConfig $config,
		private AuditLogger $auditLogger,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Reads the persisted pin for an app (null if unpinned/invalid); see "Pin an installed app to its current version".
	 *
	 * @spec openspec/specs/version-pinning/spec.md
	 */
	public function get(string $appId): ?Pin {
		return $this->readRecord($appId);
	}

	/**
	 * Returns every persisted pin, keyed by app id; see "Honest pin presentation".
	 *
	 * @spec openspec/specs/version-pinning/spec.md
	 * @return array<string, Pin>
	 */
	public function all(): array {
		return $this->readAllRecords();
	}

	/**
	 * Persists a fresh pin (drift markers cleared) and records a `pin` audit
	 * entry; see "Pin an installed app to its current version" and "Pin
	 * lifecycle operations are audited".
	 *
	 * @spec openspec/specs/version-pinning/spec.md
	 * @spec openspec/specs/audit-trail/spec.md
	 */
	public function set(string $appId, Pin $pin): void {
		$this->writeRecord($appId, $pin->withoutDrift()->toArray());

		$this->auditLogger->record(
			new AuditSubject($pin->pinnedBy, $appId, AuditLogger::OPERATION_PIN),
			new AuditedVersions(null, $pin->version, null),
			AuditLogger::STATUS_SUCCESS,
			$pin->reason,
		);
	}

	/**
	 * Removes an app's pin and records an `unpin` audit entry (a no-op, no
	 * audit entry, when no pin exists); see "Unpin" and "Pin lifecycle
	 * operations are audited".
	 *
	 * @spec openspec/specs/version-pinning/spec.md
	 * @spec openspec/specs/audit-trail/spec.md
	 */
	public function clear(string $appId, string $actorUid): void {
		$previous = $this->get($appId);
		if ($previous === null) {
			return;
		}

		$this->config->deleteKey(Application::APP_ID, $this->key($appId));

		$this->auditLogger->record(
			new AuditSubject($actorUid, $appId, AuditLogger::OPERATION_UNPIN),
			new AuditedVersions($previous->version, null, null),
			AuditLogger::STATUS_SUCCESS,
			null,
		);
	}

	/**
	 * Records newly-detected drift on an existing pin (idempotent per
	 * drifted version — a repeated call with the same drift version is a
	 * no-op) and, on a genuine new drift, records a `pin_drift` audit entry
	 * with `actor_uid=system`; see "Drift detection" and "Drift handled once
	 * per version". Returns true when this call newly recorded drift.
	 *
	 * @spec openspec/specs/version-pinning/spec.md
	 * @spec openspec/specs/audit-trail/spec.md
	 */
	public function markDrift(string $appId, PinDrift $drift): bool {
		$pin = $this->get($appId);
		if ($pin === null || $pin->driftedTo === $drift->version) {
			// No pin, or drift already recorded for this exact version: idempotent no-op.
			return false;
		}

		$this->recordDrift($appId, $pin->withDrift($drift->version, $drift->observedAt));

		return true;
	}

	/**
	 * Persists a pin with its drift markers set and records the `pin_drift`
	 * audit entry, as actor `system`.
	 */
	private function recordDrift(string $appId, Pin $drifted): void {
		$this->writeRecord($appId, $drifted->toArray());

		$this->auditLogger->record(
			new AuditSubject('system', $appId, AuditLogger::OPERATION_PIN_DRIFT),
			new AuditedVersions($drifted->version, $drifted->driftedTo, null),
			AuditLogger::STATUS_SUCCESS,
			null,
		);
	}

	/**
	 * @param array<array-key, mixed> $payload
	 */
	private function hydrate(array $payload): Pin {
		return Pin::fromArray($payload);
	}
}
