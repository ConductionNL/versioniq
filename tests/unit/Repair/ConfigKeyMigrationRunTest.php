<?php

/**
 * Tests for the counts one app config migration run prints.
 *
 * @category  Test
 * @package   OCA\Versioniq\Tests\Unit\Repair
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Versioniq\Tests\Unit\Repair;

use OCA\Versioniq\Repair\ConfigKeyMigrationRun;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Versioniq\Repair\ConfigKeyMigrationRun
 *
 * @spec exclude One-off app_versions->versioniq rename plumbing.
 */
final class ConfigKeyMigrationRunTest extends TestCase {

	/**
	 * A fresh run counts nothing.
	 */
	public function testAFreshRunCountsNothing(): void {
		$this->assertSame(
			'MigrateAppConfigKeys: 0 key(s) migrated, 0 already present, 0 had no value to migrate, 0 skipped as Nextcloud-reserved.',
			(new ConfigKeyMigrationRun())->summary()
		);
	}

	/**
	 * Each count lands in its own place in the summary.
	 */
	public function testEachCountLandsInItsOwnPlace(): void {
		$run = new ConfigKeyMigrationRun();
		$run->migrated = 4;
		$run->alreadyPresent = 3;
		$run->emptySource = 2;
		$run->skippedReserved = 1;

		$this->assertSame(
			'MigrateAppConfigKeys: 4 key(s) migrated, 3 already present, 2 had no value to migrate, 1 skipped as Nextcloud-reserved.',
			$run->summary()
		);
	}
}
