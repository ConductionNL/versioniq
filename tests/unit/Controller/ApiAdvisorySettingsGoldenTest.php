<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Controller;

require_once __DIR__ . '/../Support/BuildsByParameterName.php';

use OCA\Versioniq\Controller\ApiController;
use OCA\Versioniq\Service\Advisory\AdvisorySettingsStore;
use OCA\Versioniq\Tests\Unit\Support\BuildsByParameterName;
use OCP\App\IAppManager;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Golden test for the advisory check settings: advisorySettings() and every
 * branch of updateAdvisorySettings() (the interval and the digest flag, each
 * left out, empty, invalid or valid). The response and every settings-store
 * call in order are compared with fixtures/api-advisory-settings.golden.json.
 * Set RECORD_GOLDEN=1 to rewrite it.
 *
 * @covers \OCA\Versioniq\Controller\ApiController
 * @uses \OCA\Versioniq\Service\Api\AccessCollaborators
 * @uses \OCA\Versioniq\Service\Api\ApiCaller
 * @uses \OCA\Versioniq\Service\Api\InstallCollaborators
 * @uses \OCA\Versioniq\Service\Api\PatServices
 * @uses \OCA\Versioniq\Service\Api\ReportCollaborators
 * @uses \OCA\Versioniq\Service\Api\ReportStores
 * @uses \OCA\Versioniq\Service\AutoUpdate\AutoUpdateStores
 */
final class ApiAdvisorySettingsGoldenTest extends TestCase {
	use BuildsByParameterName;

	private const FIXTURE = __DIR__ . '/fixtures/api-advisory-settings.golden.json';

	/** @var list<list<mixed>> */
	private array $calls = [];

	/**
	 * @return array<string, array{0: bool, 1: string|null, 2: string|null}> admin, intervalHours, digestEnabled
	 */
	private static function cases(): array {
		return [
			'not an admin' => [false, '12', '1'],
			'nothing given' => [true, null, null],
			'empty interval' => [true, '', null],
			'interval not a number' => [true, 'abc', null],
			'interval below the minimum' => [true, '0', null],
			'interval above the maximum' => [true, '25', null],
			'interval at the minimum' => [true, '1', null],
			'interval at the maximum' => [true, '24', null],
			'decimal interval' => [true, '12.7', null],
			'digest empty is false' => [true, null, ''],
			'digest on' => [true, null, '1'],
			'digest off' => [true, null, 'false'],
			'digest unknown keeps the current value' => [true, null, 'maybe'],
			'both' => [true, '6', '0'],
			'bad interval stops before the digest' => [true, 'x', '0'],
		];
	}

	public function testEveryCaseMatchesTheGolden(): void {
		$actual = ['read' => $this->outcome(true, null)];
		foreach (self::cases() as $name => [$admin, $interval, $digest]) {
			$actual[$name] = $this->outcome($admin, static fn (ApiController $controller) => $controller->updateAdvisorySettings($interval, $digest));
		}

		if (getenv('RECORD_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->markTestSkipped('Golden fixture recorded.');
		}

		/** @var array<string, mixed> $expected */
		$expected = json_decode((string)file_get_contents(self::FIXTURE), true);
		self::assertSame(array_keys($expected), array_keys($actual), 'case list changed');
		foreach ($actual as $name => $case) {
			self::assertSame(json_encode($expected[$name]), json_encode($case), "case {$name}");
		}
	}

	/**
	 * @param callable(ApiController): mixed|null $call The update to make, or null for advisorySettings().
	 * @return array<string, mixed>
	 */
	private function outcome(bool $admin, ?callable $call): array {
		$this->calls = [];
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('root');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn($admin);

		$controller = $this->buildByName(ApiController::class, [
			'appName' => 'versioniq',
			'userSession' => $session,
			'groupManager' => $groups,
			'serverVersion' => (new ReflectionClass(\OCP\ServerVersion::class))->newInstanceWithoutConstructor(),
			'appManager' => $this->createMock(IAppManager::class),
			'advisorySettingsStore' => $this->settingsStore(),
			'connectionReports' => null,
		]);

		$response = $call === null ? $controller->advisorySettings() : $call($controller);

		return ['status' => $response->getStatus(), 'data' => $response->getData(), 'calls' => $this->calls];
	}

	private function settingsStore(): AdvisorySettingsStore {
		$hours = 6;
		$digest = true;
		$store = $this->createMock(AdvisorySettingsStore::class);
		$store->method('getIntervalHours')->willReturnCallback(function () use (&$hours): int {
			$this->calls[] = ['getIntervalHours'];

			return $hours;
		});
		$store->method('setIntervalHours')->willReturnCallback(function (int $value) use (&$hours): void {
			$this->calls[] = ['setIntervalHours', $value];
			$hours = $value;
		});
		$store->method('isDigestEnabled')->willReturnCallback(function () use (&$digest): bool {
			$this->calls[] = ['isDigestEnabled'];

			return $digest;
		});
		$store->method('setDigestEnabled')->willReturnCallback(function (bool $value) use (&$digest): void {
			$this->calls[] = ['setDigestEnabled', $value];
			$digest = $value;
		});

		return $store;
	}
}
