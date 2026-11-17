<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Installer;

use OCA\Versioniq\Service\Installer\TableSizeProbe;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Versioniq\Service\Installer\TableSizeProbe
 */
final class TableSizeProbeTest extends TestCase {
	/** @var list<string> Tables a count was run on. */
	private array $counted = [];

	/**
	 * A connection whose tables hold these row counts; a table mapped to null throws on count.
	 *
	 * @param array<string, int|null> $rows
	 */
	private function probe(array $rows): TableSizeProbe {
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturnCallback(static fn (string $table): bool => array_key_exists($table, $rows));
		$db->method('getQueryBuilder')->willReturnCallback(function () use ($rows): IQueryBuilder {
			$table = null;
			$query = $this->createMock(IQueryBuilder::class);
			$functions = $this->createMock(IFunctionBuilder::class);
			$functions->method('count')->willReturn($this->createMock(IQueryFunction::class));
			$query->method('func')->willReturn($functions);
			$query->method('select')->willReturnSelf();
			$query->method('from')->willReturnCallback(function (string $from) use (&$table, $query): IQueryBuilder {
				$table = $from;
				return $query;
			});
			$query->method('executeQuery')->willReturnCallback(function () use (&$table, $rows): IResult {
				$this->counted[] = $table;
				if ($rows[$table] === null) {
					throw new \RuntimeException('lock wait timeout');
				}
				$result = $this->createMock(IResult::class);
				$result->method('fetchOne')->willReturn((string)$rows[$table]);
				return $result;
			});
			return $query;
		});

		return new TableSizeProbe($db, $this->createMock(LoggerInterface::class));
	}

	public function testEachSizeClass(): void {
		$probed = $this->probe([
			'small_t' => 99999,
			'large_t' => 100000,
			'edge_t' => 1000000,
			'huge_t' => 1000001,
		])->probe(['small_t', 'large_t', 'edge_t', 'huge_t', 'new_t']);

		$this->assertSame([
			['name' => 'small_t', 'rows' => 99999, 'sizeClass' => 'small'],
			['name' => 'large_t', 'rows' => 100000, 'sizeClass' => 'large'],
			['name' => 'edge_t', 'rows' => 1000000, 'sizeClass' => 'large'],
			['name' => 'huge_t', 'rows' => 1000001, 'sizeClass' => 'very_large'],
			['name' => 'new_t', 'rows' => null, 'sizeClass' => 'new'],
		], $probed);
		$this->assertSame(['small_t', 'large_t', 'edge_t', 'huge_t'], $this->counted);
	}

	public function testANameThatFailsThePatternIsNotQueried(): void {
		$probed = $this->probe(['ok_t' => 1])->probe(['Users; DROP', 'ok_t', 'ok_t']);

		$this->assertSame([
			['name' => 'Users; DROP', 'rows' => null, 'sizeClass' => 'unknown'],
			['name' => 'ok_t', 'rows' => 1, 'sizeClass' => 'small'],
		], $probed);
		$this->assertSame(['ok_t'], $this->counted);
	}

	public function testAtMostTwentyTablesAreCounted(): void {
		$names = array_map(static fn (int $i): string => 't' . $i, range(1, 22));
		$probed = $this->probe(array_fill_keys($names, 5))->probe($names);

		$this->assertCount(20, $this->counted);
		$this->assertSame(['name' => 't20', 'rows' => 5, 'sizeClass' => 'small'], $probed[19]);
		$this->assertSame(['name' => 't21', 'rows' => null, 'sizeClass' => 'unknown'], $probed[20]);
		$this->assertSame(['name' => 't22', 'rows' => null, 'sizeClass' => 'unknown'], $probed[21]);
	}

	public function testAFailingCountIsSizeUnknown(): void {
		$probed = $this->probe(['locked_t' => null, 'ok_t' => 3])->probe(['locked_t', 'ok_t']);

		$this->assertSame(['name' => 'locked_t', 'rows' => null, 'sizeClass' => 'unknown'], $probed[0]);
		$this->assertSame('small', $probed[1]['sizeClass']);
	}
}
