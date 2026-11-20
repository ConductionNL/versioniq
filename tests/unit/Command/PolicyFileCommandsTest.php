<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Command;

use OCA\Versioniq\Command\ExportPolicies;
use OCA\Versioniq\Command\ImportPolicies;
use OCA\Versioniq\Service\Audit\AuditedVersions;
use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\Audit\AuditSubject;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateSettingsStore;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateWindow;
use OCA\Versioniq\Service\Installer\AppIdFormat;
use OCA\Versioniq\Service\Policy\Policy;
use OCA\Versioniq\Service\Policy\PolicyChange;
use OCA\Versioniq\Service\Policy\PolicyFile;
use OCA\Versioniq\Service\Policy\PolicyFileContents;
use OCA\Versioniq\Service\Policy\PolicyFileException;
use OCA\Versioniq\Service\Policy\PolicyFileSync;
use OCA\Versioniq\Service\Policy\PolicySource;
use OCA\Versioniq\Service\Policy\PolicyStore;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Export, edit one level, import with --dry-run (nothing changes) and
 * without it (the change and its audit row), over the real file and sync.
 *
 * @spec openspec/changes/autoupdate-default-and-config-file/specs/auto-update-policies/spec.md#requirement-policies-can-be-kept-in-a-file
 */
#[CoversClass(ExportPolicies::class)]
#[CoversClass(ImportPolicies::class)]
#[UsesClass(PolicyFileSync::class)]
#[UsesClass(PolicySource::class)]
#[UsesClass(PolicyFile::class)]
#[UsesClass(PolicyFileContents::class)]
#[UsesClass(PolicyFileException::class)]
#[UsesClass(PolicyChange::class)]
#[UsesClass(Policy::class)]
#[UsesClass(AuditSubject::class)]
#[UsesClass(AuditedVersions::class)]
#[UsesClass(AutoUpdateWindow::class)]
#[UsesClass(AppIdFormat::class)]
final class PolicyFileCommandsTest extends TestCase {
	private PolicyStore&MockObject $policies;
	private AuditLogger&MockObject $audit;
	private PolicyFileSync $sync;
	private string $path;

	protected function setUp(): void {
		$this->policies = $this->createMock(PolicyStore::class);
		$this->policies->method('all')->willReturn([
			'openregister' => new Policy('patch', 'alice', '2026-11-01T00:00:00+00:00'),
			'calendar' => new Policy('none', 'alice', '2026-11-01T00:00:00+00:00'),
		]);
		$settings = $this->createMock(AutoUpdateSettingsStore::class);
		$settings->method('isEnabled')->willReturn(true);
		$settings->method('getWindow')->willReturn('01:00-05:00');
		$settings->method('getDefaultLevel')->willReturn('none');
		$this->audit = $this->createMock(AuditLogger::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-11-14T10:00:00+00:00'));
		$this->sync = new PolicyFileSync($this->policies, $settings, $this->audit, $time);
		$this->path = (string)tempnam(sys_get_temp_dir(), 'vq-cli');
	}

	protected function tearDown(): void {
		@unlink($this->path);
	}

	private function import(string $configured = ''): CommandTester {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturn($configured);
		$source = new PolicySource($config, $this->policies, $this->createMock(AutoUpdateSettingsStore::class));

		return new CommandTester(new ImportPolicies($this->sync, $source));
	}

	private function exportAndEdit(): void {
		$export = new CommandTester(new ExportPolicies($this->sync));
		$this->assertSame(0, $export->execute(['file' => $this->path]));
		$data = json_decode((string)file_get_contents($this->path), true);
		$this->assertSame(['calendar' => 'none', 'openregister' => 'patch'], $data['policies']);
		$data['policies']['openregister'] = 'minor';
		file_put_contents($this->path, json_encode($data));
	}

	public function testExportWithoutAFilePrintsThePolicyFile(): void {
		$export = new CommandTester(new ExportPolicies($this->sync));

		$this->assertSame(0, $export->execute([]));
		$data = json_decode($export->getDisplay(), true);
		$this->assertSame(1, $data['schemaVersion']);
		$this->assertSame(['enabled' => true, 'window' => '01:00-05:00', 'defaultLevel' => 'none'], $data['autoUpdate']);
	}

	public function testADryRunPrintsTheDifferenceAndWritesNothing(): void {
		$this->exportAndEdit();
		$this->policies->expects($this->never())->method('set');
		$this->audit->expects($this->never())->method('record');

		$import = $this->import();
		$this->assertSame(0, $import->execute(['file' => $this->path, '--dry-run' => true]));
		$this->assertStringContainsString('policies.openregister: patch -> minor', $import->getDisplay());
		$this->assertStringNotContainsString('Applied', $import->getDisplay());
	}

	public function testAnImportAppliesTheChangeWithAnAuditRow(): void {
		$this->exportAndEdit();
		$this->policies->expects($this->once())->method('set')->with('openregister', $this->callback(
			static fn (Policy $p): bool => $p->level === 'minor' && $p->setBy === 'occ'
		));
		$this->audit->expects($this->once())->method('record')->with(
			$this->callback(static fn (AuditSubject $s): bool => $s->appId === 'openregister' && $s->operation === 'policy'),
			$this->callback(static fn (AuditedVersions $v): bool => $v->fromVersion === 'patch' && $v->toVersion === 'minor'),
			'success',
		);

		$import = $this->import();
		$this->assertSame(0, $import->execute(['file' => $this->path]));
		$this->assertStringContainsString('Applied 1 change(s).', $import->getDisplay());
	}

	public function testPruneRemovesPoliciesTheFileDoesNotName(): void {
		file_put_contents($this->path, '{"schemaVersion":1,"policies":{"openregister":"patch"}}');
		$this->policies->expects($this->once())->method('clear')->with('calendar');

		$import = $this->import();
		$this->assertSame(0, $import->execute(['file' => $this->path, '--prune' => true]));
		$this->assertStringContainsString('policies.calendar: none -> (removed)', $import->getDisplay());
	}

	public function testAnInvalidFileIsRefusedWithEveryProblem(): void {
		file_put_contents($this->path, '{"schemaVersion":1,"autoUpdate":{"defaultLevel":"weekly"},"policies":{"openregister":"weekly"}}');
		$this->policies->expects($this->never())->method('set');

		$import = $this->import();
		$this->assertSame(1, $import->execute(['file' => $this->path]));
		$this->assertStringContainsString('$.autoUpdate.defaultLevel: must be one of', $import->getDisplay());
		$this->assertStringContainsString('$.policies.openregister: must be one of', $import->getDisplay());
	}

	public function testAnImportIsRefusedWhileConfigPhpNamesAPolicyFile(): void {
		file_put_contents($this->path, '{"schemaVersion":1,"policies":{"openregister":"minor"}}');
		$this->policies->expects($this->never())->method('set');

		$import = $this->import('/etc/nextcloud/policies.json');
		$this->assertSame(1, $import->execute(['file' => $this->path]));
		$this->assertStringContainsString('/etc/nextcloud/policies.json', $import->getDisplay());
	}

	public function testAnUnchangedFileChangesNothing(): void {
		file_put_contents($this->path, '{"schemaVersion":1,"policies":{"openregister":"patch","calendar":"none"}}');
		$this->audit->expects($this->never())->method('record');

		$import = $this->import();
		$this->assertSame(0, $import->execute(['file' => $this->path]));
		$this->assertStringContainsString('Nothing to change.', $import->getDisplay());
	}
}
