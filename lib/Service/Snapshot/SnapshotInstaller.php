<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Snapshot;

use OCA\Versioniq\Service\Installer\InstallOptions;
use OCA\Versioniq\Service\Installer\InstallRequest;
use OCA\Versioniq\Service\Installer\InstallTarget;
use OCA\Versioniq\Service\Installer\PinChoice;
use OCA\Versioniq\Service\InstallerService;

/**
 * The installer as the snapshot classes see it (install-bulk-rollback design
 * D1 and D5): the apps installed now, and one rollback step installed through
 * the standard installer. The one place the snapshot code reaches the
 * installer.
 *
 * @psalm-import-type RollbackStep from RollbackPlanner
 * @psalm-api
 */
class SnapshotInstaller {
	public function __construct(
		private InstallerService $installerService,
	) {
	}

	/**
	 * The app cards of every managed app, as `InstallerService::getInstalledApps()` lists them.
	 *
	 * @return list<array<string, mixed>>
	 * @spec openspec/changes/install-bulk-rollback/specs/migration-safety/spec.md
	 */
	public function installedApps(): array {
		/** @var list<array<string, mixed>> */
		return $this->installerService->getInstalledApps();
	}

	/**
	 * Installs (or, on a dry run, checks) one step from the snapshot's source.
	 * A downgrade passes allowDowngrade, because the plan was acknowledged; a
	 * `pinned` step moves the pin with overridePin=repin.
	 *
	 * @param RollbackStep $step
	 * @return array<array-key, mixed> The installer's structured outcome.
	 * @spec openspec/changes/install-bulk-rollback/specs/cli-commands/spec.md
	 */
	public function install(array $step, bool $dryRun): array {
		return $this->installerService->installAppVersion(new InstallRequest(
			new InstallTarget($step['appId'], (string)$step['to'], $step['sourceId']),
			new InstallOptions(false, $dryRun, false, RollbackRunner::isDowngrade($step)),
			new PinChoice($step['kind'] === 'pinned' ? InstallerService::OVERRIDE_PIN_REPIN : null, false),
		));
	}
}
