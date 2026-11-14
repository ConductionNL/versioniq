<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/inventory-export-sbom-metrics/specs/inventory-export/spec.md
 */

namespace OCA\Versioniq\Tests\Unit\Service\Inventory;

use InvalidArgumentException;
use OCA\Versioniq\Service\Inventory\SbomWriter;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Versioniq\Service\Inventory\SbomWriter
 */
class SbomWriterTest extends TestCase {
	private const UUID = '123e4567-e89b-42d3-a456-426614174000';
	private const SHA = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

	private static function inventory(): array {
		return [
			'server' => ['name' => 'nextcloud-server', 'version' => '32.0.1', 'purl' => 'pkg:generic/nextcloud-server@32.0.1'],
			'apps' => [
				['id' => 'files', 'name' => 'Files', 'version' => '32.0.1', 'licence' => 'agpl', 'shipped' => true,
					'source' => 'appstore', 'sha256' => null, 'purl' => 'pkg:generic/nextcloud-app/files@32.0.1'],
				['id' => 'openregister', 'name' => 'OpenRegister', 'version' => '0.2.10', 'licence' => 'EUPL-1.2', 'shipped' => false,
					'source' => 'github:ConductionNL/OpenRegister', 'sha256' => self::SHA, 'purl' => 'pkg:github/conductionnl/openregister@0.2.10'],
				['id' => 'odd', 'name' => 'Odd', 'version' => '1.0.0', 'licence' => 'Proprietary', 'shipped' => false,
					'source' => 'appstore', 'sha256' => null, 'purl' => 'pkg:generic/nextcloud-app/odd@1.0.0'],
			],
		];
	}

	private static function decoded(string $format): array {
		return json_decode(
			(new SbomWriter())->write(self::inventory(), $format, 'oc123', '2026-11-14T10:00:00+00:00', self::UUID),
			true,
			flags: JSON_THROW_ON_ERROR
		);
	}

	public function testCycloneDxCarriesTheRequiredFieldsAndOneComponentPerEntry(): void {
		$bom = self::decoded('cyclonedx');

		$this->assertSame('CycloneDX', $bom['bomFormat']);
		$this->assertSame('1.5', $bom['specVersion']);
		$this->assertSame('urn:uuid:' . self::UUID, $bom['serialNumber']);
		$this->assertSame(1, $bom['version']);
		$this->assertSame('2026-11-14T10:00:00+00:00', $bom['metadata']['timestamp']);
		$this->assertSame(['platform', 'application', 'application', 'application'], array_column($bom['components'], 'type'));
		$refs = array_column($bom['components'], 'bom-ref');
		$this->assertSame($refs, array_values(array_unique($refs)));
	}

	public function testCycloneDxLicencesHashesAndPurls(): void {
		[, $files, $openregister, $odd] = self::decoded('cyclonedx')['components'];

		$this->assertSame([['license' => ['id' => 'AGPL-3.0-or-later']]], $files['licenses']);
		$this->assertSame([['license' => ['id' => 'EUPL-1.2']]], $openregister['licenses']);
		$this->assertSame([['license' => ['name' => 'Proprietary']]], $odd['licenses']);
		$this->assertSame([['alg' => 'SHA-256', 'content' => self::SHA]], $openregister['hashes']);
		$this->assertArrayNotHasKey('hashes', $files);
		$this->assertSame('pkg:github/conductionnl/openregister@0.2.10', $openregister['purl']);
	}

	public function testSpdxDescribesEveryPackage(): void {
		$doc = self::decoded('spdx');

		$this->assertSame('SPDX-2.3', $doc['spdxVersion']);
		$this->assertSame('CC0-1.0', $doc['dataLicense']);
		$this->assertSame('SPDXRef-DOCUMENT', $doc['SPDXID']);
		$this->assertStringContainsString(self::UUID, $doc['documentNamespace']);
		$this->assertCount(4, $doc['packages']);
		$this->assertSame(array_column($doc['packages'], 'SPDXID'), array_column($doc['relationships'], 'relatedSpdxElement'));
		$this->assertSame(['DESCRIBES'], array_values(array_unique(array_column($doc['relationships'], 'relationshipType'))));
		foreach ($doc['packages'] as $package) {
			$this->assertMatchesRegularExpression('/^SPDXRef-[A-Za-z0-9.\-]+$/', $package['SPDXID']);
			foreach (['name', 'versionInfo', 'downloadLocation', 'licenseDeclared', 'copyrightText'] as $field) {
				$this->assertArrayHasKey($field, $package);
			}
		}
	}

	public function testSpdxLicencesAndChecksums(): void {
		[, $files, $openregister, $odd] = self::decoded('spdx')['packages'];

		$this->assertSame('AGPL-3.0-or-later', $files['licenseDeclared']);
		$this->assertSame('EUPL-1.2', $openregister['licenseDeclared']);
		$this->assertSame('NOASSERTION', $odd['licenseDeclared']);
		$this->assertSame('Declared licence: Proprietary', $odd['licenseComments']);
		$this->assertSame([['algorithm' => 'SHA256', 'checksumValue' => self::SHA]], $openregister['checksums']);
		$this->assertSame('pkg:github/conductionnl/openregister@0.2.10', $openregister['externalRefs'][0]['referenceLocator']);
	}

	public function testAnUnknownFormatIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		(new SbomWriter())->write(self::inventory(), 'xml', 'oc123', 'now', self::UUID);
	}

	public function testSpdxIdMapping(): void {
		$this->assertSame('AGPL-3.0-or-later', SbomWriter::spdxId('AGPL'));
		$this->assertSame('MIT', SbomWriter::spdxId('MIT'));
		$this->assertNull(SbomWriter::spdxId('mit-ish'));
		$this->assertNull(SbomWriter::spdxId(null));
	}
}
