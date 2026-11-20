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
use OCA\Versioniq\Db\AuditEntry;
use OCA\Versioniq\Db\AuditEntryMapper;
use OCA\Versioniq\Service\Inventory\SbomArchive;
use OCA\Versioniq\Tests\Unit\Support\BuildsByParameterName;
use OCP\App\IAppManager;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * GET /api/audit flags each install row with whether an SBOM was kept for it
 * (inventory-export-sbom-metrics task 3.2); other rows carry no flag.
 *
 * @covers \OCA\Versioniq\Controller\ApiController
 * @uses \OCA\Versioniq\Db\AuditEntry
 * @uses \OCA\Versioniq\Service\Api\AccessCollaborators
 * @uses \OCA\Versioniq\Service\Api\ApiCaller
 * @uses \OCA\Versioniq\Service\Api\InstallCollaborators
 * @uses \OCA\Versioniq\Service\Api\PatServices
 * @uses \OCA\Versioniq\Service\Api\ReportCollaborators
 * @uses \OCA\Versioniq\Service\Api\ReportStores
 * @uses \OCA\Versioniq\Service\AutoUpdate\AutoUpdateStores
 *
 * @spec openspec/changes/inventory-export-sbom-metrics/specs/inventory-export/spec.md#requirement-every-update-leaves-an-sbom-of-the-state-it-produced
 */
final class ApiAuditSbomFlagTest extends TestCase {
	use BuildsByParameterName;

	private static function entry(int $id, string $operation): AuditEntry {
		$entry = new AuditEntry();
		$entry->setId($id);
		$entry->setActorUid('alice');
		$entry->setAppId('openregister');
		$entry->setOperation($operation);
		$entry->setStatus('success');

		return $entry;
	}

	private function controller(?SbomArchive $archive): ApiController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(true);
		$mapper = $this->createMock(AuditEntryMapper::class);
		$mapper->method('findPage')->willReturn([self::entry(42, 'install'), self::entry(41, 'pin'), self::entry(40, 'install')]);
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $name, mixed $default = null): mixed => $default);

		return $this->buildByName(ApiController::class, [
			'appName' => 'versioniq',
			'request' => $request,
			'userSession' => $session,
			'groupManager' => $groups,
			'serverVersion' => (new ReflectionClass(\OCP\ServerVersion::class))->newInstanceWithoutConstructor(),
			'appManager' => $this->createMock(IAppManager::class),
			'auditEntryMapper' => $mapper,
			'connectionReports' => null,
			'sbomArchive' => $archive,
		]);
	}

	public function testEachInstallRowSaysWhetherAnSbomWasKept(): void {
		$archive = $this->createMock(SbomArchive::class);
		$archive->method('storedIds')->willReturn([42 => true]);

		$entries = $this->controller($archive)->auditLog()->getData()['entries'];

		self::assertSame([42, 41, 40], array_column($entries, 'id'));
		self::assertTrue($entries[0]['sbomAvailable']);
		self::assertArrayNotHasKey('sbomAvailable', $entries[1]);
		self::assertFalse($entries[2]['sbomAvailable']);
		self::assertSame('openregister', $entries[0]['appId']);
	}

	public function testWithoutTheArchiveTheRowsGoOutAsBefore(): void {
		$entries = $this->controller(null)->auditLog()->getData()['entries'];

		self::assertContainsOnlyInstancesOf(AuditEntry::class, $entries);
	}
}
