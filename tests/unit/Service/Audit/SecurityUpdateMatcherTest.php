<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Audit;

require_once __DIR__ . '/../../Support/AuditRows.php';

use OCA\Versioniq\Service\Audit\SecurityUpdateMatcher;
use OCA\Versioniq\Tests\Unit\Support\AuditRows;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Versioniq\Service\Audit\SecurityUpdateMatcher
 * @uses   \OCA\Versioniq\Db\AuditEntry
 */
final class SecurityUpdateMatcherTest extends TestCase {
	/**
	 * The ids of the matched entries and their fixed advisory ids.
	 *
	 * @param array $entries The entries.
	 *
	 * @return array<int, list<string>>
	 */
	private function matched(array $entries): array {
		$result = [];
		foreach ((new SecurityUpdateMatcher())->match($entries) as $match) {
			$result[$match['entry']->getId()] = array_column($match['fixedAdvisories'], 'id');
		}

		return $result;
	}

	public function testAnInstallFollowedByAResolutionByUpdateIsASecurityUpdate(): void {
		$entries = [
			AuditRows::entry(1, '2026-11-01 08:00:00', 'advisory_open', ['to' => '1.0.0', 'advisories' => [['id' => 'GHSA-1', 'severity' => 'high']]]),
			AuditRows::entry(2, '2026-11-02 09:00:00', 'install', ['actor' => 'admin', 'from' => '1.0.0', 'to' => '1.0.1']),
			AuditRows::entry(3, '2026-11-02 10:00:00', 'advisory_resolved', ['from' => '1.0.0', 'to' => '1.0.1', 'message' => 'update', 'advisories' => [['id' => 'GHSA-1', 'severity' => 'high']]]),
		];

		$matches = (new SecurityUpdateMatcher())->match($entries);

		$this->assertCount(1, $matches);
		$this->assertSame(2, $matches[0]['entry']->getId());
		$this->assertSame([['id' => 'GHSA-1', 'severity' => 'high']], $matches[0]['fixedAdvisories']);
	}

	public function testAnInstallWithNoFollowingResolutionIsLeftOut(): void {
		$entries = [
			AuditRows::entry(2, '2026-11-02 09:00:00', 'install', ['to' => '1.0.1']),
			AuditRows::entry(3, '2026-11-02 10:00:00', 'advisory_resolved', ['to' => '1.0.1', 'message' => 'dismissed', 'advisories' => [['id' => 'GHSA-1', 'severity' => 'high']]]),
			AuditRows::entry(4, '2026-11-02 10:00:00', 'advisory_resolved', ['to' => '1.0.2', 'message' => 'update', 'advisories' => [['id' => 'GHSA-2', 'severity' => 'low']]]),
		];

		$this->assertSame([], $this->matched($entries));
	}

	public function testAResolutionAfterTheNextInstallBelongsToThatInstall(): void {
		$entries = [
			AuditRows::entry(5, '2026-11-03 09:00:00', 'install', ['to' => '1.0.1']),
			AuditRows::entry(6, '2026-11-03 09:30:00', 'install', ['to' => '1.0.2']),
			AuditRows::entry(7, '2026-11-03 10:00:00', 'advisory_resolved', ['to' => '1.0.1', 'message' => 'update', 'advisories' => [['id' => 'GHSA-1', 'severity' => 'high']]]),
			AuditRows::entry(8, '2026-11-03 10:00:00', 'advisory_resolved', ['to' => '1.0.2', 'message' => 'update', 'advisories' => [['id' => 'GHSA-2', 'severity' => 'low']]]),
		];

		$this->assertSame([6 => ['GHSA-2']], $this->matched($entries));
	}

	public function testOtherAppsAndFailedInstallsDoNotMatch(): void {
		$entries = [
			AuditRows::entry(9, '2026-11-04 09:00:00', 'install', ['to' => '1.0.1', 'status' => 'failure']),
			AuditRows::entry(10, '2026-11-04 09:05:00', 'install', ['appId' => 'other', 'to' => '1.0.1']),
			AuditRows::entry(11, '2026-11-04 10:00:00', 'advisory_resolved', ['to' => '1.0.1', 'message' => 'update', 'advisories' => [['id' => 'GHSA-1', 'severity' => 'high']]]),
		];

		$this->assertSame([], $this->matched($entries));
	}

	public function testAServerUpdateCountsAndInputOrderDoesNotMatter(): void {
		$entries = [
			AuditRows::entry(13, '2026-11-05 10:00:00', 'advisory_resolved', ['appId' => ':server', 'to' => '32.0.4', 'message' => 'update', 'advisories' => [['id' => 'NC-1', 'severity' => 'critical'], ['id' => 'NC-2', 'severity' => 'medium']]]),
			AuditRows::entry(12, '2026-11-05 09:00:00', 'server_update', ['appId' => ':server', 'from' => '32.0.3', 'to' => '32.0.4']),
		];

		$this->assertSame([12 => ['NC-1', 'NC-2']], $this->matched($entries));
	}
}
