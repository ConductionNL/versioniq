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
use OCA\Versioniq\Service\Advisory\NcscAdvisoryFeed;
use OCA\Versioniq\Tests\Unit\Support\BuildsByParameterName;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The NCSC-NL switch on GET and PUT /api/advisory/settings (advisories-ncsc-feed task 3.1).
 *
 * @spec openspec/changes/advisories-ncsc-feed/specs/security-advisory-correlation/spec.md#requirement-ncsc-nl-advisories-are-matched-to-the-installed-server-and-apps
 */
final class ApiNcscSettingsTest extends TestCase {
	use BuildsByParameterName;

	/** @var array<string, mixed> */
	private array $stored = [];

	private function controller(bool $admin = true): ApiController {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueBool')->willReturnCallback(fn (string $app, string $key, bool $default = false): bool => (bool)($this->stored[$key] ?? $default));
		$config->method('setValueBool')->willReturnCallback(function (string $app, string $key, bool $value): bool {
			$this->stored[$key] = $value;

			return true;
		});
		$config->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default = ''): string => (string)($this->stored[$key] ?? $default));
		$config->method('getValueInt')->willReturnCallback(fn (string $app, string $key, int $default = 0): int => $default);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('root');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn($admin);

		return $this->buildByName(ApiController::class, [
			'appName' => 'versioniq',
			'userSession' => $session,
			'groupManager' => $groups,
			'serverVersion' => (new ReflectionClass(\OCP\ServerVersion::class))->newInstanceWithoutConstructor(),
			'appManager' => $this->createMock(IAppManager::class),
			'advisorySettingsStore' => new AdvisorySettingsStore($config),
			'connectionReports' => null,
		]);
	}

	public function testTheSwitchIsOffAndUnreadByDefault(): void {
		$data = $this->controller()->advisorySettings()->getData();

		self::assertFalse($data['ncscEnabled']);
		self::assertSame(['readUpTo' => null, 'caughtUp' => false], $data['ncscStatus']);
	}

	public function testThePutRoundTripsTheSwitch(): void {
		$on = $this->controller()->updateAdvisorySettings(null, null, '1')->getData();
		self::assertTrue($on['ncscEnabled']);
		self::assertTrue($this->controller()->advisorySettings()->getData()['ncscEnabled']);

		// A JSON false reaches PHP as "".
		$off = $this->controller()->updateAdvisorySettings(null, null, '')->getData();
		self::assertFalse($off['ncscEnabled']);
	}

	public function testOmittingTheSwitchLeavesIt(): void {
		$this->stored[AdvisorySettingsStore::CONFIG_NCSC_ENABLED] = true;

		self::assertTrue($this->controller()->updateAdvisorySettings('6', null)->getData()['ncscEnabled']);
	}

	public function testTheStatusSaysHowFarTheReadGot(): void {
		$this->stored[NcscAdvisoryFeed::CONFIG_CURSOR] = '2026-09-24T09:14:33+00:00';
		$this->stored[NcscAdvisoryFeed::CONFIG_CAUGHT_UP] = false;

		self::assertSame(['readUpTo' => '2026-09-24T09:14:33+00:00', 'caughtUp' => false], $this->controller()->advisorySettings()->getData()['ncscStatus']);
	}

	public function testANonAdminCannotSwitchIt(): void {
		self::assertSame(403, $this->controller(false)->updateAdvisorySettings(null, null, '1')->getStatus());
		self::assertArrayNotHasKey(AdvisorySettingsStore::CONFIG_NCSC_ENABLED, $this->stored);
	}
}
