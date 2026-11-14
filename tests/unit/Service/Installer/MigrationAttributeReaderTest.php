<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Installer;

use OCA\Versioniq\Service\Installer\MigrationAttributeReader;
use PHPUnit\Framework\TestCase;

/**
 * Reads fabricated migration step files as text, never loading them.
 *
 * @covers \OCA\Versioniq\Service\Installer\MigrationAttributeReader
 */
final class MigrationAttributeReaderTest extends TestCase {
	private string $root;

	protected function setUp(): void {
		parent::setUp();
		$this->root = sys_get_temp_dir() . '/versioniq-attributes-' . uniqid('', true);
		mkdir($this->root, 0777, true);
	}

	protected function tearDown(): void {
		foreach (glob($this->root . '/*') ?: [] as $file) {
			unlink($file);
		}
		rmdir($this->root);
		parent::tearDown();
	}

	private function step(string $header, string $changeSchema = 'return null;', string $postSchemaChange = ''): string {
		$file = $this->root . '/Version' . uniqid() . '.php';
		file_put_contents($file, <<<PHP
<?php
namespace OCA\Fixture\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
{$header}
class VersionFixture extends SimpleMigrationStep {
	public function changeSchema(IOutput \$output, \Closure \$schemaClosure, array \$options): ?ISchemaWrapper {
		{$changeSchema}
	}

	public function postSchemaChange(IOutput \$output, \Closure \$schemaClosure, array \$options): void {
		{$postSchemaChange}
	}
}
PHP);

		return $file;
	}

	public function testEachAttributeKindIsReadWithItsArguments(): void {
		$file = $this->step(<<<'PHP'
use OCP\Migration\Attributes\AddColumn;
use OCP\Migration\Attributes\AddIndex;
use OCP\Migration\Attributes\ColumnType;
use OCP\Migration\Attributes\CreateTable;
use OCP\Migration\Attributes\IndexType;

#[CreateTable(table: 'fixture_items', columns: ['id', 'name'], description: 'Holds the items, (one per row)')]
#[AddColumn(table: 'fixture_users', name: 'last_seen', type: ColumnType::DATETIME)]
#[AddIndex('fixture_users', IndexType::INDEX, 'Speeds up the lookup')]
PHP);

		$read = (new MigrationAttributeReader())->read($file);

		$this->assertSame([
			['attribute' => 'CreateTable', 'table' => 'fixture_items', 'name' => '', 'description' => 'Holds the items, (one per row)'],
			['attribute' => 'AddColumn', 'table' => 'fixture_users', 'name' => 'last_seen', 'description' => ''],
			['attribute' => 'AddIndex', 'table' => 'fixture_users', 'name' => '', 'description' => 'Speeds up the lookup'],
		], $read['actions']);
		$this->assertSame(['fixture_items', 'fixture_users'], $read['tables']);
		$this->assertFalse($read['dataStep']);
		$this->assertTrue($read['describes']);
	}

	public function testSeveralAttributesInOneBlockAndPositionalColumnArguments(): void {
		$file = $this->step(<<<'PHP'
use OCP\Migration\Attributes\{DropColumn, ModifyColumn as Changed};

#[DropColumn('fixture_users', 'legacy'), Changed('fixture_users', 'email', null, 'Longer addresses')]
PHP);

		$actions = (new MigrationAttributeReader())->read($file)['actions'];

		$this->assertSame([
			['attribute' => 'DropColumn', 'table' => 'fixture_users', 'name' => 'legacy', 'description' => ''],
			['attribute' => 'ModifyColumn', 'table' => 'fixture_users', 'name' => 'email', 'description' => 'Longer addresses'],
		], $actions);
	}

	public function testAnUnknownAttributeFromTheNamespaceIsKeptByItsNameAndOthersAreIgnored(): void {
		$file = $this->step(<<<'PHP'
use OCP\AppFramework\Attribute\Consumable;

#[\OCP\Migration\Attributes\PartitionTable(table: 'fixture_log')]
#[Consumable(since: '30.0.0')]
#[\Override]
PHP);

		$actions = (new MigrationAttributeReader())->read($file)['actions'];

		$this->assertSame([['attribute' => 'PartitionTable', 'table' => 'fixture_log', 'name' => '', 'description' => '']], $actions);
	}

	public function testWithoutAttributesTheChangeSchemaTablesAreScanned(): void {
		$file = $this->step('', <<<'PHP'
$schema = $schemaClosure();
		if (!$schema->hasTable('fixture_items')) {
			$table = $schema->createTable('fixture_items');
		}
		$users = $schema->getTable("fixture_users");
		$schema->dropTable('fixture_items');
		return $schema;
PHP);

		$read = (new MigrationAttributeReader())->read($file);

		$this->assertSame([], $read['actions']);
		$this->assertSame(['fixture_items', 'fixture_users'], $read['tables']);
		$this->assertTrue($read['describes']);
	}

	public function testAPostSchemaChangeBodyOrADataAttributeMakesADataStep(): void {
		$withBody = $this->step('', 'return null;', '$this->db->executeStatement(\'UPDATE x SET y = 1\');');
		$commentOnly = $this->step('', 'return null;', '// nothing to move');
		$cleansing = $this->step("use OCP\\Migration\\Attributes\\DataCleansing;\n#[DataCleansing(table: 'fixture_items', description: 'Removes orphans')]");

		$reader = new MigrationAttributeReader();
		$this->assertTrue($reader->read($withBody)['dataStep']);
		$this->assertTrue($reader->read($withBody)['describes']);
		$this->assertFalse($reader->read($commentOnly)['dataStep']);
		$this->assertTrue($reader->read($cleansing)['dataStep']);
	}

	public function testAStepThatDescribesNothing(): void {
		$read = (new MigrationAttributeReader())->read($this->step('', '$x = 1; return null;'));

		$this->assertSame(['actions' => [], 'tables' => [], 'dataStep' => false, 'describes' => false], $read);
	}

	public function testAnUnreadableFileIsNull(): void {
		$this->assertNull((new MigrationAttributeReader())->read($this->root . '/Version0Missing.php'));
	}
}
