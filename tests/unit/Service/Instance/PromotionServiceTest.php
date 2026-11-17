<?php

declare(strict_types=1);

/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Instance;

use OCA\Versioniq\Service\Audit\AuditedVersions;
use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\Audit\AuditSubject;
use OCA\Versioniq\Service\Installer\InstallRequest;
use OCA\Versioniq\Service\Instance\InstanceConnection;
use OCA\Versioniq\Service\Instance\InstanceConnectionStore;
use OCA\Versioniq\Service\Instance\PromotionOptions;
use OCA\Versioniq\Service\Instance\PromotionPlanner;
use OCA\Versioniq\Service\Instance\PromotionService;
use OCA\Versioniq\Service\Instance\RemoteInstanceReader;
use OCA\Versioniq\Service\InstallerService;
use OCA\Versioniq\Service\Settings\InstanceSettings;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Promotion from an earlier stage: what it installs and through which
 * request, the downgrade, pin and waiting-period outcomes, the override in the
 * audit message, and a remote that moved on (install-multi-instance-promotion task 3.2).
 *
 * @covers \OCA\Versioniq\Service\Instance\PromotionService
 * @covers \OCA\Versioniq\Service\Instance\PromotionPlanner
 * @uses \OCA\Versioniq\Service\Instance\PromotionOptions
 * @covers \OCA\Versioniq\Service\Settings\InstanceSettings
 * @uses \OCA\Versioniq\Service\Instance\InstanceConnection
 * @uses \OCA\Versioniq\Service\Instance\PromotionRefusal
 * @uses \OCA\Versioniq\Service\Installer\InstallRequest
 * @uses \OCA\Versioniq\Service\Installer\InstallTarget
 * @uses \OCA\Versioniq\Service\Installer\InstallOptions
 * @uses \OCA\Versioniq\Service\Installer\PinChoice
 * @uses \OCA\Versioniq\Service\Audit\AuditSubject
 * @uses \OCA\Versioniq\Service\Audit\AuditedVersions
 */
final class PromotionServiceTest extends TestCase {
	private const SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
	private const NOW = 1_794_729_600; // 2026-11-15T08:00:00Z

	/** @var array<string, string> */
	private array $config = [];

	/** @var list<InstallRequest> */
	private array $installs = [];

	/** @var list<array{AuditSubject, AuditedVersions, string, ?string}> */
	private array $audits = [];

	/** @var array{statusCode: int, payload: array<string, mixed>} */
	private array $installAnswer = ['statusCode' => 200, 'payload' => ['installStatus' => 'installed']];

	/** @var list<array<string, mixed>> */
	private array $remoteApps = [];

	private ?string $remoteError = null;

	private function connection(): InstanceConnection {
		return new InstanceConnection([
			'id' => 'acceptance', 'name' => 'Acceptance', 'stage' => 'acceptance', 'order' => 1,
			'baseUrl' => 'https://acc.example.org', 'username' => 'reader', 'encryptedPassword' => 'x',
			'addedBy' => 'alice', 'addedAt' => '2026-11-01T00:00:00+00:00',
		]);
	}

	/**
	 * The remote runs openregister $version since $daysAgo days (null: unknown), from a forge source.
	 */
	private function remoteRuns(string $version, ?int $daysAgo, string $sourceId = 'github:ConductionNL/openregister'): void {
		$this->remoteApps = [[
			'appId' => 'openregister',
			'installedVersion' => $version,
			'state' => 'enabled',
			'sourceId' => $sourceId,
			'sha256' => self::SHA,
			'runningSince' => $daysAgo === null ? null : gmdate('c', self::NOW - $daysAgo * 86400),
		]];
	}

	private function service(string $localVersion = '2.3.0', int $minDays = 0): PromotionService {
		$this->config['promotion.min_days'] = (string)$minDays;

		$store = $this->createMock(InstanceConnectionStore::class);
		$store->method('get')->willReturnCallback(fn (string $id): ?InstanceConnection => $id === 'acceptance' ? $this->connection() : null);

		$reader = $this->createMock(RemoteInstanceReader::class);
		$reader->method('fetch')->willReturnCallback(fn (): array => [
			'manifest' => $this->remoteError === null ? ['apps' => $this->remoteApps] : null,
			'fetchedAt' => 'now', 'error' => $this->remoteError, 'checkedAt' => 'now',
		]);
		$reader->method('cached')->willReturnCallback(fn (): array => ['manifest' => ['apps' => $this->remoteApps], 'fetchedAt' => 'then', 'error' => null, 'checkedAt' => 'then']);

		$installer = $this->createMock(InstallerService::class);
		$installer->method('installAppVersion')->willReturnCallback(function (InstallRequest $request): array {
			$this->installs[] = $request;
			return $this->installAnswer;
		});

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default = ''): string => $this->config[$key] ?? $default);
		$appConfig->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value): bool {
			$this->config[$key] = $value;
			return true;
		});
		$appConfig->method('deleteKey')->willReturnCallback(function (string $app, string $key): void {
			unset($this->config[$key]);
		});

		$audit = $this->createMock(AuditLogger::class);
		$audit->method('record')->willReturnCallback(function (AuditSubject $s, AuditedVersions $v, string $status, ?string $message = null): void {
			$this->audits[] = [$s, $v, $status, $message];
		});

		$apps = $this->createMock(IAppManager::class);
		$apps->method('getAppVersion')->willReturn($localVersion);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new PromotionService(new PromotionPlanner($store, $reader, new InstanceSettings($appConfig), $apps, $time), $installer, $audit, $session);
	}

	public function testAnUpgradeInstallsTheRemoteVersionFromItsSourceWithItsDigestAndIsAudited(): void {
		$this->remoteRuns('2.4.1', 30);

		$result = $this->service()->promote('acceptance', 'openregister');

		self::assertSame(200, $result['statusCode']);
		self::assertCount(1, $this->installs);
		$request = $this->installs[0];
		self::assertSame(['openregister', '2.4.1', 'github:ConductionNL/openregister'], [$request->target->appId, $request->target->version, $request->target->sourceOverride]);
		self::assertSame(self::SHA, $request->options->expectedSha);
		self::assertFalse($request->options->allowDowngrade);
		self::assertFalse($request->options->acceptNewSha);
		self::assertSame(['id' => 'acceptance', 'name' => 'Acceptance', 'stage' => 'acceptance'], $result['payload']['promotedFrom']);
		self::assertSame('2.3.0', $result['payload']['previousVersion']);

		self::assertCount(1, $this->audits);
		[$subject, $versions, $status, $message] = $this->audits[0];
		self::assertSame(['alice', 'openregister', 'promote'], [$subject->actorUid, $subject->appId, $subject->operation]);
		self::assertSame(['2.3.0', '2.4.1', 'github:ConductionNL/openregister'], [$versions->fromVersion, $versions->toVersion, $versions->sourceId]);
		self::assertSame('success', $status);
		self::assertSame('Promoted from Acceptance (acceptance)', $message);
	}

	public function testAnAppStoreVersionIsPromotedWithoutADigest(): void {
		$this->remoteRuns('2.4.1', 30, '');

		$this->service()->promote('acceptance', 'openregister');

		self::assertSame('appstore', $this->installs[0]->target->sourceOverride);
		self::assertNull($this->installs[0]->options->expectedSha);
	}

	public function testADowngradeIsPlannedAsOneAndPassesTheAcknowledgementThrough(): void {
		$this->remoteRuns('2.2.0', 30);
		self::assertSame('downgrade', $this->service()->plan('acceptance', 'openregister')['direction']);

		$this->installAnswer = ['statusCode' => 409, 'payload' => ['code' => 'downgrade_requires_confirmation']];
		$refused = $this->service()->promote('acceptance', 'openregister');
		self::assertSame(409, $refused['statusCode']);
		self::assertSame('failure', $this->audits[0][2]);
		self::assertFalse($this->installs[0]->options->allowDowngrade);

		$this->installAnswer = ['statusCode' => 200, 'payload' => []];
		$this->service()->promote('acceptance', 'openregister', new PromotionOptions(allowDowngrade: true));
		self::assertTrue($this->installs[1]->options->allowDowngrade);
		self::assertSame('success', $this->audits[1][2]);
	}

	public function testAPinnedAppKeepsTheInstallersRefusal(): void {
		$this->remoteRuns('2.4.1', 30);
		$this->installAnswer = ['statusCode' => 409, 'payload' => ['code' => 'pinned', 'message' => 'openregister is pinned to 2.3.0.']];

		$result = $this->service()->promote('acceptance', 'openregister');

		self::assertSame(409, $result['statusCode']);
		self::assertSame('pinned', $result['payload']['code']);
		self::assertSame('failure', $this->audits[0][2]);
	}

	public function testTheWaitingPeriodNotMetIsRefusedWithTheDaysLeftAndInstallsNothing(): void {
		$this->remoteRuns('2.4.1', 5);

		$result = $this->service(minDays: 14)->promote('acceptance', 'openregister');

		self::assertSame(409, $result['statusCode']);
		self::assertSame('waiting_period', $result['payload']['code']);
		self::assertSame(9, $result['payload']['daysLeft']);
		self::assertSame('9 days are left in the waiting period of 14 days.', $result['payload']['message']);
		self::assertSame([], $this->installs);
		self::assertSame([], $this->audits);
	}

	public function testAnUnknownRunningSinceDoesNotMeetTheWaitingPeriod(): void {
		$this->remoteRuns('2.4.1', null);

		$plan = $this->service(minDays: 14)->plan('acceptance', 'openregister');
		$result = $this->service(minDays: 14)->promote('acceptance', 'openregister');

		self::assertFalse($plan['waitMet']);
		self::assertSame(14, $plan['daysLeft']);
		self::assertSame(409, $result['statusCode']);
		self::assertStringContainsString('is unknown', $result['payload']['message']);
	}

	public function testAnOverrideInstallsAndIsWrittenIntoTheAuditMessage(): void {
		$this->remoteRuns('2.4.1', 5);

		$result = $this->service(minDays: 14)->promote('acceptance', 'openregister', new PromotionOptions(overrideMinDays: true));

		self::assertSame(200, $result['statusCode']);
		self::assertCount(1, $this->installs);
		self::assertSame('Promoted from Acceptance (acceptance); the waiting period of 14 days was overridden with 9 days left', $this->audits[0][3]);
	}

	public function testARemoteThatNoLongerRunsThePlannedVersionIsRefused(): void {
		$this->remoteRuns('2.4.2', 30);

		$result = $this->service()->promote('acceptance', 'openregister', new PromotionOptions(plannedVersion: '2.4.1'));

		self::assertSame(409, $result['statusCode']);
		self::assertSame('version_moved', $result['payload']['code']);
		self::assertSame('Acceptance no longer runs 2.4.1; it runs 2.4.2 now.', $result['payload']['message']);
		self::assertSame([], $this->installs);
	}

	public function testUnknownUnreadableAndMissingAppsAreRefusedBeforeAnyInstall(): void {
		$this->remoteRuns('2.4.1', 30);
		self::assertSame([404, 'unknown_connection'], self::codeOf($this->service()->promote('production', 'openregister')));
		self::assertSame([409, 'not_on_remote'], self::codeOf($this->service()->promote('acceptance', 'opencatalogi')));

		$this->remoteError = 'This account is not an admin on that instance.';
		$unreadable = $this->service()->promote('acceptance', 'openregister');
		self::assertSame([502, 'connection_unreadable'], self::codeOf($unreadable));
		self::assertSame('This account is not an admin on that instance.', $unreadable['payload']['message']);
		self::assertSame([], $this->installs);
	}

	public function testADryRunPlansWithoutInstalling(): void {
		$this->remoteRuns('2.4.1', 30);

		$result = $this->service()->promote('acceptance', 'openregister', new PromotionOptions(dryRun: true));

		self::assertTrue($result['payload']['dryRun']);
		self::assertSame('2.4.1', $result['payload']['plan']['version']);
		self::assertSame([], $this->installs);
		self::assertSame([], $this->audits);
	}

	public function testTheWaitingPeriodSettingAcceptsZeroToNinety(): void {
		$this->service();
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default = ''): string => $this->config[$key] ?? $default);
		$appConfig->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value): bool {
			$this->config[$key] = $value;
			return true;
		});
		$appConfig->method('deleteKey')->willReturnCallback(function (string $app, string $key): void {
			unset($this->config[$key]);
		});
		$settings = new InstanceSettings($appConfig);

		$settings->setPromotionMinDays(14);
		self::assertSame(14, $settings->promotionMinDays());
		$settings->setPromotionMinDays(0);
		self::assertSame(0, $settings->promotionMinDays());
		self::assertArrayNotHasKey('promotion.min_days', $this->config);

		$this->expectException(\InvalidArgumentException::class);
		$settings->setPromotionMinDays(91);
	}

	/**
	 * @param array{statusCode: int, payload: array<string, mixed>} $result
	 * @return array{int, mixed}
	 */
	private static function codeOf(array $result): array {
		return [$result['statusCode'], $result['payload']['code'] ?? null];
	}
}
