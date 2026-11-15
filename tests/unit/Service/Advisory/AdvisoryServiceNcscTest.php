<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Tests\Unit\Service\Advisory;

require_once __DIR__ . '/../../Support/BuildsByParameterName.php';

use OCA\Versioniq\Service\Advisory\AdvisoryService;
use OCA\Versioniq\Service\Advisory\BranchAwareRange;
use OCA\Versioniq\Service\Advisory\NcscAdvisoryFeed;
use OCA\Versioniq\Service\Advisory\NextcloudAdvisoryFeed;
use OCA\Versioniq\Service\Advisory\ServerVersionProvider;
use OCA\Versioniq\Service\Source\SourceBinding;
use OCA\Versioniq\Service\Source\SourceBindingStore;
use OCA\Versioniq\Service\Source\SourceInterface;
use OCA\Versioniq\Service\Source\SourceRegistry;
use OCA\Versioniq\Tests\Unit\Support\BuildsByParameterName;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * NCSC-NL records in the advisory sweep (advisories-ncsc-feed tasks 2.1, 2.2):
 * the version match decides the state, a shared CVE counts once, and the
 * switch off sends nothing to NCSC-NL.
 *
 * @spec openspec/changes/advisories-ncsc-feed/specs/security-advisory-correlation/spec.md#requirement-ncsc-nl-advisories-are-matched-to-the-installed-server-and-apps
 */
final class AdvisoryServiceNcscTest extends TestCase {
	use BuildsByParameterName;

	private const SERVER = ':server';

	private function service(array $feed, NcscAdvisoryFeed $ncsc, string $serverVersion = '31.0.2'): AdvisoryService {
		$nextcloud = $this->createMock(NextcloudAdvisoryFeed::class);
		$nextcloud->method('fetchAll')->willReturn(['advisories' => $feed, 'error' => null]);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getEnabledApps')->willReturn(['spreed']);
		$appManager->method('getAppVersion')->willReturn('21.0.1');

		$registry = $this->createMock(SourceRegistry::class);
		$registry->method('get')->willReturn($this->createMock(SourceInterface::class));
		$bindingStore = $this->createMock(SourceBindingStore::class);
		$bindingStore->method('get')->willReturn(SourceBinding::appStore());
		$provider = $this->createMock(ServerVersionProvider::class);
		$provider->method('current')->willReturn($serverVersion);

		return $this->buildByName(AdvisoryService::class, [
			'sourceRegistry' => $registry,
			'bindingStore' => $bindingStore,
			'appManager' => $appManager,
			'advisoryFeed' => $nextcloud,
			'branchRange' => new BranchAwareRange(),
			'serverVersion' => $provider,
			'ncscFeed' => $ncsc,
			'logger' => $this->createMock(LoggerInterface::class),
		]);
	}

	private function ncsc(array $byTarget, bool $enabled = true): NcscAdvisoryFeed {
		$feed = $this->createMock(NcscAdvisoryFeed::class);
		$feed->method('isEnabled')->willReturn($enabled);
		$feed->method('fetchAll')->willReturn(['advisories' => $byTarget, 'error' => null]);

		return $feed;
	}

	private static function ncscRecord(string $id, ?string $range, array $cves = ['CVE-2026-40001']): array {
		return [
			'id' => $id,
			'source' => 'ncsc',
			'severity' => 'high',
			'summary' => 'Kwetsbaarheden verholpen in Nextcloud Server',
			'cveIds' => $cves,
			'ratings' => ['likelihood' => 'medium', 'damage' => 'high'],
			'published' => '2026-09-24T09:14:33Z',
			'url' => 'https://advisories.ncsc.nl/csaf/v2/2026/ncsc-2026-0901.json',
			'ranges' => [$range],
			'affected' => [],
			'firstPatchedVersion' => null,
			'patchedVersions' => [],
		];
	}

	public function testAVersionNotStatedRecordLeavesTheServerAvailableNotVulnerable(): void {
		$results = $this->service([], $this->ncsc([self::SERVER => [self::ncscRecord('NCSC-2026-0901', 'vers:unknown/*')]]))->correlateAll();

		self::assertSame('advisory-available', $results[self::SERVER]['state']);
		self::assertSame('version_not_stated', $results[self::SERVER]['advisories'][0]['match']);
		self::assertSame(['likelihood' => 'medium', 'damage' => 'high'], $results[self::SERVER]['advisories'][0]['ratings']);
		self::assertNull($results[self::SERVER]['recommendedVersion']);
	}

	public function testAnAffectedRecordMarksTheServerVulnerableWithoutNamingAFix(): void {
		$results = $this->service([], $this->ncsc([self::SERVER => [self::ncscRecord('NCSC-2026-0901', 'vers:generic/>=31.0.0|<31.0.9')]]))->correlateAll();

		self::assertSame('pinned-to-vulnerable', $results[self::SERVER]['state']);
		self::assertSame('NCSC-2026-0901', $results[self::SERVER]['advisories'][0]['id']);
		self::assertSame('affected', $results[self::SERVER]['advisories'][0]['match']);
		self::assertNull($results[self::SERVER]['recommendedVersion']);
	}

	public function testARangeThatExcludesTheVersionIsHistoryOnly(): void {
		$results = $this->service([], $this->ncsc([self::SERVER => [self::ncscRecord('NCSC-2026-0901', 'vers:generic/<30.0.0')]]))->correlateAll();

		self::assertSame('advisory-available', $results[self::SERVER]['state']);
		self::assertSame('not_affected', $results[self::SERVER]['advisories'][0]['match']);
	}

	public function testAnAppRecordReachesThatApp(): void {
		$results = $this->service([], $this->ncsc(['spreed' => [self::ncscRecord('NCSC-2026-0902', 'vers:generic/<21.0.4')]]))->correlateAll();

		self::assertSame('pinned-to-vulnerable', $results['spreed']['state']);
	}

	public function testASharedCveCountsOnceAndCarriesBothIds(): void {
		$ghsa = [
			'id' => 'GHSA-aaaa-bbbb-cccc',
			'severity' => 'high',
			'summary' => 'Shares leak',
			'cveIds' => ['CVE-2026-40001'],
			'affected' => [],
			'firstPatchedVersion' => '31.0.9',
			'patchedVersions' => ['31.0.9'],
		];
		$results = $this->service([self::SERVER => [$ghsa]], $this->ncsc([self::SERVER => [self::ncscRecord('NCSC-2026-0901', 'vers:generic/<31.0.9')]]))->correlateAll();

		self::assertCount(1, $results[self::SERVER]['advisories']);
		self::assertSame('GHSA-aaaa-bbbb-cccc', $results[self::SERVER]['advisories'][0]['id']);
		self::assertSame(['NCSC-2026-0901'], $results[self::SERVER]['advisories'][0]['relatedIds']);
		self::assertSame('31.0.9', $results[self::SERVER]['recommendedVersion']);
	}

	public function testARecordWithAnotherCveStaysSeparate(): void {
		$ghsa = ['id' => 'GHSA-1', 'severity' => 'low', 'summary' => 'x', 'cveIds' => ['CVE-2026-1'], 'affected' => ['< 30.0.0'], 'firstPatchedVersion' => null, 'patchedVersions' => []];
		$results = $this->service([self::SERVER => [$ghsa]], $this->ncsc([self::SERVER => [self::ncscRecord('NCSC-2026-0901', 'vers:unknown/*')]]))->correlateAll();

		self::assertSame(['GHSA-1', 'NCSC-2026-0901'], array_column($results[self::SERVER]['advisories'], 'id'));
		self::assertArrayNotHasKey('relatedIds', $results[self::SERVER]['advisories'][0]);
	}

	public function testSwitchedOffNothingIsAskedOfNcsc(): void {
		$ncsc = $this->createMock(NcscAdvisoryFeed::class);
		$ncsc->method('isEnabled')->willReturn(false);
		$ncsc->expects(self::never())->method('fetchAll');

		$results = $this->service([], $ncsc)->correlateAll();

		self::assertArrayNotHasKey(self::SERVER, $results);
	}
}
