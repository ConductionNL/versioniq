<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Policy;

use OCA\Versioniq\Service\AutoUpdate\AutoUpdateWindow;
use OCA\Versioniq\Service\Installer\AppIdFormat;
use OCA\Versioniq\Service\Policy\Policy;
use OCA\Versioniq\Service\Policy\PolicyFile;
use OCA\Versioniq\Service\Policy\PolicyFileContents;
use OCA\Versioniq\Service\Policy\PolicyFileException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * @spec openspec/changes/autoupdate-default-and-config-file/specs/auto-update-policies/spec.md#requirement-policies-can-be-kept-in-a-file
 */
#[CoversClass(PolicyFile::class)]
#[UsesClass(PolicyFileContents::class)]
#[UsesClass(PolicyFileException::class)]
#[UsesClass(Policy::class)]
#[UsesClass(AutoUpdateWindow::class)]
#[UsesClass(AppIdFormat::class)]
final class PolicyFileTest extends TestCase {
	private const VALID = '{"schemaVersion":1,"autoUpdate":{"enabled":true,"window":"01:00-05:00","defaultLevel":"patch"},'
		. '"policies":{"openregister":"minor","calendar":"none"}}';

	public function testAValidFileParsesIntoItsSettingsAndPolicies(): void {
		$contents = PolicyFile::parse(self::VALID);

		$this->assertTrue($contents->enabled);
		$this->assertSame('01:00-05:00', $contents->window);
		$this->assertSame('patch', $contents->defaultLevel);
		$this->assertSame(['calendar' => 'none', 'openregister' => 'minor'], $contents->policies);
	}

	public function testSettingsTheFileLeavesOutAreNull(): void {
		$contents = PolicyFile::parse('{"schemaVersion":1,"policies":{}}');

		$this->assertNull($contents->enabled);
		$this->assertNull($contents->window);
		$this->assertNull($contents->defaultLevel);
		$this->assertSame([], $contents->policies);
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function brokenFiles(): array {
		return [
			'not json' => ['{', '$: not valid JSON'],
			'not an object' => ['[1]', '$: must be an object'],
			'unknown top key' => ['{"schemaVersion":1,"policies":{},"presets":[]}', '$.presets: is not a known key'],
			'wrong schema version' => ['{"schemaVersion":2,"policies":{}}', '$.schemaVersion: must be 1'],
			'missing policies' => ['{"schemaVersion":1}', '$.policies: is required'],
			'policies a list' => ['{"schemaVersion":1,"policies":["minor"]}', '$.policies: must be an object of app id to level'],
			'autoUpdate not an object' => ['{"schemaVersion":1,"autoUpdate":true,"policies":{}}', '$.autoUpdate: must be an object'],
			'unknown autoUpdate key' => ['{"schemaVersion":1,"autoUpdate":{"interval":3},"policies":{}}', '$.autoUpdate.interval: is not a known key'],
			'enabled not a boolean' => ['{"schemaVersion":1,"autoUpdate":{"enabled":"yes"},"policies":{}}', '$.autoUpdate.enabled: must be true or false'],
			'bad window' => ['{"schemaVersion":1,"autoUpdate":{"window":"night"},"policies":{}}', '$.autoUpdate.window: must be HH:MM-HH:MM'],
			'bad default level' => ['{"schemaVersion":1,"autoUpdate":{"defaultLevel":"weekly"},"policies":{}}', '$.autoUpdate.defaultLevel: must be one of none, patch, minor, all'],
			'bad app level' => ['{"schemaVersion":1,"policies":{"openregister":"weekly"}}', '$.policies.openregister: must be one of none, patch, minor, all'],
			'bad app id' => ['{"schemaVersion":1,"policies":{"Open Register":"minor"}}', '$.policies.Open Register: is not a valid app id'],
		];
	}

	#[DataProvider('brokenFiles')]
	public function testEachBrokenRuleIsReportedWithItsJsonPath(string $json, string $expected): void {
		try {
			PolicyFile::parse($json);
			$this->fail('A broken file must be refused.');
		} catch (PolicyFileException $error) {
			$this->assertCount(1, $error->errors);
			$this->assertStringStartsWith($expected, $error->errors[0]);
		}
	}

	public function testEveryProblemIsReportedAtOnce(): void {
		$errors = PolicyFile::validate([
			'schemaVersion' => 1,
			'autoUpdate' => ['defaultLevel' => 'weekly'],
			'policies' => ['openregister' => 'weekly', 'calendar' => 'all'],
		]);

		$this->assertSame([
			'$.autoUpdate.defaultLevel: must be one of none, patch, minor, all',
			'$.policies.openregister: must be one of none, patch, minor, all',
		], $errors);
	}

	public function testAnEncodedFileReadsBackTheSame(): void {
		$contents = new PolicyFileContents(false, '02:00-04:00', 'minor', ['zeta' => 'all', 'alpha' => 'patch']);

		$json = PolicyFile::encode($contents);
		$back = PolicyFile::parse($json);

		$this->assertSame(['alpha' => 'patch', 'zeta' => 'all'], $back->policies);
		$this->assertFalse($back->enabled);
		$this->assertSame('02:00-04:00', $back->window);
		$this->assertSame('minor', $back->defaultLevel);
		$this->assertStringContainsString('"schemaVersion": 1', $json);
	}

	public function testEncodingLeavesOutUnnamedSettingsAndKeepsEmptyObjects(): void {
		$json = PolicyFile::encode(new PolicyFileContents(null, null, null, []));

		$this->assertSame(['schemaVersion' => 1, 'autoUpdate' => [], 'policies' => []], json_decode($json, true));
		$this->assertStringContainsString('"policies": {}', $json);
	}

	public function testReadParsesAFileOnDisk(): void {
		$path = tempnam(sys_get_temp_dir(), 'vq-policies');
		file_put_contents($path, self::VALID);
		try {
			$this->assertSame('minor', PolicyFile::read($path)->policies['openregister']);
		} finally {
			unlink($path);
		}
	}

	public function testAnUnreadableFileIsAnErrorNamingThePath(): void {
		$this->expectException(PolicyFileException::class);
		$this->expectExceptionMessage('$: cannot read /nonexistent/policies.json');

		PolicyFile::read('/nonexistent/policies.json');
	}
}
