<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Installer;

use OCA\Versioniq\Service\Installer\MigrationDiffer;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the migration diff algorithm against real fixture directories —
 * mirrors {@see \OCA\Versioniq\Tests\Unit\Service\InstallerRecoveryTest}'s
 * approach of driving filesystem primitives against a real temp tree, since
 * the diff itself is a pure filesystem read with no network dependency.
 */
final class MigrationDifferTest extends TestCase {
	private string $root;

	protected function setUp(): void {
		parent::setUp();
		$this->root = sys_get_temp_dir() . '/appversions-migrationdiff-' . uniqid('', true);
		mkdir($this->root, 0777, true);
	}

	protected function tearDown(): void {
		$this->removeRecursive($this->root);
		parent::tearDown();
	}

	private function makeMigrationDir(string $appPath, array $files): void {
		$dir = $appPath . '/lib/Migration';
		mkdir($dir, 0777, true);
		foreach ($files as $file) {
			file_put_contents($dir . '/' . $file, '<?php // fixture');
		}
	}

	public function testDiffNamesStepsPresentInInstalledButAbsentFromTarget(): void {
		$installed = $this->root . '/installed';
		$target = $this->root . '/target';
		$this->makeMigrationDir($installed, [
			'Version1000Date20260101000000.php',
			'Version2040Date20260201000000.php',
		]);
		$this->makeMigrationDir($target, [
			'Version1000Date20260101000000.php',
		]);

		$diff = (new MigrationDiffer())->diff($installed, $target);

		self::assertSame(['Version2040Date20260201000000'], $diff);
	}

	public function testDiffIsEmptyWhenMigrationSetsAreIdentical(): void {
		$installed = $this->root . '/installed';
		$target = $this->root . '/target';
		$this->makeMigrationDir($installed, ['Version1000Date20260101000000.php']);
		$this->makeMigrationDir($target, ['Version1000Date20260101000000.php']);

		$diff = (new MigrationDiffer())->diff($installed, $target);

		self::assertSame([], $diff);
	}

	public function testDiffIsEmptyWhenNeitherSideHasAMigrationsDirectory(): void {
		$installed = $this->root . '/installed';
		$target = $this->root . '/target';
		mkdir($installed, 0777, true);
		mkdir($target, 0777, true);

		$diff = (new MigrationDiffer())->diff($installed, $target);

		self::assertSame([], $diff);
	}

	public function testDiffIsSortedRegardlessOfFilesystemOrder(): void {
		$installed = $this->root . '/installed';
		$target = $this->root . '/target';
		$this->makeMigrationDir($installed, [
			'Version3000Date20260301000000.php',
			'Version1000Date20260101000000.php',
			'Version2000Date20260201000000.php',
		]);
		mkdir($target, 0777, true);

		$diff = (new MigrationDiffer())->diff($installed, $target);

		self::assertSame([
			'Version1000Date20260101000000',
			'Version2000Date20260201000000',
			'Version3000Date20260301000000',
		], $diff);
	}

	public function testDiffReturnsNullWhenTheInstalledPathIsUnknown(): void {
		$target = $this->root . '/target';
		mkdir($target, 0777, true);

		$diff = (new MigrationDiffer())->diff(null, $target);

		self::assertNull($diff);
	}

	public function testDiffReturnsNullWhenTheTargetPathIsUnknown(): void {
		$installed = $this->root . '/installed';
		mkdir($installed, 0777, true);

		$diff = (new MigrationDiffer())->diff($installed, null);

		self::assertNull($diff);
	}

	/** @var list<array{0: string, 1: mixed}> The where clauses the migrations query was built with. */
	private array $where = [];

	/**
	 * A connection whose `migrations` table records these versions for the
	 * app; null makes the read fail.
	 *
	 * @param list<string>|null $versions
	 */
	private function connection(?array $versions): IDBConnection {
		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->never())->method('executeStatement');
		$db->method('getQueryBuilder')->willReturnCallback(function () use ($versions): IQueryBuilder {
			$query = $this->createMock(IQueryBuilder::class);
			$expr = $this->createMock(IExpressionBuilder::class);
			$expr->method('eq')->willReturnCallback(function (string $column, mixed $value): string {
				$this->where[] = [$column, $value];
				return $column . '=' . $value;
			});
			$query->method('expr')->willReturn($expr);
			$query->method('createNamedParameter')->willReturnCallback(static fn (mixed $value): string => ':' . $value);
			$query->expects($this->once())->method('select')->with('version')->willReturnSelf();
			$query->expects($this->once())->method('from')->with('migrations')->willReturnSelf();
			$query->method('where')->willReturnSelf();
			$query->expects($this->never())->method('insert');
			$query->expects($this->never())->method('delete');
			$query->method('executeQuery')->willReturnCallback(function () use ($versions): IResult {
				if ($versions === null) {
					throw new \RuntimeException('no such table');
				}
				$result = $this->createMock(IResult::class);
				$result->method('fetchAll')->willReturn($versions);
				return $result;
			});
			return $query;
		});

		return $db;
	}

	public function testPendingListsTheTargetStepsNotRecordedAsRun(): void {
		$target = $this->root . '/target';
		$this->makeMigrationDir($target, [
			'Version1000Date20260101000000.php',
			'Version2040Date20260201000000.php',
			'Version2050Date20260301000000.php',
		]);

		$pending = (new MigrationDiffer($this->connection(['1000Date20260101000000', '9Date20200101000000'])))
			->pending('openregister', $target, null);

		self::assertSame([
			'steps' => ['Version2040Date20260201000000', 'Version2050Date20260301000000'],
			'estimated' => false,
		], $pending);
		self::assertSame([['app', ':openregister']], $this->where);
	}

	public function testAStepThatRanAndShipsAgainIsNotListed(): void {
		// Step A ran during an earlier install of this version that was later
		// downgraded: the installed copy lacks it, but Nextcloud recorded it.
		$installed = $this->root . '/installed';
		$target = $this->root . '/target';
		$this->makeMigrationDir($installed, ['Version1000Date20260101000000.php']);
		$this->makeMigrationDir($target, [
			'Version1000Date20260101000000.php',
			'Version2040Date20260201000000.php',
			'Version2050Date20260301000000.php',
		]);

		$pending = (new MigrationDiffer($this->connection(['1000Date20260101000000', '2040Date20260201000000'])))
			->pending('openregister', $target, $installed);

		self::assertSame(['steps' => ['Version2050Date20260301000000'], 'estimated' => false], $pending);
	}

	public function testAnUnreadableTableFallsBackToTheFileDiffAsAnEstimate(): void {
		$installed = $this->root . '/installed';
		$target = $this->root . '/target';
		$this->makeMigrationDir($installed, ['Version1000Date20260101000000.php']);
		$this->makeMigrationDir($target, [
			'Version1000Date20260101000000.php',
			'Version2040Date20260201000000.php',
		]);

		$pending = (new MigrationDiffer($this->connection(null)))->pending('openregister', $target, $installed);

		self::assertSame(['steps' => ['Version2040Date20260201000000'], 'estimated' => true], $pending);
	}

	public function testWithoutAConnectionThePendingListIsAnEstimate(): void {
		$target = $this->root . '/target';
		$this->makeMigrationDir($target, ['Version2040Date20260201000000.php']);

		$pending = (new MigrationDiffer())->pending('openregister', $target, null);

		self::assertSame(['steps' => ['Version2040Date20260201000000'], 'estimated' => true], $pending);
	}

	public function testAnUnreadableTargetHasNoPendingList(): void {
		$differ = new MigrationDiffer($this->createMock(IDBConnection::class));

		self::assertNull($differ->pending('openregister', $this->root . '/missing', null));
		self::assertNull($differ->pending('openregister', null, null));
	}

	public function testATargetWithoutMigrationsHasNothingPending(): void {
		$target = $this->root . '/target';
		mkdir($target, 0777, true);

		$pending = (new MigrationDiffer($this->connection([])))->pending('openregister', $target, null);

		self::assertSame(['steps' => [], 'estimated' => false], $pending);
	}

	private function removeRecursive(string $path): void {
		if (!is_dir($path)) {
			if (is_file($path)) {
				unlink($path);
			}

			return;
		}
		$entries = scandir($path) ?: [];
		foreach ($entries as $entry) {
			if ($entry === '.' || $entry === '..') {
				continue;
			}
			$this->removeRecursive($path . '/' . $entry);
		}
		rmdir($path);
	}
}
