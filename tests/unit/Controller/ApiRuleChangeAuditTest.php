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
use OCA\Versioniq\Service\Audit\AuditedVersions;
use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\Audit\AuditSubject;
use OCA\Versioniq\Service\Audit\RuleChangeAuditor;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateSettingsStore;
use OCA\Versioniq\Service\Policy\Policy;
use OCA\Versioniq\Service\Policy\PolicyStore;
use OCA\Versioniq\Tests\Unit\Support\BuildsByParameterName;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Each write to the update rules leaves one audit row with the value before and
 * after (audit-attribution-and-forwarding task 2.1): setPolicy, clearPolicy,
 * updateAutoUpdateSettings and updateAdvisorySettings, and none for a refused
 * call or a clear of a policy that did not exist.
 *
 * @covers \OCA\Versioniq\Controller\ApiController
 * @uses \OCA\Versioniq\Service\Audit\RuleChangeAuditor
 * @uses \OCA\Versioniq\Service\Audit\AuditSubject
 * @uses \OCA\Versioniq\Service\Audit\AuditedVersions
 * @uses \OCA\Versioniq\Service\Policy\Policy
 * @uses \OCA\Versioniq\Service\Api\AccessCollaborators
 * @uses \OCA\Versioniq\Service\Api\ApiCaller
 * @uses \OCA\Versioniq\Service\Api\InstallCollaborators
 * @uses \OCA\Versioniq\Service\Api\PatServices
 * @uses \OCA\Versioniq\Service\Api\ReportCollaborators
 * @uses \OCA\Versioniq\Service\Api\ReportStores
 * @uses \OCA\Versioniq\Service\AutoUpdate\AutoUpdateStores
 * @uses \OCA\Versioniq\Service\AutoUpdate\AutoUpdateWindow
 *
 * @spec openspec/changes/audit-attribution-and-forwarding/specs/audit-trail/spec.md#requirement-changes-to-the-update-rules-are-audited
 */
final class ApiRuleChangeAuditTest extends TestCase {
	use BuildsByParameterName;

	/** @var list<array{actor: string, app: string, operation: string, message: ?string}> */
	private array $rows = [];

	/** @var array<string, Policy> */
	private array $policies = [];

	private bool $enabled = false;
	private string $window = '01:00-05:00';
	private int $hours = 6;
	private bool $digest = true;

	/**
	 * @param array<string, string> $params The request parameters.
	 */
	private function controller(bool $admin = true, array $params = []): ApiController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn($admin);
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $name, mixed $default = null): mixed => $params[$name] ?? $default);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-11-14T10:00:00+00:00'));

		$logger = $this->createMock(AuditLogger::class);
		$logger->method('record')->willReturnCallback(function (AuditSubject $subject, AuditedVersions $versions, string $status, ?string $message = null): void {
			$this->rows[] = ['actor' => $subject->actorUid, 'app' => $subject->appId, 'operation' => $subject->operation, 'message' => $message];
		});

		return $this->buildByName(ApiController::class, [
			'appName' => 'versioniq',
			'request' => $request,
			'userSession' => $session,
			'groupManager' => $groups,
			'timeFactory' => $time,
			'serverVersion' => (new ReflectionClass(\OCP\ServerVersion::class))->newInstanceWithoutConstructor(),
			'appManager' => $this->createMock(IAppManager::class),
			'policyStore' => $this->policyStore(),
			'settingsStore' => $this->autoUpdateSettings(),
			'advisorySettingsStore' => $this->advisorySettings(),
			'connectionReports' => null,
			'ruleAudit' => new RuleChangeAuditor($logger),
		]);
	}

	private function policyStore(): PolicyStore {
		$store = $this->createMock(PolicyStore::class);
		$store->method('get')->willReturnCallback(fn (string $appId): ?Policy => $this->policies[$appId] ?? null);
		$store->method('set')->willReturnCallback(function (string $appId, Policy $policy): void {
			$this->policies[$appId] = $policy;
		});
		$store->method('clear')->willReturnCallback(function (string $appId): void {
			unset($this->policies[$appId]);
		});

		return $store;
	}

	private function autoUpdateSettings(): AutoUpdateSettingsStore {
		$store = $this->createMock(AutoUpdateSettingsStore::class);
		$store->method('isEnabled')->willReturnCallback(fn (): bool => $this->enabled);
		$store->method('getWindow')->willReturnCallback(fn (): string => $this->window);
		$store->method('setEnabled')->willReturnCallback(function (bool $enabled): void {
			$this->enabled = $enabled;
		});
		$store->method('setWindow')->willReturnCallback(function (string $window): void {
			$this->window = $window;
		});

		return $store;
	}

	private function advisorySettings(): AdvisorySettingsStore {
		$store = $this->createMock(AdvisorySettingsStore::class);
		$store->method('getIntervalHours')->willReturnCallback(fn (): int => $this->hours);
		$store->method('isDigestEnabled')->willReturnCallback(fn (): bool => $this->digest);
		$store->method('setIntervalHours')->willReturnCallback(function (int $hours): void {
			$this->hours = $hours;
		});
		$store->method('setDigestEnabled')->willReturnCallback(function (bool $digest): void {
			$this->digest = $digest;
		});

		return $store;
	}

	public function testSettingAPolicyWritesOneRowWithTheLevelBeforeAndAfter(): void {
		$this->policies['openregister'] = new Policy('patch', 'bob', '2026-11-01T00:00:00+00:00');

		$response = $this->controller(params: ['level' => 'minor'])->setPolicy('openregister');

		self::assertSame(200, $response->getStatus());
		self::assertSame([['actor' => 'alice', 'app' => 'openregister', 'operation' => 'policy_set', 'message' => 'level: patch -> minor']], $this->rows);
	}

	public function testARefusedLevelWritesNoRow(): void {
		$response = $this->controller(params: ['level' => 'everything'])->setPolicy('openregister');

		self::assertSame(400, $response->getStatus());
		self::assertSame([], $this->rows);
	}

	public function testClearingAPolicyWritesOneRow(): void {
		$this->policies['mail'] = new Policy('all', 'bob', '2026-11-01T00:00:00+00:00');

		$this->controller()->clearPolicy('mail');

		self::assertSame([['actor' => 'alice', 'app' => 'mail', 'operation' => 'policy_clear', 'message' => 'level: all -> none']], $this->rows);
	}

	public function testClearingAPolicyThatDidNotExistWritesNoRow(): void {
		$response = $this->controller()->clearPolicy('mail');

		self::assertSame(200, $response->getStatus());
		self::assertSame([], $this->rows);
	}

	public function testTurningAutomaticUpdatesOnWritesTheSwitchAndBothWindows(): void {
		$this->controller(params: ['window' => '02:00-04:00'])->updateAutoUpdateSettings('1');

		self::assertSame(
			[['actor' => 'alice', 'app' => 'versioniq', 'operation' => 'auto_update_settings', 'message' => 'enabled: false -> true, window: 01:00-05:00 -> 02:00-04:00']],
			$this->rows
		);
	}

	public function testAMalformedWindowWritesNoRow(): void {
		$response = $this->controller(params: ['window' => 'tonight'])->updateAutoUpdateSettings('1');

		self::assertSame(400, $response->getStatus());
		self::assertSame([], $this->rows);
	}

	public function testChangingTheAdvisoryIntervalWritesOneRow(): void {
		$this->controller()->updateAdvisorySettings('12', null);

		self::assertSame([['actor' => 'alice', 'app' => 'versioniq', 'operation' => 'advisory_settings', 'message' => 'intervalHours: 6 -> 12']], $this->rows);
	}

	public function testANonAdminWritesNoRow(): void {
		$controller = $this->controller(admin: false, params: ['level' => 'minor']);
		$controller->setPolicy('openregister');
		$controller->clearPolicy('openregister');
		$controller->updateAutoUpdateSettings('1');
		$controller->updateAdvisorySettings('12', '0');

		self::assertSame([], $this->rows);
		self::assertSame(['enabled' => false, 'hours' => 6, 'digest' => true], ['enabled' => $this->enabled, 'hours' => $this->hours, 'digest' => $this->digest]);
	}
}
