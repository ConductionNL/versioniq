<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Service\Audit;

use OCA\Versioniq\Db\AuditEntry;
use OCA\Versioniq\Db\AuditEntryMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;

/**
 * Single write path for the audit trail. Best-effort by construction: the
 * installers' backup-and-restore flow is the most security-critical code in
 * this app, so a failing audit INSERT (table missing mid-upgrade, DB hiccup)
 * must never abort or roll back an otherwise successful (or failing) install.
 * Failures are logged via {@see LoggerInterface::error} and swallowed.
 *
 * @psalm-api
 */
class AuditLogger {
	public const STATUS_SUCCESS = 'success';
	public const STATUS_FAILURE = 'failure';

	public const OPERATION_INSTALL = 'install';
	public const OPERATION_BIND_SOURCE = 'bind_source';
	/** An app's source binding removed; the app falls back to the App Store (sources-trust-audit-and-offline-feed). */
	public const OPERATION_UNBIND_SOURCE = 'unbind_source';
	/** A trusted-source pattern added (sources-trust-audit-and-offline-feed). */
	public const OPERATION_TRUST_SOURCE = 'trust_source';
	/** A trusted-source pattern removed (sources-trust-audit-and-offline-feed). */
	public const OPERATION_UNTRUST_SOURCE = 'untrust_source';
	public const OPERATION_PIN = 'pin';
	public const OPERATION_UNPIN = 'unpin';
	public const OPERATION_PIN_DRIFT = 'pin_drift';
	/** An instance setting changed from the Settings tab (#438). */
	public const OPERATION_SETTINGS = 'settings';

	private const OPERATION_PATTERN = '/^[a-z_]{1,32}$/';
	private const MESSAGE_MAX_LENGTH = 4000;

	public function __construct(
		private AuditEntryMapper $mapper,
		private ITimeFactory $timeFactory,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Records one audit entry; see "Version operations are recorded",
	 * "Source binding changes are recorded", and "Pin lifecycle operations
	 * are audited" (pin / unpin / pin_drift).
	 *
	 * @spec openspec/specs/audit-trail/spec.md
	 * @spec openspec/specs/version-pinning/spec.md
	 */
	public function record(
		AuditSubject $subject,
		AuditedVersions $versions,
		string $status,
		?string $message = null,
	): void {
		if ($this->rejectsOperation($subject)) {
			return;
		}

		try {
			$entry = $this->entryFor($subject, $versions);
			$entry->setStatus($status);
			$entry->setMessage($this->sanitizeMessage($message));
			$entry->setCreatedAt($this->timeFactory->getDateTime('now', new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'));

			$this->mapper->insert($entry);
		} catch (\Throwable $error) {
			// Best-effort: never let an audit-write failure propagate into the
			// caller (an install / bind operation), which would turn a logging
			// bug into an outage. See design.md "Write path: best-effort by
			// construction".
			$this->logWriteFailure($subject, $error);
		}
	}

	/**
	 * Whether the operation name is malformed; such an entry is logged and
	 * not recorded.
	 */
	private function rejectsOperation(AuditSubject $subject): bool {
		if (preg_match(self::OPERATION_PATTERN, $subject->operation) === 1) {
			return false;
		}

		$this->logger->warning('AuditLogger: rejected malformed operation name, entry not recorded', [
			'appId' => $subject->appId,
			'operation' => $subject->operation,
		]);

		return true;
	}

	/**
	 * A new entry with who did what to which app, and the versions it went
	 * between; empty versions are stored as null.
	 */
	private function entryFor(AuditSubject $subject, AuditedVersions $versions): AuditEntry {
		$entry = new AuditEntry();
		$entry->setActorUid($subject->actorUid);
		$entry->setAppId($subject->appId);
		$entry->setOperation($subject->operation);
		$entry->setFromVersion($versions->fromVersion === '' ? null : $versions->fromVersion);
		$entry->setToVersion($versions->toVersion === '' ? null : $versions->toVersion);
		$entry->setSourceId($versions->sourceId === '' ? null : $versions->sourceId);

		return $entry;
	}

	private function logWriteFailure(AuditSubject $subject, \Throwable $error): void {
		$this->logger->error('AuditLogger: failed to record audit entry', [
			'appId' => $subject->appId,
			'operation' => $subject->operation,
			'message' => $error->getMessage(),
		]);
	}

	/**
	 * Redacts anything that looks like a bearer token / Authorization header
	 * (defence in depth on top of the write-path rule that secrets never flow
	 * into a message in the first place) and truncates to a sane bound.
	 */
	private function sanitizeMessage(?string $message): ?string {
		if ($message === null || $message === '') {
			return null;
		}

		$redacted = preg_replace('/Bearer\s+\S+/i', 'Bearer [redacted]', $message) ?? $message;
		$redacted = preg_replace('/Authorization:\s*\S+/i', 'Authorization: [redacted]', $redacted) ?? $redacted;

		if (mb_strlen($redacted) > self::MESSAGE_MAX_LENGTH) {
			$redacted = mb_substr($redacted, 0, self::MESSAGE_MAX_LENGTH);
		}

		return $redacted;
	}
}
