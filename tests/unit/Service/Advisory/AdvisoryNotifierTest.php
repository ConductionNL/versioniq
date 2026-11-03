<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Tests\Unit\Service\Advisory;

require_once __DIR__ . '/../../Support/BuildsByParameterName.php';

use OCA\Versioniq\Service\Advisory\AdvisoryNotifier;
use OCA\Versioniq\Service\Advisory\AdvisoryService;
use OCA\Versioniq\Tests\Unit\Support\BuildsByParameterName;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class AdvisoryNotifierTest extends TestCase {
	use BuildsByParameterName;

	/** @var array<string, string> in-memory app-config backing the dedup store */
	private array $store = [];

	private function appConfig(): IAppConfig {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => $this->store[$key] ?? $default
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->store[$key] = $value;

				return true;
			}
		);

		return $config;
	}

	private function groupManagerWithAdmin(): IGroupManager {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn([$user]);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('get')->with('admin')->willReturn($group);

		return $groupManager;
	}

	private function fluentNotification(): INotification {
		$notification = $this->createMock(INotification::class);
		$notification->method('setApp')->willReturnSelf();
		$notification->method('setUser')->willReturnSelf();
		$notification->method('setDateTime')->willReturnSelf();
		$notification->method('setObject')->willReturnSelf();
		$notification->method('setSubject')->willReturnSelf();

		return $notification;
	}

	private function timeFactory(): ITimeFactory {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-07-07T00:00:00+00:00'));

		return $time;
	}

	/**
	 * @return array<string, array{appId: string, installedVersion: ?string, state: string, advisories: list<array{id: string, severity: string, summary: string}>, recommendedVersion: ?string, error: ?string}>
	 */
	private function vulnerableCorrelation(): array {
		return [
			'openregister' => [
				'appId' => 'openregister',
				'installedVersion' => '1.0.5',
				'state' => AdvisoryService::STATE_VULNERABLE,
				'advisories' => [['id' => 'GHSA-xxxx', 'severity' => 'high', 'summary' => 'SQLi']],
				'recommendedVersion' => '1.2.3',
				'error' => null,
			],
			'contacts' => [
				'appId' => 'contacts',
				'installedVersion' => '5.0.0',
				'state' => AdvisoryService::STATE_NONE,
				'advisories' => [],
				'recommendedVersion' => null,
				'error' => null,
			],
		];
	}

	public function testFiresNotificationForNewlyVulnerableApp(): void {
		$manager = $this->createMock(IManager::class);
		$manager->method('createNotification')->willReturn($this->fluentNotification());
		// Exactly one notification for the single pinned-to-vulnerable app.
		$manager->expects($this->once())->method('notify');

		$notifier = $this->buildByName(AdvisoryNotifier::class, [
			'notificationManager' => $manager,
			'appConfig' => $this->appConfig(),
			'groupManager' => $this->groupManagerWithAdmin(),
			'timeFactory' => $this->timeFactory(),
			'logger' => $this->createMock(LoggerInterface::class),
		]);

		$fired = $notifier->notifyNewAdvisories($this->vulnerableCorrelation());

		$this->assertSame(1, $fired);
	}

	public function testDoesNotReNotifyAlreadyNotifiedAdvisory(): void {
		$manager = $this->createMock(IManager::class);
		$manager->method('createNotification')->willReturn($this->fluentNotification());
		// First run notifies once; the second run must NOT notify again.
		$manager->expects($this->once())->method('notify');

		$notifier = $this->buildByName(AdvisoryNotifier::class, [
			'notificationManager' => $manager,
			'appConfig' => $this->appConfig(),
			'groupManager' => $this->groupManagerWithAdmin(),
			'timeFactory' => $this->timeFactory(),
			'logger' => $this->createMock(LoggerInterface::class),
		]);

		$firstRun = $notifier->notifyNewAdvisories($this->vulnerableCorrelation());
		$secondRun = $notifier->notifyNewAdvisories($this->vulnerableCorrelation());

		$this->assertSame(1, $firstRun);
		$this->assertSame(0, $secondRun);
	}

	public function testCleanCorrelationFiresNothing(): void {
		$manager = $this->createMock(IManager::class);
		$manager->expects($this->never())->method('notify');

		$notifier = $this->buildByName(AdvisoryNotifier::class, [
			'notificationManager' => $manager,
			'appConfig' => $this->appConfig(),
			'groupManager' => $this->groupManagerWithAdmin(),
			'timeFactory' => $this->timeFactory(),
			'logger' => $this->createMock(LoggerInterface::class),
		]);

		$clean = [
			'contacts' => [
				'appId' => 'contacts',
				'installedVersion' => '5.0.0',
				'state' => AdvisoryService::STATE_NONE,
				'advisories' => [],
				'recommendedVersion' => null,
				'error' => null,
			],
		];

		$this->assertSame(0, $notifier->notifyNewAdvisories($clean));
	}

	public function testNotifierHasNoVersionMutationDependency(): void {
		// Structural guarantee behind "MUST NOT auto-update or auto-unpin":
		// AdvisoryNotifier's constructor must not depend on any installer /
		// version-mutation service — it can only read config and notify.
		$constructorParams = (new \ReflectionClass(AdvisoryNotifier::class))
			->getConstructor()?->getParameters() ?? [];

		foreach ($constructorParams as $param) {
			$type = $param->getType();
			$typeName = $type instanceof \ReflectionNamedType ? $type->getName() : '';
			$this->assertStringNotContainsStringIgnoringCase('installer', $typeName);
			$this->assertStringNotContainsStringIgnoringCase('install', $typeName);
		}
	}
}
