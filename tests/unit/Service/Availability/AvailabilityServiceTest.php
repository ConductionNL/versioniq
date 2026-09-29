<?php

declare(strict_types=1);

namespace OCA\Versioniq\Tests\Unit\Service\Availability;

use OCA\Versioniq\Service\Availability\AvailabilityService;
use OCA\Versioniq\Service\InstallerService;
use OCA\Versioniq\Service\Pin\Pin;
use OCA\Versioniq\Service\Pin\PinStore;
use OCP\AppFramework\Http;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The availability sweep of inventory-pending-updates (design D2).
 *
 * @spec openspec/specs/pending-updates/spec.md
 */
final class AvailabilityServiceTest extends TestCase {
	/**
	 * @param list<array<string, mixed>> $apps
	 * @param array<string, array<string, mixed>|\Throwable> $versionsByApp
	 * @param array<string, Pin> $pins
	 */
	private function service(array $apps, array $versionsByApp, array $pins = [], array $unmanageable = []): AvailabilityService {
		$installer = $this->createMock(InstallerService::class);
		$installer->method('getInstalledApps')->willReturn($apps);
		$installer->method('isManageableApp')->willReturnCallback(static fn (string $id): bool => !in_array($id, $unmanageable, true));
		$installer->method('getAppVersions')->willReturnCallback(static function (string $id) use ($versionsByApp): array {
			$answer = $versionsByApp[$id] ?? null;
			if ($answer instanceof \Throwable) {
				throw $answer;
			}

			return $answer ?? self::envelope([]);
		});

		$pinStore = $this->createMock(PinStore::class);
		$pinStore->method('get')->willReturnCallback(static fn (string $id): ?Pin => $pins[$id] ?? null);

		return new AvailabilityService($installer, $pinStore, new NullLogger());
	}

	private static function app(string $id, ?string $installed, string $state = 'enabled', ?string $boundSourceId = null): array {
		return ['id' => $id, 'installedVersion' => $installed, 'state' => $state, 'boundSourceId' => $boundSourceId];
	}

	/**
	 * @param list<array{0: string, 1: ?bool}> $versions
	 */
	private static function envelope(array $versions, ?string $error = null, string $sourceId = 'appstore'): array {
		$list = array_map(static fn (array $v): array => ['version' => $v[0], 'changelog' => null, 'serverCompatible' => $v[1]], $versions);
		$envelope = [
			'installedVersion' => null,
			'availableVersions' => $list,
			'versions' => $list,
			'source' => 'appstore',
			'sourceId' => $sourceId,
			'statusCode' => Http::STATUS_OK,
			'hasError' => $error !== null && $list === [],
		];
		if ($error !== null) {
			$envelope['error'] = $error;
		}

		return $envelope;
	}

	public function testANewerCompatibleVersionIsRecordedAndAnIncompatibleOneIsNot(): void {
		$service = $this->service(
			[self::app('openregister', '2.3.0')],
			['openregister' => self::envelope([['3.0.0', false], ['2.4.1', true], ['2.3.4', true], ['2.3.0', true]])],
		);

		$entry = $service->sweep(60.0)['openregister'];

		self::assertSame('2.3.0', $entry['installedVersion']);
		self::assertSame('3.0.0', $entry['newestVersion']);
		self::assertSame('2.4.1', $entry['newestCompatibleVersion']);
		self::assertSame(1, $entry['linesBehind']);
		self::assertTrue($entry['updateAvailable']);
		self::assertFalse($entry['pinned']);
		self::assertSame('appstore', $entry['sourceId']);
		self::assertNull($entry['error']);
	}

	public function testAVersionWithUnknownCompatibilityStillCounts(): void {
		$service = $this->service(
			[self::app('hermiq', '1.0.0')],
			['hermiq' => self::envelope([['1.2.0', null], ['1.1.0', null]], null, 'github:ConductionNL/hermiq')],
		);

		$entry = $service->sweep(60.0)['hermiq'];

		self::assertSame('1.2.0', $entry['newestCompatibleVersion']);
		self::assertSame(2, $entry['linesBehind']);
		self::assertSame('github:ConductionNL/hermiq', $entry['sourceId']);
	}

	public function testAPreReleaseIsNeverCountedAsAnUpdate(): void {
		$service = $this->service(
			[self::app('calendar', '5.0.0')],
			['calendar' => self::envelope([['5.1.0-beta.1', true], ['5.0.0', true]])],
		);

		$entry = $service->sweep(60.0)['calendar'];

		self::assertSame('5.0.0', $entry['newestVersion']);
		self::assertSame('5.0.0', $entry['newestCompatibleVersion']);
		self::assertSame(0, $entry['linesBehind']);
		self::assertFalse($entry['updateAvailable']);
	}

	public function testAPinnedAppIsSweptAndMarkedPinned(): void {
		$service = $this->service(
			[self::app('openregister', '2.3.0')],
			['openregister' => self::envelope([['2.4.1', true]])],
			['openregister' => new Pin('2.3.0', 'admin', '2026-09-01T00:00:00+00:00', null)],
		);

		$entry = $service->sweep(60.0)['openregister'];

		self::assertTrue($entry['pinned']);
		self::assertTrue($entry['updateAvailable']);
		self::assertSame('2.4.1', $entry['newestCompatibleVersion']);
	}

	public function testAnUnreachableSourceIsRecordedAsAnErrorNotAsUpToDate(): void {
		$service = $this->service(
			[self::app('hermiq', '1.0.0'), self::app('deck', '1.0.0')],
			[
				'hermiq' => self::envelope([], 'GitHub refused the request (HTTP 403).'),
				'deck' => new \RuntimeException('connection reset'),
			],
		);

		$result = $service->sweep(60.0);

		self::assertSame('GitHub refused the request (HTTP 403).', $result['hermiq']['error']);
		self::assertNull($result['hermiq']['newestVersion']);
		self::assertFalse($result['hermiq']['updateAvailable']);
		self::assertSame('connection reset', $result['deck']['error']);
		self::assertNull($result['deck']['newestCompatibleVersion']);
	}

	public function testReleaseLinesAreCountedNotPatches(): void {
		$service = $this->service(
			[self::app('openregister', '2.3.0')],
			['openregister' => self::envelope([['2.3.1', true], ['2.3.2', true], ['2.3.9', true], ['2.4.0', true], ['2.4.3', true], ['3.0.0', true], ['3.1.2', true]])],
		);

		$entry = $service->sweep(60.0)['openregister'];

		self::assertSame(3, $entry['linesBehind']);
		self::assertSame('3.1.2', $entry['newestCompatibleVersion']);
	}

	public function testAppsThatAreNotInstalledOrNotManageableAreSkipped(): void {
		$service = $this->service(
			[self::app('bound-only', null, 'notInstalled'), self::app('files', '1.0.0'), self::app('deck', '1.0.0', 'disabled')],
			['deck' => self::envelope([['1.1.0', true]])],
			[],
			['files'],
		);

		$result = $service->sweep(60.0);

		self::assertSame(['deck'], array_keys($result));
	}

	public function testAnAppPastTheBudgetIsRecordedAsNotChecked(): void {
		$service = $this->service(
			[self::app('deck', '1.0.0')],
			['deck' => self::envelope([['1.1.0', true]])],
		);

		$entry = $service->sweep(0.0)['deck'];

		self::assertNotNull($entry['error']);
		self::assertNull($entry['newestVersion']);
	}
}
