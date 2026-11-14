<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\OpenMetrics;

use Generator;
use OCA\Versioniq\Service\Availability\AvailabilityResultStore;
use OCA\Versioniq\Service\Inventory\InventoryBuilder;
use OCP\OpenMetrics\IMetricFamily;
use OCP\OpenMetrics\Metric;
use OCP\OpenMetrics\MetricType;

/**
 * `versioniq_app_version` on the server's OpenMetrics endpoint (inventory-export-sbom-metrics
 * D6), Nextcloud 33 and later: a gauge of 1 per installed app, labelled with its
 * id, version, whether it ships with the server, its source, and how many release
 * lines it is behind (`unknown` before the first availability sweep). On
 * Nextcloud 32 the `<openmetrics>` entry is ignored and this class never loads.
 *
 * @spec openspec/changes/inventory-export-sbom-metrics/specs/inventory-export/spec.md#requirement-installed-app-versions-are-exposed-as-openmetrics
 */
class AppVersionMetricFamily implements IMetricFamily {
	public const NAME = 'versioniq_app_version';

	public function __construct(
		private InventoryBuilder $inventory,
		private AvailabilityResultStore $availability,
	) {
	}

	public function name(): string {
		return self::NAME;
	}

	public function type(): MetricType {
		return MetricType::gauge;
	}

	public function unit(): string {
		return '';
	}

	public function help(): string {
		return 'Installed app versions, one sample per app';
	}

	/**
	 * One sample per installed app.
	 *
	 * @return Generator<Metric>
	 *
	 * @spec openspec/changes/inventory-export-sbom-metrics/specs/inventory-export/spec.md#requirement-installed-app-versions-are-exposed-as-openmetrics
	 */
	public function metrics(): Generator {
		$behind = $this->linesBehind();
		foreach ($this->inventory->build()['apps'] as $app) {
			yield new Metric(1, [
				'app_id' => $app['id'],
				'version' => $app['version'],
				'shipped' => $app['shipped'] ? 'true' : 'false',
				'source' => $app['source'],
				'behind' => $behind === null ? 'unknown' : self::behindOf(update: $behind[$app['id']] ?? null),
			]);
		}
	}

	/**
	 * The stored sweep's apps, or null when no sweep has run.
	 *
	 * @return array<string, mixed>|null
	 */
	private function linesBehind(): ?array {
		$snapshot = $this->availability->read();

		return $snapshot['checkedAt'] === null ? null : $snapshot['updates'];
	}

	/** The app's release lines behind, or `unknown` when the sweep did not reach it. */
	private static function behindOf(mixed $update): string {
		$lines = is_array($update) ? ($update['linesBehind'] ?? null) : null;

		return is_int($lines) ? (string)$lines : 'unknown';
	}
}
