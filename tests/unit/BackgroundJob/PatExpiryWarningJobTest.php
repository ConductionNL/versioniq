<?php

declare(strict_types=1);

namespace OCA\Versioniq\Tests\Unit\BackgroundJob;

require_once __DIR__ . '/../Support/BuildsByParameterName.php';

use OCA\Versioniq\BackgroundJob\PatExpiryWarningJob;
use OCA\Versioniq\Db\Pat;
use OCA\Versioniq\Db\PatMapper;
use OCA\Versioniq\Service\Pat\PatExpiryEvaluator;
use OCA\Versioniq\Service\Pat\PatExpiryNotifier;
use OCA\Versioniq\Tests\Unit\Support\BuildsByParameterName;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class PatExpiryWarningJobTest extends TestCase {
	use BuildsByParameterName;

	private function pat(int $id, ?string $expiresAt, array $warned = []): Pat {
		return Pat::fromRow([
			'id' => $id,
			'owner_uid' => 'alice',
			'label' => 'token-' . $id,
			'target_pattern' => 'ConductionNL/*',
			'kind' => Pat::KIND_CLASSIC,
			'forge' => 'github',
			'encrypted_token' => 'x',
			'token_hint' => 'x',
			'shared_with_admins' => false,
			'expires_at' => $expiresAt,
			'created_at' => '2026-01-01 00:00:00',
			'warned_thresholds' => json_encode($warned, JSON_THROW_ON_ERROR),
		]);
	}

	private function job(
		PatMapper $mapper,
		PatExpiryEvaluator $evaluator,
		PatExpiryNotifier $notifier,
		?LoggerInterface $logger = null,
	): PatExpiryWarningJob {
		return $this->buildByName(PatExpiryWarningJob::class, [
			'time' => $this->createMock(ITimeFactory::class),
			'patMapper' => $mapper,
			'evaluator' => $evaluator,
			'notifier' => $notifier,
			'logger' => $logger ?? $this->createMock(LoggerInterface::class),
		]);
	}

	private function invokeRun(PatExpiryWarningJob $job): void {
		$method = new \ReflectionMethod($job, 'run');
		$method->setAccessible(true);
		$method->invoke($job, null);
	}

	public function testSweepsOnceADay(): void {
		$job = $this->job($this->createMock(PatMapper::class), $this->createMock(PatExpiryEvaluator::class), $this->createMock(PatExpiryNotifier::class));

		$this->assertSame(24 * 60 * 60, $job->getInterval());
	}

	public function testUnknownExpiryIsNeverProbedOrNotified(): void {
		$mapper = $this->createMock(PatMapper::class);
		$mapper->method('findAll')->willReturn([$this->pat(1, null)]);
		$mapper->expects($this->never())->method('update');

		$evaluator = $this->createMock(PatExpiryEvaluator::class);
		$evaluator->expects($this->never())->method('highestCrossedThreshold');

		$notifier = $this->createMock(PatExpiryNotifier::class);
		$notifier->expects($this->never())->method('notify');

		$this->invokeRun($this->job($mapper, $evaluator, $notifier));
	}

	public function test14dThresholdNotifiesOnceAndPersistsLedger(): void {
		$pat = $this->pat(1, '2026-08-04 00:00:00');
		$mapper = $this->createMock(PatMapper::class);
		$mapper->method('findAll')->willReturn([$pat]);
		$mapper->expects($this->once())->method('update')->willReturnArgument(0);

		$evaluator = $this->createMock(PatExpiryEvaluator::class);
		$evaluator->method('highestCrossedThreshold')->willReturn(PatExpiryEvaluator::THRESHOLD_14D);
		$evaluator->method('daysRemaining')->willReturn(12);

		$notifier = $this->createMock(PatExpiryNotifier::class);
		$notifier->expects($this->once())
			->method('notify')
			->with($pat, PatExpiryEvaluator::THRESHOLD_14D, 12)
			->willReturn(true);

		$this->invokeRun($this->job($mapper, $evaluator, $notifier));

		$this->assertTrue($pat->hasWarnedThreshold(PatExpiryEvaluator::THRESHOLD_14D));
	}

	public function testAlreadyWarnedThresholdIsNotRenotified(): void {
		$pat = $this->pat(1, '2026-08-04 00:00:00', ['14d']);
		$mapper = $this->createMock(PatMapper::class);
		$mapper->method('findAll')->willReturn([$pat]);
		$mapper->expects($this->never())->method('update');

		$evaluator = $this->createMock(PatExpiryEvaluator::class);
		$evaluator->method('highestCrossedThreshold')->willReturn(PatExpiryEvaluator::THRESHOLD_14D);

		$notifier = $this->createMock(PatExpiryNotifier::class);
		$notifier->expects($this->never())->method('notify');

		$this->invokeRun($this->job($mapper, $evaluator, $notifier));
	}

	public function testFailedNotifyLeavesLedgerUntouchedForRetry(): void {
		$pat = $this->pat(1, '2026-08-04 00:00:00');
		$mapper = $this->createMock(PatMapper::class);
		$mapper->method('findAll')->willReturn([$pat]);
		$mapper->expects($this->never())->method('update');

		$evaluator = $this->createMock(PatExpiryEvaluator::class);
		$evaluator->method('highestCrossedThreshold')->willReturn(PatExpiryEvaluator::THRESHOLD_14D);
		$evaluator->method('daysRemaining')->willReturn(12);

		$notifier = $this->createMock(PatExpiryNotifier::class);
		$notifier->method('notify')->willReturn(false);

		$this->invokeRun($this->job($mapper, $evaluator, $notifier));

		$this->assertFalse($pat->hasWarnedThreshold(PatExpiryEvaluator::THRESHOLD_14D));
	}

	public function testOneBadTokenDoesNotBlockTheRestOfTheSweep(): void {
		$badPat = $this->pat(1, '2026-08-04 00:00:00');
		$goodPat = $this->pat(2, '2026-08-06 00:00:00');

		$mapper = $this->createMock(PatMapper::class);
		$mapper->method('findAll')->willReturn([$badPat, $goodPat]);
		$mapper->expects($this->once())->method('update')->willReturnArgument(0);

		$evaluator = $this->createMock(PatExpiryEvaluator::class);
		$evaluator->method('highestCrossedThreshold')->willReturnCallback(
			function (?string $expiresAt) {
				if ($expiresAt === '2026-08-04 00:00:00') {
					throw new \RuntimeException('evaluator exploded');
				}

				return PatExpiryEvaluator::THRESHOLD_14D;
			}
		);
		$evaluator->method('daysRemaining')->willReturn(14);

		$notifier = $this->createMock(PatExpiryNotifier::class);
		$notifier->expects($this->once())
			->method('notify')
			->with($goodPat, PatExpiryEvaluator::THRESHOLD_14D, 14)
			->willReturn(true);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		$this->invokeRun($this->job($mapper, $evaluator, $notifier, $logger));

		$this->assertTrue($goodPat->hasWarnedThreshold(PatExpiryEvaluator::THRESHOLD_14D));
	}
}
