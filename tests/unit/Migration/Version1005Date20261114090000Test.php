<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Tests\Unit\Migration;

use OCA\Versioniq\Migration\Version1005Date20261114090000;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Schema\IColumn;
use OCP\DB\Schema\ITable;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * The `advisories` column is added to the audit table when missing and the
 * step changes nothing when it is there (audit-security-reports D2).
 *
 * @spec openspec/changes/audit-security-reports/specs/audit-trail/spec.md#requirement-advisory-exposure-and-server-updates-are-recorded-in-the-history
 */
final class Version1005Date20261114090000Test extends TestCase {
	private function migrate(bool $hasTable, bool $hasColumn, ?array &$added): ?ISchemaWrapper {
		$added = [];
		$table = $this->createMock(ITable::class);
		$table->method('hasColumn')->with('advisories')->willReturn($hasColumn);
		$table->method('addColumn')->willReturnCallback(function (string $name, $type, array $options = []) use (&$added): IColumn {
			$added[] = [$name, $type, $options];
			return $this->createMock(IColumn::class);
		});
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with('app_versions_audit')->willReturn($hasTable);
		$schema->method('getTable')->with('app_versions_audit')->willReturn($table);

		return (new Version1005Date20261114090000())->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []);
	}

	public function testTheColumnIsAddedWhenMissing(): void {
		$result = $this->migrate(true, false, $added);

		$this->assertNotNull($result);
		$this->assertSame([['advisories', 'text', ['notnull' => false]]], $added);
	}

	public function testNothingChangesWhenTheColumnExists(): void {
		$this->assertNull($this->migrate(true, true, $added));
		$this->assertSame([], $added);
	}

	public function testNothingChangesWithoutTheTable(): void {
		$this->assertNull($this->migrate(false, false, $added));
		$this->assertSame([], $added);
	}
}
