<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/advisories-triage/specs/security-advisory-correlation/spec.md#requirement-an-admin-dismisses-an-advisory-with-a-recorded-reason
 */

namespace OCA\Versioniq\Tests\Unit\Service\Advisory;

use OCA\Versioniq\Service\Advisory\AdvisoryTriageStore;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Versioniq\Service\Advisory\AdvisoryTriageStore
 */
final class AdvisoryTriageStoreTest extends TestCase {
	/** @var array<string, string> */
	private array $stored = [];

	/** @var list<string> */
	private array $warnings = [];

	private function store(): AdvisoryTriageStore {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => $this->stored[$app . '/' . $key] ?? $default,
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->stored[$app . '/' . $key] = $value;

				return true;
			},
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function (string $message): void {
			$this->warnings[] = $message;
		});

		return new AdvisoryTriageStore($config, $logger);
	}

	public function testAnEntryForTheServerAndForAnAppRoundTrip(): void {
		$triage = [
			AdvisoryTriageStore::key(':server', 'GHSA-aaaa-bbbb-cccc') => ['dismissal' => ['reason' => 'not_used', 'by' => 'alice', 'at' => '2026-09-27T10:05:00+00:00', 'until' => null, 'installedVersion' => '32.0.1']],
			AdvisoryTriageStore::key('openregister', 'GHSA-xxxx-yyyy-zzzz') => ['assignee' => ['type' => 'user', 'id' => 'bob'], 'assignedBy' => 'alice', 'assignedAt' => '2026-09-27T10:00:00+00:00'],
		];

		$this->store()->write($triage);

		$this->assertSame(':server|GHSA-aaaa-bbbb-cccc', array_key_first($triage));
		$this->assertArrayHasKey('versioniq/advisory.triage', $this->stored);
		$this->assertSame($triage, $this->store()->read());
	}

	public function testNothingStoredAndAnEmptyMapReadAsEmpty(): void {
		$this->assertSame([], $this->store()->read());

		$this->store()->write([]);
		$this->assertSame('{}', $this->stored['versioniq/advisory.triage']);
		$this->assertSame([], $this->store()->read());
	}

	public function testMalformedJsonReadsAsEmptyWithAWarning(): void {
		$this->stored['versioniq/advisory.triage'] = '{"openregister|GHSA-1": ';

		$this->assertSame([], $this->store()->read());
		$this->assertSame(['AdvisoryTriageStore: stored triage is not valid JSON; reading it as empty'], $this->warnings);
	}

	public function testAListOrNonObjectEntriesAreNotATriageMap(): void {
		$this->stored['versioniq/advisory.triage'] = '[{"a":1}]';
		$this->assertSame([], $this->store()->read());

		$this->stored['versioniq/advisory.triage'] = '{"x|1":"not an entry","y|2":{"assignee":{"type":"group","id":"admin"}}}';
		$this->assertSame(['y|2' => ['assignee' => ['type' => 'group', 'id' => 'admin']]], $this->store()->read());
	}

	public function testAnEntryUnseenForThirtyOneDaysIsPrunedAndASeenOneIsStamped(): void {
		$this->store()->write([
			'old|GHSA-1' => ['assignee' => ['type' => 'user', 'id' => 'bob'], 'lastSeenAt' => '2026-08-01T00:00:00+00:00'],
			'recent|GHSA-2' => ['assignee' => ['type' => 'user', 'id' => 'bob'], 'lastSeenAt' => '2026-08-20T00:00:00+00:00'],
			'seen|GHSA-3' => ['dismissal' => ['reason' => 'not_used', 'at' => '2026-01-01T00:00:00+00:00'], 'lastSeenAt' => '2026-01-01T00:00:00+00:00'],
			'never-stamped|GHSA-4' => ['dismissal' => ['reason' => 'mitigated', 'at' => '2026-07-01T00:00:00+00:00']],
		]);

		$pruned = $this->store()->stampAndPrune(['seen|GHSA-3'], '2026-09-01T00:00:00+00:00');

		$this->assertSame(2, $pruned);
		$after = $this->store()->read();
		$this->assertSame(['recent|GHSA-2', 'seen|GHSA-3'], array_keys($after));
		$this->assertSame('2026-09-01T00:00:00+00:00', $after['seen|GHSA-3']['lastSeenAt']);
		$this->assertSame('2026-08-20T00:00:00+00:00', $after['recent|GHSA-2']['lastSeenAt']);
	}

	public function testPruneKeepsAnEntryUnseenForExactlyThirtyDaysAndOneWithoutAnyTime(): void {
		$this->store()->write([
			'edge|GHSA-1' => ['lastSeenAt' => '2026-08-02T00:00:00+00:00'],
			'timeless|GHSA-2' => ['assignee' => ['type' => 'user', 'id' => 'bob']],
		]);

		$this->assertSame(0, $this->store()->prune('2026-09-01T00:00:00+00:00'));
		$this->assertSame(['edge|GHSA-1', 'timeless|GHSA-2'], array_keys($this->store()->read()));
	}

	public function testStampingAnEmptyMapWritesNothing(): void {
		$this->assertSame(0, $this->store()->stampAndPrune(['a|1'], '2026-09-01T00:00:00+00:00'));
		$this->assertSame([], $this->stored);
	}
}
