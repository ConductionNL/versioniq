<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service;

require_once __DIR__ . '/../Support/AuditArgs.php';

require_once __DIR__ . '/../Support/BuildsByParameterName.php';

use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\ExternalReleaseInstallerService;
use OCA\Versioniq\Service\Installer\ExternalInstallOptions;
use OCA\Versioniq\Service\Installer\ExternalInstallRequest;
use OCA\Versioniq\Service\Installer\ExternalInstallRun;
use OCA\Versioniq\Service\SelectedReleaseInstallerService;
use OCA\Versioniq\Service\Source\SourceBinding;
use OCA\Versioniq\Tests\Unit\Support\AuditArgs;
use OCA\Versioniq\Tests\Unit\Support\BuildsByParameterName;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Exercises the private audit-recording helpers on both installer services in
 * isolation (task 8 "Installer tests extended"). The full install path is
 * network- and Server::get()-dependent and not unit-reachable — see the
 * rationale in {@see \OCA\Versioniq\Tests\Unit\Service\InstallerRecoveryTest}
 * — so, mirroring that file's approach, instances are built without their
 * constructor via reflection and the private/protected dependencies these
 * helpers touch are injected directly.
 */
final class InstallerAuditHookTest extends TestCase {
	use BuildsByParameterName;

	private function userSession(string $uid = 'alice'): IUserSession {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return $session;
	}

	private function anonymousSession(): IUserSession {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);

		return $session;
	}

	/**
	 * @template T of object
	 * @param class-string<T> $class
	 * @return T
	 */
	private function instanceWithInjectedDeps(string $class, AuditLogger $auditLogger, IUserSession $userSession): object {
		return $this->buildByName($class, ['auditLogger' => $auditLogger, 'userSession' => $userSession]);
	}

	public function testSelectedInstallerRecordsSuccessAgainstAppStore(): void {
		$auditLogger = $this->createMock(AuditLogger::class);
		$auditLogger->expects($this->once())
			->method('record')
			->with(...AuditArgs::expected(
				'alice',
				'openregister',
				AuditLogger::OPERATION_INSTALL,
				'2.5.0',
				'2.3.0',
				'appstore',
				AuditLogger::STATUS_SUCCESS,
				null,
			));

		$instance = $this->instanceWithInjectedDeps(SelectedReleaseInstallerService::class, $auditLogger, $this->userSession());

		$method = (new ReflectionClass(SelectedReleaseInstallerService::class))->getMethod('recordInstallAudit');
		$method->setAccessible(true);
		$method->invoke($instance, $this->signedInstall('2.5.0'), [AuditLogger::STATUS_SUCCESS, null]);
	}

	public function testSelectedInstallerRecordsFailureWithMessageAndNullFromVersionOnFreshInstall(): void {
		$auditLogger = $this->createMock(AuditLogger::class);
		$auditLogger->expects($this->once())
			->method('record')
			->with(...AuditArgs::expected(
				'alice',
				'openregister',
				AuditLogger::OPERATION_INSTALL,
				null,
				'2.3.0',
				'appstore',
				AuditLogger::STATUS_FAILURE,
				'Could not download selected release: HTTP 404',
			));

		$instance = $this->instanceWithInjectedDeps(SelectedReleaseInstallerService::class, $auditLogger, $this->userSession());

		$method = (new ReflectionClass(SelectedReleaseInstallerService::class))->getMethod('recordInstallAudit');
		$method->setAccessible(true);
		$method->invoke($instance, $this->signedInstall(''), [AuditLogger::STATUS_FAILURE, 'Could not download selected release: HTTP 404']);
	}

	public function testSelectedInstallerFallsBackToSystemActorWhenNoUserInSession(): void {
		$auditLogger = $this->createMock(AuditLogger::class);
		$auditLogger->expects($this->once())
			->method('record')
			->with(...AuditArgs::expected('system', 'openregister', AuditLogger::OPERATION_INSTALL, null, '2.3.0', 'appstore', AuditLogger::STATUS_SUCCESS, null));

		$instance = $this->instanceWithInjectedDeps(SelectedReleaseInstallerService::class, $auditLogger, $this->anonymousSession());

		$method = (new ReflectionClass(SelectedReleaseInstallerService::class))->getMethod('recordInstallAudit');
		$method->setAccessible(true);
		$method->invoke($instance, $this->signedInstall(''), [AuditLogger::STATUS_SUCCESS, null]);
	}

	/**
	 * An App Store install of openregister 2.3.0 over `$installedVersion` ('' for a fresh install).
	 */
	private function signedInstall(string $installedVersion): \OCA\Versioniq\Service\Installer\SignedInstall {
		return new \OCA\Versioniq\Service\Installer\SignedInstall('openregister', ['version' => '2.3.0'], $installedVersion, 'yes');
	}

	public function testExternalInstallerRecordsSuccessWithBindingSourceIdAndIntegrityWarning(): void {
		$auditLogger = $this->createMock(AuditLogger::class);
		$auditLogger->expects($this->once())
			->method('record')
			->with(...AuditArgs::expected(
				'alice',
				'openregister',
				AuditLogger::OPERATION_INSTALL,
				'2.5.0',
				'2.3.0',
				'github:ConductionNL/openregister',
				AuditLogger::STATUS_SUCCESS,
				'No SHA-256 checksum available for this artifact.',
			));

		$instance = $this->instanceWithInjectedDeps(ExternalReleaseInstallerService::class, $auditLogger, $this->userSession());

		$method = (new ReflectionClass(ExternalReleaseInstallerService::class))->getMethod('recordInstallAudit');
		$method->setAccessible(true);
		$method->invoke($instance, $this->externalRun(), [AuditLogger::STATUS_SUCCESS, 'No SHA-256 checksum available for this artifact.']);
	}

	public function testExternalInstallerRecordsFailureWithTheFailureMessage(): void {
		$auditLogger = $this->createMock(AuditLogger::class);
		$auditLogger->expects($this->once())
			->method('record')
			->with(...AuditArgs::expected(
				'alice',
				'openregister',
				AuditLogger::OPERATION_INSTALL,
				'2.5.0',
				'2.3.0',
				'github:ConductionNL/openregister',
				AuditLogger::STATUS_FAILURE,
				'SHA-256 mismatch — expected aaa, got bbb.',
			));

		$instance = $this->instanceWithInjectedDeps(ExternalReleaseInstallerService::class, $auditLogger, $this->userSession());

		$method = (new ReflectionClass(ExternalReleaseInstallerService::class))->getMethod('recordInstallAudit');
		$method->setAccessible(true);
		$method->invoke($instance, $this->externalRun(), [AuditLogger::STATUS_FAILURE, 'SHA-256 mismatch — expected aaa, got bbb.']);
	}

	/**
	 * No PAT/Authorization material can reach the audit message: the private
	 * helper only ever forwards the exception message it is handed, and the
	 * external installer's PAT flows through {@see \OCA\Versioniq\Service\Pat\PatManager::useToken()}
	 * — a token never becomes a variable in scope at any of the installer's
	 * throw sites. AuditLogger additionally redacts any Bearer/Authorization
	 * pattern defensively (see AuditLoggerTest).
	 */
	public function testExternalInstallerAuditMessageNeverCarriesAnAuthorizationHeader(): void {
		$auditLogger = $this->createMock(AuditLogger::class);
		$capturedMessage = null;
		$auditLogger->method('record')->willReturnCallback(
			function (mixed ...$args) use (&$capturedMessage): void {
				$capturedMessage = AuditArgs::flatten($args)[7] ?? null;
			}
		);

		$instance = $this->instanceWithInjectedDeps(ExternalReleaseInstallerService::class, $auditLogger, $this->userSession());

		$method = (new ReflectionClass(ExternalReleaseInstallerService::class))->getMethod('recordInstallAudit');
		$method->setAccessible(true);
		$method->invoke($instance, $this->externalRun(), [AuditLogger::STATUS_FAILURE, 'Could not download selected release: connection reset']);

		$this->assertIsString($capturedMessage);
		$this->assertStringNotContainsString('Authorization', $capturedMessage);
		$this->assertStringNotContainsString('Bearer', $capturedMessage);
	}

	/**
	 * An external install of openregister 2.3.0 from GitHub over installed 2.5.0.
	 */
	private function externalRun(): ExternalInstallRun {
		$request = new ExternalInstallRequest('openregister', '2.3.0', [], new ExternalInstallOptions(false, false));

		return new ExternalInstallRun($request, SourceBinding::github('ConductionNL', 'openregister'), '2.5.0');
	}
}
