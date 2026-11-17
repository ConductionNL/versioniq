<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\OpenMetrics;

use OCA\Versioniq\OpenMetrics\AppVersionMetricFamily;
use OCA\Versioniq\Service\Availability\AvailabilityResultStore;
use OCA\Versioniq\Service\Inventory\InventoryBuilder;
use OCP\OpenMetrics\Metric;
use OCP\OpenMetrics\MetricType;
use PHPUnit\Framework\TestCase;

/**
 * The `versioniq_app_version` metric family (inventory-export-sbom-metrics task 4.1):
 * a gauge of 1 per installed app with the labels app_id, version, shipped, source
 * and behind, `unknown` before the first availability sweep.
 *
 * @covers \OCA\Versioniq\OpenMetrics\AppVersionMetricFamily
 *
 * @spec openspec/changes/inventory-export-sbom-metrics/specs/inventory-export/spec.md#requirement-installed-app-versions-are-exposed-as-openmetrics
 */
final class AppVersionMetricFamilyTest extends TestCase {
	/**
	 * @param array{updates: array<string, mixed>, checkedAt: ?int} $snapshot
	 */
	private function family(array $snapshot): AppVersionMetricFamily {
		$inventory = $this->createMock(InventoryBuilder::class);
		$inventory->method('build')->willReturn([
			'server' => ['name' => 'nextcloud-server', 'version' => '33.0.0', 'purl' => 'pkg:generic/nextcloud-server@33.0.0'],
			'apps' => [
				['id' => 'calendar', 'name' => 'Calendar', 'version' => '5.1.0', 'licence' => 'agpl', 'shipped' => true, 'source' => 'appstore', 'sha256' => null, 'purl' => 'p'],
				['id' => 'openregister', 'name' => 'OpenRegister', 'version' => '2.3.0', 'licence' => 'EUPL-1.2', 'shipped' => false, 'source' => 'appstore', 'sha256' => null, 'purl' => 'p'],
				['id' => 'hermiq', 'name' => 'Hermiq', 'version' => '1.4.0', 'licence' => 'EUPL-1.2', 'shipped' => false, 'source' => 'github:ConductionNL/hermiq', 'sha256' => null, 'purl' => 'p'],
			],
		]);
		$availability = $this->createMock(AvailabilityResultStore::class);
		$availability->method('read')->willReturn($snapshot);

		return new AppVersionMetricFamily($inventory, $availability);
	}

	/**
	 * @return list<array<string, string>>
	 */
	private static function labels(AppVersionMetricFamily $family): array {
		return array_map(static fn (Metric $metric): array => $metric->labels, iterator_to_array($family->metrics(), false));
	}

	public function testItIsAGaugeNamedVersioniqAppVersion(): void {
		$family = $this->family(['updates' => [], 'checkedAt' => null]);

		self::assertSame('versioniq_app_version', $family->name());
		self::assertSame(MetricType::gauge, $family->type());
		self::assertSame('', $family->unit());
		self::assertNotSame('', $family->help());
	}

	public function testEachAppIsOneSampleWithItsLabels(): void {
		$family = $this->family(['updates' => ['openregister' => ['linesBehind' => 2], 'calendar' => ['linesBehind' => 0]], 'checkedAt' => 1794650400]);

		$metrics = iterator_to_array($family->metrics(), false);

		self::assertSame([1, 1, 1], array_map(static fn (Metric $metric): int|float|bool => $metric->value, $metrics));
		self::assertSame([
			['app_id' => 'calendar', 'version' => '5.1.0', 'shipped' => 'true', 'source' => 'appstore', 'behind' => '0'],
			['app_id' => 'openregister', 'version' => '2.3.0', 'shipped' => 'false', 'source' => 'appstore', 'behind' => '2'],
			['app_id' => 'hermiq', 'version' => '1.4.0', 'shipped' => 'false', 'source' => 'github:ConductionNL/hermiq', 'behind' => 'unknown'],
		], self::labels($family));
	}

	public function testBehindIsUnknownBeforeTheFirstSweep(): void {
		$labels = self::labels($this->family(['updates' => ['openregister' => ['linesBehind' => 2]], 'checkedAt' => null]));

		self::assertSame(['unknown', 'unknown', 'unknown'], array_column($labels, 'behind'));
	}
}
