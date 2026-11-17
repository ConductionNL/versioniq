<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Controller;

require_once __DIR__ . '/../Support/AuditArgs.php';
require_once __DIR__ . '/../Support/DispatchesLikeNextcloud.php';

use OCA\Versioniq\Controller\SettingsController;
use OCA\Versioniq\Service\Advisory\LocalFeedCopy;
use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\Settings\InstanceSettings;
use OCA\Versioniq\Tests\Unit\Support\AuditArgs;
use OCA\Versioniq\Tests\Unit\Support\DispatchesLikeNextcloud;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * #438 item 7: the occ-only settings reach a page through
 * GET/PUT /api/instance-settings.
 *
 * @spec openspec/specs/audit-trail/spec.md
 * @spec openspec/specs/external-sources/spec.md
 */
final class SettingsControllerTest extends TestCase {
	use DispatchesLikeNextcloud;

	/** @var array<string, string|int> */
	private array $config = [];

	private function controller(bool $admin, ?AuditLogger $audit = null, ?IRequest $request = null, bool $signedIn = true): SettingsController {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => (string)($this->config[$key] ?? $default),
		);
		$appConfig->method('getValueInt')->willReturnCallback(
			fn (string $app, string $key, int $default = 0): int => (int)($this->config[$key] ?? $default),
		);
		$appConfig->method('setValueInt')->willReturnCallback(
			function (string $app, string $key, int $value): bool {
				$this->config[$key] = $value;
				return true;
			},
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($signedIn ? $user : null);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn($admin);

		return new SettingsController(
			'versioniq',
			$request ?? $this->createMock(IRequest::class),
			new InstanceSettings($appConfig),
			$audit ?? $this->getMockBuilder(AuditLogger::class)->disableOriginalConstructor()->onlyMethods(['record'])->getMock(),
			$groups,
			$session,
		);
	}

	public function testNonAdminCannotReadOrWrite(): void {
		$controller = $this->controller(false);

		self::assertSame(403, $controller->instanceSettings()->getStatus());
		$request = $this->requestWith(['auditRetentionDays' => '90']);
		self::assertSame(403, $this->dispatch($this->controller(false, null, $request), 'updateInstanceSettings', $request)->getStatus());
		self::assertSame([], $this->config);
	}

	public function testSignedOutCallerCannotReadOrWrite(): void {
		$audit = $this->getMockBuilder(AuditLogger::class)->disableOriginalConstructor()->onlyMethods(['record'])->getMock();
		$audit->expects(self::never())->method(self::anything());

		self::assertSame(403, $this->controller(true, $audit, null, false)->instanceSettings()->getStatus());
		$request = $this->requestWith(['auditRetentionDays' => '90']);
		self::assertSame(403, $this->dispatch($this->controller(true, $audit, $request, false), 'updateInstanceSettings', $request)->getStatus());
		self::assertSame([], $this->config);
	}

	public function testReadReturnsTheSettings(): void {
		$response = $this->controller(true)->instanceSettings();

		self::assertSame(200, $response->getStatus());
		self::assertSame(365, $response->getData()['auditRetentionDays']);
	}

	public function testUpdateStoresAndAuditsTheChange(): void {
		$audit = $this->getMockBuilder(AuditLogger::class)->disableOriginalConstructor()->onlyMethods(['record'])->getMock();
		$audit->expects(self::once())->method('record')->with(...AuditArgs::expected('admin', 'versioniq', AuditLogger::OPERATION_SETTINGS));

		$request = $this->requestWith(['auditRetentionDays' => '90']);
		$response = $this->dispatch($this->controller(true, $audit, $request), 'updateInstanceSettings', $request);

		self::assertSame(200, $response->getStatus());
		self::assertSame(90, $response->getData()['auditRetentionDays']);
		self::assertSame(90, $this->config['audit_retention_days']);
	}

	public function testAnInvalidValueIsA400(): void {
		$request = $this->requestWith(['auditRetentionDays' => '7']);
		$response = $this->dispatch($this->controller(true, null, $request), 'updateInstanceSettings', $request);

		self::assertSame(400, $response->getStatus());
		self::assertSame([], $this->config);
	}

	/**
	 * The controller with a local feed copy and a request carrying $upload as `file`
	 * (sources-trust-audit-and-offline-feed 3.3).
	 *
	 * @param array<string, mixed>|null $upload
	 */
	private function importController(bool $admin, LocalFeedCopy $copy, ?array $upload, ?AuditLogger $audit = null): SettingsController {
		$request = $this->createMock(IRequest::class);
		$request->method('getUploadedFile')->with('file')->willReturn($upload);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn($admin);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1792800000);

		return new SettingsController(
			'versioniq',
			$request,
			new InstanceSettings($this->createMock(IAppConfig::class)),
			$audit ?? $this->getMockBuilder(AuditLogger::class)->disableOriginalConstructor()->onlyMethods(['record'])->getMock(),
			$groups,
			$session,
			$copy,
			$time,
		);
	}

	/**
	 * @return array{tmp_name: string, error: int}
	 */
	private function upload(string $content): array {
		$path = tempnam(sys_get_temp_dir(), 'feed');
		file_put_contents($path, $content);

		return ['tmp_name' => $path, 'error' => UPLOAD_ERR_OK];
	}

	public function testAnAdminImportsAFeedCopyAndTheImportIsAudited(): void {
		$copy = $this->createMock(LocalFeedCopy::class);
		$copy->expects(self::once())->method('import')->with('[{"ghsa_id":"GHSA-aaaa"}]', 1792800000)
			->willReturn(['copiedAt' => 1792800000, 'records' => 1]);
		$audit = $this->getMockBuilder(AuditLogger::class)->disableOriginalConstructor()->onlyMethods(['record'])->getMock();
		$audit->expects(self::once())->method('record')->willReturnCallback(
			static function ($subject, $versions, string $status, ?string $message): void {
				self::assertSame('admin', $subject->actorUid);
				self::assertSame(AuditLogger::OPERATION_SETTINGS, $subject->operation);
				self::assertSame(AuditLogger::STATUS_SUCCESS, $status);
				self::assertSame('Advisory feed copy imported: 1 advisories', $message);
			},
		);

		$response = $this->importController(true, $copy, $this->upload('[{"ghsa_id":"GHSA-aaaa"}]'), $audit)->importAdvisoryFeedCopy();

		self::assertSame(200, $response->getStatus());
		self::assertSame(['copiedAt' => 1792800000, 'records' => 1], $response->getData());
	}

	public function testABrokenFileIsRefusedWithTheReasonAndNotAudited(): void {
		$copy = $this->createMock(LocalFeedCopy::class);
		$copy->method('import')->willThrowException(new \InvalidArgumentException('The file is not valid JSON: Syntax error'));
		$audit = $this->getMockBuilder(AuditLogger::class)->disableOriginalConstructor()->onlyMethods(['record'])->getMock();
		$audit->expects(self::never())->method('record');

		$response = $this->importController(true, $copy, $this->upload('<html>'), $audit)->importAdvisoryFeedCopy();

		self::assertSame(400, $response->getStatus());
		self::assertSame(['message' => 'The file is not valid JSON: Syntax error'], $response->getData());
	}

	public function testAnImportWithoutAFileIsRefused(): void {
		$copy = $this->createMock(LocalFeedCopy::class);
		$copy->expects(self::never())->method('import');

		$missing = $this->importController(true, $copy, null)->importAdvisoryFeedCopy();
		$failed = $this->importController(true, $copy, ['tmp_name' => '/nope', 'error' => UPLOAD_ERR_PARTIAL])->importAdvisoryFeedCopy();

		self::assertSame(400, $missing->getStatus());
		self::assertSame(['message' => 'Choose a feed file to import.'], $missing->getData());
		self::assertSame(400, $failed->getStatus());
	}

	public function testANonAdminCannotImportAFeedCopy(): void {
		$copy = $this->createMock(LocalFeedCopy::class);
		$copy->expects(self::never())->method('import');

		self::assertSame(403, $this->importController(false, $copy, $this->upload('[]'))->importAdvisoryFeedCopy()->getStatus());
	}
}
