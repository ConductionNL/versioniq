<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\BackgroundJob;

use OCA\Versioniq\BackgroundJob\ExternalChangeReconcileJob;
use OCA\Versioniq\Service\Advisory\ServerVersionProvider;
use OCA\Versioniq\Service\Audit\ExternalChangeRecorder;
use OCA\Versioniq\Service\Audit\SeenStateStore;
use OCA\Versioniq\Service\Reactivation\ReactivationService;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Versioniq\BackgroundJob\ExternalChangeReconcileJob
 * @uses \OCA\Versioniq\Service\Audit\SeenStateStore
 */
final class ExternalChangeReconcileJobTest extends TestCase {
	private string $stored = '';
	/** @var list<array{0: string, 1: ?string, 2: ?string}> */
	private array $updates = [];
	private ReactivationService&MockObject $reactivation;

	protected function setUp(): void {
		$this->reactivation = $this->createMock(ReactivationService::class);
	}

	/**
	 * @param array<string, string> $versions installed versions by app id
	 * @param list<string> $enabled
	 */
	private function job(array $versions, array $enabled, string $server = '33.0.2'): ExternalChangeReconcileJob {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(fn (): string => $this->stored);
		$config->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value): bool {
			$this->stored = $value;

			return true;
		});
		$config->method('searchValues')->with('installed_version')->willReturn($versions);

		$recorder = $this->createMock(ExternalChangeRecorder::class);
		$recorder->method('recordUpdate')->willReturnCallback(function (string $appId, ?string $from, ?string $to, string $trigger, string $actor): void {
			$this->assertSame(ExternalChangeRecorder::HOURLY_TRIGGER, $trigger);
			$this->assertSame('system', $actor);
			$this->updates[] = [$appId, $from, $to];
		});

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getEnabledApps')->willReturn($enabled);
		$provider = $this->createMock(ServerVersionProvider::class);
		$provider->method('current')->willReturn($server);

		return new ExternalChangeReconcileJob($this->createMock(ITimeFactory::class), new SeenStateStore($config), $recorder, $this->reactivation, $appManager, $config, $provider, $this->createMock(LoggerInterface::class));
	}

	private function seed(array $apps, string $server = '33.0.2'): void {
		$this->stored = (string)json_encode(['serverVersion' => $server, 'apps' => $apps, 'disabledByUpdate' => (object)[]]);
	}

	public function testRunsHourly(): void {
		$this->assertSame(3600, $this->job([], [])->getInterval());
	}

	public function testTheFirstRunStoresABaselineAndRecordsNothing(): void {
		$this->reactivation->expects($this->never())->method('appDisabled');

		$this->job(['forms' => '4.3.0', 'calendar' => '5.1.0'], ['forms'])->reconcile();

		$this->assertSame([], $this->updates);
		$this->assertSame([
			'serverVersion' => '33.0.2',
			'apps' => ['calendar' => ['version' => '5.1.0', 'enabled' => false], 'forms' => ['version' => '4.3.0', 'enabled' => true]],
			'disabledByUpdate' => [],
		], json_decode($this->stored, true));
	}

	public function testAVersionChangedWithoutAnEventIsRecordedAsFoundByTheHourlyCheck(): void {
		$this->seed(['forms' => ['version' => '4.3.0', 'enabled' => true]]);

		$this->job(['forms' => '4.2.1'], ['forms'])->reconcile();

		$this->assertSame([['forms', '4.3.0', '4.2.1']], $this->updates);
		$this->assertSame('4.2.1', json_decode($this->stored, true)['apps']['forms']['version']);
	}

	public function testAnAppThatAppearedOrWentAwayIsRecordedWithANullVersion(): void {
		$this->seed(['gone' => ['version' => '1.0.0', 'enabled' => true]]);

		$this->job(['fresh' => '2.0.0'], ['fresh'])->reconcile();

		$this->assertSame([['fresh', null, '2.0.0'], ['gone', '1.0.0', null]], $this->updates);
		$this->assertSame(['fresh'], array_keys(json_decode($this->stored, true)['apps']));
	}

	public function testAVersioniqInstallAlreadyInTheRecordIsNotRecordedAgain(): void {
		// InstallFinalizer already stored 2.4.0 when Versioniq installed it.
		$this->seed(['openregister' => ['version' => '2.4.0', 'enabled' => true]]);
		$this->reactivation->expects($this->never())->method('appDisabled');

		$this->job(['openregister' => '2.4.0'], ['openregister'])->reconcile();

		$this->assertSame([], $this->updates);
	}

	public function testAnEnabledAppFoundDisabledGoesToReactivationWithThePictureFromBefore(): void {
		$this->seed(['forms' => ['version' => '4.3.0', 'enabled' => true], 'notes' => ['version' => '1.0.0', 'enabled' => false]]);
		$this->reactivation->expects($this->once())->method('appDisabled')
			->with('forms', ['version' => '4.3.0', 'enabled' => true], '33.0.2', 'system', ExternalChangeRecorder::HOURLY_TRIGGER);

		$this->job(['forms' => '4.3.0', 'notes' => '1.0.0'], [], '34.0.0')->reconcile();

		$this->assertSame('34.0.0', json_decode($this->stored, true)['serverVersion']);
	}

	public function testAnAppEnabledAgainLosesItsLeftDisabledMark(): void {
		$this->stored = (string)json_encode([
			'serverVersion' => '34.0.0',
			'apps' => ['forms' => ['version' => '4.3.0', 'enabled' => false], 'notes' => ['version' => '1.0.0', 'enabled' => false]],
			'disabledByUpdate' => ['forms' => ['toVersion' => 'Nextcloud 34', 'compatible' => true, 'compatibleVersion' => null], 'notes' => ['toVersion' => '1.0.0', 'compatible' => false, 'compatibleVersion' => null]],
		]);

		$this->job(['forms' => '4.3.0', 'notes' => '1.0.0'], ['forms'], '34.0.0')->reconcile();

		$this->assertSame(['notes'], array_keys(json_decode($this->stored, true)['disabledByUpdate']));
	}
}
