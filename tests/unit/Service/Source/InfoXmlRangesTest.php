<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * inventory-upgrade-readiness task 1.2: the server and PHP range read from a
 * release's info.xml, stored and turned back into App Store style specs.
 *
 * @spec openspec/changes/inventory-upgrade-readiness/specs/upgrade-readiness/spec.md#requirement-a-forge-releases-ranges-are-read-without-downloading-the-release
 */

declare(strict_types=1);

namespace OCA\Versioniq\Tests\Unit\Service\Source;

use OCA\Versioniq\Service\Source\InfoXmlRanges;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Versioniq\Service\Source\InfoXmlRanges
 */
final class InfoXmlRangesTest extends TestCase {
	private const INFO_XML = <<<'XML'
<?xml version="1.0"?>
<info>
	<id>hermiq</id>
	<dependencies>
		<php min-version="8.1" max-version="8.3"/>
		<nextcloud min-version="31" max-version="33"/>
	</dependencies>
</info>
XML;

	public function testBothRangesAreReadAsSpecs(): void {
		$ranges = InfoXmlRanges::fromXml(self::INFO_XML);

		$this->assertSame('>=31 <=33', $ranges->platformSpec());
		$this->assertSame('>=8.1 <=8.3', $ranges->phpSpec());
		$this->assertSame(['nextcloudMin' => '31', 'nextcloudMax' => '33', 'phpMin' => '8.1', 'phpMax' => '8.3'], $ranges->toArray());
	}

	public function testAMissingOrNonNumericBoundIsLeftOut(): void {
		$ranges = InfoXmlRanges::fromXml('<info><dependencies><nextcloud min-version="30" max-version="dev"/></dependencies></info>');

		$this->assertSame('>=30', $ranges->platformSpec());
		$this->assertSame('', $ranges->phpSpec());
	}

	public function testAnEmptyOrBrokenDocumentHasNoRange(): void {
		foreach (['', '<info><dependencies>', 'not xml at all'] as $xml) {
			$ranges = InfoXmlRanges::fromXml($xml);
			$this->assertSame('', $ranges->platformSpec(), $xml);
			$this->assertSame('', $ranges->phpSpec(), $xml);
		}
	}

	public function testStoredRangesRoundTripAndBadValuesReadEmpty(): void {
		$ranges = InfoXmlRanges::fromArray(InfoXmlRanges::fromXml(self::INFO_XML)->toArray());
		$this->assertSame('>=31 <=33', $ranges->platformSpec());

		$bad = InfoXmlRanges::fromArray(['nextcloudMin' => 31, 'nextcloudMax' => '<script>', 'phpMax' => '8.4']);
		$this->assertSame('', $bad->platformSpec());
		$this->assertSame('<=8.4', $bad->phpSpec());
	}
}
