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

use OCA\Versioniq\Controller\SbomController;
use OCA\Versioniq\Service\Inventory\SbomArchive;
use OCA\Versioniq\Service\Inventory\SbomExporter;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * GET /api/sbom (inventory-export-sbom-metrics task 2.1): a download for an
 * admin, 403 for anyone else, 400 for an unknown format; GET /api/sbom/{id}
 * (task 3.2): the copy kept for one install, 404 when none was kept.
 *
 * @covers \OCA\Versioniq\Controller\SbomController
 *
 * @spec openspec/changes/inventory-export-sbom-metrics/specs/inventory-export/spec.md#requirement-an-admin-exports-the-inventory-as-an-sbom
 */
final class SbomControllerTest extends TestCase {
	private function controller(bool $admin, ?SbomExporter $exporter = null, bool $signedIn = true, ?SbomArchive $archive = null): SbomController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($signedIn ? $user : null);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->with('alice')->willReturn($admin);

		return new SbomController('versioniq', $this->createMock(IRequest::class), $exporter ?? $this->createMock(SbomExporter::class), $groups, $session, $archive ?? $this->createMock(SbomArchive::class));
	}

	/**
	 * The headers the response set itself; getHeaders() also merges the
	 * server's own, which needs a running server.
	 *
	 * @return array<string, string>
	 */
	private static function headersOf(\OCP\AppFramework\Http\Response $response): array {
		return (new \ReflectionProperty(\OCP\AppFramework\Http\Response::class, 'headers'))->getValue($response);
	}

	public function testAnAdminDownloadsTheSbom(): void {
		$exporter = $this->createMock(SbomExporter::class);
		$exporter->expects(self::once())->method('export')->with('spdx')->willReturn('{"spdxVersion":"SPDX-2.3"}');
		$exporter->method('fileName')->with('spdx')->willReturn('nextcloud-sbom-20261114-100000.spdx.json');

		$response = $this->controller(true, $exporter)->export('spdx');

		self::assertInstanceOf(DataDownloadResponse::class, $response);
		self::assertSame(200, $response->getStatus());
		self::assertSame('{"spdxVersion":"SPDX-2.3"}', $response->render());
		self::assertSame('application/spdx+json', self::headersOf($response)['Content-Type']);
		self::assertStringContainsString('nextcloud-sbom-20261114-100000.spdx.json', self::headersOf($response)['Content-Disposition']);
	}

	public function testCycloneDxIsTheDefault(): void {
		$exporter = $this->createMock(SbomExporter::class);
		$exporter->expects(self::once())->method('export')->with('cyclonedx')->willReturn('{}');
		$exporter->method('fileName')->willReturn('x.cdx.json');

		$response = $this->controller(true, $exporter)->export();

		self::assertSame('application/vnd.cyclonedx+json', self::headersOf($response)['Content-Type']);
	}

	public function testANonAdminIsRefused(): void {
		$exporter = $this->createMock(SbomExporter::class);
		$exporter->expects(self::never())->method('export');

		$response = $this->controller(false, $exporter)->export('cyclonedx');

		self::assertInstanceOf(DataResponse::class, $response);
		self::assertSame(403, $response->getStatus());
		self::assertSame(403, $this->controller(true, $exporter, false)->export('cyclonedx')->getStatus());
	}

	public function testAnUnknownFormatIsA400(): void {
		$response = $this->controller(true)->export('xml');

		self::assertSame(400, $response->getStatus());
		self::assertSame(['message' => 'format must be one of: cyclonedx, spdx.'], $response->getData());
	}

	public function testAnAdminDownloadsTheCopyKeptForAnInstall(): void {
		$archive = $this->createMock(SbomArchive::class);
		$archive->method('read')->with(42)->willReturn('{"bomFormat":"CycloneDX"}');

		$response = $this->controller(true, null, true, $archive)->archived(42);

		self::assertInstanceOf(DataDownloadResponse::class, $response);
		self::assertSame('{"bomFormat":"CycloneDX"}', $response->render());
		self::assertSame('application/vnd.cyclonedx+json', self::headersOf($response)['Content-Type']);
		self::assertStringContainsString('nextcloud-sbom-install-42.cdx.json', self::headersOf($response)['Content-Disposition']);
	}

	public function testAnInstallWithoutACopyIsA404(): void {
		$archive = $this->createMock(SbomArchive::class);
		$archive->method('read')->willReturn(null);

		$response = $this->controller(true, null, true, $archive)->archived(7);

		self::assertSame(404, $response->getStatus());
	}

	public function testANonAdminGetsNoCopy(): void {
		$archive = $this->createMock(SbomArchive::class);
		$archive->expects(self::never())->method('read');

		self::assertSame(403, $this->controller(false, null, true, $archive)->archived(42)->getStatus());
	}
}
