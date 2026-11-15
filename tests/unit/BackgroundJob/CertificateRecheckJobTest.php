<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/safety-withdrawn-and-revoked/specs/withdrawn-and-revoked/spec.md#requirement-installed-apps-are-rechecked-against-a-current-revocation-list
 */

namespace OCA\Versioniq\Tests\Unit\BackgroundJob;

use OCA\Versioniq\BackgroundJob\CertificateRecheckJob;
use OCA\Versioniq\Service\AdminNotificationServices;
use OCA\Versioniq\Service\Audit\AuditedVersions;
use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\Audit\AuditOrigin;
use OCA\Versioniq\Service\Audit\AuditSubject;
use OCA\Versioniq\Service\Safety\InstalledCertificateCheck;
use OCA\Versioniq\Service\Safety\RevocationList;
use OCA\Versioniq\Service\Safety\RevokedCertificateStore;
use OCA\Versioniq\Service\Settings\InstanceSettings;
use OCA\Versioniq\Service\Source\SourceBinding;
use OCA\Versioniq\Service\Source\SourceBindingStore;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use phpseclib3\File\X509;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The daily certificate recheck (design D2): one notification per app and
 * serial, shipped and forge apps skipped, disable only with the switch on,
 * and an audit row on disable.
 *
 * Certificates are real (generated per run); the revocation list is the
 * scripted phpseclib X509 stub, revoking the serials in $revokedSerials.
 *
 * @covers \OCA\Versioniq\BackgroundJob\CertificateRecheckJob
 * @covers \OCA\Versioniq\Service\Safety\InstalledCertificateCheck
 * @covers \OCA\Versioniq\Service\Safety\RevokedCertificateStore
 * @uses   \OCA\Versioniq\Service\AdminNotice
 * @uses   \OCA\Versioniq\Service\AdminNotificationServices
 * @uses   \OCA\Versioniq\Service\Audit\AuditOrigin
 * @uses   \OCA\Versioniq\Service\Audit\AuditSubject
 * @uses   \OCA\Versioniq\Service\Audit\AuditedVersions
 * @uses   \OCA\Versioniq\Service\Settings\InstanceSettings
 * @uses   \OCA\Versioniq\Service\Source\SourceBinding
 */
final class CertificateRecheckJobTest extends TestCase {
	private string $root = '';

	/** @var array<string, string|int|bool> */
	private array $config = [];

	/** @var list<string> */
	private array $revokedSerials = [];

	/** @var array<string, array{serial: int, shipped?: bool, forge?: bool, enabled?: bool}> */
	private array $apps = [];

	/** @var list<array{string, array<string, string>}> */
	private array $notified = [];

	/** @var list<string> */
	private array $disabled = [];

	/** @var list<array{AuditSubject, AuditedVersions, string, ?string, string}> */
	private array $audits = [];

	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . '/vq-recheck-' . bin2hex(random_bytes(4));
		mkdir($this->root);
		X509::$handler = fn (X509 $x509, string $method, array $args): mixed => $method === 'getRevoked'
			? (in_array($args[0], $this->revokedSerials, true) ? ['revocationDate' => 'x'] : false)
			: null;
	}

	protected function tearDown(): void {
		X509::$handler = null;
		foreach (glob($this->root . '/*/appinfo/signature.json') ?: [] as $file) {
			unlink($file);
			rmdir(dirname($file));
			rmdir(dirname($file, 2));
		}
		@rmdir($this->root);
	}

	/**
	 * @param array{shipped?: bool, forge?: bool, enabled?: bool} $flags
	 */
	private function app(string $appId, int $serial, array $flags = []): void {
		$this->apps[$appId] = ['serial' => $serial] + $flags;
		mkdir($this->root . '/' . $appId . '/appinfo', 0o777, true);
		$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
		$csr = openssl_csr_new(['commonName' => $appId], $key);
		$cert = openssl_csr_sign($csr, null, $key, 1, [], $serial);
		openssl_x509_export($cert, $pem);
		file_put_contents($this->root . '/' . $appId . '/appinfo/signature.json', json_encode(['hashes' => [], 'signature' => 'x', 'certificate' => $pem]));
	}

	private function job(): CertificateRecheckJob {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppInstalledVersions')->willReturnCallback(
			fn (): array => array_map(static fn (): string => '1.0.0', $this->apps),
		);
		$appManager->method('isShipped')->willReturnCallback(fn (string $id): bool => $this->apps[$id]['shipped'] ?? false);
		$appManager->method('getAppPath')->willReturnCallback(fn (string $id): string => $this->root . '/' . $id);
		$appManager->method('isEnabledForAnyone')->willReturnCallback(
			fn (string $id): bool => ($this->apps[$id]['enabled'] ?? true) && !in_array($id, $this->disabled, true),
		);
		$appManager->method('getAppVersion')->willReturn('1.0.0');
		$appManager->method('disableApp')->willReturnCallback(function (string $id): void {
			$this->disabled[] = $id;
		});

		$bindings = $this->createMock(SourceBindingStore::class);
		$bindings->method('get')->willReturnCallback(fn (string $id): ?SourceBinding => ($this->apps[$id]['forge'] ?? false)
			? SourceBinding::github('o', $id)
			: null);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => (string)($this->config[$key] ?? $default),
		);
		$appConfig->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value): bool {
			$this->config[$key] = $value;

			return true;
		});
		$appConfig->method('deleteKey')->willReturnCallback(function (string $app, string $key): void {
			unset($this->config[$key]);
		});
		$appConfig->method('getValueBool')->willReturnCallback(
			fn (string $app, string $key, bool $default = false): bool => (bool)($this->config[$key] ?? $default),
		);

		$revocationList = $this->createMock(RevocationList::class);
		$revocationList->method('current')->willReturnCallback(static fn (): X509 => new X509());

		$audit = $this->createMock(AuditLogger::class);
		$audit->method('record')->willReturnCallback(function (AuditSubject $subject, AuditedVersions $versions, string $status, ?string $message = null): void {
			$this->audits[] = [$subject, $versions, $status, $message, AuditOrigin::current()];
		});

		$check = new InstalledCertificateCheck(
			$appManager,
			$bindings,
			$revocationList,
			new RevokedCertificateStore($appConfig),
			new InstanceSettings($appConfig),
			$audit,
			$this->adminServices(),
			$this->createMock(LoggerInterface::class),
		);

		return new CertificateRecheckJob($this->createMock(ITimeFactory::class), $revocationList, $check, $this->createMock(LoggerInterface::class));
	}

	private function adminServices(): AdminNotificationServices {
		$notificationManager = $this->createMock(IManager::class);
		$notificationManager->method('createNotification')->willReturnCallback(function (): INotification {
			$notification = $this->createMock(INotification::class);
			$captured = ['user' => ''];
			foreach (['setApp', 'setDateTime', 'setObject', 'setLink'] as $method) {
				$notification->method($method)->willReturnSelf();
			}
			$notification->method('setUser')->willReturnCallback(function (string $uid) use ($notification, &$captured): INotification {
				$captured['user'] = $uid;

				return $notification;
			});
			$notification->method('setSubject')->willReturnCallback(function (string $subject, array $params) use ($notification, &$captured): INotification {
				$this->notified[] = [$subject . ':' . $captured['user'], $params];

				return $notification;
			});

			return $notification;
		});

		$users = array_map(function (string $uid): IUser {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);

			return $user;
		}, ['admin', 'bob']);
		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn($users);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->with('admin')->willReturn($group);

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturn('https://example.test/settings/admin/versioniq');

		return new AdminNotificationServices($notificationManager, $groups, $this->createMock(ITimeFactory::class), $urls);
	}

	private function runJob(): void {
		$job = $this->job();
		(new \ReflectionMethod($job, 'run'))->invoke($job, null);
	}

	public function testARevokedCertificateNotifiesEveryAdminOncePerAppAndSerial(): void {
		$this->app('badapp', 4711);
		$this->app('goodapp', 4712);
		$this->revokedSerials = ['4711'];

		$this->runJob();
		$this->runJob();

		self::assertSame([
			['certificate_revoked:admin', ['app' => 'badapp', 'serial' => '4711']],
			['certificate_revoked:bob', ['app' => 'badapp', 'serial' => '4711']],
		], $this->notified);
		self::assertSame('4711', $this->config['safety.revoked.badapp']);
		self::assertArrayNotHasKey('safety.revoked.goodapp', $this->config);
	}

	public function testTheAppStaysEnabledWhileAutomaticDisablingIsOff(): void {
		$this->app('badapp', 4711);
		$this->revokedSerials = ['4711'];

		$this->runJob();

		self::assertSame([], $this->disabled);
		self::assertSame([], $this->audits);
	}

	public function testTheAppIsDisabledAndAuditedWithTheSwitchOn(): void {
		$this->config[InstanceSettings::KEY_DISABLE_REVOKED] = true;
		$this->app('badapp', 4711);
		$this->revokedSerials = ['4711'];

		$this->runJob();
		$this->runJob();

		self::assertSame(['badapp'], $this->disabled);
		self::assertCount(1, $this->audits);
		[$subject, $versions, $status, $message, $origin] = $this->audits[0];
		self::assertSame(['', 'badapp', 'disable_revoked', 'job'], [$subject->actorUid, $subject->appId, $subject->operation, $origin]);
		self::assertSame(['1.0.0', '1.0.0', 'appstore'], [$versions->fromVersion, $versions->toVersion, $versions->sourceId]);
		self::assertSame([AuditLogger::STATUS_SUCCESS, 'Disabled: certificate 4711 was revoked'], [$status, $message]);
	}

	public function testShippedAndForgeAppsAreSkipped(): void {
		$this->app('files', 1, ['shipped' => true]);
		$this->app('hermiq', 2, ['forge' => true]);
		$this->revokedSerials = ['1', '2'];

		$this->runJob();

		self::assertSame([], $this->notified);
		self::assertArrayNotHasKey('safety.revoked.files', $this->config);
		self::assertArrayNotHasKey('safety.revoked.hermiq', $this->config);
	}

	public function testAReplacedCertificateClearsTheFlag(): void {
		$this->app('badapp', 4711);
		$this->config['safety.revoked.badapp'] = '4700';

		$this->runJob();

		self::assertArrayNotHasKey('safety.revoked.badapp', $this->config);
		self::assertSame([], $this->notified);
	}

	public function testAnAppWithoutACertificateIsNotChecked(): void {
		$this->app('broken', 9);
		file_put_contents($this->root . '/broken/appinfo/signature.json', '{}');
		$this->revokedSerials = ['9'];

		$this->runJob();

		self::assertSame([], $this->notified);
	}

	public function testItRunsDaily(): void {
		$job = $this->job();

		self::assertSame(86400, (new \ReflectionProperty($job, 'interval'))->getValue($job));
	}
}
