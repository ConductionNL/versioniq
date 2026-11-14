<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Controller;

require_once __DIR__ . '/../Support/AuditArgs.php';
require_once __DIR__ . '/../Support/DispatchesLikeNextcloud.php';

use OCA\Versioniq\Controller\SettingsController;
use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\Checks\CheckStore;
use OCA\Versioniq\Service\Settings\InstanceSettings;
use OCA\Versioniq\Tests\Unit\Support\AuditArgs;
use OCA\Versioniq\Tests\Unit\Support\DispatchesLikeNextcloud;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * #438 item 7: the occ-only settings reach a page through
 * GET/PUT /api/instance-settings.
 *
 * @spec openspec/specs/audit-trail/spec.md
 * @spec openspec/specs/external-sources/spec.md
 * @spec openspec/changes/safety-update-checks/specs/update-checks/spec.md#requirement-admins-and-operators-attach-checks-that-every-dry-run-runs
 */
final class SettingsControllerTest extends TestCase {
	use DispatchesLikeNextcloud;

	/** @var array<string, string|int> */
	private array $config = [];

	/** @var array<string, mixed> The operator's config.php values. */
	private array $system = [];

	/** @var list<string> System config keys something tried to write. */
	private array $systemWrites = [];

	private function controller(bool $admin, ?AuditLogger $audit = null, ?IRequest $request = null, bool $signedIn = true): SettingsController {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => (string)($this->config[$key] ?? $default),
		);
		$appConfig->method('getValueInt')->willReturnCallback(
			fn (string $app, string $key, int $default = 0): int => (int)($this->config[$key] ?? $default),
		);
		$appConfig->method('setValueInt')->willReturnCallback(
			function (string $app, string $key, int $value): bool {
				$this->config[$key] = $value;
				return true;
			},
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->config[$key] = $value;
				return true;
			},
		);
		$system = $this->createMock(IConfig::class);
		$system->method('getSystemValue')->willReturnCallback(fn (string $key, mixed $default = ''): mixed => $this->system[$key] ?? $default);
		$system->method('setSystemValue')->willReturnCallback(function (string $key): void {
			$this->systemWrites[] = $key;
		});
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('encrypt')->willReturnCallback(static fn (string $plain): string => 'enc(' . strrev($plain) . ')');

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($signedIn ? $user : null);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn($admin);

		return new SettingsController(
			'versioniq',
			$request ?? $this->createMock(IRequest::class),
			new InstanceSettings($appConfig),
			$audit ?? $this->createMock(AuditLogger::class),
			$groups,
			$session,
			new CheckStore($appConfig, $system, $crypto, $this->createMock(LoggerInterface::class)),
		);
	}

	public function testNonAdminCannotReadOrWrite(): void {
		$controller = $this->controller(false);

		self::assertSame(403, $controller->instanceSettings()->getStatus());
		$request = $this->requestWith(['auditRetentionDays' => '90']);
		self::assertSame(403, $this->dispatch($this->controller(false, null, $request), 'updateInstanceSettings', $request)->getStatus());
		self::assertSame([], $this->config);
	}

	public function testSignedOutCallerCannotReadOrWrite(): void {
		$audit = $this->createMock(AuditLogger::class);
		$audit->expects(self::never())->method(self::anything());

		self::assertSame(403, $this->controller(true, $audit, null, false)->instanceSettings()->getStatus());
		$request = $this->requestWith(['auditRetentionDays' => '90']);
		self::assertSame(403, $this->dispatch($this->controller(true, $audit, $request, false), 'updateInstanceSettings', $request)->getStatus());
		self::assertSame([], $this->config);
	}

	public function testReadReturnsTheSettings(): void {
		$response = $this->controller(true)->instanceSettings();

		self::assertSame(200, $response->getStatus());
		self::assertSame(365, $response->getData()['auditRetentionDays']);
	}

	public function testUpdateStoresAndAuditsTheChange(): void {
		$audit = $this->createMock(AuditLogger::class);
		$audit->expects(self::once())->method('record')->with(...AuditArgs::expected('admin', 'versioniq', AuditLogger::OPERATION_SETTINGS));

		$request = $this->requestWith(['auditRetentionDays' => '90']);
		$response = $this->dispatch($this->controller(true, $audit, $request), 'updateInstanceSettings', $request);

		self::assertSame(200, $response->getStatus());
		self::assertSame(90, $response->getData()['auditRetentionDays']);
		self::assertSame(90, $this->config['audit_retention_days']);
	}

	public function testAnInvalidValueIsA400(): void {
		$request = $this->requestWith(['auditRetentionDays' => '7']);
		$response = $this->dispatch($this->controller(true, null, $request), 'updateInstanceSettings', $request);

		self::assertSame(400, $response->getStatus());
		self::assertSame([], $this->config);
	}

	public function testNonAdminCannotChangeTheUpdateChecks(): void {
		$audit = $this->createMock(AuditLogger::class);
		$audit->expects(self::never())->method('record');
		$request = $this->requestWith(['checks' => [['name' => 'Staging tests', 'url' => 'https://ci.example.org/verdict/{appId}/{version}']]]);

		$response = $this->dispatch($this->controller(false, $audit, $request), 'updateUpdateChecks', $request);

		self::assertSame(403, $response->getStatus());
		self::assertArrayNotHasKey(CheckStore::KEY, $this->config);
		self::assertSame(403, $this->dispatch($this->controller(true, $audit, $request, false), 'updateUpdateChecks', $request)->getStatus());
	}

	public function testAChangeIsStoredAuditedAndReturnedWithoutTheToken(): void {
		$audit = $this->createMock(AuditLogger::class);
		$audit->expects(self::once())->method('record')->with(...AuditArgs::expected(
			'admin', 'versioniq', AuditLogger::OPERATION_SETTINGS, null, null, null, AuditLogger::STATUS_SUCCESS,
			'Update checks changed: 1 verdict check(s): Staging tests',
		));
		$request = $this->requestWith(['checks' => [[
			'name' => 'Staging tests',
			'url' => 'https://ci.example.org/verdict/{appId}/{version}',
			'apps' => ['openregister'],
			'token' => 's3cret-token',
		]]]);

		$response = $this->dispatch($this->controller(true, $audit, $request), 'updateUpdateChecks', $request);

		self::assertSame(200, $response->getStatus());
		$verdict = $response->getData()['verdict'];
		self::assertCount(1, $verdict);
		self::assertSame('Staging tests', $verdict[0]['name']);
		self::assertTrue($verdict[0]['hasToken']);
		self::assertStringNotContainsString('s3cret', (string)json_encode($response->getData()));
		self::assertStringNotContainsString('s3cret', (string)$this->config[CheckStore::KEY], 'the token is stored encrypted');
		self::assertStringContainsString('enc(nekot-terc3s)', (string)$this->config[CheckStore::KEY]);
	}

	public function testAnHttpAddressIsA400AndChangesNothing(): void {
		$audit = $this->createMock(AuditLogger::class);
		$audit->expects(self::never())->method('record');
		$request = $this->requestWith(['checks' => [['name' => 'Plain', 'url' => 'http://ci.example.org/verdict']]]);

		$response = $this->dispatch($this->controller(true, $audit, $request), 'updateUpdateChecks', $request);

		self::assertSame(400, $response->getStatus());
		self::assertSame('The address of check "Plain" must be an https address.', $response->getData()['message']);
		self::assertArrayNotHasKey(CheckStore::KEY, $this->config);
	}

	public function testCommandChecksAreListedReadOnly(): void {
		$this->system[CheckStore::SYSTEM_KEY] = [['name' => 'Lint', 'command' => ['/opt/checks/lint.sh', '{path}'], 'timeout' => 30]];

		$read = $this->controller(true)->instanceSettings();
		self::assertSame(200, $read->getStatus());
		self::assertSame(
			[['name' => 'Lint', 'command' => ['/opt/checks/lint.sh', '{path}'], 'apps' => [], 'timeout' => 30]],
			$read->getData()['updateChecks']['command'],
		);
		self::assertSame('versioniq.update_checks', $read->getData()['updateChecks']['commandConfigKey']);

		// A command sent from the page is no verdict check and is refused; config.php is never written.
		$request = $this->requestWith(['checks' => [['name' => 'Shell', 'command' => ['/bin/rm', '-rf', '/']]]]);
		$response = $this->dispatch($this->controller(true, null, $request), 'updateUpdateChecks', $request);

		self::assertSame(400, $response->getStatus());
		self::assertSame([], $this->systemWrites);
		self::assertSame(['Lint'], array_column($this->controller(true)->instanceSettings()->getData()['updateChecks']['command'], 'name'));
	}

	public function testAListThatIsNotAListIsA400(): void {
		$request = $this->requestWith(['checks' => 'not a list']);

		self::assertSame(400, $this->dispatch($this->controller(true, null, $request), 'updateUpdateChecks', $request)->getStatus());
	}
}
