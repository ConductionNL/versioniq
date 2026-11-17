<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Inventory;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\Security\ISecureRandom;

/**
 * The instance's current inventory as an SBOM document (inventory-export-sbom-metrics
 * D5): the route, the command and the copy written after an update all build it
 * here, with this instance's id, the time and a fresh UUID.
 *
 * @spec openspec/changes/inventory-export-sbom-metrics/specs/inventory-export/spec.md#requirement-an-admin-exports-the-inventory-as-an-sbom
 */
class SbomExporter {
	/** The media type of each format. */
	public const MEDIA_TYPES = [
		SbomWriter::FORMAT_CYCLONEDX => 'application/vnd.cyclonedx+json',
		SbomWriter::FORMAT_SPDX => 'application/spdx+json',
	];

	public function __construct(
		private InventoryBuilder $inventory,
		private SbomWriter $writer,
		private IConfig $config,
		private ITimeFactory $time,
		private ISecureRandom $random,
	) {
	}

	/**
	 * The SBOM of what runs now, as JSON.
	 *
	 * @param string $format cyclonedx or spdx.
	 *
	 * @throws \InvalidArgumentException For an unknown format.
	 *
	 * @spec openspec/changes/inventory-export-sbom-metrics/specs/inventory-export/spec.md#requirement-an-admin-exports-the-inventory-as-an-sbom
	 */
	public function export(string $format): string {
		if (!in_array($format, SbomWriter::FORMATS, true)) {
			throw new \InvalidArgumentException('Unknown SBOM format: ' . $format);
		}

		return $this->writer->write($this->inventory->build(), $format, [
			'instanceId' => $this->instanceId(),
			'timestamp' => $this->time->getDateTime('now', new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM),
			'uuid' => $this->uuid(),
		]);
	}

	/**
	 * The download name, e.g. `nextcloud-sbom-20261114-100000.cdx.json`.
	 *
	 * @param string $format cyclonedx or spdx.
	 *
	 * @spec openspec/changes/inventory-export-sbom-metrics/specs/inventory-export/spec.md#requirement-an-admin-exports-the-inventory-as-an-sbom
	 */
	public function fileName(string $format): string {
		$stamp = $this->time->getDateTime('now', new \DateTimeZone('UTC'))->format('Ymd-His');

		return 'nextcloud-sbom-' . $stamp . ($format === SbomWriter::FORMAT_SPDX ? '.spdx.json' : '.cdx.json');
	}

	private function instanceId(): string {
		$id = $this->config->getSystemValueString('instanceid', '');

		return preg_replace('/[^A-Za-z0-9.\-]/', '-', $id === '' ? 'instance' : $id) ?? 'instance';
	}

	/** A random RFC 4122 version 4 UUID. */
	private function uuid(): string {
		$hex = $this->random->generate(32, '0123456789abcdef');
		$hex[12] = '4';
		$hex[16] = '89ab'[hexdec($hex[16]) & 3];

		return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
	}
}
