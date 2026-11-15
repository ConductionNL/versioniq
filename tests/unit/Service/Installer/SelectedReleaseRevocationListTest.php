<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/safety-withdrawn-and-revoked/specs/withdrawn-and-revoked/spec.md#requirement-installed-apps-are-rechecked-against-a-current-revocation-list
 */

namespace OCA\Versioniq\Tests\Unit\Service\Installer;

use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\Cache\ArtifactCache;
use OCA\Versioniq\Service\Installer\InstallFinalizer;
use OCA\Versioniq\Service\Installer\MigrationDiffer;
use OCA\Versioniq\Service\Safety\RevocationList;
use OCA\Versioniq\Service\SelectedReleaseInstallerService;
use OCP\IUserSession;
use phpseclib3\File\X509;
use PHPUnit\Framework\TestCase;

/**
 * Installs read their revocation list from RevocationList (design D1), so an
 * install and the daily recheck can never disagree.
 *
 * @covers \OCA\Versioniq\Service\SelectedReleaseInstallerService
 */
final class SelectedReleaseRevocationListTest extends TestCase {
	private string $savedServerRoot = '';

	protected function setUp(): void {
		$this->savedServerRoot = (string)\OC::$SERVERROOT;
		// No shipped files: only the RevocationList may answer.
		\OC::$SERVERROOT = sys_get_temp_dir() . '/vq-no-server-' . bin2hex(random_bytes(4));
	}

	protected function tearDown(): void {
		X509::$handler = null;
		\OC::$SERVERROOT = $this->savedServerRoot;
	}

	private function verify(RevocationList $list): void {
		$service = new SelectedReleaseInstallerService(
			$this->createMock(InstallFinalizer::class),
			$this->createMock(AuditLogger::class),
			$this->createMock(IUserSession::class),
			$this->createMock(MigrationDiffer::class),
			$this->createMock(ArtifactCache::class),
			$list,
		);

		$method = new \ReflectionMethod($service, 'verifyCertificate');
		$method->invoke($service, 'badapp', 'APP-CERT');
	}

	private function scriptX509(\WeakMap $roles): void {
		$serial = new class {
			public function toString(): string {
				return '7';
			}
		};
		X509::$handler = static function (X509 $x509, string $method, array $args) use ($roles, $serial): mixed {
			return match ($method) {
				'loadX509' => ['tbsCertificate' => ['serialNumber' => $serial]],
				'getRevoked' => ($roles[$x509] ?? null) === 'newest' && $args[0] === '7' ? ['revocationDate' => 'x'] : false,
				'validateSignature' => true,
				default => null,
			};
		};
	}

	public function testARevocationInTheNewestListRefusesTheInstall(): void {
		$roles = new \WeakMap();
		$this->scriptX509($roles);
		$newest = new X509();
		$roles[$newest] = 'newest';

		$list = $this->createMock(RevocationList::class);
		$list->expects(self::once())->method('rootCertificates')->willReturn(['ROOT']);
		$list->expects(self::once())->method('current')->willReturn($newest);

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Certificate "7" has been revoked');
		$this->verify($list);
	}

	public function testACertificateTheNewestListDoesNotRevokeGoesOnToTheIssuerCheck(): void {
		$roles = new \WeakMap();
		$this->scriptX509($roles);

		$list = $this->createMock(RevocationList::class);
		$list->method('rootCertificates')->willReturn(['ROOT']);
		$list->method('current')->willReturn(new X509());

		// Past the revocation check, the certificate's CN is checked next; the
		// scripted certificate carries none.
		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('App with id badapp has a cert with no CN');
		$this->verify($list);
	}
}
