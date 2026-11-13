<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Audit;

use OCA\Versioniq\Service\Source\SourceBindingStore;
use OCP\App\IAppManager;
use OCP\IUserSession;

/**
 * Writes the history rows for changes Versioniq did not make: a version that
 * changed (`external_update`), an app switched off on purpose
 * (`external_disable`), and an app Versioniq switched back on after an update
 * left it off (`reactivate`).
 *
 * @psalm-api
 */
class ExternalChangeRecorder {
	/** The actor of a change no signed-in user made. */
	public const SYSTEM = 'system';

	/** The trigger of a change the hourly comparison found. */
	public const HOURLY_TRIGGER = 'found by the hourly check: no update event was seen';

	public function __construct(
		private SeenStateStore $seenState,
		private AuditLogger $auditLogger,
		private IUserSession $userSession,
		private SourceBindingStore $bindings,
		private TriggerDescriber $triggers,
		private IAppManager $appManager,
	) {
	}

	/**
	 * Records a version change Nextcloud reported, when it differs from what
	 * was last seen, and remembers the new version. Nothing is recorded before
	 * the first hourly check wrote a baseline.
	 *
	 * @spec openspec/changes/audit-external-changes
	 * @return bool True when a row was written.
	 */
	public function versionChanged(string $appId, string $newVersion): bool {
		$state = $this->seenState->read();
		if ($state === null) {
			return false;
		}
		$seen = $state['apps'][$appId] ?? null;
		if ($seen !== null && $seen['version'] === $newVersion) {
			return false;
		}

		$this->recordUpdate($appId, $seen['version'] ?? null, $newVersion, $this->triggers->describe(), $this->actor());
		$this->seenState->rememberApp($appId, $newVersion, $seen['enabled'] ?? $this->appManager->isEnabledForAnyone($appId));

		return true;
	}

	/**
	 * Writes one `external_update` row; a null version is an app that appeared or went away.
	 *
	 * @spec openspec/changes/audit-external-changes
	 */
	public function recordUpdate(string $appId, ?string $fromVersion, ?string $toVersion, string $trigger, string $actor): void {
		$this->auditLogger->record(
			new AuditSubject($actor, $appId, AuditLogger::OPERATION_EXTERNAL_UPDATE),
			new AuditedVersions($fromVersion, $toVersion, $this->sourceId($appId)),
			AuditLogger::STATUS_SUCCESS,
			$trigger,
		);
	}

	/**
	 * Writes one `external_disable` row: an app switched off without a version change.
	 *
	 * @spec openspec/changes/audit-external-changes
	 */
	public function recordDisable(string $appId, string $version, string $trigger, string $actor): void {
		$this->auditLogger->record(
			new AuditSubject($actor, $appId, AuditLogger::OPERATION_EXTERNAL_DISABLE),
			new AuditedVersions($version, $version, $this->sourceId($appId)),
			AuditLogger::STATUS_SUCCESS,
			$trigger,
		);
	}

	/**
	 * Writes one `reactivate` row with its outcome.
	 *
	 * @spec openspec/changes/audit-external-changes
	 */
	public function recordReactivate(string $appId, string $version, bool $succeeded, string $message): void {
		$this->auditLogger->record(
			new AuditSubject(self::SYSTEM, $appId, AuditLogger::OPERATION_REACTIVATE),
			new AuditedVersions(null, $version, $this->sourceId($appId)),
			$succeeded ? AuditLogger::STATUS_SUCCESS : AuditLogger::STATUS_FAILURE,
			$message,
		);
	}

	/**
	 * The signed-in user's uid, or `system`.
	 *
	 * @spec openspec/changes/audit-external-changes
	 */
	public function actor(): string {
		return $this->userSession->getUser()?->getUID() ?? self::SYSTEM;
	}

	/**
	 * The trigger of the current process.
	 *
	 * @spec openspec/changes/audit-external-changes
	 */
	public function trigger(): string {
		return $this->triggers->describe();
	}

	/**
	 * The app's bound source, or null for the App Store.
	 */
	private function sourceId(string $appId): ?string {
		$binding = $this->bindings->get($appId);
		if ($binding === null) {
			return null;
		}
		$id = $binding->getId();

		return ($id === '' || $id === 'appstore') ? null : $id;
	}
}
