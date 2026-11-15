<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Alert;

use OCA\Versioniq\BackgroundJob\WebhookDeliveryJob;
use OCA\Versioniq\Notification\Notifier;
use OCA\Versioniq\Service\Alert\AlertDispatcher;
use OCA\Versioniq\Service\Alert\AlertText;
use OCA\Versioniq\Service\Alert\WebhookSender;
use OCA\Versioniq\Service\Alert\WebhookTargetStore;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Dispatch queues one job per matching target and never throws; the job
 * delivers, retries up to three attempts in all and then records the failure.
 *
 * @covers \OCA\Versioniq\Service\Alert\AlertDispatcher
 * @covers \OCA\Versioniq\Service\Alert\AlertText
 * @covers \OCA\Versioniq\BackgroundJob\WebhookDeliveryJob
 * @spec openspec/changes/releases-new-release-alerts/specs/alert-webhooks/spec.md#requirement-every-versioniq-alert-is-delivered-to-matching-targets-in-the-background
 */
final class AlertDeliveryTest extends TestCase {
	private const ALERT = ['kind' => 'auto_update', 'subject' => 'auto_update_failure', 'parameters' => ['app' => 'openregister'], 'text' => 'Automatic update failed'];

	/** @var list<array{0: string, 1: mixed}> */
	private array $queued = [];

	private function jobList(): IJobList {
		$jobs = $this->createMock(IJobList::class);
		$jobs->method('add')->willReturnCallback(function (string $class, mixed $argument): void {
			$this->queued[] = [$class, $argument];
		});

		return $jobs;
	}

	private function text(): AlertText {
		$text = $this->createMock(AlertText::class);
		$text->method('english')->willReturn('Automatic update failed');

		return $text;
	}

	public function testDispatchQueuesOneJobPerMatchingTargetAndSendsNothing(): void {
		$store = $this->createMock(WebhookTargetStore::class);
		$store->expects(self::once())->method('idsFor')->with('auto_update')->willReturn(['a1', 'b2']);

		(new AlertDispatcher($store, $this->jobList(), $this->text(), $this->createMock(LoggerInterface::class)))
			->dispatch('auto_update_failure', ['app' => 'openregister']);

		self::assertSame([
			[WebhookDeliveryJob::class, ['targetId' => 'a1', 'alert' => self::ALERT, 'attempt' => 1]],
			[WebhookDeliveryJob::class, ['targetId' => 'b2', 'alert' => self::ALERT, 'attempt' => 1]],
		], $this->queued);
	}

	public function testEverySubjectMapsToItsKindAndAnUnknownSubjectQueuesNothing(): void {
		$asked = [];
		$store = $this->createMock(WebhookTargetStore::class);
		$store->method('idsFor')->willReturnCallback(function (string $kind) use (&$asked): array {
			$asked[] = $kind;

			return [];
		});
		$dispatcher = new AlertDispatcher($store, $this->jobList(), $this->text(), $this->createMock(LoggerInterface::class));
		foreach (['new_release', 'pinned_to_vulnerable', 'advisory_digest', 'auto_update_success', 'pin_drift', 'pat_expired', 'pat_expiring', 'something_else'] as $subject) {
			$dispatcher->dispatch($subject, []);
		}

		self::assertSame(['new_release', 'security', 'security', 'auto_update', 'pin_drift', 'token', 'token'], $asked);
		self::assertSame([], $this->queued);
	}

	public function testAFailingStoreIsLoggedAndNeverThrows(): void {
		$store = $this->createMock(WebhookTargetStore::class);
		$store->method('idsFor')->willThrowException(new \RuntimeException('config down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning');

		(new AlertDispatcher($store, $this->jobList(), $this->text(), $logger))->dispatch('pin_drift', []);

		self::assertSame([], $this->queued);
	}

	/**
	 * @param list<array{ok: bool, error: ?string}> $answers
	 * @return list<array{0: string, 1: bool, 2: ?string}> The recorded deliveries.
	 */
	private function runJob(array $argument, array $answers, ?array $target = null): array {
		$recorded = [];
		$store = $this->createMock(WebhookTargetStore::class);
		$store->method('forDelivery')->willReturn($target ?? ['id' => 'a1', 'name' => 'Ops', 'url' => 'https://hooks.example.org', 'format' => 'json', 'kinds' => ['auto_update'], 'enabled' => true, 'secret' => 'k']);
		$store->method('recordDelivery')->willReturnCallback(function (string $id, array $result) use (&$recorded): void {
			$recorded[] = [$id, $result['ok'], $result['error']];
		});
		$sender = $this->createMock(WebhookSender::class);
		$sender->method('send')->willReturnOnConsecutiveCalls(...$answers);
		$job = new WebhookDeliveryJob($this->createMock(ITimeFactory::class), $store, $sender, $this->jobList());
		$run = new \ReflectionMethod($job, 'run');
		$run->setAccessible(true);
		$run->invoke($job, $argument);

		return $recorded;
	}

	public function testASuccessIsRecorded(): void {
		self::assertSame([['a1', true, null]], $this->runJob(['targetId' => 'a1', 'alert' => self::ALERT, 'attempt' => 1], [['ok' => true, 'error' => null]]));
		self::assertSame([], $this->queued);
	}

	public function testAFailureBeforeTheThirdAttemptQueuesARetryAndRecordsNothing(): void {
		self::assertSame([], $this->runJob(['targetId' => 'a1', 'alert' => self::ALERT, 'attempt' => 2], [['ok' => false, 'error' => 'HTTP 500']]));
		self::assertSame([[WebhookDeliveryJob::class, ['attempt' => 3, 'targetId' => 'a1', 'alert' => self::ALERT]]], $this->queued);
	}

	public function testTheThirdFailedAttemptIsRecordedAndNotRetried(): void {
		self::assertSame([['a1', false, 'HTTP 500']], $this->runJob(['targetId' => 'a1', 'alert' => self::ALERT, 'attempt' => 3], [['ok' => false, 'error' => 'HTTP 500']]));
		self::assertSame([], $this->queued);
	}

	public function testARemovedOrDisabledTargetIsSkipped(): void {
		$disabled = ['id' => 'a1', 'name' => 'Ops', 'url' => 'https://hooks.example.org', 'format' => 'json', 'kinds' => ['auto_update'], 'enabled' => false, 'secret' => null];
		self::assertSame([], $this->runJob(['targetId' => 'a1', 'alert' => self::ALERT, 'attempt' => 1], [], $disabled));
		self::assertSame([], $this->runJob(['alert' => self::ALERT], []));
		self::assertSame([], $this->queued);
	}

	public function testTheTextIsTheEnglishRenderingOfTheNotifier(): void {
		$notification = $this->createMock(INotification::class);
		foreach (['setApp', 'setUser', 'setDateTime', 'setObject', 'setSubject'] as $setter) {
			$notification->method($setter)->willReturnSelf();
		}
		$notification->method('getParsedSubject')->willReturn('New version of hermiq: 1.5.0');
		$notification->method('getParsedMessage')->willReturn('');
		$manager = $this->createMock(IManager::class);
		$manager->method('createNotification')->willReturn($notification);
		$notifier = $this->createMock(Notifier::class);
		$notifier->expects(self::exactly(2))->method('prepare')->with($notification, 'en')
			->willReturnOnConsecutiveCalls($notification, self::throwException(new \InvalidArgumentException('unknown')));

		$text = new AlertText($notifier, $manager);
		self::assertSame('New version of hermiq: 1.5.0', $text->english('new_release', ['app' => 'hermiq', 'version' => '1.5.0']));
		self::assertSame('mystery', $text->english('mystery', []));
	}
}
