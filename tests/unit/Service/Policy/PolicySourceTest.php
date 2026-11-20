<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Policy;

use OCA\Versioniq\Service\AutoUpdate\AutoUpdateSettingsStore;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateWindow;
use OCA\Versioniq\Service\Installer\AppIdFormat;
use OCA\Versioniq\Service\Policy\Policy;
use OCA\Versioniq\Service\Policy\PolicyFile;
use OCA\Versioniq\Service\Policy\PolicyFileContents;
use OCA\Versioniq\Service\Policy\PolicyFileException;
use OCA\Versioniq\Service\Policy\PolicySource;
use OCA\Versioniq\Service\Policy\PolicyStore;
use OCP\IConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * @spec openspec/changes/autoupdate-default-and-config-file/specs/auto-update-policies/spec.md#requirement-policies-can-be-kept-in-a-file
 */
#[CoversClass(PolicySource::class)]
#[UsesClass(PolicyFile::class)]
#[UsesClass(PolicyFileContents::class)]
#[UsesClass(PolicyFileException::class)]
#[UsesClass(Policy::class)]
#[UsesClass(AutoUpdateWindow::class)]
#[UsesClass(AppIdFormat::class)]
final class PolicySourceTest extends TestCase {
	private function source(string $configured, ?PolicyStore $policies = null): PolicySource {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->with('versioniq.policy_file', '')->willReturn($configured);
		$settings = $this->createMock(AutoUpdateSettingsStore::class);
		$settings->method('isEnabled')->willReturn(true);
		$settings->method('getWindow')->willReturn('01:00-05:00');
		$settings->method('getDefaultLevel')->willReturn('minor');

		return new PolicySource($config, $policies ?? $this->createMock(PolicyStore::class), $settings);
	}

	public function testWithoutAConfiguredFileTheStoresAreTheSource(): void {
		$policies = $this->createMock(PolicyStore::class);
		$policies->method('all')->willReturn(['deck' => new Policy('all', 'alice', '2026-11-01T00:00:00+00:00')]);
		$source = $this->source('  ', $policies);

		$this->assertNull($source->filePath());
		$contents = $source->current();
		$this->assertSame(['deck' => 'all'], $contents->policies);
		$this->assertSame('minor', $contents->defaultLevel);
	}

	public function testAConfiguredFileIsReadInsteadOfTheStores(): void {
		$path = tempnam(sys_get_temp_dir(), 'vq-source');
		file_put_contents($path, '{"schemaVersion":1,"autoUpdate":{"enabled":false},"policies":{"openregister":"patch"}}');
		$policies = $this->createMock(PolicyStore::class);
		$policies->expects($this->never())->method('all');
		try {
			$source = $this->source($path, $policies);
			$this->assertSame($path, $source->filePath());
			$contents = $source->current();
			$this->assertFalse($contents->enabled);
			$this->assertNull($contents->defaultLevel);
			$this->assertSame(['openregister' => 'patch'], $contents->policies);
		} finally {
			unlink($path);
		}
	}

	public function testABrokenFileIsAnErrorAndNeverTheStores(): void {
		$path = tempnam(sys_get_temp_dir(), 'vq-source');
		file_put_contents($path, '{"schemaVersion":1,"policies":{"openregister":"weekly"}}');
		$policies = $this->createMock(PolicyStore::class);
		$policies->expects($this->never())->method('all');
		try {
			$this->expectException(PolicyFileException::class);
			$this->expectExceptionMessage('$.policies.openregister: must be one of none, patch, minor, all, security');
			$this->source($path, $policies)->current();
		} finally {
			unlink($path);
		}
	}
}
