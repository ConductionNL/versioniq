<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Tests\Unit\Service\Advisory;

use OCA\Versioniq\Service\Advisory\AdvisoryExposureTracker;
use OCA\Versioniq\Service\Advisory\AdvisoryService;
use OCA\Versioniq\Service\Advisory\ServerVersionProvider;
use OCA\Versioniq\Service\Audit\AuditedVersions;
use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\Audit\AuditSubject;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Advisory exposure and server updates in the history (audit-security-reports
 * D1, D3): opening, resolving by update, by withdrawal and otherwise, an
 * unreached app kept open, the first run marked `tracking started`, and a
 * changed server version recorded as `server_update`.
 *
 * @spec openspec/changes/audit-security-reports/specs/audit-trail/spec.md#requirement-advisory-exposure-and-server-updates-are-recorded-in-the-history
 */
final class AdvisoryExposureTrackerTest extends TestCase {
	/** @var array<string, string> In-memory app config. */
	private array $config = [];

	/** @var list<array{actor: string, appId: string, operation: string, from: ?string, to: ?string, message: ?string, advisories: ?array}> */
	private array $entries = [];

	private string $serverVersion = '32.0.1';

	private function tracker(): AdvisoryExposureTracker {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default = ''): string => $this->config[$key] ?? $default);
		$config->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value): bool {
			$this->config[$key] = $value;
			return true;
		});

		$audit = $this->createMock(AuditLogger::class);
		$audit->method('record')->willReturnCallback(function (AuditSubject $subject, AuditedVersions $versions, string $status, ?string $message = null, ?array $advisories = null): void {
			$this->entries[] = [
				'actor' => $subject->actorUid,
				'appId' => $subject->appId,
				'operation' => $subject->operation,
				'from' => $versions->fromVersion,
				'to' => $versions->toVersion,
				'message' => $message,
				'advisories' => $advisories,
			];
		});

		$server = $this->createMock(ServerVersionProvider::class);
		$server->method('current')->willReturnCallback(fn (): string => $this->serverVersion);

		return new AdvisoryExposureTracker($config, $audit, $server, new NullLogger());
	}

	/**
	 * A correlation row.
	 *
	 * @param list<array{id: string, severity: string}> $advisories
	 */
	private static function row(string $version, string $state, array $advisories = [], ?string $error = null): array {
		return ['installedVersion' => $version, 'state' => $state, 'advisories' => $advisories, 'error' => $error];
	}

	private static function vulnerable(string $version, string ...$ids): array {
		return self::row($version, AdvisoryService::STATE_VULNERABLE, array_map(static fn (string $id): array => ['id' => $id, 'severity' => 'high'], $ids));
	}

	/** @return list<string> */
	private function operations(): array {
		return array_map(static fn (array $e): string => $e['operation'] . ' ' . $e['appId'] . ' ' . ($e['message'] ?? '-'), $this->entries);
	}

	public function testTheFirstRunOpensWhatIsActiveAsTrackingStartedAndOnlyRemembersTheServer(): void {
		$this->tracker()->track(['fixtureapp' => self::vulnerable('1.0.0', 'GHSA-1'), 'clean' => self::row('2.0.0', AdvisoryService::STATE_NONE)], 1000);

		$this->assertSame(['advisory_open fixtureapp tracking started'], $this->operations());
		$this->assertSame('system', $this->entries[0]['actor']);
		$this->assertSame('1.0.0', $this->entries[0]['to']);
		$this->assertSame([['id' => 'GHSA-1', 'severity' => 'high']], $this->entries[0]['advisories']);
		$this->assertSame('32.0.1', $this->config[AdvisoryExposureTracker::CONFIG_SERVER_VERSION]);
		$open = json_decode($this->config[AdvisoryExposureTracker::CONFIG_EXPOSURES], true);
		$this->assertSame(['openedAt' => 1000, 'severity' => 'high', 'version' => '1.0.0', 'trackingStarted' => true], $open['fixtureapp|GHSA-1']);
	}

	public function testANewAdvisoryOpensOnceWithoutTrackingStarted(): void {
		$this->tracker()->track([], 1000);
		$this->tracker()->track(['fixtureapp' => self::vulnerable('1.0.0', 'GHSA-1')], 2000);
		$this->tracker()->track(['fixtureapp' => self::vulnerable('1.0.0', 'GHSA-1')], 3000);

		$this->assertSame(['advisory_open fixtureapp -'], $this->operations());
	}

	public function testAnUpdateResolvesWithTheVersionsItMovedBetween(): void {
		$this->tracker()->track([], 1000);
		$this->tracker()->track(['fixtureapp' => self::vulnerable('1.0.0', 'GHSA-1')], 2000);
		$this->tracker()->track(['fixtureapp' => self::row('1.0.1', AdvisoryService::STATE_AVAILABLE, [['id' => 'GHSA-1', 'severity' => 'high']])], 3000);

		$resolved = $this->entries[1];
		$this->assertSame('advisory_resolved', $resolved['operation']);
		$this->assertSame('update', $resolved['message']);
		$this->assertSame('1.0.0', $resolved['from']);
		$this->assertSame('1.0.1', $resolved['to']);
		$this->assertSame([['id' => 'GHSA-1', 'severity' => 'high']], $resolved['advisories']);
		$this->assertSame('{}', $this->config[AdvisoryExposureTracker::CONFIG_EXPOSURES]);
	}

	public function testAWithdrawnAdvisoryResolvesAsWithdrawn(): void {
		$this->tracker()->track([], 1000);
		$this->tracker()->track(['fixtureapp' => self::vulnerable('1.0.0', 'GHSA-1')], 2000);
		$this->tracker()->track(['fixtureapp' => self::row('1.0.0', AdvisoryService::STATE_NONE)], 3000);

		$this->assertSame('advisory_resolved fixtureapp withdrawn', $this->operations()[1]);
	}

	public function testAnAdvisoryStillInTheHistoryButNoLongerAffectingResolvesAsNotAffected(): void {
		$this->tracker()->track([], 1000);
		$this->tracker()->track(['fixtureapp' => self::vulnerable('1.0.0', 'GHSA-1')], 2000);
		$this->tracker()->track(['fixtureapp' => self::row('1.0.0', AdvisoryService::STATE_AVAILABLE, [['id' => 'GHSA-1', 'severity' => 'high']])], 3000);

		$this->assertSame('advisory_resolved fixtureapp not_affected', $this->operations()[1]);
	}

	public function testAnUnreachedAppKeepsItsPairsOpen(): void {
		$this->tracker()->track([], 1000);
		$this->tracker()->track(['fixtureapp' => self::vulnerable('1.0.0', 'GHSA-1')], 2000);
		$this->tracker()->track(['fixtureapp' => self::row('1.0.0', AdvisoryService::STATE_NONE, [], 'source did not answer')], 3000);

		$this->assertSame(['advisory_open fixtureapp -'], $this->operations());
		$this->assertArrayHasKey('fixtureapp|GHSA-1', json_decode($this->config[AdvisoryExposureTracker::CONFIG_EXPOSURES], true));
	}

	public function testAChangedServerVersionIsRecordedAsAServerUpdate(): void {
		$this->tracker()->track([], 1000);
		$this->serverVersion = '32.0.2';
		$this->tracker()->track([], 2000);
		$this->tracker()->track([], 3000);

		$this->assertCount(1, $this->entries);
		$this->assertSame(['actor' => 'system', 'appId' => ':server', 'operation' => 'server_update', 'from' => '32.0.1', 'to' => '32.0.2', 'message' => 'Detected by the advisory check', 'advisories' => null], $this->entries[0]);
	}
}
