<?php

declare(strict_types=1);

namespace OCA\Versioniq\Tests\Unit\Service\Pat;

use OCA\Versioniq\Db\Pat;
use OCA\Versioniq\Service\Pat\PatDeeplinkBuilder;
use OCA\Versioniq\Service\Pat\PatExpiryEvaluator;
use OCA\Versioniq\Service\Pat\PatExpiryNotifier;
use OCA\Versioniq\Service\Source\ForgeRegistry;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class PatExpiryNotifierTest extends TestCase {
	private function pat(int $id = 1, string $kind = Pat::KIND_CLASSIC): Pat {
		return Pat::fromRow([
			'id' => $id,
			'owner_uid' => 'alice',
			'label' => 'conduction-bot',
			'target_pattern' => 'ConductionNL/*',
			'kind' => $kind,
			'forge' => 'github',
			'encrypted_token' => 'x',
			'token_hint' => 'x',
			'shared_with_admins' => false,
			'expires_at' => '2026-08-04 00:00:00',
			'created_at' => '2026-01-01 00:00:00',
			'warned_thresholds' => '[]',
		]);
	}

	private function deeplinkBuilder(): PatDeeplinkBuilder {
		$request = $this->createMock(IRequest::class);
		$request->method('getServerHost')->willReturn('cloud.example.com');

		return new PatDeeplinkBuilder($request, new ForgeRegistry($this->createMock(\OCP\IAppConfig::class)));
	}

	private function timeFactory(): ITimeFactory {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-07-23T00:00:00+00:00'));

		return $time;
	}

	public function testExpiringNotificationTargetsOwnerWithDeeplinkAndDays(): void {
		$notification = $this->createMock(INotification::class);
		$notification->method('setApp')->willReturnSelf();
		$notification->method('setUser')->with('alice')->willReturnSelf();
		$notification->method('setDateTime')->willReturnSelf();
		$notification->method('setObject')->with('pat', '1')->willReturnSelf();
		$notification->method('setLink')->with($this->stringContains('github.com/settings/tokens/new'))->willReturnSelf();
		$notification->expects($this->once())
			->method('setSubject')
			->with('pat_expiring', ['label' => 'conduction-bot', 'forge' => 'github', 'daysRemaining' => 5])
			->willReturnSelf();

		$manager = $this->createMock(IManager::class);
		$manager->method('createNotification')->willReturn($notification);
		$manager->expects($this->once())->method('notify');

		$notifier = new PatExpiryNotifier($manager, $this->deeplinkBuilder(), $this->timeFactory(), $this->createMock(LoggerInterface::class));

		$result = $notifier->notify($this->pat(), PatExpiryEvaluator::THRESHOLD_14D, 5);

		$this->assertTrue($result);
	}

	public function testExpiredNotificationOmitsDaysRemaining(): void {
		$notification = $this->createMock(INotification::class);
		$notification->method('setApp')->willReturnSelf();
		$notification->method('setUser')->willReturnSelf();
		$notification->method('setDateTime')->willReturnSelf();
		$notification->method('setObject')->willReturnSelf();
		$notification->method('setLink')->willReturnSelf();
		$notification->expects($this->once())
			->method('setSubject')
			->with('pat_expired', ['label' => 'conduction-bot', 'forge' => 'github'])
			->willReturnSelf();

		$manager = $this->createMock(IManager::class);
		$manager->method('createNotification')->willReturn($notification);
		$manager->expects($this->once())->method('notify');

		$notifier = new PatExpiryNotifier($manager, $this->deeplinkBuilder(), $this->timeFactory(), $this->createMock(LoggerInterface::class));

		$result = $notifier->notify($this->pat(), PatExpiryEvaluator::THRESHOLD_EXPIRED, null);

		$this->assertTrue($result);
	}

	public function testNotifyFailureIsLoggedAndReturnsFalse(): void {
		$manager = $this->createMock(IManager::class);
		$manager->method('createNotification')->willThrowException(new \RuntimeException('boom'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		$notifier = new PatExpiryNotifier($manager, $this->deeplinkBuilder(), $this->timeFactory(), $logger);

		$result = $notifier->notify($this->pat(), PatExpiryEvaluator::THRESHOLD_14D, 5);

		$this->assertFalse($result);
	}
}
