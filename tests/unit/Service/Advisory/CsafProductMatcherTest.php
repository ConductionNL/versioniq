<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Advisory;

use OCA\Versioniq\Service\Advisory\AdvisoryPackageMap;
use OCA\Versioniq\Service\Advisory\CsafProductMatcher;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The Nextcloud products a CSAF document is about (advisories-ncsc-feed D3).
 *
 * @spec openspec/changes/advisories-ncsc-feed/design.md
 */
class CsafProductMatcherTest extends TestCase {

	/**
	 * A matcher over an instance with Talk and Mail enabled.
	 *
	 * @return CsafProductMatcher The matcher.
	 */
	private function matcher(): CsafProductMatcher {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getEnabledApps')->willReturn(['spreed', 'mail']);
		$apps->method('getAppInfo')->willReturnMap([
			['spreed', false, null, ['name' => 'Talk']],
			['mail', false, null, ['name' => 'Mail']],
		]);

		return new CsafProductMatcher(new AdvisoryPackageMap($apps, $this->createMock(LoggerInterface::class)));
	}

	/**
	 * A committed CSAF fixture.
	 *
	 * @param string $name The file name.
	 *
	 * @return array<array-key, mixed> The document.
	 */
	private static function fixture(string $name): array {
		return json_decode((string)file_get_contents(__DIR__ . '/fixtures/csaf/' . $name), true, 512, JSON_THROW_ON_ERROR);
	}

	public function testServerAndTalkAreFoundWithTheirRanges(): void {
		$targets = $this->matcher()->targets(self::fixture('ncsc-nextcloud-server-talk.json'));

		$this->assertSame(
			[
				['target' => ':server', 'range' => 'vers:generic/>=30.0.0|<30.0.14', 'productIds' => ['CSAFPID-0001']],
				['target' => ':server', 'range' => 'vers:generic/=31.0.2', 'productIds' => ['CSAFPID-0002']],
				['target' => 'spreed', 'range' => 'vers:unknown/*', 'productIds' => ['CSAFPID-0003']],
			],
			$targets
		);
	}

	public function testTheClientAnotherVendorAndAProductNotKnownAffectedAreDropped(): void {
		$targets = $this->matcher()->targets(self::fixture('ncsc-nextcloud-server-talk.json'));
		$ids = array_merge(...array_column($targets, 'productIds'));

		$this->assertNotContains('CSAFPID-0004', $ids, 'the desktop client is not an app on this instance');
		$this->assertNotContains('CSAFPID-0005', $ids, 'Mail is listed as fixed, not known affected');
		$this->assertNotContains('CSAFPID-0006', $ids, 'Acme Server is not Nextcloud Server');
	}

	public function testADocumentAboutAnotherVendorHasNoTargets(): void {
		$this->assertSame([], $this->matcher()->targets(self::fixture('ncsc-other-vendor.json')));
	}

	public function testAProductTheInstanceDoesNotHaveIsDropped(): void {
		$document = self::fixture('ncsc-nextcloud-server-talk.json');
		$document['product_tree']['branches'][0]['branches'][1]['name'] = 'Nextcloud Deck';

		$targets = $this->matcher()->targets($document);

		$this->assertSame([':server', ':server'], array_column($targets, 'target'));
	}

	public function testAMalformedDocumentHasNoTargets(): void {
		$this->assertSame([], $this->matcher()->targets([]));
		$this->assertSame([], $this->matcher()->targets(['vulnerabilities' => 'x', 'product_tree' => 'y']));
	}
}
