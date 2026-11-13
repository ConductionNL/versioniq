<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Source;

require_once __DIR__ . '/../../Support/BuildsByParameterName.php';

use OCA\Versioniq\Service\Source\AppStoreSource;
use OCA\Versioniq\Tests\Unit\Support\BuildsByParameterName;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Golden over AppStoreSource::normalizeVersions(): the version list built
 * from the App Store's release entries, per case, compared with
 * fixtures/appstore-normalize-versions.golden.json. Set RECORD_GOLDEN=1 to
 * rewrite the fixture.
 *
 * @covers \OCA\Versioniq\Service\Source\AppStoreSource
 * @uses \OCA\Versioniq\Service\Source\ReleaseDate
 * @uses \OCA\Versioniq\Service\Connection\ReportedHttp
 * @uses \OCA\Versioniq\Service\Installer\AppAutoloader
 */
final class AppStoreNormalizeVersionsGoldenTest extends TestCase {
	use BuildsByParameterName;

	private const FIXTURE = __DIR__ . '/fixtures/appstore-normalize-versions.golden.json';

	/**
	 * @return array<string, list<mixed>>
	 */
	private static function cases(): array {
		return [
			'empty' => [],
			'plain strings' => ['1.0.0', '1.2.0', '1.10.0', ''],
			'numeric strings' => ['1', '2', '10'],
			'version keys in order' => [
				['version' => '1.0.0', 'ver' => '9.9.9'],
				['ver' => '1.1.0', 'name' => '9.9.9'],
				['name' => '1.2.0', 'tag_name' => '9.9.9'],
				['tag_name' => '1.3.0'],
			],
			'skipped entries' => [['version' => ''], ['version' => 5], ['other' => '1.0.0'], 42, null, true, ['version' => '2.0.0']],
			'changelog, spec and date' => [
				['version' => '2.0.0', 'changelog' => 'Fixed it', 'platformVersionSpec' => '>=30.0.0 <32.0.0', 'created' => '2026-01-02T03:04:05+00:00'],
				['version' => '3.0.0', 'translations' => ['en' => ['changelog' => 'English notes']], 'platformVersionSpec' => '>=33.0.0', 'created' => 'not a date'],
				['version' => '1.0.0', 'platformVersionSpec' => 7, 'created' => 1_700_000_000],
			],
			'duplicates fill gaps, first value wins' => [
				['version' => '2.0.0'],
				['version' => '2.0.0', 'translations' => ['en' => ['changelog' => 'second']], 'platformVersionSpec' => '>=31.0.0', 'created' => '2026-02-01T00:00:00+00:00'],
				['version' => '2.0.0', 'translations' => ['en' => ['changelog' => 'third']], 'platformVersionSpec' => '>=40.0.0', 'created' => '2027-01-01T00:00:00+00:00'],
				'2.0.0',
				['version' => '1.0.0', 'translations' => ['en' => ['changelog' => 'kept']], 'platformVersionSpec' => '>=40.0.0'],
				['version' => '1.0.0', 'translations' => ['en' => ['changelog' => 'ignored']], 'platformVersionSpec' => '>=30.0.0', 'created' => '2025-05-05T00:00:00+00:00'],
			],
			'string then array' => ['3.1.0', ['version' => '3.1.0', 'translations' => ['en' => ['changelog' => 'late']], 'platformVersionSpec' => '>=31.0.0']],
			'prerelease sorting' => ['1.0.0-beta.1', '1.0.0', '1.0.0-rc.1', '0.9.0', 'v1.0.0', '1.0'],
		];
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function caseNames(): array {
		$names = [];
		foreach (array_keys(self::cases()) as $name) {
			$names[$name] = [$name];
		}

		return $names;
	}

	/**
	 * @dataProvider caseNames
	 */
	public function testCase(string $name): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturn('31.0.2.1');
		$source = $this->buildByName(AppStoreSource::class, ['config' => $config]);
		$method = new ReflectionMethod(AppStoreSource::class, 'normalizeVersions');
		$actual = json_encode($method->invoke($source, self::cases()[$name]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

		$fixture = is_file(self::FIXTURE) ? (array)json_decode((string)file_get_contents(self::FIXTURE), true, 512, JSON_THROW_ON_ERROR) : [];
		if (getenv('RECORD_GOLDEN') === '1') {
			$fixture[$name] = $actual;
			ksort($fixture);
			file_put_contents(self::FIXTURE, json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
		}

		$this->assertArrayHasKey($name, $fixture);
		$this->assertSame($fixture[$name], $actual);
	}
}
