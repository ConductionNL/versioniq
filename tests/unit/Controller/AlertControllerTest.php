<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Controller;

use OCA\Versioniq\Controller\AlertController;
use OCA\Versioniq\Service\Api\ApiCaller;
use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\Audit\AuditSubject;
use OCA\Versioniq\Service\ReleaseAlert\ReleaseAlertSettings;
use OCA\Versioniq\Service\ReleaseAlert\ReleaseEventLog;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * @spec openspec/changes/releases-new-release-alerts/specs/release-alerts/spec.md#requirement-admins-are-notified-of-new-releases-in-the-scope-they-choose
 */
final class AlertControllerTest extends TestCase {
	/** @var array<string, string> */
	private array $stored = [];

	/** @var list<array{0: AuditSubject, 1: string, 2: ?string}> */
	private array $audited = [];

	private function controller(bool $admin = true): AlertController {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default = ''): string => $this->stored[$key] ?? $default);
		$config->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value): bool {
			$this->stored[$key] = $value;

			return true;
		});
		$audit = $this->createMock(AuditLogger::class);
		$audit->method('record')->willReturnCallback(function (AuditSubject $subject, $versions, string $status, ?string $message = null): void {
			$this->audited[] = [$subject, $status, $message];
		});
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn($admin);

		return new AlertController(
			'versioniq',
			$this->createMock(IRequest::class),
			new ReleaseAlertSettings($config, new ReleaseEventLog($config), $audit),
			new ApiCaller($session, $groups, $this->createMock(ITimeFactory::class)),
		);
	}

	public function testANonAdminIsRefusedAndNothingIsStored(): void {
		$controller = $this->controller(false);

		self::assertSame(403, $controller->releaseAlerts()->getStatus());
		self::assertSame(403, $controller->setReleaseScope('all')->getStatus());
		self::assertSame([], $this->stored);
		self::assertSame([], $this->audited);
	}

	public function testAnAdminReadsTheScopeAndTheLog(): void {
		$this->stored[ReleaseEventLog::KEY] = '[{"appId": "hermiq", "version": "1.5.0"}]';

		$response = $this->controller()->releaseAlerts();

		self::assertSame(200, $response->getStatus());
		self::assertSame(['scope' => 'forge', 'releases' => [['appId' => 'hermiq', 'version' => '1.5.0']]], $response->getData());
	}

	public function testChangingTheScopeIsStoredAndAuditedOnce(): void {
		$response = $this->controller()->setReleaseScope('all');

		self::assertSame(200, $response->getStatus());
		self::assertSame(['scope' => 'all'], $response->getData());
		self::assertSame('all', $this->stored[ReleaseAlertSettings::KEY_SCOPE]);
		self::assertCount(1, $this->audited);
		self::assertSame(AuditLogger::STATUS_SUCCESS, $this->audited[0][1]);
		self::assertSame('Settings changed: release_alerts.scope=all', $this->audited[0][2]);
	}

	public function testAnUnknownScopeIs400AndNotAudited(): void {
		$response = $this->controller()->setReleaseScope('weekly');

		self::assertSame(400, $response->getStatus());
		self::assertSame([], $this->stored);
		self::assertSame([], $this->audited);
	}

	public function testChangingTheScopeNeedsThePassword(): void {
		$method = new \ReflectionMethod(AlertController::class, 'setReleaseScope');

		self::assertCount(1, $method->getAttributes(PasswordConfirmationRequired::class));
	}
}
