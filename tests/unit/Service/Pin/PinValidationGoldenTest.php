<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Pin;

use InvalidArgumentException;
use OCA\Versioniq\Service\Pin\Pin;
use PHPUnit\Framework\TestCase;

/**
 * Golden over the Pin constructor's checks: for each argument list, the
 * pin's fields or the message it is refused with, compared with
 * fixtures/pin-validation.golden.json. Set RECORD_GOLDEN=1 to rewrite it.
 *
 * @covers \OCA\Versioniq\Service\Pin\Pin
 */
final class PinValidationGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/pin-validation.golden.json';

	/**
	 * @return array<string, list<string|null>>
	 */
	private static function cases(): array {
		$at = '2026-01-01T00:00:00+00:00';
		$cases = [];
		foreach (['1.2.3', '1.0.0-beta.1+build', 'v2', '', ' 1.0', '1.0/..', '1_0', "1.0\n"] as $version) {
			$cases['version ' . json_encode($version)] = [$version, 'admin', $at];
		}
		$cases['no pinnedBy'] = ['1.0.0', '', $at];
		$cases['no pinnedAt'] = ['1.0.0', 'admin', ''];
		$cases['reason'] = ['1.0.0', 'admin', $at, 'hold'];
		$cases['empty reason'] = ['1.0.0', 'admin', $at, ''];
		foreach (['1.1.0', '', 'x y', '1.1.0+meta'] as $drift) {
			$cases['drifted to ' . json_encode($drift)] = ['1.0.0', 'admin', $at, null, $drift, $at];
		}
		$cases['drifted to without when'] = ['1.0.0', 'admin', $at, null, '1.1.0', null];
		$cases['drift time without version'] = ['1.0.0', 'admin', $at, null, null, $at];
		$cases['empty drift time'] = ['1.0.0', 'admin', $at, null, '1.1.0', ''];
		$cases['bad version and no pinnedBy'] = ['', '', ''];
		$cases['no pinnedBy and bad drift'] = ['1.0.0', '', $at, null, '', null];

		return $cases;
	}

	public function testPinChecksMatchTheGoldenFixture(): void {
		$actual = [];
		foreach (self::cases() as $name => $args) {
			try {
				$actual[$name] = get_object_vars(new Pin(...$args));
			} catch (InvalidArgumentException $error) {
				$actual[$name] = ['refused' => $error->getMessage()];
			}
		}
		$json = json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
		if (getenv('RECORD_GOLDEN') === '1') {
			@mkdir(dirname(self::FIXTURE));
			file_put_contents(self::FIXTURE, $json);
		}

		$this->assertSame(file_get_contents(self::FIXTURE), $json);
	}
}
