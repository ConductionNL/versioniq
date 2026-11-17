<?php

declare(strict_types=1);

/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Command;

use OCA\Versioniq\Command\PromoteVersion;
use OCA\Versioniq\Service\Installer\FailureClassifier;
use OCA\Versioniq\Service\Instance\PromotionOptions;
use OCA\Versioniq\Service\Instance\PromotionService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `occ versioniq:promote`: the order of the promotions, the options it passes,
 * the exit codes 0, 10 and 11 and a passed-through install code, and
 * stopping at the first failure unless --continue (install-multi-instance-promotion task 5.1).
 *
 * @covers \OCA\Versioniq\Command\PromoteVersion
 * @covers \OCA\Versioniq\Command\InstallVersion
 * @uses \OCA\Versioniq\Service\Instance\PromotionOptions
 */
final class PromoteVersionTest extends TestCase {
	/** @var list<array{string, string, bool, bool, ?string, bool}> */
	private array $calls = [];

	/**
	 * A command whose promotions answer $answers by app id.
	 *
	 * @param array<string, array{statusCode: int, payload: array<string, mixed>}> $answers
	 */
	private function tester(array $answers): CommandTester {
		$promotions = $this->createMock(PromotionService::class);
		$promotions->method('promote')->willReturnCallback(function (string $connection, string $appId, PromotionOptions $options) use ($answers): array {
			$this->calls[] = [$connection, $appId, $options->allowDowngrade, $options->overrideMinDays, $options->plannedVersion, $options->dryRun];
			return $answers[$appId];
		});

		return new CommandTester(new PromoteVersion($promotions));
	}

	private static function promoted(): array {
		return ['statusCode' => 200, 'payload' => ['promotedFrom' => ['id' => 'acceptance', 'name' => 'Acceptance', 'stage' => 'acceptance'], 'previousVersion' => '2.3.0']];
	}

	public function testTwoAppsArePromotedInOrderAndExitZero(): void {
		$tester = $this->tester(['openregister' => self::promoted(), 'opencatalogi' => self::promoted()]);

		$exit = $tester->execute(['connection' => 'acceptance', 'appIds' => ['openregister', 'opencatalogi']]);

		self::assertSame(0, $exit);
		self::assertSame([['acceptance', 'openregister', false, false, null, false], ['acceptance', 'opencatalogi', false, false, null, false]], $this->calls);
		self::assertStringContainsString('openregister: promoted from Acceptance; the previous version was 2.3.0.', $tester->getDisplay());
	}

	public function testTheOptionsArePassedThrough(): void {
		$tester = $this->tester(['openregister' => ['statusCode' => 200, 'payload' => ['dryRun' => true, 'plan' => ['installedVersion' => '2.3.0', 'version' => '2.4.1']]]]);

		$exit = $tester->execute(['connection' => 'acceptance', 'appIds' => ['openregister'], '--dry-run' => true, '--allow-downgrade' => true, '--override-min-days' => true]);

		self::assertSame(0, $exit);
		self::assertSame([['acceptance', 'openregister', true, true, null, true]], $this->calls);
		self::assertStringContainsString('openregister: would promote 2.3.0 to 2.4.1.', $tester->getDisplay());
	}

	public function testTheWaitingPeriodExitsTenAndNamesTheDaysLeft(): void {
		$tester = $this->tester(['openregister' => ['statusCode' => 409, 'payload' => ['code' => 'waiting_period', 'daysLeft' => 9, 'message' => '9 days are left in the waiting period of 14 days.']]]);

		$exit = $tester->execute(['connection' => 'acceptance', 'appIds' => ['openregister']]);

		self::assertSame(10, $exit);
		self::assertStringContainsString('9 days are left', $tester->getDisplay());
	}

	public function testAnUnknownOrUnreadableConnectionExitsEleven(): void {
		$unknown = $this->tester(['openregister' => ['statusCode' => 404, 'payload' => ['code' => 'unknown_connection', 'message' => 'No connection named x.']]]);
		self::assertSame(11, $unknown->execute(['connection' => 'x', 'appIds' => ['openregister']]));

		$unreadable = $this->tester(['openregister' => ['statusCode' => 502, 'payload' => ['code' => 'connection_unreadable', 'message' => 'The instance could not be reached.']]]);
		self::assertSame(11, $unreadable->execute(['connection' => 'acceptance', 'appIds' => ['openregister']]));
	}

	public function testAnInstallFailurePassesTheInstallCodeThroughAndStops(): void {
		$tester = $this->tester([
			'openregister' => ['statusCode' => 422, 'payload' => ['code' => 'sha_mismatch', 'category' => FailureClassifier::CATEGORY_SHA_MISMATCH, 'message' => 'Checksum mismatch.']],
			'opencatalogi' => self::promoted(),
		]);

		$exit = $tester->execute(['connection' => 'acceptance', 'appIds' => ['openregister', 'opencatalogi']]);

		self::assertSame(6, $exit);
		self::assertCount(1, $this->calls);
	}

	public function testContinueGoesOnAndExitsWithTheFirstFailure(): void {
		$tester = $this->tester([
			'openregister' => ['statusCode' => 409, 'payload' => ['code' => 'waiting_period', 'message' => '3 days are left in the waiting period of 14 days.']],
			'opencatalogi' => ['statusCode' => 404, 'payload' => ['code' => 'unknown_connection', 'message' => 'No connection named acceptance.']],
			'openconnector' => self::promoted(),
		]);

		$exit = $tester->execute(['connection' => 'acceptance', 'appIds' => ['openregister', 'opencatalogi', 'openconnector'], '--continue' => true]);

		self::assertSame(10, $exit);
		self::assertSame(['openregister', 'opencatalogi', 'openconnector'], array_column($this->calls, 1));
	}
}
