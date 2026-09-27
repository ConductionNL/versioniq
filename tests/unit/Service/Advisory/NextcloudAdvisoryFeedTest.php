<?php

declare(strict_types=1);

namespace OCA\Versioniq\Tests\Unit\Service\Advisory;

use OCA\Versioniq\Service\Advisory\AdvisoryPackageMap;
use OCA\Versioniq\Service\Advisory\NextcloudAdvisoryFeed;
use OCP\App\IAppManager;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class NextcloudAdvisoryFeedTest extends TestCase {
	/**
	 * @param list<array{body: string, link?: string, status?: int}> $pages
	 * @param list<string> $installedApps
	 */
	private function feed(array $pages, array $installedApps = ['mail', 'spreed', 'tables']): NextcloudAdvisoryFeed {
		$responses = [];
		foreach ($pages as $page) {
			$response = $this->createMock(IResponse::class);
			$response->method('getStatusCode')->willReturn($page['status'] ?? 200);
			$response->method('getBody')->willReturn($page['body']);
			$response->method('getHeader')->willReturnCallback(
				static fn (string $key): string => strtolower($key) === 'link' ? ($page['link'] ?? '') : '',
			);
			$responses[] = $response;
		}

		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnOnConsecutiveCalls(...$responses);
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('');

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getEnabledApps')->willReturn($installedApps);
		$appManager->method('getAppInfo')->willReturnCallback(
			static fn (string $id) => ['name' => ['mail' => 'Mail', 'spreed' => 'Talk', 'tables' => 'Tables'][$id] ?? $id],
		);

		$logger = $this->createMock(LoggerInterface::class);

		return new NextcloudAdvisoryFeed(
			$clientService,
			$config,
			new AdvisoryPackageMap($appManager, $logger),
			$logger,
		);
	}

	/**
	 * @param list<array{package: string, range?: string, patched?: string}> $vulns
	 */
	private function advisory(string $ghsa, array $vulns, string $severity = 'high', string $summary = 'Something'): array {
		return [
			'ghsa_id' => $ghsa,
			'severity' => $severity,
			'summary' => $summary,
			'vulnerabilities' => array_map(static fn (array $v): array => [
				'package' => ['ecosystem' => 'nextcloud', 'name' => $v['package']],
				'vulnerable_version_range' => $v['range'] ?? '',
				'patched_versions' => $v['patched'] ?? '',
			], $vulns),
		];
	}

	public function testGroupsAdvisoriesByResolvedTarget(): void {
		$body = json_encode([
			$this->advisory('GHSA-aaaa', [['package' => 'Mail', 'range' => '>= 3.5.0', 'patched' => '3.7.25, 5.5.16']]),
			$this->advisory('GHSA-bbbb', [['package' => 'Talk', 'patched' => '21.1.10']]),
		], JSON_THROW_ON_ERROR);

		$result = $this->feed([['body' => $body]])->fetchAll();

		self::assertNull($result['error']);
		self::assertSame(['mail', 'spreed'], array_keys($result['advisories']));
		self::assertSame('GHSA-aaaa', $result['advisories']['mail'][0]['id']);
		self::assertSame(['3.7.25', '5.5.16'], $result['advisories']['mail'][0]['patchedVersions']);
		self::assertSame('3.7.25', $result['advisories']['mail'][0]['firstPatchedVersion']);
	}

	/**
	 * The whole reason this class does cursor pagination: the endpoint IGNORES
	 * `?page=`, so a page-number loop reads the first 100 records over and
	 * over and the feed is silently truncated.
	 */
	public function testFollowsTheCursorInTheLinkHeader(): void {
		$page1 = json_encode([$this->advisory('GHSA-aaaa', [['package' => 'Mail', 'patched' => '1.0.1']])], JSON_THROW_ON_ERROR);
		$page2 = json_encode([$this->advisory('GHSA-bbbb', [['package' => 'Tables', 'patched' => '2.0.1']])], JSON_THROW_ON_ERROR);

		$result = $this->feed([
			['body' => $page1, 'link' => '<https://api.github.com/repositories/1/security-advisories?per_page=100&after=CURSOR>; rel="next"'],
			['body' => $page2],
		])->fetchAll();

		self::assertNull($result['error']);
		self::assertArrayHasKey('mail', $result['advisories'], 'page one must be kept');
		self::assertArrayHasKey('tables', $result['advisories'], 'page two must be followed via the cursor');
	}

	/**
	 * A server that keeps returning the same page must not spin to MAX_PAGES.
	 */
	public function testStopsWhenAPageAddsNothingNew(): void {
		$same = json_encode([$this->advisory('GHSA-aaaa', [['package' => 'Mail', 'patched' => '1.0.1']])], JSON_THROW_ON_ERROR);
		$link = '<https://api.github.com/x?after=SAME>; rel="next"';

		// Only two responses are provisioned. If the loop did not stop, the
		// third get() would return null and the test would error rather than
		// pass — which is the point.
		$result = $this->feed([
			['body' => $same, 'link' => $link],
			['body' => $same, 'link' => $link],
		])->fetchAll();

		self::assertNull($result['error']);
		self::assertCount(1, $result['advisories']['mail'], 'the duplicate advisory must not be counted twice');
	}

	public function testDropsPackagesTheInstanceCannotActOn(): void {
		$body = json_encode([
			$this->advisory('GHSA-aaaa', [['package' => 'Desktop', 'patched' => '1.0.1']]),
			$this->advisory('GHSA-bbbb', [['package' => 'Some Uninstalled App', 'patched' => '2.0.1']]),
		], JSON_THROW_ON_ERROR);

		$result = $this->feed([['body' => $body]])->fetchAll();

		self::assertSame([], $result['advisories'], 'a client advisory and an uninstalled app must both be dropped');
	}

	public function testServerAdvisoriesAreKeyedByTheServerSentinel(): void {
		$body = json_encode([
			$this->advisory('GHSA-cccc', [['package' => 'Server', 'patched' => '31.0.1']]),
		], JSON_THROW_ON_ERROR);

		$result = $this->feed([['body' => $body]])->fetchAll();

		self::assertArrayHasKey(AdvisoryPackageMap::SERVER, $result['advisories']);
	}

	/**
	 * Some records list the same package twice. Merging keeps every branch;
	 * letting the second entry win would silently drop patches.
	 */
	public function testMergesRepeatedPackagesWithinOneAdvisory(): void {
		$body = json_encode([
			$this->advisory('GHSA-dddd', [
				['package' => 'Server', 'range' => '< 21.0.0', 'patched' => '21.0.9'],
				['package' => 'Server', 'range' => '>= 22.0.0', 'patched' => '22.2.10'],
			]),
		], JSON_THROW_ON_ERROR);

		$result = $this->feed([['body' => $body]])->fetchAll();

		$entry = $result['advisories'][AdvisoryPackageMap::SERVER][0];
		self::assertSame(['21.0.9', '22.2.10'], $entry['patchedVersions']);
		self::assertSame(['< 21.0.0', '>= 22.0.0'], $entry['affected']);
	}

	// ── Failure modes: an unreachable feed must never read as "nothing found" ──

	public function testReportsAnHttpFailureRatherThanReturningSilence(): void {
		$result = $this->feed([['body' => '', 'status' => 503]])->fetchAll();

		self::assertNotNull($result['error']);
		self::assertStringContainsString('503', $result['error']);
	}

	public function testReportsInvalidJson(): void {
		$result = $this->feed([['body' => '{not json']])->fetchAll();

		self::assertNotNull($result['error']);
		self::assertStringContainsString('invalid JSON', $result['error']);
	}

	/**
	 * A failure on page two must keep page one AND report the error. Throwing
	 * the partial away would turn a half-read feed into "no advisories".
	 */
	public function testKeepsWhatItReadBeforeAFailureAndStillReportsIt(): void {
		$page1 = json_encode([$this->advisory('GHSA-aaaa', [['package' => 'Mail', 'patched' => '1.0.1']])], JSON_THROW_ON_ERROR);

		$result = $this->feed([
			['body' => $page1, 'link' => '<https://api.github.com/x?after=C>; rel="next"'],
			['body' => '', 'status' => 500],
		])->fetchAll();

		self::assertNotNull($result['error'], 'the failure must be reported');
		self::assertArrayHasKey('mail', $result['advisories'], 'page one must survive the page-two failure');
	}

	/**
	 * Issue #443: the feed lower-cases a present severity and maps GitHub's
	 * `moderate` to `medium`, like the two Source classes; a missing one is
	 * `unknown`, the value AdvisorySourceInterface documents.
	 *
	 * @spec openspec/specs/security-advisory-correlation/spec.md
	 */
	public function testSeverityIsNormalisedToTheDocumentedValues(): void {
		$unscored = $this->advisory('GHSA-none', [['package' => 'Mail']]);
		unset($unscored['severity']);
		$body = json_encode([
			$unscored,
			$this->advisory('GHSA-high', [['package' => 'Talk']], 'HIGH'),
			$this->advisory('GHSA-mod', [['package' => 'Tables']], 'moderate'),
		], JSON_THROW_ON_ERROR);

		$result = $this->feed([['body' => $body]])->fetchAll();

		self::assertSame('unknown', $result['advisories']['mail'][0]['severity']);
		self::assertSame('high', $result['advisories']['spreed'][0]['severity']);
		self::assertSame('medium', $result['advisories']['tables'][0]['severity']);
	}
}
