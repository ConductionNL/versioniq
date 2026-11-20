<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/inventory-export-sbom-metrics/specs/inventory-export/spec.md#requirement-an-admin-exports-the-inventory-as-an-sbom
 */

namespace OCA\Versioniq\Tests\Unit\Service\Inventory;

use InvalidArgumentException;
use OCA\Versioniq\Service\Inventory\InventoryBuilder;
use OCA\Versioniq\Service\Inventory\SbomExporter;
use OCA\Versioniq\Service\Inventory\SbomWriter;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;

/**
 * The SBOM of what runs now, stamped with the instance id, the time and a fresh
 * version 4 UUID (inventory-export-sbom-metrics task 2.1).
 *
 * @covers \OCA\Versioniq\Service\Inventory\SbomExporter
 * @uses \OCA\Versioniq\Service\Inventory\SbomWriter
 */
final class SbomExporterTest extends TestCase {
	private function exporter(string $instanceId = 'oc1a2b3c', ?InventoryBuilder $builder = null): SbomExporter {
		if ($builder === null) {
			$builder = $this->createMock(InventoryBuilder::class);
			$builder->method('build')->willReturn([
				'server' => ['name' => 'nextcloud-server', 'version' => '32.0.1', 'purl' => 'pkg:generic/nextcloud-server@32.0.1'],
				'apps' => [['id' => 'hermiq', 'name' => 'Hermiq', 'version' => '1.4.0', 'licence' => 'EUPL-1.2', 'shipped' => false,
					'source' => 'github:ConductionNL/hermiq', 'sha256' => null, 'purl' => 'pkg:github/conductionnl/hermiq@1.4.0']],
			]);
		}
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->with('instanceid', '')->willReturn($instanceId);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-11-14T10:00:00+00:00'));
		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->with(32, '0123456789abcdef')->willReturn('0123456789abcdeffedcba9876543210');

		return new SbomExporter($builder, new SbomWriter(), $config, $time, $random);
	}

	public function testTheCycloneDxExportCarriesTheTimeAVersion4UuidAndTheApps(): void {
		$bom = json_decode($this->exporter()->export('cyclonedx'), true, 512, JSON_THROW_ON_ERROR);

		self::assertSame('urn:uuid:01234567-89ab-4def-bedc-ba9876543210', $bom['serialNumber']);
		self::assertSame('2026-11-14T10:00:00+00:00', $bom['metadata']['timestamp']);
		self::assertSame('pkg:github/conductionnl/hermiq@1.4.0', $bom['components'][1]['purl']);
	}

	public function testTheSpdxExportNamesTheInstance(): void {
		$doc = json_decode($this->exporter('oc 1/2')->export('spdx'), true, 512, JSON_THROW_ON_ERROR);

		self::assertSame('nextcloud-oc-1-2', $doc['name']);
		self::assertSame('https://spdx.org/spdxdocs/versioniq-oc-1-2-01234567-89ab-4def-bedc-ba9876543210', $doc['documentNamespace']);
	}

	public function testAnInstanceWithoutAnIdIsNamedInstance(): void {
		$doc = json_decode($this->exporter('')->export('spdx'), true, 512, JSON_THROW_ON_ERROR);

		self::assertSame('nextcloud-instance', $doc['name']);
	}

	public function testAnUnknownFormatIsRefusedBeforeTheInventoryIsBuilt(): void {
		$builder = $this->createMock(InventoryBuilder::class);
		$builder->expects(self::never())->method('build');

		$this->expectException(InvalidArgumentException::class);
		$this->exporter('x', $builder)->export('xml');
	}

	public function testTheFileNameCarriesTheTimeAndTheFormat(): void {
		self::assertSame('nextcloud-sbom-20261114-100000.cdx.json', $this->exporter()->fileName(SbomWriter::FORMAT_CYCLONEDX));
		self::assertSame('nextcloud-sbom-20261114-100000.spdx.json', $this->exporter()->fileName(SbomWriter::FORMAT_SPDX));
	}
}
