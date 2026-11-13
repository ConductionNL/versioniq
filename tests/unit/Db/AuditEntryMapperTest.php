<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Db;

use DateTimeImmutable;
use OCA\Versioniq\Db\AuditEntry;
use OCA\Versioniq\Db\AuditEntryMapper;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for the queries AuditEntryMapper builds: the newest-first page
 * with its optional app filter, and the batched, oldest-first retention
 * delete. The query builder is a recording double, so the exact table,
 * ordering, limits and bound parameters are asserted.
 *
 * @covers \OCA\Versioniq\Db\AuditEntryMapper
 * @uses \OCA\Versioniq\Db\AuditEntry
 */
final class AuditEntryMapperTest extends TestCase {
	/** @var list<list<mixed>> */
	private array $log = [];

	/**
	 * A query builder double that records every fluent call, named parameter
	 * and expression in $this->log, and answers the given rows.
	 *
	 * @param list<array<string, mixed>> $rows rows for fetchAssociative()
	 * @param list<mixed> $ones values for fetchOne()
	 */
	private function queryBuilder(array $rows = [], array $ones = [], int $affected = 0): IQueryBuilder {
		$qb = $this->createMock(IQueryBuilder::class);
		foreach (['select', 'from', 'where', 'orderBy', 'addOrderBy', 'setMaxResults', 'setFirstResult', 'delete'] as $method) {
			$qb->method($method)->willReturnCallback(function (mixed ...$args) use ($method, $qb): IQueryBuilder {
				// The double receives defaulted arguments as null; record what was passed.
				while ($args !== [] && end($args) === null) {
					array_pop($args);
				}
				$this->log[] = array_merge([$method], $args);

				return $qb;
			});
		}
		$parameter = 0;
		$qb->method('createNamedParameter')->willReturnCallback(function (mixed $value, mixed $type = IQueryBuilder::PARAM_STR) use (&$parameter): string {
			$name = ':p' . $parameter++;
			$this->log[] = ['param', $name, $value, $type];

			return $name;
		});

		$expr = $this->createMock(IExpressionBuilder::class);
		foreach (['eq' => '=', 'lt' => '<', 'in' => 'IN'] as $method => $operator) {
			$expr->method($method)->willReturnCallback(static fn (string $column, string $value): string => "$column $operator $value");
		}
		$expr->method('orX')->willReturnCallback(function (string ...$parts): ICompositeExpression {
			$this->log[] = array_merge(['orX'], $parts);

			return $this->createMock(ICompositeExpression::class);
		});
		$qb->method('expr')->willReturn($expr);

		$result = $this->createMock(IResult::class);
		$result->method('fetchAssociative')->willReturnOnConsecutiveCalls(...array_merge($rows, [false]));
		$result->method('fetchOne')->willReturnOnConsecutiveCalls(...array_merge($ones, [false]));
		$result->method('closeCursor')->willReturnCallback(function (): bool {
			$this->log[] = ['closeCursor'];

			return true;
		});
		$qb->method('executeQuery')->willReturnCallback(function () use ($result): IResult {
			$this->log[] = ['executeQuery'];

			return $result;
		});
		$qb->method('executeStatement')->willReturnCallback(function () use ($affected): int {
			$this->log[] = ['executeStatement'];

			return $affected;
		});

		return $qb;
	}

	private function connection(IQueryBuilder ...$builders): IDBConnection {
		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->exactly(count($builders)))->method('getQueryBuilder')->willReturnOnConsecutiveCalls(...$builders);

		return $db;
	}

	public function testThePageIsNewestFirstWithLimitAndOffset(): void {
		$qb = $this->queryBuilder([
			['id' => 9, 'app_id' => 'mail', 'operation' => 'install', 'status' => 'success', 'created_at' => '2026-11-13 08:00:00'],
			['id' => 8, 'app_id' => 'deck', 'operation' => 'pin', 'status' => 'success', 'created_at' => '2026-11-12 08:00:00'],
		]);
		$mapper = new AuditEntryMapper($this->connection($qb));

		$entries = $mapper->findPage(null, 25, 50);

		$this->assertSame([
			['select', '*'],
			['from', 'app_versions_audit'],
			['orderBy', 'created_at', 'DESC'],
			['addOrderBy', 'id', 'DESC'],
			['setMaxResults', 25],
			['setFirstResult', 50],
			['executeQuery'],
			['closeCursor'],
		], $this->log);
		$this->assertContainsOnlyInstancesOf(AuditEntry::class, $entries);
		$this->assertSame([9, 8], array_map(static fn (AuditEntry $e): int => $e->getId(), $entries));
		$this->assertSame(['mail', 'deck'], array_map(static fn (AuditEntry $e): string => $e->getAppId(), $entries));
	}

	public function testAnAppIdFiltersThePage(): void {
		$qb = $this->queryBuilder();
		$mapper = new AuditEntryMapper($this->connection($qb));

		$this->assertSame([], $mapper->findPage('mail', 10, 0));

		$this->assertSame([
			['select', '*'],
			['from', 'app_versions_audit'],
			['orderBy', 'created_at', 'DESC'],
			['addOrderBy', 'id', 'DESC'],
			['setMaxResults', 10],
			['setFirstResult', 0],
			['param', ':p0', 'mail', IQueryBuilder::PARAM_STR],
			['where', 'app_id = :p0'],
			['executeQuery'],
			['closeCursor'],
		], $this->log);
	}

	public function testAnEmptyAppIdDoesNotFilter(): void {
		$mapper = new AuditEntryMapper($this->connection($this->queryBuilder()));

		$mapper->findPage('', 10, 0);

		$this->assertNotContains('where', array_column($this->log, 0));
	}

	public function testRetentionDeletesOneOldestFirstBatchById(): void {
		$select = $this->queryBuilder([], ['4', '7', 11]);
		$delete = $this->queryBuilder([], [], 3);
		$mapper = new AuditEntryMapper($this->connection($select, $delete));

		$deleted = $mapper->deleteOlderThan(new DateTimeImmutable('2026-08-15 13:45:00'), 500);

		$this->assertSame(3, $deleted);
		$this->assertSame([
			['select', 'id'],
			['from', 'app_versions_audit'],
			['param', ':p0', '2026-08-15 13:45:00', IQueryBuilder::PARAM_STR],
			['where', 'created_at < :p0'],
			['orderBy', 'created_at', 'ASC'],
			['setMaxResults', 500],
			['executeQuery'],
			['closeCursor'],
			['delete', 'app_versions_audit'],
			['param', ':p0', [4, 7, 11], IQueryBuilder::PARAM_INT_ARRAY],
			['where', 'id IN :p0'],
			['executeStatement'],
		], $this->log);
	}

	public function testRetentionWithNothingOldDeletesNothing(): void {
		$mapper = new AuditEntryMapper($this->connection($this->queryBuilder()));

		$this->assertSame(0, $mapper->deleteOlderThan(new DateTimeImmutable('2026-01-01 00:00:00'), 100));
		$this->assertNotContains('delete', array_column($this->log, 0));
		$this->assertSame(['closeCursor'], $this->log[array_key_last($this->log)]);
	}
}
