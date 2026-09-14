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

use Exception;
use OCA\Versioniq\Service\Advisory\AdvisoryPackageMap;
use OCA\Versioniq\Service\Advisory\NextcloudAdvisoryFeed;
use OCA\Versioniq\Service\Connection\ConnectionReportService;
use OCP\App\IAppManager;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * An advisory check tells the connection report how much of the feed it read.
 * The count is advisories, not the apps they were grouped under: a check that
 * read 100 advisories about uninstalled apps still read the feed.
 *
 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-versioniq-conn-002-versioniq-reports-what-a-real-request-met
 */
final class NextcloudAdvisoryFeedConnectionReportTest extends TestCase {
	private const NEXT = '<https://api.github.com/repositories/1/security-advisories?per_page=100&after=CURSOR>; rel="next"';

	/**
	 * @param list<IResponse|Exception> $answers
	 */
	private function feed(array $answers, ConnectionReportService $reports): NextcloudAdvisoryFeed {
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(static function () use (&$answers): IResponse {
			$answer = array_shift($answers);
			if ($answer instanceof Exception) {
				throw $answer;
			}

			return $answer;
		});
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('');

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getEnabledApps')->willReturn(['mail']);
		$appManager->method('getAppInfo')->willReturn(['name' => 'Mail']);

		$logger = $this->createMock(LoggerInterface::class);

		return new NextcloudAdvisoryFeed($clientService, $config, new AdvisoryPackageMap($appManager, $logger), $logger, $reports);
	}

	private function page(int $status, array $records, string $link = ''): IResponse {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getBody')->willReturn(json_encode($records, JSON_THROW_ON_ERROR));
		$response->method('getHeader')->willReturnCallback(static fn (string $key): string => strtolower($key) === 'link' ? $link : '');

		return $response;
	}

	private function advisory(string $ghsa, string $package): array {
		return [
			'ghsa_id' => $ghsa,
			'severity' => 'high',
			'summary' => 'Something',
			'vulnerabilities' => [['package' => ['ecosystem' => 'nextcloud', 'name' => $package], 'patched_versions' => '1.0.1']],
		];
	}

	public function testAFullReadReportsEveryAdvisoryRead(): void {
		$reports = $this->createMock(ConnectionReportService::class);
		// Two advisories, one about an app this instance does not run.
		$reports->expects($this->once())->method('advisoryFeedRead')->with(2, null);

		$result = $this->feed([
			$this->page(200, [$this->advisory('GHSA-aaaa', 'Mail')], self::NEXT),
			$this->page(200, [$this->advisory('GHSA-bbbb', 'Some Uninstalled App')]),
		], $reports)->fetchAll();

		self::assertNull($result['error']);
	}

	public function testAReadThatStopsHalfwayReportsWhatItRead(): void {
		$reports = $this->createMock(ConnectionReportService::class);
		$reports->expects($this->once())->method('advisoryFeedRead')->with(1, 'The Nextcloud advisory feed returned HTTP 502.');

		$result = $this->feed([
			$this->page(200, [$this->advisory('GHSA-aaaa', 'Mail')], self::NEXT),
			$this->page(502, []),
		], $reports)->fetchAll();

		// The check answers exactly as before.
		self::assertSame('The Nextcloud advisory feed returned HTTP 502.', $result['error']);
		self::assertArrayHasKey('mail', $result['advisories']);
	}

	public function testNoAnswerReportsNothingRead(): void {
		$reports = $this->createMock(ConnectionReportService::class);
		$reports->expects($this->once())->method('advisoryFeedRead')->with(0, $this->stringStartsWith('Could not read the Nextcloud advisory feed: '));

		$this->feed([new Exception('cURL error 6')], $reports)->fetchAll();
	}
}
