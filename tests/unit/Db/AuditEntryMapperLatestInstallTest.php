<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Db;

use OCA\Versioniq\Db\AuditEntry;
use OCA\Versioniq\Db\AuditEntryMapper;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * The row an install's SBOM copy is kept with (inventory-export-sbom-metrics
 * task 3.1): the newest successful install row of the app, or null.
 *
 * @covers \OCA\Versioniq\Db\AuditEntryMapper
 * @uses \OCA\Versioniq\Db\AuditEntry
 *
 * @spec openspec/changes/inventory-export-sbom-metrics/specs/inventory-export/spec.md#requirement-every-update-leaves-an-sbom-of-the-state-it-produced
 */
final class AuditEntryMapperLatestInstallTest extends TestCase {
	/** @var list<list<mixed>> */
	private array $log = [];

	/**
	 * @param list<array<string, mixed>> $rows
	 */
	private function mapper(array $rows): AuditEntryMapper {
		$qb = $this->createMock(IQueryBuilder::class);
		foreach (['select', 'from', 'where', 'andWhere', 'orderBy', 'setMaxResults'] as $method) {
			$qb->method($method)->willReturnCallback(function (mixed ...$args) use ($method, $qb): IQueryBuilder {
				while ($args !== [] && end($args) === null) {
					array_pop($args);
				}
				$this->log[] = array_merge([$method], $args);

				return $qb;
			});
		}
		$parameter = 0;
		$qb->method('createNamedParameter')->willReturnCallback(function (mixed $value) use (&$parameter): string {
			$name = ':p' . $parameter++;
			$this->log[] = ['param', $name, $value];

			return $name;
		});
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturnCallback(static fn (string $column, string $value): string => "$column = $value");
		$qb->method('expr')->willReturn($expr);
		$result = $this->createMock(IResult::class);
		$result->method('fetchAssociative')->willReturnOnConsecutiveCalls(...array_merge($rows, [false]));
		$qb->method('executeQuery')->willReturn($result);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		return new AuditEntryMapper($db);
	}

	public function testItAsksForTheNewestSuccessfulInstallOfTheApp(): void {
		$entry = $this->mapper([['id' => 42, 'app_id' => 'mail', 'operation' => 'install', 'status' => 'success']])->findLatestSuccessfulInstall('mail');

		self::assertInstanceOf(AuditEntry::class, $entry);
		self::assertSame(42, $entry->getId());
		self::assertSame([
			['select', '*'],
			['from', 'app_versions_audit'],
			['param', ':p0', 'mail'],
			['where', 'app_id = :p0'],
			['param', ':p1', 'install'],
			['andWhere', 'operation = :p1'],
			['param', ':p2', 'success'],
			['andWhere', 'status = :p2'],
			['orderBy', 'id', 'DESC'],
			['setMaxResults', 1],
		], $this->log);
	}

	public function testAnAppWithoutAnInstallHasNone(): void {
		self::assertNull($this->mapper([])->findLatestSuccessfulInstall('mail'));
	}
}
