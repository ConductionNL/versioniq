<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * inventory-upgrade-readiness task 2.2: GET and POST /api/readiness are
 * admin-only; POST queues a job with both targets and lists no versions.
 *
 * @spec openspec/changes/inventory-upgrade-readiness/specs/upgrade-readiness/spec.md#requirement-an-admin-checks-every-installed-app-against-a-target-server-major-and-php-version
 */

declare(strict_types=1);

namespace OCA\Versioniq\Tests\Unit\Controller;

require_once __DIR__ . '/../Support/BuildsByParameterName.php';

use OCA\Versioniq\BackgroundJob\UpgradeReadinessJob;
use OCA\Versioniq\Controller\ApiController;
use OCA\Versioniq\Service\InstallerService;
use OCA\Versioniq\Service\Readiness\ReadinessResultStore;
use OCA\Versioniq\Tests\Unit\Support\BuildsByParameterName;
use OCP\AppFramework\Http;
use OCP\BackgroundJob\IJobList;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\ServerVersion;
use PHPUnit\Framework\TestCase;

final class ApiReadinessTest extends TestCase {
	use BuildsByParameterName;

	private function controller(bool $admin, ReadinessResultStore $store, ?IJobList $jobs): ApiController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->with('alice')->willReturn($admin);
		$installer = $this->createMock(InstallerService::class);
		$installer->expects(self::never())->method('getAppVersions');

		return $this->buildByName(ApiController::class, [
			'appName' => 'versioniq',
			'request' => $this->createMock(IRequest::class),
			'installerService' => $installer,
			'groupManager' => $groups,
			'userSession' => $session,
			'serverVersion' => (new \ReflectionClass(ServerVersion::class))->newInstanceWithoutConstructor(),
			'connectionReports' => null,
			'readinessResultStore' => $store,
			'jobList' => $jobs,
		]);
	}

	public function testANonAdminGets403AndNoJobIsQueued(): void {
		$store = $this->createMock(ReadinessResultStore::class);
		$store->expects(self::never())->method('read');
		$jobs = $this->createMock(IJobList::class);
		$jobs->expects(self::never())->method('add');
		$controller = $this->controller(false, $store, $jobs);

		self::assertSame(Http::STATUS_FORBIDDEN, $controller->startReadiness('33', '8.2')->getStatus());
		self::assertSame(Http::STATUS_FORBIDDEN, $controller->readiness()->getStatus());
	}

	public function testPostQueuesAJobWithBothTargets(): void {
		$jobs = $this->createMock(IJobList::class);
		$jobs->expects(self::once())->method('add')->with(UpgradeReadinessJob::class, ['server' => '33', 'php' => '8.2']);

		$response = $this->controller(true, $this->createMock(ReadinessResultStore::class), $jobs)->startReadiness(' 33', '8.2 ');

		self::assertSame(Http::STATUS_ACCEPTED, $response->getStatus());
		self::assertSame(['queued' => true, 'server' => '33', 'php' => '8.2'], $response->getData());
	}

	public function testBadTargetsAre400AndQueueNothing(): void {
		$jobs = $this->createMock(IJobList::class);
		$jobs->expects(self::never())->method('add');
		$controller = $this->controller(true, $this->createMock(ReadinessResultStore::class), $jobs);

		foreach ([['', '8.2'], ['33', ''], ['next', '8.2'], ['33', 'eight'], ['3333', '8.2']] as [$server, $php]) {
			self::assertSame(Http::STATUS_BAD_REQUEST, $controller->startReadiness($server, $php)->getStatus(), $server . '/' . $php);
		}
	}

	public function testWithoutBackgroundJobsTheCheckIsRefused(): void {
		$response = $this->controller(true, $this->createMock(ReadinessResultStore::class), null)->startReadiness('33', '8.2');

		self::assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
	}

	public function testGetReturnsTheStoredReportItsTimeAndThisPhp(): void {
		$report = ['server' => '33', 'php' => '8.2', 'apps' => []];
		$store = $this->createMock(ReadinessResultStore::class);
		$store->method('read')->willReturn(['report' => $report, 'checkedAt' => 1_790_000_000]);

		$response = $this->controller(true, $store, $this->createMock(IJobList::class))->readiness();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['report' => $report, 'checkedAt' => 1_790_000_000, 'php' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION], $response->getData());
	}
}
