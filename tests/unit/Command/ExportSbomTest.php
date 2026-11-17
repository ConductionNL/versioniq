<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Command;

use OCA\Versioniq\Command\ExportSbom;
use OCA\Versioniq\Service\Inventory\SbomExporter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `occ versioniq:sbom` (inventory-export-sbom-metrics task 2.1): the SBOM on
 * stdout or into a file, exit 0; an unknown format or an unwritable file exits 1.
 *
 * @covers \OCA\Versioniq\Command\ExportSbom
 *
 * @spec openspec/changes/inventory-export-sbom-metrics/specs/inventory-export/spec.md#requirement-an-admin-exports-the-inventory-as-an-sbom
 */
final class ExportSbomTest extends TestCase {
	private function tester(?SbomExporter $exporter = null): CommandTester {
		if ($exporter === null) {
			$exporter = $this->createMock(SbomExporter::class);
			$exporter->method('export')->willReturnCallback(static fn (string $format): string => '{"format":"' . $format . '"}');
		}

		return new CommandTester(new ExportSbom($exporter));
	}

	public function testItWritesAnSpdxSbomToTheFileAndExitsZero(): void {
		$file = tempnam(sys_get_temp_dir(), 'sbom');
		$tester = $this->tester();

		$code = $tester->execute(['--format' => 'spdx', '--output' => $file]);

		self::assertSame(0, $code);
		self::assertSame('{"format":"spdx"}' . "\n", file_get_contents($file));
		self::assertStringContainsString('Wrote the spdx SBOM to ' . $file, $tester->getDisplay());
		unlink($file);
	}

	public function testWithoutAFileItPrintsCycloneDxByDefault(): void {
		$tester = $this->tester();

		self::assertSame(0, $tester->execute([]));
		self::assertSame('{"format":"cyclonedx"}', trim($tester->getDisplay()));
	}

	public function testAnUnknownFormatExitsOneWithoutExporting(): void {
		$exporter = $this->createMock(SbomExporter::class);
		$exporter->expects(self::never())->method('export');
		$tester = $this->tester($exporter);

		self::assertSame(1, $tester->execute(['--format' => 'xml']));
		self::assertStringContainsString('--format must be one of: cyclonedx, spdx.', $tester->getDisplay());
	}

	public function testAFileThatCannotBeWrittenExitsOne(): void {
		$tester = $this->tester();

		self::assertSame(1, $tester->execute(['--output' => sys_get_temp_dir() . '/no-such-dir-' . uniqid() . '/sbom.json']));
		self::assertStringContainsString('Could not write', $tester->getDisplay());
	}
}
