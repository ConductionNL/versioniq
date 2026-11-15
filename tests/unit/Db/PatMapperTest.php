<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Db;

use OCA\Versioniq\Db\Pat;
use OCA\Versioniq\Db\PatMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for the queries PatMapper builds: lookup by id, the unfiltered
 * sweep, the tokens a user may see (own or shared with admins, newest
 * first) and the per-owner delete. The query builder is a recording double,
 * so the exact table, predicates and bound parameters are asserted.
 *
 * @covers \OCA\Versioniq\Db\PatMapper
 * @uses \OCA\Versioniq\Db\Pat
 */
final class PatMapperTest extends TestCase {
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

	public function testFindByIdBindsAnIntegerId(): void {
		$qb = $this->queryBuilder([['id' => 5, 'owner_uid' => 'alice', 'label' => 'Team', 'shared_with_admins' => '1']]);
		$mapper = new PatMapper($this->connection($qb));

		$pat = $mapper->findById(5);

		$this->assertSame(5, $pat->getId());
		$this->assertSame('alice', $pat->getOwnerUid());
		$this->assertTrue($pat->getSharedWithAdmins());
		$this->assertSame([
			['select', '*'],
			['from', 'app_versions_pats'],
			['param', ':p0', 5, IQueryBuilder::PARAM_INT],
			['where', 'id = :p0'],
			['executeQuery'],
			['closeCursor'],
		], $this->log);
	}

	public function testAMissingIdThrows(): void {
		$mapper = new PatMapper($this->connection($this->queryBuilder()));

		$this->expectException(DoesNotExistException::class);
		$mapper->findById(404);
	}

	public function testFindAllReadsEveryRowWithoutAFilter(): void {
		$qb = $this->queryBuilder([['id' => 1, 'owner_uid' => 'alice'], ['id' => 2, 'owner_uid' => 'bob']]);
		$mapper = new PatMapper($this->connection($qb));

		$pats = $mapper->findAll();

		$this->assertSame(['alice', 'bob'], array_map(static fn (Pat $p): string => $p->getOwnerUid(), $pats));
		$this->assertSame([
			['select', '*'],
			['from', 'app_versions_pats'],
			['executeQuery'],
			['closeCursor'],
		], $this->log);
	}

	public function testVisibleTokensAreOwnOrSharedNewestFirst(): void {
		$qb = $this->queryBuilder([['id' => 3, 'owner_uid' => 'bob', 'shared_with_admins' => '1']]);
		$mapper = new PatMapper($this->connection($qb));

		$pats = $mapper->findVisibleTo('alice');

		$this->assertSame([3], array_map(static fn (Pat $p): int => $p->getId(), $pats));
		$where = $this->log[5];
		$this->assertSame('where', $where[0]);
		$this->assertInstanceOf(ICompositeExpression::class, $where[1]);
		$this->log[5] = ['where', 'composite'];
		$this->assertSame([
			['select', '*'],
			['from', 'app_versions_pats'],
			['param', ':p0', 'alice', IQueryBuilder::PARAM_STR],
			['param', ':p1', true, IQueryBuilder::PARAM_BOOL],
			['orX', 'owner_uid = :p0', 'shared_with_admins = :p1'],
			['where', 'composite'],
			['orderBy', 'created_at', 'DESC'],
			['executeQuery'],
			['closeCursor'],
		], $this->log);
	}

	public function testDeleteByOwnerDeletesOnlyThatOwnersRows(): void {
		$mapper = new PatMapper($this->connection($this->queryBuilder([], [], 2)));

		$this->assertSame(2, $mapper->deleteByOwner('alice'));
		$this->assertSame([
			['delete', 'app_versions_pats'],
			['param', ':p0', 'alice', IQueryBuilder::PARAM_STR],
			['where', 'owner_uid = :p0'],
			['executeStatement'],
		], $this->log);
	}
}
