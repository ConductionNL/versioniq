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

use OCA\Versioniq\Service\Advisory\AdvisoryPackageMap;
use OCA\Versioniq\Service\Advisory\CsafProductMatcher;
use OCA\Versioniq\Service\Advisory\NcscAdvisoryFeed;
use OCA\Versioniq\Service\Connection\ConnectionReportService;
use OCA\Versioniq\Service\Connection\ReportedHttp;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The incremental NCSC-NL read (advisories-ncsc-feed tasks 1.3 and 2.3).
 *
 * @spec openspec/changes/advisories-ncsc-feed/specs/security-advisory-correlation/spec.md#requirement-ncsc-nl-advisories-are-matched-to-the-installed-server-and-apps
 */
final class NcscAdvisoryFeedTest extends TestCase {
	private const BASE = 'https://csaf.example.org/v2/';

	/** 2026-09-27T12:00:00Z */
	private const NOW = 1790510400;

	/** @var array<string, mixed> */
	private array $stored = [];

	/** @var list<string> */
	private array $requested = [];

	/** @var list<array{read: int, matched: int, error: ?string}> */
	private array $reports = [];

	/**
	 * @param array<string, array{0: int, 1: string}> $responses Status and body per URL.
	 * @param list<int> $times The clock, one reading per call; the last repeats.
	 */
	private function feed(array $responses, array $times = [self::NOW]): NcscAdvisoryFeed {
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(function (string $url) use ($responses): IResponse {
			$this->requested[] = $url;
			[$status, $body] = $responses[$url] ?? [404, ''];
			$response = $this->createMock(IResponse::class);
			$response->method('getStatusCode')->willReturn($status);
			$response->method('getBody')->willReturn($body);

			return $response;
		});
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => (string)($this->stored[$key] ?? ($key === NcscAdvisoryFeed::CONFIG_BASE ? self::BASE : $default)),
		);
		$config->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value): bool {
			$this->stored[$key] = $value;

			return true;
		});
		$config->method('setValueBool')->willReturnCallback(function (string $app, string $key, bool $value): bool {
			$this->stored[$key] = $value;

			return true;
		});

		$reports = $this->createMock(ConnectionReportService::class);
		$reports->method('ncscFeedRead')->willReturnCallback(function (array $outcome): bool {
			$this->reports[] = $outcome;

			return true;
		});

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(static function () use (&$times): int {
			return count($times) > 1 ? array_shift($times) : $times[0];
		});

		$apps = $this->createMock(IAppManager::class);
		$apps->method('getEnabledApps')->willReturn(['spreed']);
		$apps->method('getAppInfo')->willReturnCallback(static fn (string $id): array => ['name' => $id === 'spreed' ? 'Talk' : $id]);
		$logger = $this->createMock(LoggerInterface::class);

		return new NcscAdvisoryFeed(
			new ReportedHttp($clientService, $reports),
			$config,
			new CsafProductMatcher(new AdvisoryPackageMap($apps, $logger)),
			$time,
			$logger,
		);
	}

	/**
	 * A CSAF document about Nextcloud Server.
	 *
	 * @param list<array<string, mixed>> $scores The first vulnerability's scores.
	 */
	private static function serverDocument(string $id, string $title = 'Kwetsbaarheid verholpen in Nextcloud Server', array $scores = [['cvss_v3' => ['baseSeverity' => 'HIGH']]]): string {
		return (string)json_encode([
			'document' => [
				'title' => $title,
				'tracking' => ['id' => $id, 'initial_release_date' => '2026-09-24T09:14:33Z'],
				'notes' => [['title' => 'Kans', 'text' => 'medium'], ['title' => 'Schade', 'text' => 'high']],
			],
			'product_tree' => ['branches' => [[
				'category' => 'vendor',
				'name' => 'Nextcloud',
				'branches' => [[
					'category' => 'product_name',
					'name' => 'Nextcloud Server',
					'branches' => [['category' => 'product_version_range', 'name' => 'vers:generic/<31.0.9', 'product' => ['product_id' => 'CSAFPID-1']]],
				]],
			]]],
			'vulnerabilities' => [['cve' => 'CVE-2026-40001', 'product_status' => ['known_affected' => ['CSAFPID-1']], 'scores' => $scores]],
		]);
	}

	private static function otherVendor(): string {
		return (string)json_encode([
			'document' => ['title' => 'Kwetsbaarheid in iets anders', 'tracking' => ['id' => 'NCSC-2026-0500']],
			'product_tree' => ['branches' => [['category' => 'vendor', 'name' => 'Elders', 'branches' => [['category' => 'product_name', 'name' => 'Iets', 'product' => ['product_id' => 'X']]]]]],
			'vulnerabilities' => [['cve' => 'CVE-2026-1', 'product_status' => ['known_affected' => ['X']]]],
		]);
	}

	public function testAFirstReadTakesTheLastYearOldestFirstAndIndexesOnlyNextcloudDocuments(): void {
		$changes = implode("\n", [
			'"2026/ncsc-2026-0901.json","2026-09-24T09:14:33.175708+00:00"',
			'"2026/ncsc-2026-0500.json","2026-06-01T08:00:00+00:00"',
			'"2024/ncsc-2024-0001.json","2024-01-01T08:00:00+00:00"',
		]);
		$feed = $this->feed([
			self::BASE . 'changes.csv' => [200, $changes],
			self::BASE . '2026/ncsc-2026-0901.json' => [200, self::serverDocument('NCSC-2026-0901')],
			self::BASE . '2026/ncsc-2026-0500.json' => [200, self::otherVendor()],
		]);

		$result = $feed->fetchAll();

		self::assertSame([self::BASE . 'changes.csv', self::BASE . '2026/ncsc-2026-0500.json', self::BASE . '2026/ncsc-2026-0901.json'], $this->requested);
		self::assertNull($result['error']);
		self::assertSame([':server'], array_keys($result['advisories']));
		$record = $result['advisories'][':server'][0];
		self::assertSame('NCSC-2026-0901', $record['id']);
		self::assertSame('ncsc', $record['source']);
		self::assertSame('high', $record['severity']);
		self::assertSame(['CVE-2026-40001'], $record['cveIds']);
		self::assertSame(['likelihood' => 'medium', 'damage' => 'high'], $record['ratings']);
		self::assertSame(['vers:generic/<31.0.9'], $record['ranges']);
		self::assertSame(self::BASE . '2026/ncsc-2026-0901.json', $record['url']);
		self::assertSame('2026-09-24T09:14:33.175708+00:00', $this->stored[NcscAdvisoryFeed::CONFIG_CURSOR]);
		self::assertTrue($this->stored[NcscAdvisoryFeed::CONFIG_CAUGHT_UP]);
		self::assertSame([['read' => 2, 'matched' => 1, 'error' => null]], $this->reports);
	}

	public function testOnlyLinesNewerThanTheCursorAreFetchedAndAChangedDocumentReplacesItsEntry(): void {
		$this->stored[NcscAdvisoryFeed::CONFIG_CURSOR] = '2026-09-24T09:14:33.175708+00:00';
		$this->stored[NcscAdvisoryFeed::CONFIG_INDEX] = (string)json_encode(['NCSC-2026-0901' => ['id' => 'NCSC-2026-0901', 'title' => 'Oud', 'targets' => [['target' => ':server', 'range' => null, 'severity' => 'low']]]]);
		$feed = $this->feed([
			self::BASE . 'changes.csv' => [200, '"2026/ncsc-2026-0901.json","2026-09-26T10:00:00+00:00"' . "\n" . '"2026/ncsc-2026-0901.json","2026-09-24T09:14:33.175708+00:00"'],
			self::BASE . '2026/ncsc-2026-0901.json' => [200, self::serverDocument('NCSC-2026-0901', 'Nieuw')],
		]);

		$result = $feed->fetchAll();

		self::assertSame([self::BASE . 'changes.csv', self::BASE . '2026/ncsc-2026-0901.json'], $this->requested);
		self::assertCount(1, $result['advisories'][':server']);
		self::assertSame('Nieuw', $result['advisories'][':server'][0]['summary']);
		self::assertSame('2026-09-26T10:00:00+00:00', $this->stored[NcscAdvisoryFeed::CONFIG_CURSOR]);
	}

	public function testTheCursorStopsAtTheLastProcessedDocumentWhenTheBudgetRunsOut(): void {
		$feed = $this->feed([
			self::BASE . 'changes.csv' => [200, '"2026/b.json","2026-09-25T00:00:00+00:00"' . "\n" . '"2026/a.json","2026-09-20T00:00:00+00:00"'],
			self::BASE . '2026/a.json' => [200, self::serverDocument('NCSC-2026-0001')],
			self::BASE . '2026/b.json' => [200, self::serverDocument('NCSC-2026-0002')],
		], [self::NOW, self::NOW, self::NOW, self::NOW + 121]);

		$result = $feed->fetchAll();

		self::assertNotContains(self::BASE . '2026/b.json', $this->requested);
		self::assertSame(['NCSC-2026-0001'], array_column($result['advisories'][':server'], 'id'));
		self::assertSame('2026-09-20T00:00:00+00:00', $this->stored[NcscAdvisoryFeed::CONFIG_CURSOR]);
		self::assertFalse($this->stored[NcscAdvisoryFeed::CONFIG_CAUGHT_UP]);
	}

	public function testAFailedChangesListKeepsTheIndexAndReportsTheError(): void {
		$this->stored[NcscAdvisoryFeed::CONFIG_CURSOR] = '2026-09-24T00:00:00+00:00';
		$this->stored[NcscAdvisoryFeed::CONFIG_INDEX] = (string)json_encode(['NCSC-2026-0901' => ['id' => 'NCSC-2026-0901', 'title' => 'Bekend', 'targets' => [['target' => ':server', 'range' => 'vers:unknown/*', 'severity' => 'high']]]]);
		$feed = $this->feed([self::BASE . 'changes.csv' => [500, 'oops']]);

		$result = $feed->fetchAll();

		self::assertSame('The NCSC-NL advisories returned HTTP 500.', $result['error']);
		self::assertSame(['NCSC-2026-0901'], array_column($result['advisories'][':server'], 'id'));
		self::assertSame('2026-09-24T00:00:00+00:00', $this->stored[NcscAdvisoryFeed::CONFIG_CURSOR]);
		self::assertSame([['read' => 0, 'matched' => 0, 'error' => 'The NCSC-NL advisories returned HTTP 500.']], $this->reports);
	}

	public function testADocumentThatFailsToLoadStopsTheReadBeforeIt(): void {
		$feed = $this->feed([
			self::BASE . 'changes.csv' => [200, '"2026/b.json","2026-09-25T00:00:00+00:00"' . "\n" . '"2026/a.json","2026-09-20T00:00:00+00:00"'],
			self::BASE . '2026/a.json' => [503, ''],
		]);

		$result = $feed->fetchAll();

		self::assertSame('The NCSC-NL advisories returned HTTP 503.', $result['error']);
		self::assertSame('', $this->stored[NcscAdvisoryFeed::CONFIG_CURSOR]);
		self::assertSame([], $result['advisories']);
	}

	public function testADocumentThatIsNotJsonIsSkippedAndCounted(): void {
		$feed = $this->feed([
			self::BASE . 'changes.csv' => [200, '"2026/a.json","2026-09-20T00:00:00+00:00"'],
			self::BASE . '2026/a.json' => [200, 'not json'],
		]);

		$result = $feed->fetchAll();

		self::assertNull($result['error']);
		self::assertSame('2026-09-20T00:00:00+00:00', $this->stored[NcscAdvisoryFeed::CONFIG_CURSOR]);
		self::assertSame([['read' => 1, 'matched' => 0, 'error' => null]], $this->reports);
	}

	public function testWithoutScoresTheSeverityIsUnknownAndTheRatingsAreKeptAsGiven(): void {
		$feed = $this->feed([
			self::BASE . 'changes.csv' => [200, '"2026/a.json","2026-09-20T00:00:00+00:00"'],
			self::BASE . '2026/a.json' => [200, self::serverDocument('NCSC-2026-0001', 'Zonder score', [])],
		]);

		$record = $feed->fetchAll()['advisories'][':server'][0];

		self::assertSame('unknown', $record['severity']);
		self::assertSame(['likelihood' => 'medium', 'damage' => 'high'], $record['ratings']);
	}

	public function testAPathThatClimbsOutOfTheDirectoryIsIgnored(): void {
		$feed = $this->feed([self::BASE . 'changes.csv' => [200, '"../secret.json","2026-09-20T00:00:00+00:00"']]);

		$feed->fetchAll();

		self::assertSame([self::BASE . 'changes.csv'], $this->requested);
	}
}
