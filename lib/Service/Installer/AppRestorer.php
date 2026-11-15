<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Installer;

use OCA\Versioniq\Service\Audit\AuditedVersions;
use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\Audit\AuditSubject;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IUserSession;

/**
 * Puts an app back as its newest backup kept it: the app folder first, then
 * its tables in one transaction, then the installed version and enabled value
 * it had (safety-rollback-backup-and-preflight D4). Every attempt with a
 * backup is audited.
 *
 * @spec openspec/specs/migration-safety/spec.md#requirement-restoring-a-broken-install-puts-back-files-and-tables
 * @psalm-api
 */
class AppRestorer {
	public function __construct(
		private AppBackupStore $store,
		private AppTableDumper $dumper,
		private IAppManager $appManager,
		private IAppConfig $appConfig,
		private AuditLogger $auditLogger,
		private IUserSession $userSession,
	) {
	}

	/**
	 * Restores the app from its newest kept backup.
	 *
	 * @spec openspec/specs/migration-safety/spec.md#requirement-restoring-a-broken-install-puts-back-files-and-tables
	 * @return array{appId: string, version: string, enabled: string, tables: int, rows: int, backupCreatedAt: string}
	 * @throws NoBackupException When the app has no kept backup.
	 * @throws \Throwable When the restore fails; it is audited as a failure.
	 */
	public function restore(string $appId): array {
		if (!AppIdFormat::isValid($appId)) {
			throw new \InvalidArgumentException('Invalid app id.');
		}
		$backup = $this->store->latest($appId);
		if ($backup === null) {
			throw new NoBackupException('There is no backup to restore ' . $appId . ' from. Roll back to an earlier version instead.');
		}

		try {
			$current = $this->appManager->getAppVersion($appId);
		} catch (\Throwable) {
			$current = '';
		}
		$versions = new AuditedVersions($current === '' ? null : $current, $backup['version'], null);
		$subject = new AuditSubject($this->userSession->getUser()?->getUID() ?? 'system', $appId, AuditLogger::OPERATION_RESTORE);

		try {
			$this->store->restoreFolder($backup, $this->appManager->getAppPath($appId, true));
			if (function_exists('opcache_reset')) {
				opcache_reset();
			}
			$tables = $this->store->tables($backup);
			$rows = $this->dumper->restore($tables);
			$this->appConfig->setValueString($appId, 'installed_version', $backup['version']);
			$this->appConfig->setValueString($appId, 'enabled', $backup['enabled']);
		} catch (\Throwable $error) {
			$this->auditLogger->record($subject, $versions, AuditLogger::STATUS_FAILURE, $error->getMessage());
			throw $error;
		}

		$restoredAt = gmdate(DATE_ATOM, $backup['createdAt']);
		$this->auditLogger->record($subject, $versions, AuditLogger::STATUS_SUCCESS, 'Restored from the backup of ' . $restoredAt . '.');

		return [
			'appId' => $appId,
			'version' => $backup['version'],
			'enabled' => $backup['enabled'],
			'tables' => count($tables),
			'rows' => $rows,
			'backupCreatedAt' => $restoredAt,
		];
	}
}
