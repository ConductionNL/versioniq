<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Command;

require_once __DIR__ . '/../Support/InstallArgs.php';

use OCA\Versioniq\Command\InstallVersion;
use OCA\Versioniq\Service\Installer\FailureClassifier;
use OCA\Versioniq\Service\InstallerService;
use OCA\Versioniq\Tests\Unit\Support\InstallArgs;
use OCP\AppFramework\Http;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class InstallVersionTest extends TestCase {
	public function testSelfManagementIsRefusedWithoutCallingInstallAppVersion(): void {
		$installer = $this->createMock(InstallerService::class);
		$installer->method('isManageableApp')->with('versioniq')->willReturn(false);
		$installer->expects(self::never())->method('installAppVersion');

		$tester = new CommandTester(new InstallVersion($installer));
		$exitCode = $tester->execute(
			['appId' => 'versioniq', 'version' => '1.0.0'],
			['capture_stderr_separately' => true]
		);

		self::assertNotSame(0, $exitCode);
		self::assertStringContainsString('cannot be installed', $tester->getErrorOutput());
	}

	public function testARefusalIsReportedWordForWordAsTextAndAsJson(): void {
		$installer = $this->createMock(InstallerService::class);
		$installer->method('isManageableApp')->willReturn(false);
		$installer->expects(self::never())->method('installAppVersion');
		$message = '"core" cannot be installed or updated from Versioniq (it is Versioniq itself or a core/always-enabled app).';

		$text = new CommandTester(new InstallVersion($installer));
		self::assertSame(2, $text->execute(['appId' => ' core ', 'version' => ' 1.0.0 '], ['capture_stderr_separately' => true]));
		self::assertSame('', $text->getDisplay());
		self::assertSame($message . "\n", $text->getErrorOutput());

		$json = new CommandTester(new InstallVersion($installer));
		self::assertSame(2, $json->execute(['appId' => ' core ', 'version' => ' 1.0.0 ', '--json' => true], ['capture_stderr_separately' => true]));
		self::assertSame('{"appId":"core","toVersion":"1.0.0","message":' . json_encode($message, JSON_UNESCAPED_SLASHES) . "}\n", $json->getDisplay());
		self::assertSame('', $json->getErrorOutput());
	}

	public function testReproduciblePinnedInstallSucceedsWithExitZero(): void {
		$installer = $this->createMock(InstallerService::class);
		$installer->method('isManageableApp')->willReturn(true);
		$installer->expects(self::once())
			->method('installAppVersion')
			->with(...InstallArgs::expected('openregister', '2.3.0', false, null, null, false, false, false, false))
			->willReturn([
				'statusCode' => Http::STATUS_OK,
				'payload' => [
					'appId' => 'openregister',
					'fromVersion' => '2.2.0',
					'toVersion' => '2.3.0',
					'message' => 'App updated.',
					'updateType' => 'upgrade',
					'installStatus' => 'installed',
				],
			]);

		$tester = new CommandTester(new InstallVersion($installer));
		$exitCode = $tester->execute(['appId' => 'openregister', 'version' => '2.3.0']);

		self::assertSame(0, $exitCode);
		self::assertStringContainsString('2.3.0', $tester->getDisplay());
	}

	public function testDowngradeWithoutFlagIsRefusedWithDocumentedExitCodeAndSucceedsWithFlag(): void {
		$installer = $this->createMock(InstallerService::class);
		$installer->method('isManageableApp')->willReturn(true);
		$installer->method('installAppVersion')->willReturnCallback(
			static function (mixed ...$args): array {
				['appId' => $appId, 'targetVersion' => $version, 'allowDowngrade' => $allowDowngrade] = InstallArgs::named($args);
				if (!$allowDowngrade) {
					return [
						'statusCode' => Http::STATUS_CONFLICT,
						'payload' => [
							'appId' => $appId,
							'fromVersion' => '2.5.0',
							'toVersion' => $version,
							'message' => 'The requested version is older than the installed version.',
							'category' => FailureClassifier::CATEGORY_DOWNGRADE_GUARD,
							'hint' => '2.5.0 is installed; 2.3.0 is older.',
							'installStatus' => 'failed',
						],
					];
				}

				return [
					'statusCode' => Http::STATUS_OK,
					'payload' => [
						'appId' => $appId,
						'fromVersion' => '2.5.0',
						'toVersion' => $version,
						'message' => 'App downgraded.',
						'updateType' => 'downgrade',
						'installStatus' => 'installed',
					],
				];
			}
		);

		$command = new InstallVersion($installer);

		$refused = new CommandTester($command);
		$refusedExit = $refused->execute(
			['appId' => 'openregister', 'version' => '2.3.0'],
			['capture_stderr_separately' => true]
		);
		self::assertSame(3, $refusedExit);
		self::assertStringContainsString('older than the installed version', $refused->getErrorOutput());

		$acknowledged = new CommandTester($command);
		$acknowledgedExit = $acknowledged->execute(['appId' => 'openregister', 'version' => '2.3.0', '--allow-downgrade' => true]);
		self::assertSame(0, $acknowledgedExit);
	}

	/**
	 * The source and the accept-new-SHA flag reach the installer; the source
	 * trimmed.
	 */
	public function testSourceAndAcceptNewShaReachTheInstaller(): void {
		$installer = $this->createMock(InstallerService::class);
		$installer->method('isManageableApp')->willReturn(true);
		$installer->expects(self::once())
			->method('installAppVersion')
			->with(...InstallArgs::expected('openregister', '2.3.0', false, 'github:acme/openregister', null, false, true, false, false))
			->willReturn(['statusCode' => Http::STATUS_OK, 'payload' => ['installStatus' => 'installed']]);

		$tester = new CommandTester(new InstallVersion($installer));
		$exitCode = $tester->execute(['appId' => 'openregister', 'version' => '2.3.0', '--source' => ' github:acme/openregister ', '--accept-new-sha' => true]);

		self::assertSame(0, $exitCode);
	}

	public function testDryRunLeavesTheInstanceUntouchedAndReportsUpdateTypeAsJson(): void {
		$installer = $this->createMock(InstallerService::class);
		$installer->method('isManageableApp')->willReturn(true);
		$installer->expects(self::once())
			->method('installAppVersion')
			->with(...InstallArgs::expected('openregister', '2.3.0', false, null, null, false, false, false, true))
			->willReturn([
				'statusCode' => Http::STATUS_OK,
				'payload' => [
					'appId' => 'openregister',
					'fromVersion' => '2.2.0',
					'toVersion' => '2.3.0',
					'message' => 'Dry run mode: no changes were applied.',
					'updateType' => 'dry-run',
					'dryRun' => true,
					'installStatus' => 'dry-run',
				],
			]);

		$tester = new CommandTester(new InstallVersion($installer));
		$exitCode = $tester->execute(['appId' => 'openregister', 'version' => '2.3.0', '--dry-run' => true, '--json' => true]);

		self::assertSame(0, $exitCode);
		/** @var array<string, mixed> $decoded */
		$decoded = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
		self::assertSame('dry-run', $decoded['updateType']);
		self::assertTrue($decoded['dryRun']);
	}

	/**
	 * @dataProvider integrityFailureCategoryProvider
	 */
	public function testIntegrityFailureCategoriesMapToExitSix(string $category): void {
		$this->assertExitCodeForCategory($category, 6, Http::STATUS_UNPROCESSABLE_ENTITY);
	}

	/**
	 * @return list<list<string>>
	 */
	public static function integrityFailureCategoryProvider(): array {
		return [
			[FailureClassifier::CATEGORY_CHECKSUM_MISMATCH],
			[FailureClassifier::CATEGORY_SHA_MISMATCH],
			[FailureClassifier::CATEGORY_APPID_MISMATCH],
			[FailureClassifier::CATEGORY_VERSION_MISMATCH],
		];
	}

	public function testPreflightPermissionFailureExitsFour(): void {
		$this->assertExitCodeForCategory(FailureClassifier::CATEGORY_PREFLIGHT_PERMISSION, 4, Http::STATUS_CONFLICT);
	}

	public function testDownloadFailureExitsFive(): void {
		$this->assertExitCodeForCategory(FailureClassifier::CATEGORY_DOWNLOAD, 5, Http::STATUS_BAD_GATEWAY);
	}

	public function testIncompatibleFailureExitsSeven(): void {
		$this->assertExitCodeForCategory(FailureClassifier::CATEGORY_INCOMPATIBLE, 7, Http::STATUS_UNPROCESSABLE_ENTITY);
	}

	public function testFinalizeFailureExitsEightAndPrintsInstallStatusExplicitly(): void {
		$installer = $this->createMock(InstallerService::class);
		$installer->method('isManageableApp')->willReturn(true);
		$installer->method('installAppVersion')->willReturn([
			'statusCode' => Http::STATUS_INTERNAL_SERVER_ERROR,
			'payload' => [
				'appId' => 'openregister',
				'toVersion' => '2.3.0',
				'message' => 'A migration or repair step failed.',
				'category' => FailureClassifier::CATEGORY_FINALIZE,
				'hint' => 'The previous app files could not be reliably restored.',
				'installStatus' => 'installed-but-broken',
			],
		]);

		$tester = new CommandTester(new InstallVersion($installer));
		$exitCode = $tester->execute(
			['appId' => 'openregister', 'version' => '2.3.0'],
			['capture_stderr_separately' => true]
		);

		self::assertSame(8, $exitCode);
		self::assertStringContainsString('installed-but-broken', $tester->getErrorOutput());
	}

	public function testUntrustedSourceFailureExitsNine(): void {
		$installer = $this->createMock(InstallerService::class);
		$installer->method('isManageableApp')->willReturn(true);
		$installer->method('installAppVersion')->willReturn([
			'statusCode' => Http::STATUS_FORBIDDEN,
			'payload' => [
				'appId' => 'someapp',
				'toVersion' => '1.0.0',
				'message' => 'Source "github:evil/repo" is not in the trusted source allowlist.',
			],
		]);

		$tester = new CommandTester(new InstallVersion($installer));
		$exitCode = $tester->execute(
			['appId' => 'someapp', 'version' => '1.0.0', '--source' => 'github:evil/repo'],
			['capture_stderr_separately' => true]
		);

		self::assertSame(9, $exitCode);
		self::assertStringContainsString('trusted source allowlist', $tester->getErrorOutput());
	}

	public function testUnknownFailureWithoutCategoryFallsBackToExitOne(): void {
		$installer = $this->createMock(InstallerService::class);
		$installer->method('isManageableApp')->willReturn(true);
		$installer->method('installAppVersion')->willReturn([
			'statusCode' => Http::STATUS_INTERNAL_SERVER_ERROR,
			'payload' => [
				'appId' => 'someapp',
				'toVersion' => '1.0.0',
				'message' => 'An unexpected error occurred during installation.',
				'category' => FailureClassifier::CATEGORY_UNKNOWN,
			],
		]);

		$tester = new CommandTester(new InstallVersion($installer));
		$exitCode = $tester->execute(['appId' => 'someapp', 'version' => '1.0.0']);

		self::assertSame(1, $exitCode);
	}

	private function assertExitCodeForCategory(string $category, int $expectedExitCode, int $statusCode): void {
		$installer = $this->createMock(InstallerService::class);
		$installer->method('isManageableApp')->willReturn(true);
		$installer->method('installAppVersion')->willReturn([
			'statusCode' => $statusCode,
			'payload' => [
				'appId' => 'someapp',
				'toVersion' => '1.0.0',
				'message' => 'Installation failed.',
				'category' => $category,
				'hint' => 'Some hint.',
			],
		]);

		$tester = new CommandTester(new InstallVersion($installer));
		$exitCode = $tester->execute(['appId' => 'someapp', 'version' => '1.0.0']);

		self::assertSame($expectedExitCode, $exitCode);
	}
	/**
	 * @return array<string, mixed>
	 */
	private static function previewPayload(?array $preview): array {
		return [
			'appId' => 'openregister',
			'fromVersion' => '2.3.0',
			'toVersion' => '2.4.0',
			'message' => 'Dry run mode: no changes were applied.',
			'updateType' => 'upgrade',
			'dryRun' => true,
			'migrationPreview' => $preview,
		];
	}

	private static function preview(): array {
		return [
			'steps' => [
				[
					'name' => 'Version2040Date20260201000000',
					'actions' => [['attribute' => 'AddIndex', 'table' => 'openregister_objects', 'name' => '', 'description' => '']],
					'tables' => [['name' => 'openregister_objects', 'rows' => 4200000, 'sizeClass' => 'very_large']],
					'dataStep' => false,
					'describes' => true,
				],
				[
					'name' => 'Version2050Date20260301000000',
					'actions' => [['attribute' => 'CreateTable', 'table' => 'openregister_tags', 'name' => '', 'description' => '']],
					'tables' => [['name' => 'openregister_tags', 'rows' => null, 'sizeClass' => 'new']],
					'dataStep' => true,
					'describes' => true,
				],
				['name' => 'Version2060Date20260401000000', 'actions' => [], 'tables' => [], 'dataStep' => false, 'describes' => false],
			],
			'repairSteps' => ['OCA\\OpenRegister\\Repair\\SeedDefaults'],
			'estimated' => false,
		];
	}

	private function runWith(array $payload, array $options): CommandTester {
		$installer = $this->createMock(InstallerService::class);
		$installer->method('isManageableApp')->willReturn(true);
		$installer->method('installAppVersion')->willReturn(['statusCode' => Http::STATUS_OK, 'payload' => $payload]);
		$tester = new CommandTester(new InstallVersion($installer));
		$exitCode = $tester->execute(['appId' => 'openregister', 'version' => '2.4.0', '--dry-run' => true] + $options);
		self::assertSame(0, $exitCode);

		return $tester;
	}

	public function testAnUpgradeDryRunPrintsTheMigrationPreviewAsATable(): void {
		$display = $this->runWith(self::previewPayload(self::preview()), [])->getDisplay();

		self::assertStringContainsString('Database changes:', $display);
		self::assertMatchesRegularExpression('/Version2040Date20260201000000\s*\|\s*adds an index\s*\|\s*openregister_objects\s*\|\s*4,200,000\s*\|\s*May take long; plan a maintenance window\./', $display);
		self::assertMatchesRegularExpression('/openregister_tags\s*\|\s*\|\s*New table; fast\. Moves data; the time depends on the amount of data\./', $display);
		self::assertStringContainsString('This step does not describe what it changes.', $display);
		self::assertStringContainsString('Repair steps after the migrations: OCA\\OpenRegister\\Repair\\SeedDefaults', $display);
		self::assertDoesNotMatchRegularExpression('/\d+\s*(s|sec|seconds)\b/', $display);
	}

	public function testAnUpgradeWithoutStepsSaysThereAreNoDatabaseChanges(): void {
		$preview = ['steps' => [], 'repairSteps' => [], 'estimated' => true];
		$display = $this->runWith(self::previewPayload($preview), [])->getDisplay();

		self::assertStringContainsString('No database changes (estimate: the migrations table could not be read).', $display);
		self::assertStringNotContainsString('Repair steps', $display);
	}

	public function testAnUnreadableTargetIsReported(): void {
		$display = $this->runWith(self::previewPayload(null), [])->getDisplay();

		self::assertStringContainsString('Database changes: the target could not be read.', $display);
	}

	public function testTheJsonOutputCarriesTheMigrationPreview(): void {
		$display = $this->runWith(self::previewPayload(self::preview()), ['--json' => true])->getDisplay();

		/** @var array<string, mixed> $decoded */
		$decoded = json_decode($display, true, 512, JSON_THROW_ON_ERROR);
		self::assertSame(self::preview(), $decoded['migrationPreview']);
	}

	public function testAPayloadWithoutAPreviewPrintsNone(): void {
		$payload = self::previewPayload(null);
		unset($payload['migrationPreview']);
		$display = $this->runWith($payload, [])->getDisplay();

		self::assertStringNotContainsString('Database changes', $display);
	}
}
