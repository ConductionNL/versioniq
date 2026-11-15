<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\BackgroundJob;

use OCA\Versioniq\AppInfo\Application;
use OCA\Versioniq\Db\AuditEntryMapper;
use OCA\Versioniq\Service\Installer\AppBackupStore;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Daily prune of audit entries older than the configured retention window;
 * see "Retention". Deletes in batches of 1000 to avoid a long-running
 * transaction on a large table.
 *
 * Registered via `<background-jobs>` in `appinfo/info.xml` — there is no
 * `IRegistrationContext::registerJob()` API for `TimedJob`s in this Nextcloud
 * version, only for `IJob`/`QueuedJob` via the coordinator; TimedJobs must be
 * declared in info.xml.
 *
 * @psalm-api
 */
class PruneAuditJob extends TimedJob {
	/** Config key under the versioniq app: retention window in days. */
	public const CONFIG_KEY_RETENTION_DAYS = 'audit_retention_days';

	public const DEFAULT_RETENTION_DAYS = 365;
	public const MINIMUM_RETENTION_DAYS = 30;

	private const BATCH_SIZE = 1000;
	private const INTERVAL_SECONDS = 24 * 60 * 60;

	public function __construct(
		ITimeFactory $time,
		private AuditEntryMapper $mapper,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
		// Optional: the install backups pruned in the same daily run (safety-rollback-backup-and-preflight D3).
		private ?AppBackupStore $backupStore = null,
	) {
		parent::__construct($time);
		$this->setInterval(self::INTERVAL_SECONDS);
	}

	/**
	 * Prunes audit entries older than `versioniq.audit_retention_days`
	 * (default 365, floor 30 — lower configured values are clamped and the
	 * clamp is logged); see "Retention".
	 *
	 * @spec openspec/specs/audit-trail/spec.md
	 * @param mixed $argument
	 */
	protected function run($argument): void {
		$this->pruneBackups();
		$retentionDays = $this->resolveRetentionDays();
		$cutoff = $this->time->getDateTime('now', new \DateTimeZone('UTC'))
			->modify(sprintf('-%d days', $retentionDays));
		if (!$cutoff instanceof \DateTime) {
			return;
		}
		$cutoffImmutable = \DateTimeImmutable::createFromMutable($cutoff);

		$totalDeleted = 0;
		try {
			do {
				$deleted = $this->mapper->deleteOlderThan($cutoffImmutable, self::BATCH_SIZE);
				$totalDeleted += $deleted;
			} while ($deleted === self::BATCH_SIZE);
		} catch (\Throwable $error) {
			$this->logger->error('PruneAuditJob: prune failed', ['message' => $error->getMessage()]);

			return;
		}

		if ($totalDeleted > 0) {
			$this->logger->info('PruneAuditJob: pruned audit entries', [
				'deleted' => $totalDeleted,
				'retentionDays' => $retentionDays,
			]);
		}
	}

	/**
	 * Reads the configured retention window and clamps it to the floor,
	 * logging when a clamp occurs; see "Retention floor is enforced".
	 */
	/**
	 * Removes the install backups whose keep time has passed. A failure is
	 * logged and does not stop the audit prune.
	 *
	 * @spec openspec/changes/safety-rollback-backup-and-preflight/specs/migration-safety/spec.md#requirement-a-real-install-keeps-a-restorable-backup-of-the-app-folder-and-its-tables
	 */
	private function pruneBackups(): void {
		if ($this->backupStore === null) {
			return;
		}
		try {
			$removed = $this->backupStore->prune();
		} catch (\Throwable $error) {
			$this->logger->error('PruneAuditJob: backup prune failed', ['message' => $error->getMessage()]);

			return;
		}
		if ($removed > 0) {
			$this->logger->info('PruneAuditJob: removed expired install backups', ['removed' => $removed]);
		}
	}

	private function resolveRetentionDays(): int {
		$configured = $this->appConfig->getValueInt(
			Application::APP_ID,
			self::CONFIG_KEY_RETENTION_DAYS,
			self::DEFAULT_RETENTION_DAYS,
		);

		if ($configured < self::MINIMUM_RETENTION_DAYS) {
			$this->logger->warning('PruneAuditJob: configured retention below floor, clamping', [
				'configured' => $configured,
				'floor' => self::MINIMUM_RETENTION_DAYS,
			]);

			return self::MINIMUM_RETENTION_DAYS;
		}

		return $configured;
	}
}
