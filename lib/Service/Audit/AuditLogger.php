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
		string $actorUid,
		string $appId,
		string $operation,
		?string $fromVersion,
		?string $toVersion,
		?string $sourceId,
		string $status,
		?string $message = null,
	): void {
		if (preg_match(self::OPERATION_PATTERN, $operation) !== 1) {
			$this->logger->warning('AuditLogger: rejected malformed operation name, entry not recorded', [
				'appId' => $appId,
				'operation' => $operation,
			]);

			return;
		}

		try {
			$entry = new AuditEntry();
			$entry->setActorUid($actorUid);
			$entry->setAppId($appId);
			$entry->setOperation($operation);
			$entry->setFromVersion($fromVersion === '' ? null : $fromVersion);
			$entry->setToVersion($toVersion === '' ? null : $toVersion);
			$entry->setSourceId($sourceId === '' ? null : $sourceId);
			$entry->setStatus($status);
			$entry->setMessage($this->sanitizeMessage($message));
			$entry->setCreatedAt($this->timeFactory->getDateTime('now', new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'));

			$this->mapper->insert($entry);
		} catch (\Throwable $error) {
			// Best-effort: never let an audit-write failure propagate into the
			// caller (an install / bind operation), which would turn a logging
			// bug into an outage. See design.md "Write path: best-effort by
			// construction".
			$this->logger->error('AuditLogger: failed to record audit entry', [
				'appId' => $appId,
				'operation' => $operation,
				'message' => $error->getMessage(),
			]);
		}
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
