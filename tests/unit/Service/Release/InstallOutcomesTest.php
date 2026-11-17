<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Release;

use OCA\Versioniq\Db\AuditEntryMapper;
use OCA\Versioniq\Service\Release\InstallOutcomes;
use PHPUnit\Framework\TestCase;

/**
 * Install outcomes per version (releases-version-facts D2).
 *
 * @spec openspec/changes/releases-version-facts/specs/version-management/spec.md#requirement-every-version-shows-how-widely-it-runs-and-how-installs-of-it-went
 */
final class InstallOutcomesTest extends TestCase {
	public function testEveryVersionCarriesItsCountsAndAnUninstalledOneZero(): void {
		$audit = $this->createMock(AuditEntryMapper::class);
		$audit->expects($this->once())->method('countOutcomesByVersion')->with('hermiq')
			->willReturn(['1.4.0' => ['succeeded' => 3, 'failed' => 1]]);

		$stamped = (new InstallOutcomes($audit))->stamp(
			[['version' => '1.4.0', 'changelog' => null], ['version' => '1.3.0', 'changelog' => 'x']],
			'hermiq'
		);

		$this->assertSame([
			['version' => '1.4.0', 'changelog' => null, 'outcomes' => ['succeeded' => 3, 'failed' => 1]],
			['version' => '1.3.0', 'changelog' => 'x', 'outcomes' => ['succeeded' => 0, 'failed' => 0]],
		], $stamped);
	}

	public function testForAppReturnsTheMappersCounts(): void {
		$audit = $this->createMock(AuditEntryMapper::class);
		$audit->method('countOutcomesByVersion')->with('deck')->willReturn(['2.0.0' => ['succeeded' => 0, 'failed' => 2]]);

		$this->assertSame(['2.0.0' => ['succeeded' => 0, 'failed' => 2]], (new InstallOutcomes($audit))->forApp('deck'));
	}
}
