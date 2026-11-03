<?php

declare(strict_types=1);

namespace OCA\Versioniq\Tests\Unit\Service;

require_once __DIR__ . '/../Support/BuildsByParameterName.php';

use DateTime;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateNotifier;
use OCA\Versioniq\Service\Pin\Pin;
use OCA\Versioniq\Service\Pin\PinDriftHandler;
use OCA\Versioniq\Service\Pin\PinStore;
use OCA\Versioniq\Tests\Unit\Support\BuildsByParameterName;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden test for the linked admin notifications of AutoUpdateNotifier
 * (success and failure) and PinDriftHandler (drift): admin group, URL
 * generator, notification manager, clock and logger are recording doubles,
 * and every collaborator and notification call is compared, case by case
 * as JSON text, with fixtures/admin-notifications.golden.json. Set
 * RECORD_GOLDEN=1 to rewrite the fixture.
 *
 * @covers \OCA\Versioniq\Service\AutoUpdate\AutoUpdateNotifier
 * @covers \OCA\Versioniq\Service\Pin\PinDriftHandler
 * @uses \OCA\Versioniq\Service\AdminNotice
 * @uses \OCA\Versioniq\Service\NotifiesAdmins
 * @uses \OCA\Versioniq\Service\Pin\Pin
 * @uses \OCA\Versioniq\Service\Pin\PinDrift
 */
final class AdminNotificationsGoldenTest extends TestCase {
	use BuildsByParameterName;

	private const FIXTURE = __DIR__ . '/fixtures/admin-notifications.golden.json';

	/** @var list<array<array-key, mixed>> */
	private array $calls = [];

	private int $notifications = 0;

	/**
	 * @return array<string, array{0: string, 1: list<string>|null, 2: array<string, mixed>}>
	 */
	private static function cases(): array {
		$admins = [
			'none' => null,
			'empty' => [],
			'two' => ['alice', 'bob'],
			'duplicate' => ['alice', 'alice', 'bob'],
			'create fails' => ['alice', '!create', 'bob'],
			'subject fails' => ['alice', '!subject'],
			'notify fails' => ['!notify', 'bob'],
		];
		$cases = [];
		foreach ($admins as $label => $uids) {
			$cases["success $label"] = ['success', $uids, ['app' => 'openregister', 'from' => '1.0.0', 'to' => '1.1.0']];
			$cases["failure $label"] = ['failure', $uids, ['app' => 'openregister', 'to' => '1.1.0', 'category' => 'network', 'hint' => 'Check the proxy.']];
			$cases["drift $label"] = ['drift', $uids, ['app' => 'openregister', 'pinned' => '2.3.0', 'installed' => '2.5.0', 'newly' => true]];
		}
		$cases['success no from'] = ['success', ['alice'], ['app' => 'calendar', 'from' => null, 'to' => '4.0.0']];
		$cases['drift matches pin'] = ['drift', ['alice'], ['app' => 'openregister', 'pinned' => '2.5.0', 'installed' => '2.5.0', 'newly' => true]];
		$cases['drift already recorded'] = ['drift', ['alice'], ['app' => 'openregister', 'pinned' => '2.3.0', 'installed' => '2.5.0', 'newly' => false]];
		$cases['drift no pin'] = ['drift', ['alice'], ['app' => 'openregister', 'pinned' => null, 'installed' => '2.5.0', 'newly' => true]];

		return $cases;
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function caseNames(): array {
		$names = [];
		foreach (array_keys(self::cases()) as $name) {
			$names[$name] = [$name];
		}

		return $names;
	}

	/**
	 * @dataProvider caseNames
	 */
	public function testCase(string $name): void {
		[$kind, $uids, $args] = self::cases()[$name];
		$this->calls = [];
		$this->notifications = 0;
		try {
			match ($kind) {
				'success' => $this->autoUpdateNotifier($uids)->notifySuccess($args['app'], $args['from'], $args['to']),
				'failure' => $this->autoUpdateNotifier($uids)->notifyFailure($args['app'], $args['to'], $args['category'], $args['hint']),
				'drift' => $this->driftHandler($uids, $args)->handle($args['app'], $args['installed']),
			};
			$result = 'returned';
		} catch (\Throwable $error) {
			$result = ['threw' => $error::class, 'message' => $error->getMessage()];
		}
		$actual = json_encode(['result' => $result, 'calls' => $this->calls], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

		$fixture = is_file(self::FIXTURE) ? (array)json_decode((string)file_get_contents(self::FIXTURE), true, 512, JSON_THROW_ON_ERROR) : [];
		if (getenv('RECORD_GOLDEN') === '1') {
			$fixture[$name] = $actual;
			ksort($fixture);
			file_put_contents(self::FIXTURE, json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
		}

		$this->assertArrayHasKey($name, $fixture);
		$this->assertSame($fixture[$name], $actual);
	}

	/**
	 * @param list<string>|null $uids
	 */
	private function autoUpdateNotifier(?array $uids): AutoUpdateNotifier {
		return $this->buildByName(AutoUpdateNotifier::class, [
			'notificationManager' => $this->notificationManager(),
			'groupManager' => $this->groupManager($uids),
			'timeFactory' => $this->timeFactory(),
			'urlGenerator' => $this->urlGenerator(),
			'logger' => $this->logger(),
		]);
	}

	/**
	 * @param list<string>|null $uids
	 * @param array<string, mixed> $args
	 */
	private function driftHandler(?array $uids, array $args): PinDriftHandler {
		$pinStore = $this->createMock(PinStore::class);
		$pinStore->method('get')->willReturnCallback(function (string $appId) use ($args): ?Pin {
			$this->calls[] = ['pinStore.get', $appId];

			return $args['pinned'] === null ? null : new Pin($args['pinned'], 'alice', '2026-06-11T12:00:00+00:00');
		});
		$pinStore->method('markDrift')->willReturnCallback(function (string $appId, mixed $drift) use ($args): bool {
			$this->calls[] = ['pinStore.markDrift', $appId, (array)$drift];

			return $args['newly'];
		});

		return $this->buildByName(PinDriftHandler::class, [
			'pinStore' => $pinStore,
			'notificationManager' => $this->notificationManager(),
			'groupManager' => $this->groupManager($uids),
			'timeFactory' => $this->timeFactory(),
			'urlGenerator' => $this->urlGenerator(),
			'logger' => $this->logger(),
		]);
	}

	/**
	 * @param list<string>|null $uids
	 */
	private function groupManager(?array $uids): IGroupManager {
		$groupManager = $this->createMock(IGroupManager::class);
		$group = null;
		if ($uids !== null) {
			$users = [];
			foreach ($uids as $uid) {
				$user = $this->createMock(IUser::class);
				$user->method('getUID')->willReturn($uid);
				$users[] = $user;
			}
			$group = $this->createMock(IGroup::class);
			$group->method('getUsers')->willReturn($users);
		}
		$groupManager->method('get')->willReturnCallback(function (string $gid) use ($group): ?IGroup {
			$this->calls[] = ['groupManager.get', $gid];

			return $group;
		});

		return $groupManager;
	}

	private function notificationManager(): IManager {
		$manager = $this->createMock(IManager::class);
		$manager->method('createNotification')->willReturnCallback(function (): INotification {
			$id = ++$this->notifications;
			$this->calls[] = ['manager.createNotification', $id];

			return $this->notification($id);
		});
		$manager->method('notify')->willReturnCallback(function (INotification $notification): void {
			$this->calls[] = ['manager.notify', $notification->getUser()];
			if ($notification->getUser() === '!notify') {
				throw new RuntimeException('push failed');
			}
		});

		return $manager;
	}

	private function notification(int $id): INotification {
		$notification = $this->createMock(INotification::class);
		$user = '';
		foreach (['setApp', 'setUser', 'setDateTime', 'setObject', 'setSubject', 'setLink'] as $method) {
			$notification->method($method)->willReturnCallback(function (mixed ...$args) use ($id, $method, $notification, &$user): INotification {
				$this->calls[] = ["notification$id.$method", ...array_map(static fn (mixed $arg): mixed => $arg instanceof \DateTimeInterface ? $arg->format(DATE_ATOM) : $arg, $args)];
				if ($method === 'setUser') {
					$user = $args[0];
					if ($user === '!create') {
						throw new \InvalidArgumentException('bad user');
					}
				}
				if ($method === 'setSubject' && $user === '!subject') {
					throw new \InvalidArgumentException('bad subject');
				}

				return $notification;
			});
		}
		$notification->method('getUser')->willReturnCallback(static function () use (&$user): string {
			return $user;
		});

		return $notification;
	}

	private function timeFactory(): ITimeFactory {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(function (string $when = 'now', ?\DateTimeZone $zone = null): DateTime {
			$this->calls[] = ['time.getDateTime', $when, $zone?->getName()];

			return new DateTime('2026-07-01T10:00:00+00:00');
		});

		return $time;
	}

	private function urlGenerator(): IURLGenerator {
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturnCallback(function (string $route, array $parameters = []): string {
			$this->calls[] = ['url.linkToRouteAbsolute', $route, $parameters];

			return 'https://cloud.example/settings/admin/' . ($parameters['section'] ?? '');
		});

		return $urls;
	}

	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function (string|\Stringable $message, array $context = []): void {
			$this->calls[] = ['logger.warning', (string)$message, $context];
		});

		return $logger;
	}
}
