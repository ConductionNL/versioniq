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

use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;

/**
 * Makes the backup both installers keep before they replace an app folder,
 * and describes it for the install result
 * (safety-rollback-backup-and-preflight D3).
 *
 * A backup that cannot be written does not stop the install: the result says
 * it failed, so the admin knows no restore is available for it.
 *
 * @spec openspec/specs/migration-safety/spec.md#requirement-a-real-install-keeps-a-restorable-backup-of-the-app-folder-and-its-tables
 * @psalm-api
 */
class InstallBackup {
	public function __construct(
		private AppBackupStore $store,
		private AppTableDumper $dumper,
		private IAppManager $appManager,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Backs up the app in `$appPath` as it is now.
	 *
	 * @spec openspec/specs/migration-safety/spec.md#requirement-a-real-install-keeps-a-restorable-backup-of-the-app-folder-and-its-tables
	 * @return array{made: true, sizeBytes: int, keptUntil: string}|array{made: false, reason: 'disabled'}|array{made: false, reason: 'failed', message: string}
	 */
	public function make(string $appId, string $appPath, string $version, string $enabled): array {
		if ($this->store->keepDays() <= 0) {
			return ['made' => false, 'reason' => 'disabled'];
		}

		try {
			$namespace = AppAutoloader::resolveNamespace($appId, $this->appManager);
			$tables = $this->dumper->dump($this->dumper->tablesOf($appPath, $namespace));
			$backup = $this->store->create(new BackupSource($appId, $appPath, $version, $enabled), $tables);
		} catch (\Throwable $error) {
			$this->logger->warning('InstallBackup: the backup before the install could not be made', ['appId' => $appId, 'message' => $error->getMessage()]);

			return ['made' => false, 'reason' => 'failed', 'message' => $error->getMessage()];
		}
		if ($backup === null) {
			return ['made' => false, 'reason' => 'disabled'];
		}

		return ['made' => true, 'sizeBytes' => $backup['sizeBytes'], 'keptUntil' => gmdate('Y-m-d', $backup['keptUntil'])];
	}
}
