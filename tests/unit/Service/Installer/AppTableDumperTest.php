<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Installer;

use OCA\Versioniq\Service\Installer\AppTableDumper;
use OCP\DB\IResult;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IMigrationStep;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @spec openspec/specs/migration-safety/spec.md#requirement-a-real-install-keeps-a-restorable-backup-of-the-app-folder-and-its-tables
 */
final class AppTableDumperTest extends TestCase {
	/** @var list<string> */
	private array $schemaTables = [];

	public function testTheTablesAreThoseTheStepsLeaveInTheSchema(): void {
		$schema = $this->recordingSchema();
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')
			->with('AppTableDumper: a migration step could not be read', $this->callback(static fn (array $context): bool => $context['message'] === 'needs a database'));

		$tables = $this->dumper($this->createMock(IDBConnection::class), $logger)->tablesCreatedBy([
			$this->step(function (ISchemaWrapper $schema): ISchemaWrapper {
				$schema->createTable('notes_items');
				$schema->createTable('notes_old');
				return $schema;
			}),
			$this->step(static function (): void {
				throw new \RuntimeException('needs a database');
			}),
			$this->step(function (ISchemaWrapper $schema): ?ISchemaWrapper {
				$schema->dropTable('notes_old');
				$schema->createTable('notes_attachments');
				return null;
			}),
		], $schema);

		$this->assertSame(['notes_attachments', 'notes_items'], $tables);
	}

	public function testTablesOfRunsTheAppsMigrationsInOrderAndKeepsTablesThatExist(): void {
		$appPath = sys_get_temp_dir() . '/vq-dumper-' . bin2hex(random_bytes(4));
		mkdir($appPath . '/lib/Migration', 0777, true);
		foreach (['Version10000Date20250101', 'Version9000Date20240101', 'Version10000Date20250101Broken'] as $name) {
			touch($appPath . '/lib/Migration/' . $name . '.php');
		}
		touch($appPath . '/lib/Migration/SomeRepairStep.php');
		$loaded = [];
		$steps = [
			'OCA\\Notes\\Migration\\Version9000Date20240101' => $this->step(static function (ISchemaWrapper $schema): ISchemaWrapper {
				$schema->createTable('notes_items');
				$schema->createTable('notes_gone');
				return $schema;
			}),
			'OCA\\Notes\\Migration\\Version10000Date20250101' => $this->step(static function (ISchemaWrapper $schema): ISchemaWrapper {
				$schema->createTable('notes_tags');
				return $schema;
			}),
		];
		$loadStep = static function (string $class) use (&$loaded, $steps): object {
			$loaded[] = $class;
			if (!isset($steps[$class])) {
				throw new \RuntimeException('no such class');
			}
			return $steps[$class];
		};
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturnCallback(static fn (string $table): bool => $table !== 'notes_gone');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')
			->with('AppTableDumper: a migration step could not be loaded', ['class' => 'OCA\\Notes\\Migration\\Version10000Date20250101Broken', 'message' => 'no such class']);

		try {
			$tables = (new AppTableDumper($db, $logger, fn (): ISchemaWrapper => $this->recordingSchema(), $loadStep))->tablesOf($appPath . '/', 'OCA\\Notes\\');
		} finally {
			exec('rm -rf ' . escapeshellarg($appPath));
		}

		$this->assertSame(['notes_items', 'notes_tags'], $tables);
		$this->assertSame([
			'OCA\\Notes\\Migration\\Version9000Date20240101',
			'OCA\\Notes\\Migration\\Version10000Date20250101',
			'OCA\\Notes\\Migration\\Version10000Date20250101Broken',
		], $loaded);
	}

	public function testDumpReadsEveryRowAndKeepsBinaryValuesAsBase64(): void {
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturnOnConsecutiveCalls(['id' => 1, 'data' => "\xff\xfe"], ['id' => 2, 'data' => 'text'], false);
		$result->expects($this->once())->method('closeCursor');
		$query = $this->createMock(IQueryBuilder::class);
		$query->expects($this->once())->method('select')->with('*')->willReturnSelf();
		$query->expects($this->once())->method('from')->with('notes_items')->willReturnSelf();
		$query->method('executeQuery')->willReturn($result);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($query);

		$dump = $this->dumper($db, $this->createMock(LoggerInterface::class))->dump(['notes_items']);

		$this->assertSame(['notes_items' => [
			['id' => 1, 'data' => ['__base64' => base64_encode("\xff\xfe")]],
			['id' => 2, 'data' => 'text'],
		]], $dump);
	}

	public function testRestoreReplacesTheRowsInOneTransaction(): void {
		$calls = [];
		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->once())->method('beginTransaction')->willReturnCallback(static function () use (&$calls): void {
			$calls[] = 'begin';
		});
		$db->expects($this->once())->method('commit')->willReturnCallback(static function () use (&$calls): void {
			$calls[] = 'commit';
		});
		$db->expects($this->never())->method('rollBack');
		$db->method('getQueryBuilder')->willReturnCallback(function () use (&$calls): IQueryBuilder {
			return $this->writer($calls);
		});

		$written = $this->dumper($db, $this->createMock(LoggerInterface::class))->restore([
			'notes_items' => [['id' => 1, 'data' => ['__base64' => base64_encode("\xff")]], ['id' => 2, 'data' => 'b']],
		]);

		$this->assertSame(2, $written);
		$this->assertSame([
			'begin',
			'delete notes_items',
			'insert notes_items', 'set id=1', "set data=\xff", 'execute',
			'insert notes_items', 'set id=2', 'set data=b', 'execute',
			'commit',
		], $calls);
	}

	public function testAFailedRestoreRollsBackAndRethrows(): void {
		$db = $this->createMock(IDBConnection::class);
		$query = $this->createMock(IQueryBuilder::class);
		$query->method('delete')->willReturnSelf();
		$query->method('executeStatement')->willThrowException(new \RuntimeException('locked'));
		$db->method('getQueryBuilder')->willReturn($query);
		$db->expects($this->once())->method('rollBack');
		$db->expects($this->never())->method('commit');

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('locked');

		$this->dumper($db, $this->createMock(LoggerInterface::class))->restore(['notes_items' => []]);
	}

	private function dumper(IDBConnection $db, LoggerInterface $logger): AppTableDumper {
		return new AppTableDumper($db, $logger, fn (): ISchemaWrapper => $this->recordingSchema());
	}

	/**
	 * A schema double that keeps the created tables in {@see $schemaTables}.
	 */
	private function recordingSchema(): ISchemaWrapper {
		$this->schemaTables = [];
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('createTable')->willReturnCallback(function (string $name) {
			$this->schemaTables[] = $name;
			return $this->createMock(\OCP\DB\Schema\ITable::class);
		});
		$schema->method('dropTable')->willReturnCallback(function (string $name) use ($schema): ISchemaWrapper {
			$this->schemaTables = array_values(array_diff($this->schemaTables, [$name]));
			return $schema;
		});
		$schema->method('getTableNamesWithoutPrefix')->willReturnCallback(fn (): array => $this->schemaTables);

		return $schema;
	}

	private function step(\Closure $change): IMigrationStep {
		$step = $this->createMock(IMigrationStep::class);
		$step->method('changeSchema')->willReturnCallback(static fn ($output, \Closure $schemaClosure, array $options) => $change($schemaClosure()));

		return $step;
	}

	/**
	 * @param list<string> $calls
	 */
	private function writer(array &$calls): IQueryBuilder {
		$query = $this->createMock(IQueryBuilder::class);
		$query->method('delete')->willReturnCallback(static function (string $table) use (&$calls, $query): IQueryBuilder {
			$calls[] = 'delete ' . $table;
			return $query;
		});
		$query->method('insert')->willReturnCallback(static function (string $table) use (&$calls, $query): IQueryBuilder {
			$calls[] = 'insert ' . $table;
			return $query;
		});
		$query->method('createNamedParameter')->willReturnCallback(static fn (mixed $value): string => (string)$value);
		$query->method('setValue')->willReturnCallback(static function (string $column, string $value) use (&$calls, $query): IQueryBuilder {
			$calls[] = 'set ' . $column . '=' . $value;
			return $query;
		});
		$query->method('executeStatement')->willReturnCallback(static function () use (&$calls, $query): int {
			if (end($calls) !== false && str_starts_with((string)end($calls), 'delete')) {
				return 0;
			}
			$calls[] = 'execute';
			return 1;
		});

		return $query;
	}
}
