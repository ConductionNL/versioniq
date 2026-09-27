<?php

declare(strict_types=1);

namespace OCA\Versioniq\Tests\Unit\Controller;

use OCA\Versioniq\Controller\SettingsController;
use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\Settings\InstanceSettings;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * #438 item 7: the occ-only settings reach a page through
 * GET/PUT /api/instance-settings.
 *
 * @spec openspec/specs/audit-trail/spec.md
 * @spec openspec/specs/external-sources/spec.md
 */
final class SettingsControllerTest extends TestCase {
	/** @var array<string, string|int> */
	private array $config = [];

	private function controller(bool $admin, ?AuditLogger $audit = null): SettingsController {
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

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn($admin);

		return new SettingsController(
			'versioniq',
			$this->createMock(IRequest::class),
			new InstanceSettings($appConfig),
			$audit ?? $this->createMock(AuditLogger::class),
			$groups,
			$session,
		);
	}

	public function testNonAdminCannotReadOrWrite(): void {
		$controller = $this->controller(false);

		self::assertSame(403, $controller->instanceSettings()->getStatus());
		self::assertSame(403, $controller->updateInstanceSettings('90')->getStatus());
		self::assertSame([], $this->config);
	}

	public function testReadReturnsTheSettings(): void {
		$response = $this->controller(true)->instanceSettings();

		self::assertSame(200, $response->getStatus());
		self::assertSame(365, $response->getData()['auditRetentionDays']);
	}

	public function testUpdateStoresAndAuditsTheChange(): void {
		$audit = $this->createMock(AuditLogger::class);
		$audit->expects(self::once())->method('record')->with('admin', 'versioniq', AuditLogger::OPERATION_SETTINGS);

		$response = $this->controller(true, $audit)->updateInstanceSettings('90');

		self::assertSame(200, $response->getStatus());
		self::assertSame(90, $response->getData()['auditRetentionDays']);
		self::assertSame(90, $this->config['audit_retention_days']);
	}

	public function testAnInvalidValueIsA400(): void {
		$response = $this->controller(true)->updateInstanceSettings('7');

		self::assertSame(400, $response->getStatus());
		self::assertSame([], $this->config);
	}
}
