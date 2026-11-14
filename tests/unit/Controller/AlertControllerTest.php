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
use OCA\Versioniq\Service\ReleaseAlert\FollowList;
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
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-11-14T10:00:00+00:00'));

		return new AlertController(
			'versioniq',
			$this->createMock(IRequest::class),
			new ReleaseAlertSettings($config, new ReleaseEventLog($config), $audit, new FollowList($config, $audit, $time)),
			new ApiCaller($session, $groups, $this->createMock(ITimeFactory::class)),
		);
	}

	public function testANonAdminIsRefusedAndNothingIsStored(): void {
		$controller = $this->controller(false);

		self::assertSame(403, $controller->releaseAlerts()->getStatus());
		self::assertSame(403, $controller->setReleaseScope('all')->getStatus());
		self::assertSame(403, $controller->follow('forms', 'appstore')->getStatus());
		self::assertSame(403, $controller->unfollow('forms')->getStatus());
		self::assertSame([], $this->stored);
		self::assertSame([], $this->audited);
	}

	public function testAnAdminReadsTheScopeAndTheLog(): void {
		$this->stored[ReleaseEventLog::KEY] = '[{"appId": "hermiq", "version": "1.5.0"}]';

		$response = $this->controller()->releaseAlerts();

		self::assertSame(200, $response->getStatus());
		self::assertSame(['scope' => 'forge', 'releases' => [['appId' => 'hermiq', 'version' => '1.5.0']], 'follows' => []], $response->getData());
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

	public function testFollowingAnAppIsStoredListedAndAudited(): void {
		$controller = $this->controller();

		$response = $controller->follow('forms', 'appstore');

		self::assertSame(200, $response->getStatus());
		self::assertSame(['follow' => ['appId' => 'forms', 'sourceId' => 'appstore', 'addedBy' => 'alice', 'addedAt' => '2026-11-14T10:00:00+00:00']], $response->getData());
		self::assertSame([['appId' => 'forms', 'sourceId' => 'appstore', 'addedBy' => 'alice', 'addedAt' => '2026-11-14T10:00:00+00:00']], $controller->releaseAlerts()->getData()['follows']);
		self::assertCount(1, $this->audited);
		self::assertSame('forms', $this->audited[0][0]->appId);
		self::assertSame(AuditLogger::OPERATION_SETTINGS, $this->audited[0][0]->operation);
	}

	public function testAMalformedAppIdIs400AndNotStored(): void {
		$response = $this->controller()->follow('../etc', '');

		self::assertSame(400, $response->getStatus());
		self::assertSame([], $this->stored);
		self::assertSame([], $this->audited);
	}

	public function testUnfollowingRemovesTheAppAndAnUnknownAppIs404(): void {
		$controller = $this->controller();
		$controller->follow('forms', '');

		self::assertSame(200, $controller->unfollow('forms')->getStatus());
		self::assertSame([], $controller->releaseAlerts()->getData()['follows']);
		self::assertSame(404, $controller->unfollow('forms')->getStatus());
		self::assertCount(2, $this->audited);
	}

	public function testFollowingNeedsThePassword(): void {
		foreach (['follow', 'unfollow'] as $name) {
			self::assertCount(1, (new \ReflectionMethod(AlertController::class, $name))->getAttributes(PasswordConfirmationRequired::class), $name);
		}
	}
}
