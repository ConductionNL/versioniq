<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Advisory;

require_once __DIR__ . '/../../Support/BuildsByParameterName.php';

use OCA\Versioniq\Service\Advisory\AdvisoryDigestNotifier;
use OCA\Versioniq\Service\Advisory\AdvisoryService;
use OCA\Versioniq\Service\Advisory\AdvisorySettingsStore;
use OCA\Versioniq\Tests\Unit\Support\BuildsByParameterName;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class AdvisoryDigestNotifierTest extends TestCase {
	use BuildsByParameterName;

	private const WEEK = 7 * 24 * 60 * 60;

	/** @var array<string, int|bool> */
	private array $stored = [];

	private int $notifyCalls = 0;

	/** @var list<list<mixed>> Setter calls on the notification, without setDateTime. */
	private array $setterCalls = [];

	/**
	 * @param list<string> $admins
	 */
	private function notifier(bool $digestEnabled = true, array $admins = ['alice', 'bob'], bool $notifyThrows = false): AdvisoryDigestNotifier {
		$this->notifyCalls = 0;

		$notification = $this->createMock(INotification::class);
		$this->setterCalls = [];
		foreach (['setApp', 'setDateTime', 'setUser', 'setObject', 'setSubject'] as $setter) {
			$notification->method($setter)->willReturnCallback(function (mixed ...$args) use ($setter, $notification): INotification {
				if ($setter !== 'setDateTime') {
					$this->setterCalls[] = array_merge([$setter], $args);
				}

				return $notification;
			});
		}

		$manager = $this->createMock(IManager::class);
		$manager->method('createNotification')->willReturn($notification);
		$manager->method('notify')->willReturnCallback(function () use ($notifyThrows): void {
			$this->notifyCalls++;
			if ($notifyThrows) {
				throw new \RuntimeException('notifications app is gone');
			}
		});

		$users = array_map(function (string $uid): IUser {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);

			return $user;
		}, $admins);

		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn($users);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('get')->willReturn($group);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueInt')->willReturnCallback(
			fn (string $app, string $key, int $default = 0): int => (int)($this->stored[$key] ?? $default),
		);
		$config->method('setValueInt')->willReturnCallback(
			function (string $app, string $key, int $value): bool {
				$this->stored[$key] = $value;

				return true;
			},
		);

		$settings = $this->createMock(AdvisorySettingsStore::class);
		$settings->method('isDigestEnabled')->willReturn($digestEnabled);

		return $this->buildByName(AdvisoryDigestNotifier::class, [
			'notificationManager' => $manager,
			'groupManager' => $groupManager,
			'config' => $config,
			'settings' => $settings,
			'logger' => $this->createMock(LoggerInterface::class),
		]);
	}

	/**
	 * @param list<array{id: string, severity: string, summary: string}> $advisories
	 */
	private function correlation(string $appId, string $state, array $advisories = []): array {
		return [
			'appId' => $appId,
			'installedVersion' => '1.0.0',
			'state' => $state,
			'advisories' => $advisories,
			'recommendedVersion' => null,
			'error' => null,
		];
	}

	private function informational(string $appId): array {
		return $this->correlation($appId, AdvisoryService::STATE_AVAILABLE, [
			['id' => 'GHSA-' . $appId, 'severity' => 'medium', 'summary' => 'Historic issue'],
		]);
	}

	public function testSendsToEveryAdminWhenInformationalAdvisoriesExist(): void {
		$notifier = $this->notifier();

		$sent = $notifier->sendIfDue(['mail' => $this->informational('mail')], 1_700_000_000);

		$this->assertSame(2, $sent, 'both admins must be notified');
		$this->assertSame(2, $this->notifyCalls);
	}

	/**
	 * The urgent path already notifies immediately for these. Including them
	 * would mean an admin is told twice about the same thing.
	 */
	public function testIgnoresVulnerableEntriesWhichTheUrgentPathAlreadyCovers(): void {
		$notifier = $this->notifier();

		$sent = $notifier->sendIfDue([
			'mail' => $this->correlation('mail', AdvisoryService::STATE_VULNERABLE, [
				['id' => 'GHSA-x', 'severity' => 'high', 'summary' => 'Urgent'],
			]),
		], 1_700_000_000);

		$this->assertSame(0, $sent);
	}

	public function testSendsNothingWhenThereIsNothingInformationalToReport(): void {
		$notifier = $this->notifier();

		$sent = $notifier->sendIfDue(['mail' => $this->correlation('mail', AdvisoryService::STATE_NONE)], 1_700_000_000);

		$this->assertSame(0, $sent, 'a digest that says "nothing to report" weekly is how a channel stops being read');
	}

	/**
	 * A quiet week must NOT consume the window. Otherwise the first week with
	 * something to say waits another seven days.
	 */
	public function testAQuietWeekDoesNotAdvanceTheClock(): void {
		$notifier = $this->notifier();
		$notifier->sendIfDue(['mail' => $this->correlation('mail', AdvisoryService::STATE_NONE)], 1_700_000_000);

		$sent = $notifier->sendIfDue(['mail' => $this->informational('mail')], 1_700_000_001);

		$this->assertSame(2, $sent, 'the digest must send as soon as there is something to report');
	}

	public function testDoesNotResendInsideTheWeek(): void {
		$notifier = $this->notifier();
		$this->assertSame(2, $notifier->sendIfDue(['mail' => $this->informational('mail')], 1_700_000_000));

		$again = $notifier->sendIfDue(['mail' => $this->informational('mail')], 1_700_000_000 + self::WEEK - 60);

		$this->assertSame(0, $again);
	}

	public function testSendsAgainAfterAWeek(): void {
		$notifier = $this->notifier();
		$notifier->sendIfDue(['mail' => $this->informational('mail')], 1_700_000_000);

		$again = $notifier->sendIfDue(['mail' => $this->informational('mail')], 1_700_000_000 + self::WEEK);

		$this->assertSame(2, $again);
	}

	public function testSendsNothingWhenTheDigestIsDisabled(): void {
		$notifier = $this->notifier(digestEnabled: false);

		$this->assertSame(0, $notifier->sendIfDue(['mail' => $this->informational('mail')], 1_700_000_000));
		$this->assertSame(0, $this->notifyCalls);
	}

	/**
	 * A dispatch that reached nobody must not suppress the next seven days as
	 * well — that would turn one transient failure into a silent fortnight.
	 */
	public function testAFailedDispatchDoesNotConsumeTheWindow(): void {
		$notifier = $this->notifier(notifyThrows: true);
		$this->assertSame(0, $notifier->sendIfDue(['mail' => $this->informational('mail')], 1_700_000_000));

		$working = $this->notifier();
		$this->assertSame(2, $working->sendIfDue(['mail' => $this->informational('mail')], 1_700_000_060));
	}

	/**
	 * Each admin gets one notification counting the apps and their
	 * informational advisories.
	 */
	public function testTheDigestCountsAppsAndAdvisories(): void {
		$notifier = $this->notifier(admins: ['alice']);
		$twoAdvisories = $this->correlation('deck', AdvisoryService::STATE_AVAILABLE, [
			['id' => 'GHSA-1', 'severity' => 'low', 'summary' => 'one'],
			['id' => 'GHSA-2', 'severity' => 'low', 'summary' => 'two'],
		]);

		$notifier->sendIfDue(['mail' => $this->informational('mail'), 'deck' => $twoAdvisories], 1_700_000_000);

		$this->assertSame([
			['setApp', 'versioniq'],
			['setUser', 'alice'],
			['setObject', 'advisory_digest', '2'],
			['setSubject', 'advisory_digest', ['apps' => 2, 'advisories' => 3]],
		], $this->setterCalls);
	}
}
