<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Controller;

require_once __DIR__ . '/../Support/AuditArgs.php';
require_once __DIR__ . '/../Support/DispatchesLikeNextcloud.php';
require_once __DIR__ . '/../Service/Policy/PatchPolicyTest.php';

use OCA\Versioniq\Controller\PatchPolicyController;
use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\Policy\PatchPolicyStatement;
use OCA\Versioniq\Service\Policy\PatchPolicyStore;
use OCA\Versioniq\Tests\Unit\Service\Policy\PatchPolicyTest;
use OCA\Versioniq\Tests\Unit\Support\AuditArgs;
use OCA\Versioniq\Tests\Unit\Support\DispatchesLikeNextcloud;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * GET and PUT /api/patch-policy (autoupdate-security-first D3, D5).
 *
 * @covers \OCA\Versioniq\Controller\PatchPolicyController
 * @uses \OCA\Versioniq\Service\Policy\PatchPolicy
 * @uses \OCA\Versioniq\Service\Policy\PatchPolicyStore
 * @uses \OCA\Versioniq\Service\Policy\PatchPolicyStatement
 * @uses \OCA\Versioniq\Service\Advisory\AdvisorySeverity
 * @uses \OCA\Versioniq\Service\Audit\AuditSubject
 * @uses \OCA\Versioniq\Service\Audit\AuditedVersions
 * @spec openspec/changes/autoupdate-security-first/specs/auto-update-policies/spec.md#requirement-an-admin-records-a-patch-policy-and-security-fixes-go-first
 */
final class PatchPolicyControllerTest extends TestCase {
	use DispatchesLikeNextcloud;

	/** @var array<string, string> */
	private array $config = [];

	private function controller(bool $admin, IRequest $request, ?AuditLogger $audit = null, bool $signedIn = true): PatchPolicyController {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => $this->config[$key] ?? $default,
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->config[$key] = $value;
				return true;
			},
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($signedIn ? $user : null);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn($admin);
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $text, array|string $parameters = []): string => vsprintf($text, (array)$parameters));
		$l->method('n')->willReturnCallback(static fn (string $singular, string $plural, int $count): string => str_replace('%n', (string)$count, $count === 1 ? $singular : $plural));
		$l->method('l')->willReturn('27 September 2026');
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-09-27T10:00:00+00:00'));

		return new PatchPolicyController(
			'versioniq',
			$request,
			new PatchPolicyStore($appConfig, $this->createMock(LoggerInterface::class)),
			new PatchPolicyStatement($l),
			$audit ?? $this->createMock(AuditLogger::class),
			$groups,
			$session,
			$time,
		);
	}

	public function testANonAdminCannotReadOrWrite(): void {
		$audit = $this->createMock(AuditLogger::class);
		$audit->expects(self::never())->method(self::anything());
		$request = $this->requestWith(PatchPolicyTest::example());

		self::assertSame(403, $this->controller(false, $request, $audit)->patchPolicy()->getStatus());
		self::assertSame(403, $this->dispatch($this->controller(false, $request, $audit), 'updatePatchPolicy', $request)->getStatus());
		self::assertSame(403, $this->dispatch($this->controller(true, $request, $audit, false), 'updatePatchPolicy', $request)->getStatus());
		self::assertSame([], $this->config);
	}

	public function testTheWriteIsPasswordConfirmed(): void {
		self::assertNotSame([], (new ReflectionMethod(PatchPolicyController::class, 'updatePatchPolicy'))->getAttributes(PasswordConfirmationRequired::class));
	}

	public function testWithNothingStoredTheReadSaysSo(): void {
		$response = $this->controller(true, $this->requestWith([]))->patchPolicy();

		self::assertSame(200, $response->getStatus());
		$data = $response->getData();
		self::assertFalse($data['recorded']);
		self::assertSame('scheduled', $data['policy']['security']['critical']['handling']);
		self::assertSame(['No patch policy is recorded yet. Every update follows the app\'s own update policy.'], $data['statement']);
		self::assertSame(['next_window', 'scheduled', 'manual'], $data['allowed']['securityHandlings']);
		self::assertSame(365, $data['allowed']['deadlineMaxDays']);
	}

	public function testSavingStoresTheSentencesAndOneAuditEntry(): void {
		$audit = $this->createMock(AuditLogger::class);
		$audit->expects(self::once())->method('record')->with(
			...AuditArgs::expected('alice', 'versioniq', AuditLogger::OPERATION_PATCH_POLICY, null, null, null, AuditLogger::STATUS_SUCCESS, 'Patch policy changed: security.critical, security.high, security.medium, security.low, security.unknown, regular.major, statement'),
		);
		$request = $this->requestWith(PatchPolicyTest::example());

		$response = $this->dispatch($this->controller(true, $request, $audit), 'updatePatchPolicy', $request);

		self::assertSame(200, $response->getStatus());
		$data = $response->getData();
		self::assertTrue($data['recorded']);
		self::assertSame('alice', $data['policy']['setBy']);
		self::assertSame('2026-09-27T10:00:00+00:00', $data['policy']['setAt']);
		self::assertContains('Critical security fixes: installed at the next update window. Deadline: 3 days.', $data['statement']);
		self::assertContains('Major updates: installed by hand only.', $data['statement']);
		self::assertSame('Last changed by alice on 27 September 2026.', end($data['statement']));
		self::assertSame('next_window', json_decode($this->config['patch_policy'], true)['security']['critical']['handling']);
	}

	/** @return array<string, array{array<string, mixed>}> */
	public static function invalid(): array {
		return [
			'deadline 0' => [['security' => ['critical' => ['deadlineDays' => 0, 'handling' => 'next_window']]]],
			'deadline 366' => [['security' => ['critical' => ['deadlineDays' => 366, 'handling' => 'next_window']]]],
			'unknown handling' => [['security' => ['critical' => ['deadlineDays' => 3, 'handling' => 'soon']]]],
			'valid security, invalid regular' => [['security' => ['critical' => ['deadlineDays' => 3, 'handling' => 'next_window']], 'regular' => ['major' => 'later']]],
		];
	}

	/**
	 * @dataProvider invalid
	 * @param array<string, mixed> $body
	 */
	public function testAnInvalidFieldIsA400AndWritesNothing(array $body): void {
		$audit = $this->createMock(AuditLogger::class);
		$audit->expects(self::never())->method('record');
		$request = $this->requestWith($body);

		$response = $this->dispatch($this->controller(true, $request, $audit), 'updatePatchPolicy', $request);

		self::assertSame(400, $response->getStatus());
		self::assertArrayHasKey('message', $response->getData());
		self::assertSame([], $this->config);
	}

	public function testSavingTheSamePolicyAgainWritesNoEntry(): void {
		$request = $this->requestWith(PatchPolicyTest::example());
		$this->dispatch($this->controller(true, $request), 'updatePatchPolicy', $request);
		$stored = $this->config['patch_policy'];

		$audit = $this->createMock(AuditLogger::class);
		$audit->expects(self::never())->method('record');
		$response = $this->dispatch($this->controller(true, $request, $audit), 'updatePatchPolicy', $request);

		self::assertSame(200, $response->getStatus());
		self::assertSame($stored, $this->config['patch_policy']);
	}

	public function testRecordingTheNoPolicyValuesStillRecordsWho(): void {
		$audit = $this->createMock(AuditLogger::class);
		$audit->expects(self::once())->method('record')->with(
			...AuditArgs::expected('alice', 'versioniq', AuditLogger::OPERATION_PATCH_POLICY, null, null, null, AuditLogger::STATUS_SUCCESS, 'Patch policy changed: recorded'),
		);
		$request = $this->requestWith([]);

		$response = $this->dispatch($this->controller(true, $request, $audit), 'updatePatchPolicy', $request);

		self::assertTrue($response->getData()['recorded']);
	}
}
