<?php

/**
 * Tests for the repair step that marks the App Store payload cache lazy.
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

use OCA\Versioniq\Repair\MarkPayloadCacheLazy;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 *
 * @covers \OCA\Versioniq\Repair\MarkPayloadCacheLazy
 *
 * @spec exclude Performance repair of a cache row's storage flag; no
 *  capability spec covers how the payload cache is stored.
 */
final class MarkPayloadCacheLazyTest extends TestCase {

	/**
	 * Only payload cache keys are flipped, and each one is flipped TO lazy.
	 *
	 * Asserts the keys and the flag, not a call count: flipping `pin.*` or
	 * `policy.*` would also move them out of the preload, and a count-only
	 * assertion would not notice.
	 *
	 * @return void
	 */
	public function testFlipsOnlyPayloadCacheKeysToLazy(): void {
		$flipped = [];

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getKeys')->with('versioniq')->willReturn([
			'appstore.payload.mail',
			'appstore.payload_ts.mail',
			'appstore.payload.calendar',
			'appstore.api_base',
			'pin.openregister',
			'policy.hermiq',
		]);
		$appConfig->method('updateLazy')->willReturnCallback(
			function (string $app, string $key, bool $lazy) use (&$flipped): bool {
				$this->assertSame('versioniq', $app);
				$flipped[$key] = $lazy;

				return true;
			},
		);

		$step = new MarkPayloadCacheLazy($appConfig, $this->createMock(LoggerInterface::class));
		$step->run($this->createMock(IOutput::class));

		$this->assertSame(
			[
				'appstore.payload.mail' => true,
				'appstore.payload_ts.mail' => true,
				'appstore.payload.calendar' => true,
			],
			$flipped,
		);
	}

	/**
	 * One key that cannot be updated is logged, and the rest still run.
	 *
	 * @return void
	 */
	public function testAFailingKeyDoesNotAbortTheStep(): void {
		$flipped = [];

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getKeys')->willReturn(['appstore.payload.mail', 'appstore.payload.deck']);
		$appConfig->method('updateLazy')->willReturnCallback(
			function (string $app, string $key, bool $lazy) use (&$flipped): bool {
				if ($key === 'appstore.payload.mail') {
					throw new RuntimeException('boom');
				}
				$flipped[] = $key;

				return true;
			},
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		$step = new MarkPayloadCacheLazy($appConfig, $logger);
		$step->run($this->createMock(IOutput::class));

		$this->assertSame(['appstore.payload.deck'], $flipped);
	}
}
