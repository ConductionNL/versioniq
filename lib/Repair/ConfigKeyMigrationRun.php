<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Repair;

/**
 * What one MigrateAppConfigKeys run did with each stored key, counted for the
 * summary line it prints.
 */
final class ConfigKeyMigrationRun {
	public int $migrated = 0;
	public int $alreadyPresent = 0;
	public int $emptySource = 0;
	public int $skippedReserved = 0;

	public function summary(): string {
		return 'MigrateAppConfigKeys: ' . $this->migrated . ' key(s) migrated, ' . $this->alreadyPresent
			. ' already present, ' . $this->emptySource . ' had no value to migrate, '
			. $this->skippedReserved . ' skipped as Nextcloud-reserved.';
	}
}
