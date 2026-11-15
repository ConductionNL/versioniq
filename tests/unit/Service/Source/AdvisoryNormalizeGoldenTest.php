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
use OCA\Versioniq\Service\Source\ForgeReleaseSource;
use OCA\Versioniq\Tests\Unit\Support\BuildsByParameterName;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Golden over the advisory normalisation of both sources:
 * ForgeReleaseSource::normalizeAdvisory() per entry and
 * AppStoreSource::normalizeAdvisories() over the same entries, compared with
 * fixtures/advisory-normalize.golden.json. Set RECORD_GOLDEN=1 to rewrite it.
 *
 * @covers \OCA\Versioniq\Service\Source\ForgeReleaseSource
 * @covers \OCA\Versioniq\Service\Source\AppStoreSource
 * @uses \OCA\Versioniq\Service\Advisory\AdvisoryClauses
 * @uses \OCA\Versioniq\Service\Advisory\AdvisorySeverity
 * @uses \OCA\Versioniq\Service\Connection\ReportedHttp
 * @uses \OCA\Versioniq\Service\Installer\AppAutoloader
 * @uses \OCA\Versioniq\Service\Pat\PatAccess
 */
final class AdvisoryNormalizeGoldenTest extends TestCase {
	use BuildsByParameterName;

	private const FIXTURE = __DIR__ . '/fixtures/advisory-normalize.golden.json';

	/**
	 * @return array<string, mixed>
	 */
	private static function entries(): array {
		return [
			'forge full' => ['ghsa_id' => 'GHSA-1', 'severity' => 'High', 'summary' => 'Bad', 'vulnerabilities' => [['vulnerable_version_range' => ' < 1.2.0 , >= 0.9.0 ,', 'first_patched_version' => ['identifier' => 'v1.2.0']], ['vulnerable_version_range' => '< 9']]],
			'forge patched string' => ['id' => 'ID-2', 'title' => 'Titled', 'vulnerabilities' => [['vulnerable_version_range' => '', 'first_patched_version' => '2.0']]],
			'forge cve only' => ['cve_id' => 'CVE-3', 'summary' => 5, 'severity' => 'unknown-level'],
			'no id' => ['summary' => 'x'],
			'empty id' => ['ghsa_id' => '', 'id' => 'ID-5'],
			'vulnerabilities not a list' => ['ghsa_id' => 'GHSA-6', 'vulnerabilities' => ['x' => 1]],
			'first vulnerability not an array' => ['ghsa_id' => 'GHSA-7', 'vulnerabilities' => ['nope']],
			'patched empty' => ['ghsa_id' => 'GHSA-8', 'vulnerabilities' => [['first_patched_version' => ['identifier' => '']]]],
			'store affected string' => ['id' => 'S-1', 'severity' => 'critical', 'affected' => ' < 1.0, ,>= 0.1', 'firstPatchedVersion' => '1.0.0'],
			'store affected list' => ['id' => 'S-2', 'affected' => [' < 2.0 ', '', 7, '>= 1.5'], 'first_patched_version' => '2.0.0'],
			'store affectedVersions' => ['ghsa_id' => 'S-3', 'affectedVersions' => '< 3', 'firstPatchedVersion' => ''],
			'store affected wins' => ['id' => 'S-4', 'affected' => '< 4', 'affectedVersions' => '< 9', 'title' => 'T'],
			'store affected other type' => ['id' => 'S-5', 'affected' => 42, 'firstPatchedVersion' => 3],
			'not an array' => 'just a string',
		];
	}

	public function testNormalisation(): void {
		$forge = $this->buildByName(ForgeReleaseSource::class);
		$store = $this->buildByName(AppStoreSource::class);
		$one = new ReflectionMethod(ForgeReleaseSource::class, 'normalizeAdvisory');
		$all = new ReflectionMethod(AppStoreSource::class, 'normalizeAdvisories');
		$actual = [];
		foreach (self::entries() as $name => $entry) {
			$actual['forge ' . $name] = is_array($entry) ? $one->invoke($forge, $entry) : 'skipped';
		}
		$actual['store all'] = $all->invoke($store, array_values(self::entries()));
		$json = json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
		if (getenv('RECORD_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, $json);
		}

		$this->assertSame((string)file_get_contents(self::FIXTURE), $json);
	}
}
