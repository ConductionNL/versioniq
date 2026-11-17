<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Installer;

use OCA\Versioniq\Service\Installer\MigrationAttributeReader;
use OCA\Versioniq\Service\Installer\MigrationDiffer;
use OCA\Versioniq\Service\Installer\MigrationPreview;
use OCA\Versioniq\Service\Installer\TableSizeProbe;
use PHPUnit\Framework\TestCase;

/**
 * Builds previews from real step files in a temp tree; only the table count
 * (a database boundary) is stubbed.
 *
 * @covers \OCA\Versioniq\Service\Installer\MigrationPreview
 * @uses \OCA\Versioniq\Service\Installer\MigrationDiffer
 * @uses \OCA\Versioniq\Service\Installer\MigrationAttributeReader
 */
final class MigrationPreviewTest extends TestCase {
	private string $root;

	/** @var list<list<string>> The table lists the probe was asked for. */
	private array $probed = [];

	protected function setUp(): void {
		parent::setUp();
		$this->root = sys_get_temp_dir() . '/versioniq-migrationpreview-' . uniqid('', true);
		mkdir($this->root, 0777, true);
	}

	protected function tearDown(): void {
		$this->removeRecursive($this->root);
		parent::tearDown();
	}

	/**
	 * @param array<string, int> $rows
	 */
	private function preview(array $rows): MigrationPreview {
		$probe = $this->createMock(TableSizeProbe::class);
		$probe->method('probe')->willReturnCallback(function (array $tables) use ($rows): array {
			$this->probed[] = $tables;
			return array_map(
				static fn (string $table): array => array_key_exists($table, $rows)
					? ['name' => $table, 'rows' => $rows[$table], 'sizeClass' => TableSizeProbe::sizeClass($rows[$table])]
					: ['name' => $table, 'rows' => null, 'sizeClass' => TableSizeProbe::CLASS_NEW],
				$tables
			);
		});

		return new MigrationPreview(new MigrationDiffer(), new MigrationAttributeReader(), $probe);
	}

	/**
	 * @param array<string, string> $steps basename => source
	 */
	private function app(string $name, array $steps, ?string $infoXml = null): string {
		$path = $this->root . '/' . $name;
		mkdir($path . '/lib/Migration', 0777, true);
		foreach ($steps as $step => $source) {
			file_put_contents($path . '/lib/Migration/' . $step . '.php', $source);
		}
		if ($infoXml !== null) {
			mkdir($path . '/appinfo', 0777, true);
			file_put_contents($path . '/appinfo/info.xml', $infoXml);
		}

		return $path;
	}

	private static function indexStep(): string {
		return <<<'PHP'
			<?php
			namespace OCA\OpenRegister\Migration;

			use OCP\Migration\Attributes\AddIndex;
			use OCP\Migration\Attributes\IndexType;

			#[AddIndex(table: 'openregister_objects', type: IndexType::INDEX, description: 'faster lookups')]
			class Version2040Date20260201000000 extends \OCP\Migration\SimpleMigrationStep {
			}
			PHP;
	}

	public function testAnUpgradeListsTheNewStepWithItsTableSize(): void {
		$installed = $this->app('installed', ['Version1000Date20260101000000' => '<?php']);
		$target = $this->app('target', [
			'Version1000Date20260101000000' => '<?php',
			'Version2040Date20260201000000' => self::indexStep(),
		]);

		$preview = $this->preview(['openregister_objects' => 4200000])->build('openregister', $installed, $target);

		self::assertNotNull($preview);
		self::assertTrue($preview['estimated']);
		self::assertSame([], $preview['repairSteps']);
		self::assertCount(1, $preview['steps']);
		$step = $preview['steps'][0];
		self::assertSame('Version2040Date20260201000000', $step['name']);
		self::assertSame('AddIndex', $step['actions'][0]['attribute']);
		self::assertSame('openregister_objects', $step['actions'][0]['table']);
		self::assertSame([['name' => 'openregister_objects', 'rows' => 4200000, 'sizeClass' => 'very_large']], $step['tables']);
		self::assertFalse($step['dataStep']);
		self::assertTrue($step['describes']);
	}

	public function testATableTwoStepsTouchIsCountedOnce(): void {
		$target = $this->app('target', [
			'Version2040Date20260201000000' => self::indexStep(),
			'Version2050Date20260301000000' => str_replace('Version2040Date20260201000000', 'Version2050Date20260301000000', self::indexStep()),
		]);

		$preview = $this->preview(['openregister_objects' => 12])->build('openregister', null, $target);

		self::assertNotNull($preview);
		self::assertSame([['openregister_objects']], $this->probed);
		self::assertSame('small', $preview['steps'][1]['tables'][0]['sizeClass']);
	}

	public function testAStepThatDescribesNothingIsKeptAndMarked(): void {
		$target = $this->app('target', ['Version2040Date20260201000000' => '<?php class Version2040Date20260201000000 {}']);

		$preview = $this->preview([])->build('openregister', null, $target);

		self::assertNotNull($preview);
		self::assertSame([[
			'name' => 'Version2040Date20260201000000',
			'actions' => [],
			'tables' => [],
			'dataStep' => false,
			'describes' => false,
		]], $preview['steps']);
	}

	public function testTheRepairStepsAreListedInOrderWithoutSchemaChanges(): void {
		$info = <<<'XML'
			<?xml version="1.0"?>
			<info>
				<id>openregister</id>
				<repair-steps>
					<pre-migration><step>OCA\OpenRegister\Repair\Before</step></pre-migration>
					<post-migration>
						<step>OCA\OpenRegister\Repair\SeedDefaults</step>
						<step> OCA\OpenRegister\Repair\RebuildIndex </step>
					</post-migration>
				</repair-steps>
			</info>
			XML;
		$installed = $this->app('installed', ['Version1000Date20260101000000' => '<?php']);
		$target = $this->app('target', ['Version1000Date20260101000000' => '<?php'], $info);

		$preview = $this->preview([])->build('openregister', $installed, $target);

		self::assertSame([
			'steps' => [],
			'repairSteps' => ['OCA\OpenRegister\Repair\SeedDefaults', 'OCA\OpenRegister\Repair\RebuildIndex'],
			'estimated' => true,
		], $preview);
	}

	public function testAnUnreadableInfoXmlListsNoRepairSteps(): void {
		$target = $this->app('target', [], '<info><repair-steps>');

		$preview = $this->preview([])->build('openregister', null, $target);

		self::assertNotNull($preview);
		self::assertSame([], $preview['repairSteps']);
	}

	public function testAnUnreadableTargetHasNoPreview(): void {
		self::assertNull($this->preview([])->build('openregister', null, $this->root . '/missing'));
		self::assertNull($this->preview([])->build('openregister', null, null));
		self::assertSame([], $this->probed);
	}

	private function removeRecursive(string $path): void {
		if (!is_dir($path)) {
			if (file_exists($path)) {
				unlink($path);
			}
			return;
		}
		foreach (scandir($path) ?: [] as $entry) {
			if ($entry !== '.' && $entry !== '..') {
				$this->removeRecursive($path . '/' . $entry);
			}
		}
		rmdir($path);
	}
}
