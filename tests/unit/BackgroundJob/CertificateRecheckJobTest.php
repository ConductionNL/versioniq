<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\BackgroundJob;

require_once __DIR__ . '/../Support/CodeSigningFixtures.php';

use OCA\Versioniq\BackgroundJob\CertificateRecheckJob;
use OCA\Versioniq\BackgroundJob\JobRuntime;
use OCA\Versioniq\Service\AdminNotificationServices;
use OCA\Versioniq\Service\Audit\AuditedVersions;
use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\Audit\AuditOrigin;
use OCA\Versioniq\Service\Audit\AuditSubject;
use OCA\Versioniq\Service\Safety\InstalledCertificateCheck;
use OCA\Versioniq\Service\Safety\RevocationList;
use OCA\Versioniq\Service\Safety\RevokedAppDisabler;
use OCA\Versioniq\Service\Safety\RevokedAppResponder;
use OCA\Versioniq\Service\Safety\RevokedCertificateStore;
use OCA\Versioniq\Service\Settings\AppDataFiles;
use OCA\Versioniq\Service\Source\SourceBinding;
use OCA\Versioniq\Service\Source\SourceBindingStore;
use OCA\Versioniq\Tests\Unit\Support\CodeSigningFixtures;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The daily certificate recheck (safety-withdrawn-and-revoked 2.1, design D2)
 * against a real revocation list: one notification per app and serial,
 * shipped and forge-bound apps skipped, an app disabled only with the switch
 * on, and an audit row when it is.
 *
 * @covers \OCA\Versioniq\BackgroundJob\CertificateRecheckJob
 * @covers \OCA\Versioniq\Service\Safety\InstalledCertificateCheck
 * @covers \OCA\Versioniq\Service\Safety\RevokedAppResponder
 * @covers \OCA\Versioniq\Service\Safety\RevokedAppDisabler
 * @covers \OCA\Versioniq\Service\Safety\RevokedCertificateStore
 * @uses   \OCA\Versioniq\Service\Safety\RevocationList
 * @uses   \OCA\Versioniq\Service\Safety\CrlDocument
 * @uses   \OCA\Versioniq\Service\Safety\DerElement
 * @uses   \OCA\Versioniq\Service\Settings\AppDataFiles
 * @uses   \OCA\Versioniq\Service\AdminNotice
 * @uses   \OCA\Versioniq\Service\AdminNotificationServices
 * @uses   \OCA\Versioniq\BackgroundJob\JobRuntime
 *
 * @spec openspec/changes/safety-withdrawn-and-revoked/specs/withdrawn-and-revoked/spec.md#requirement-installed-apps-are-rechecked-against-a-current-revocation-list
 */
final class CertificateRecheckJobTest extends TestCase {
	/** @var array{key: \OpenSSLAsymmetricKey, cert: string} */
	private static array $authority;

	private string $root;

	private mixed $savedServerRoot = null;

	/** @var array<string, string|int|bool> */
	private array $config = [RevocationList::KEY_URL => ''];

	/** @var array<string, array{serial: int, shipped?: bool, forge?: bool}> */
	private array $apps = [];

	/** @var list<int> */
	private array $revoked = [];

	/** @var list<array{user: string, subject: string, parameters: array}> */
	private array $sent = [];

	/** @var array<int, array> */
	private array $pending = [];

	/** @var list<string> */
	private array $disabled = [];

	/** @var list<array{subject: AuditSubject, versions: AuditedVersions, status: string, message: ?string, origin: string}> */
	private array $audit = [];

	public static function setUpBeforeClass(): void {
		self::$authority = CodeSigningFixtures::authority();
	}

	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . '/vq-recheck-' . bin2hex(random_bytes(4));
		mkdir($this->root . '/resources/codesigning', 0777, true);
		file_put_contents($this->root . '/resources/codesigning/root.crt', self::$authority['cert']);
		$this->savedServerRoot = \OC::$SERVERROOT;
		\OC::$SERVERROOT = $this->root;
	}

	protected function tearDown(): void {
		\OC::$SERVERROOT = $this->savedServerRoot;
		exec('rm -rf ' . escapeshellarg($this->root));
	}

	public function testEveryAdminIsNotifiedOncePerAppAndSerial(): void {
		$this->apps = ['badapp' => ['serial' => 42], 'goodapp' => ['serial' => 43]];
		$this->revoked = [42];

		$this->runJob();
		$this->runJob();

		self::assertSame([
			['user' => 'admin', 'subject' => 'certificate_revoked', 'parameters' => ['app' => 'badapp', 'serial' => '2A', 'disabled' => '0']],
			['user' => 'beheer', 'subject' => 'certificate_revoked', 'parameters' => ['app' => 'badapp', 'serial' => '2A', 'disabled' => '0']],
		], $this->sent, 'the second run sends nothing new');
		self::assertSame('2A', $this->config[RevokedCertificateStore::KEY_PREFIX . 'badapp']);
		self::assertArrayNotHasKey(RevokedCertificateStore::KEY_PREFIX . 'goodapp', $this->config);
		self::assertSame([], $this->disabled, 'badapp stays enabled while automatic disabling is off');
		self::assertSame([], $this->audit);
	}

	public function testANewCertificateThatIsRevokedTooNotifiesAgain(): void {
		$this->apps = ['badapp' => ['serial' => 42]];
		$this->revoked = [42, 44];
		$this->runJob();

		$this->apps = ['badapp' => ['serial' => 44]];
		$this->runJob();

		self::assertSame(['2A', '2A', '2C', '2C'], array_map(static fn (array $sent): string => $sent['parameters']['serial'], $this->sent));
	}

	public function testACertificateNoLongerRevokedClearsTheBadge(): void {
		$this->apps = ['badapp' => ['serial' => 42]];
		$this->config[RevokedCertificateStore::KEY_PREFIX . 'badapp'] = '2A';

		$this->runJob();

		self::assertArrayNotHasKey(RevokedCertificateStore::KEY_PREFIX . 'badapp', $this->config);
	}

	public function testShippedAndForgeBoundAppsAreSkipped(): void {
		$this->apps = ['files_shipped' => ['serial' => 42, 'shipped' => true], 'forged' => ['serial' => 42, 'forge' => true]];
		$this->revoked = [42];
		$this->config[RevokedCertificateStore::KEY_DISABLE] = true;

		$this->runJob();

		self::assertSame([], $this->sent);
		self::assertSame([], $this->disabled);
	}

	public function testWithTheSwitchOnTheAppIsDisabledOnceAndAudited(): void {
		$this->apps = ['badapp' => ['serial' => 42]];
		$this->revoked = [42];
		$this->config[RevokedCertificateStore::KEY_DISABLE] = true;

		$this->runJob();
		$this->runJob();

		self::assertSame(['badapp'], $this->disabled, 'disabled once; an admin who enables it again is not overruled');
		self::assertCount(1, $this->audit);
		self::assertSame('badapp', $this->audit[0]['subject']->appId);
		self::assertSame(AuditLogger::OPERATION_REVOKED_DISABLE, $this->audit[0]['subject']->operation);
		self::assertSame('system', $this->audit[0]['subject']->actorUid);
		self::assertSame('1.4.0', $this->audit[0]['versions']->fromVersion);
		self::assertSame(AuditLogger::STATUS_SUCCESS, $this->audit[0]['status']);
		self::assertSame(AuditOrigin::JOB, $this->audit[0]['origin']);
		self::assertStringContainsString('2A', (string)$this->audit[0]['message']);
		self::assertSame('1', $this->sent[0]['parameters']['disabled']);
	}

	public function testAListThatCannotBeFetchedLeavesTheRecheckOnTheListInUse(): void {
		$this->config[RevocationList::KEY_URL] = 'https://mirror.example.org/root.crl';
		$this->apps = ['badapp' => ['serial' => 42]];
		$this->revoked = [42];

		$this->runJob();

		self::assertCount(2, $this->sent, 'the shipped list still applies');
		self::assertSame(1_800_000_000, $this->config[RevocationList::KEY_FETCHED_AT]);
	}

	public function testAnAppWithoutACertificateIsSkipped(): void {
		$this->apps = ['badapp' => ['serial' => 42]];
		$this->revoked = [42];
		$this->writeApps();
		file_put_contents($this->root . '/apps/badapp/appinfo/signature.json', '{"hashes": {}}');

		$this->runJob(false);

		self::assertSame([], $this->sent);
	}

	private function runJob(bool $writeApps = true): void {
		if ($writeApps) {
			$this->writeApps();
		}
		file_put_contents($this->root . '/resources/codesigning/root.crl', CodeSigningFixtures::revocationList(self::$authority, 1, $this->revoked));

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1_800_000_000);
		$time->method('getDateTime')->willReturn(new \DateTime('2027-01-01T09:00:00Z'));
		$appConfig = $this->appConfig();
		$appManager = $this->appManager();

		$list = new RevocationList(new AppDataFiles($this->createMock(IAppDataFactory::class)), $appConfig, $this->createMock(IClientService::class), $time);
		$check = new InstalledCertificateCheck($appManager, $this->bindings());
		$disabler = new RevokedAppDisabler($appManager, $this->auditLogger(), new NullLogger());
		$responder = new RevokedAppResponder(new RevokedCertificateStore($appConfig), $disabler, $this->adminServices($time), new NullLogger());
		$job = new CertificateRecheckJob(new JobRuntime($time, new NullLogger()), $list, $check, $responder);
		(new \ReflectionMethod($job, 'run'))->invoke($job, null);
	}

	private function writeApps(): void {
		foreach ($this->apps as $appId => $app) {
			if (!is_dir($this->root . '/apps/' . $appId . '/appinfo')) {
				mkdir($this->root . '/apps/' . $appId . '/appinfo', 0777, true);
			}
			file_put_contents($this->root . '/apps/' . $appId . '/appinfo/signature.json', json_encode([
				'hashes' => [],
				'signature' => 'unused',
				'certificate' => CodeSigningFixtures::appCertificate(self::$authority, $appId, $app['serial']),
			]));
		}
	}

	private function appManager(): IAppManager {
		$manager = $this->createMock(IAppManager::class);
		$manager->method('getAppInstalledVersions')->willReturnCallback(fn (): array => array_fill_keys(array_keys($this->apps), '1.4.0'));
		$manager->method('isShipped')->willReturnCallback(fn (string $appId): bool => $this->apps[$appId]['shipped'] ?? false);
		$manager->method('getAppPath')->willReturnCallback(fn (string $appId): string => $this->root . '/apps/' . $appId);
		$manager->method('isEnabledForAnyone')->willReturnCallback(fn (string $appId): bool => !in_array($appId, $this->disabled, true));
		$manager->method('getAppVersion')->willReturn('1.4.0');
		$manager->method('disableApp')->willReturnCallback(function (string $appId): void {
			$this->disabled[] = $appId;
		});

		return $manager;
	}

	private function bindings(): SourceBindingStore {
		$bindings = $this->createMock(SourceBindingStore::class);
		$bindings->method('get')->willReturnCallback(fn (string $appId): ?SourceBinding => ($this->apps[$appId]['forge'] ?? false)
			? new SourceBinding(SourceBinding::KIND_GITHUB_RELEASE, ['owner' => 'ConductionNL', 'repo' => $appId])
			: null);

		return $bindings;
	}

	private function appConfig(): IAppConfig {
		$config = $this->createMock(IAppConfig::class);
		$config->method('hasKey')->willReturnCallback(fn (string $app, string $key): bool => array_key_exists($key, $this->config));
		$config->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default = ''): string => (string)($this->config[$key] ?? $default));
		$config->method('getValueInt')->willReturnCallback(fn (string $app, string $key, int $default = 0): int => (int)($this->config[$key] ?? $default));
		$config->method('getValueBool')->willReturnCallback(fn (string $app, string $key, bool $default = false): bool => (bool)($this->config[$key] ?? $default));
		$config->method('setValueInt')->willReturnCallback(function (string $app, string $key, int $value): bool {
			$this->config[$key] = $value;

			return true;
		});
		$config->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value): bool {
			$this->config[$key] = $value;

			return true;
		});
		$config->method('deleteKey')->willReturnCallback(function (string $app, string $key): void {
			unset($this->config[$key]);
		});

		return $config;
	}

	private function auditLogger(): AuditLogger {
		$audit = $this->createMock(AuditLogger::class);
		$audit->method('record')->willReturnCallback(function (AuditSubject $subject, AuditedVersions $versions, string $status, ?string $message = null): void {
			$this->audit[] = ['subject' => $subject, 'versions' => $versions, 'status' => $status, 'message' => $message, 'origin' => AuditOrigin::current()];
		});

		return $audit;
	}

	private function adminServices(ITimeFactory $time): AdminNotificationServices {
		$users = [];
		foreach (['admin', 'beheer'] as $uid) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$users[] = $user;
		}
		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn($users);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->with('admin')->willReturn($group);

		$manager = $this->createMock(IManager::class);
		$manager->method('createNotification')->willReturnCallback(function (): INotification {
			$record = ['user' => '', 'subject' => '', 'parameters' => []];
			$notification = $this->createMock(INotification::class);
			foreach (['setApp', 'setDateTime', 'setObject', 'setLink'] as $method) {
				$notification->method($method)->willReturnSelf();
			}
			$notification->method('setUser')->willReturnCallback(function (string $uid) use ($notification, &$record): INotification {
				$record['user'] = $uid;

				return $notification;
			});
			$notification->method('setSubject')->willReturnCallback(function (string $subject, array $parameters) use ($notification, &$record): INotification {
				$record['subject'] = $subject;
				$record['parameters'] = $parameters;

				return $notification;
			});
			$this->pending[spl_object_id($notification)] = &$record;

			return $notification;
		});
		$manager->method('notify')->willReturnCallback(function (INotification $notification): void {
			$this->sent[] = $this->pending[spl_object_id($notification)];
		});
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturn('https://cloud.example/settings/admin/versioniq');

		return new AdminNotificationServices($manager, $groups, $time, $urls);
	}
}
