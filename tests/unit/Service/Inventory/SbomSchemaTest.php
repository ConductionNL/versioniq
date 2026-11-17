<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/inventory-export-sbom-metrics/specs/inventory-export/spec.md
 */

namespace OCA\Versioniq\Tests\Unit\Service\Inventory;

use OCA\Versioniq\Service\Inventory\SbomWriter;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

/**
 * Both SBOM formats validate against the published JSON schemas, vendored
 * unchanged under tests/fixtures/sbom/ (inventory-export-sbom-metrics task 1.2):
 * CycloneDX 1.5 with the two schemas it references, and SPDX 2.3.
 *
 * @covers \OCA\Versioniq\Service\Inventory\SbomWriter
 */
final class SbomSchemaTest extends TestCase {
	private const FIXTURES = __DIR__ . '/../../../fixtures/sbom/';
	private const CYCLONEDX = 'http://cyclonedx.org/schema/bom-1.5.schema.json';
	private const SPDX = 'http://spdx.org/rdf/terms/2.3';

	private static function inventory(): array {
		return [
			'server' => ['name' => 'nextcloud-server', 'version' => '32.0.1', 'purl' => 'pkg:generic/nextcloud-server@32.0.1'],
			'apps' => [
				['id' => 'files', 'name' => 'Files', 'version' => '32.0.1', 'licence' => 'agpl', 'shipped' => true,
					'source' => 'appstore', 'sha256' => null, 'purl' => 'pkg:generic/nextcloud-app/files@32.0.1'],
				['id' => 'hermiq', 'name' => 'Hermiq', 'version' => '1.4.0', 'licence' => 'EUPL-1.2', 'shipped' => false,
					'source' => 'github:ConductionNL/hermiq', 'sha256' => str_repeat('a', 64), 'purl' => 'pkg:github/conductionnl/hermiq@1.4.0'],
				['id' => 'odd app', 'name' => 'Odd', 'version' => '1.0.0', 'licence' => 'Proprietary', 'shipped' => false,
					'source' => 'appstore', 'sha256' => null, 'purl' => 'pkg:generic/nextcloud-app/odd%20app@1.0.0'],
				['id' => 'nolicence', 'name' => 'No licence', 'version' => '2.0.0', 'licence' => null, 'shipped' => false,
					'source' => 'appstore', 'sha256' => null, 'purl' => 'pkg:generic/nextcloud-app/nolicence@2.0.0'],
			],
		];
	}

	private static function validator(): Validator {
		$validator = new Validator();
		$resolver = $validator->resolver();
		$resolver->registerFile(self::CYCLONEDX, self::FIXTURES . 'cyclonedx-bom-1.5.schema.json');
		$resolver->registerFile('http://cyclonedx.org/schema/spdx.SNAPSHOT.schema.json', self::FIXTURES . 'cyclonedx-spdx.schema.json');
		$resolver->registerFile('http://cyclonedx.org/schema/jsf-0.82.SNAPSHOT.schema.json', self::FIXTURES . 'cyclonedx-jsf-0.82.schema.json');
		$resolver->registerFile(self::SPDX, self::FIXTURES . 'spdx-2.3.schema.json');

		return $validator;
	}

	private function assertValid(string $format, string $schemaId): void {
		$json = (new SbomWriter())->write(self::inventory(), $format, ['instanceId' => 'oc123abc', 'timestamp' => '2026-11-14T10:00:00+00:00', 'uuid' => '123e4567-e89b-42d3-a456-426614174000']);
		$result = self::validator()->validate(json_decode($json, false, 512, JSON_THROW_ON_ERROR), $schemaId);

		$errors = $result->isValid() ? [] : (new ErrorFormatter())->format($result->error());
		self::assertSame([], $errors, $format . ' does not validate against its schema');
	}

	public function testTheCycloneDxDocumentValidatesAgainstTheCycloneDx15Schema(): void {
		$this->assertValid(SbomWriter::FORMAT_CYCLONEDX, self::CYCLONEDX);
	}

	public function testTheSpdxDocumentValidatesAgainstTheSpdx23Schema(): void {
		$this->assertValid(SbomWriter::FORMAT_SPDX, self::SPDX);
	}

	public function testTheSchemasRefuseADocumentMissingARequiredField(): void {
		$document = json_decode((new SbomWriter())->write(self::inventory(), SbomWriter::FORMAT_SPDX, ['instanceId' => 'x', 'timestamp' => '2026-11-14T10:00:00+00:00', 'uuid' => 'u']), false, 512, JSON_THROW_ON_ERROR);
		unset($document->spdxVersion);

		self::assertFalse(self::validator()->validate($document, self::SPDX)->isValid());

		$bom = json_decode((new SbomWriter())->write(self::inventory(), SbomWriter::FORMAT_CYCLONEDX, ['instanceId' => 'x', 'timestamp' => '2026-11-14T10:00:00+00:00', 'uuid' => 'u']), false, 512, JSON_THROW_ON_ERROR);
		$bom->components[1]->type = 'nextcloud-app';

		self::assertFalse(self::validator()->validate($bom, self::CYCLONEDX)->isValid());
	}
}
