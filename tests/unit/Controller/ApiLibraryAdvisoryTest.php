<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Controller;

require_once __DIR__ . '/../Support/BuildsByParameterName.php';

use OCA\Versioniq\Controller\ApiController;
use OCA\Versioniq\Service\Advisory\AdvisoryResultStore;
use OCA\Versioniq\Service\Advisory\AdvisorySettingsStore;
use OCA\Versioniq\Service\Advisory\LibraryAdvisoryStore;
use OCA\Versioniq\Tests\Unit\Support\BuildsByParameterName;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The bundled-library switch on the advisory settings routes, and the library
 * snapshot beside the app advisories (advisories-bundled-libraries).
 *
 * @covers \OCA\Versioniq\Controller\ApiController
 * @uses \OCA\Versioniq\Service\Advisory\AdvisorySettingsStore
 * @uses \OCA\Versioniq\Service\Api\ReportStores
 * @uses \OCA\Versioniq\Service\Api\ReportCollaborators
 */
final class ApiLibraryAdvisoryTest extends TestCase {
	use BuildsByParameterName;

	/** @var array<string, mixed> */
	private array $stored = [];

	private function controller(bool $admin = true, ?LibraryAdvisoryStore $libraries = null): ApiController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('root');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn($admin);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueInt')->willReturnCallback(fn (string $app, string $key, int $default = 0): int => (int)($this->stored[$key] ?? $default));
		$config->method('getValueBool')->willReturnCallback(fn (string $app, string $key, bool $default = false): bool => (bool)($this->stored[$key] ?? $default));
		$config->method('setValueBool')->willReturnCallback(function (string $app, string $key, bool $value): bool {
			$this->stored[$key] = $value;

			return true;
		});

		$results = $this->createMock(AdvisoryResultStore::class);
		$results->method('read')->willReturn(['advisories' => ['mail' => ['state' => 'none']], 'checkedAt' => 1700000000]);

		return $this->buildByName(ApiController::class, [
			'appName' => 'versioniq',
			'userSession' => $session,
			'groupManager' => $groups,
			'serverVersion' => (new ReflectionClass(\OCP\ServerVersion::class))->newInstanceWithoutConstructor(),
			'appManager' => $this->createMock(IAppManager::class),
			'advisorySettingsStore' => new AdvisorySettingsStore($config),
			'advisoryResultStore' => $results,
			'libraryAdvisoryStore' => $libraries,
			'connectionReports' => null,
		]);
	}

	public function testTheSwitchIsOffAndRoundTrips(): void {
		$this->assertFalse($this->controller()->advisorySettings()->getData()['librariesEnabled']);

		$on = $this->controller()->updateAdvisorySettings(null, null, '1');
		$this->assertSame(200, $on->getStatus());
		$this->assertTrue($on->getData()['librariesEnabled']);
		$this->assertTrue($this->controller()->advisorySettings()->getData()['librariesEnabled']);
		$this->assertSame(true, $this->stored['advisory.libraries_enabled']);

		// PHP reads a JSON false as '', which is an explicit off.
		$this->assertFalse($this->controller()->updateAdvisorySettings(null, null, '')->getData()['librariesEnabled']);
		$this->assertSame(false, $this->stored['advisory.libraries_enabled']);
	}

	public function testOmittingTheSwitchLeavesIt(): void {
		$this->stored['advisory.libraries_enabled'] = true;

		$this->assertTrue($this->controller()->updateAdvisorySettings('12', null)->getData()['librariesEnabled']);
		$this->assertTrue($this->controller()->updateAdvisorySettings(null, null, 'maybe')->getData()['librariesEnabled']);
	}

	public function testANonAdminCannotSwitchItOn(): void {
		$response = $this->controller(false)->updateAdvisorySettings(null, null, '1');

		$this->assertSame(403, $response->getStatus());
		$this->assertArrayNotHasKey('advisory.libraries_enabled', $this->stored);
	}

	public function testTheAdvisoriesCarryTheLibrarySnapshotBesideTheAppAdvisories(): void {
		$snapshot = ['mail' => ['manifest' => 'vendor/composer/installed.json', 'checked' => 2, 'skipped' => 0, 'error' => null, 'findings' => [['id' => 'GHSA-aaaa']]]];
		$libraries = $this->createMock(LibraryAdvisoryStore::class);
		$libraries->method('read')->willReturn(['libraries' => $snapshot, 'checkedAt' => 1700000500]);

		$this->assertSame([
			'advisories' => ['mail' => ['state' => 'none']],
			'checkedAt' => 1700000000,
			'libraries' => $snapshot,
			'librariesCheckedAt' => 1700000500,
		], $this->controller(true, $libraries)->advisories()->getData());
	}

	public function testWithoutALibraryStoreTheAdvisoriesSayNeverChecked(): void {
		$data = $this->controller()->advisories()->getData();

		$this->assertSame([], $data['libraries']);
		$this->assertNull($data['librariesCheckedAt']);
	}
}
