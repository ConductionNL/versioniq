<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Source;

use OCA\Versioniq\Service\Source\AppStoreSource;
use OCA\Versioniq\Service\Source\ForgeReleaseSource;
use OCA\Versioniq\Service\Source\SourceBinding;
use OCA\Versioniq\Service\Source\SourceRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for SourceRegistry::get(): an App Store binding resolves to the
 * App Store driver and every forge binding to the one forge driver.
 *
 * @covers \OCA\Versioniq\Service\Source\SourceRegistry
 * @uses \OCA\Versioniq\Service\Source\SourceBinding
 */
final class SourceRegistryDriverTest extends TestCase {
	public function testEachBindingResolvesToItsDriver(): void {
		$appStore = $this->createMock(AppStoreSource::class);
		$forge = $this->createMock(ForgeReleaseSource::class);
		$registry = new SourceRegistry($appStore, $forge);

		$this->assertSame($appStore, $registry->get(SourceBinding::appStore()));
		$this->assertSame($forge, $registry->get(SourceBinding::github('ConductionNL', 'openregister')));
		$this->assertSame($forge, $registry->get(SourceBinding::release('forgejo', 'team', 'app')));
		$this->assertSame($forge, $registry->get(SourceBinding::codeberg('old', 'app')));
	}
}
