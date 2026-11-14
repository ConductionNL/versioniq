<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Advisory;

use OCA\Versioniq\Service\Advisory\OsvClient;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Versioniq\Service\Advisory\OsvClient
 * @uses \OCA\Versioniq\Service\Advisory\AdvisorySeverity
 */
final class OsvClientTest extends TestCase {
	/** @var list<array{method: string, url: string, body: mixed, options: array<string, mixed>}> */
	private array $requests = [];

	/**
	 * @param list<array{status?: int, body: mixed}> $batches answers to successive querybatch posts
	 * @param array<string, array{status?: int, body: mixed}> $records answers per vulnerability id
	 */
	private function client(array $batches, array $records = [], string $base = ''): OsvClient {
		$client = $this->createMock(IClient::class);
		$client->method('post')->willReturnCallback(function (string $url, array $options) use (&$batches): IResponse {
			$this->requests[] = ['method' => 'post', 'url' => $url, 'body' => json_decode((string)$options['body'], true), 'options' => $options];

			return $this->response(array_shift($batches) ?? ['status' => 500, 'body' => null]);
		});
		$client->method('get')->willReturnCallback(function (string $url, array $options) use ($records): IResponse {
			$this->requests[] = ['method' => 'get', 'url' => $url, 'body' => null, 'options' => $options];
			$id = rawurldecode(substr($url, strrpos($url, '/') + 1));

			return $this->response($records[$id] ?? ['status' => 404, 'body' => null]);
		});
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueBool')->with('allow_local_remote_servers', false)->willReturn(false);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn($base);

		return new OsvClient($clientService, $config, $appConfig);
	}

	/**
	 * @param array{status?: int, body: mixed} $answer
	 */
	private function response(array $answer): IResponse {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($answer['status'] ?? 200);
		$response->method('getBody')->willReturn(is_string($answer['body']) ? $answer['body'] : json_encode($answer['body']));

		return $response;
	}

	/**
	 * An OSV record for one Packagist package.
	 *
	 * @param list<string> $fixed
	 * @return array<string, mixed>
	 */
	private static function record(string $id, string $package, array $fixed, ?string $severity = 'MODERATE', array $aliases = []): array {
		$record = [
			'id' => $id,
			'summary' => 'Summary of ' . $id,
			'aliases' => $aliases,
			'affected' => [[
				'package' => ['ecosystem' => 'Packagist', 'name' => $package],
				'ranges' => [['type' => 'ECOSYSTEM', 'events' => array_merge([['introduced' => '0']], array_map(static fn (string $v): array => ['fixed' => $v], $fixed))]],
			]],
		];
		if ($severity !== null) {
			$record['database_specific'] = ['severity' => $severity];
		}

		return $record;
	}

	public function testOneBatchForTwoAppsSharingALibrary(): void {
		$osv = $this->client(
			[['body' => ['results' => [['vulns' => [['id' => 'GHSA-aaaa-bbbb-cccc']]], []]]]],
			['GHSA-aaaa-bbbb-cccc' => ['body' => self::record('GHSA-aaaa-bbbb-cccc', 'vendor/library', ['2.4.5', '3.0.1'], 'MODERATE', ['CVE-2026-0001', 'PYSEC-1'])]],
		);

		// The same library bundled in two apps, plus one clean library.
		$result = $osv->check([
			['name' => 'vendor/library', 'version' => '2.4.3'],
			['name' => 'vendor/clean', 'version' => '1.0.0'],
			['name' => 'vendor/library', 'version' => '2.4.3'],
		]);

		$this->assertCount(2, $this->requests);
		$this->assertSame('post', $this->requests[0]['method']);
		$this->assertSame('https://api.osv.dev/v1/querybatch', $this->requests[0]['url']);
		$this->assertSame(['queries' => [
			['package' => ['name' => 'vendor/library', 'ecosystem' => 'Packagist'], 'version' => '2.4.3'],
			['package' => ['name' => 'vendor/clean', 'ecosystem' => 'Packagist'], 'version' => '1.0.0'],
		]], $this->requests[0]['body']);
		$this->assertSame(['allow_local_address' => false], $this->requests[0]['options']['nextcloud']);
		$this->assertSame(30, $this->requests[0]['options']['timeout']);
		$this->assertSame('https://api.osv.dev/v1/vulns/GHSA-aaaa-bbbb-cccc', $this->requests[1]['url']);

		$this->assertSame([
			'findings' => ['vendor/library@2.4.3' => [[
				'package' => 'vendor/library',
				'version' => '2.4.3',
				'id' => 'GHSA-aaaa-bbbb-cccc',
				'cveIds' => ['CVE-2026-0001'],
				'severity' => 'medium',
				'summary' => 'Summary of GHSA-aaaa-bbbb-cccc',
				'fixedIn' => '2.4.5',
				'url' => 'https://osv.dev/vulnerability/GHSA-aaaa-bbbb-cccc',
				'malicious' => false,
			]]],
			'unchecked' => [],
			'asked' => 2,
			'error' => null,
		], $result);
	}

	public function testFollowsThePageTokenOfAPagedResult(): void {
		$osv = $this->client(
			[
				['body' => ['results' => [['vulns' => [['id' => 'GHSA-one']], 'next_page_token' => 'tok-2']]]],
				['body' => ['results' => [['vulns' => [['id' => 'GHSA-two']]]]]],
			],
			[
				'GHSA-one' => ['body' => self::record('GHSA-one', 'vendor/library', ['2.5.0'])],
				'GHSA-two' => ['body' => self::record('GHSA-two', 'vendor/library', ['2.6.0'], 'HIGH')],
			],
		);

		$result = $osv->check([['name' => 'vendor/library', 'version' => '2.4.3']]);

		$this->assertSame('tok-2', $this->requests[1]['body']['queries'][0]['page_token']);
		$this->assertSame(['GHSA-one', 'GHSA-two'], array_column($result['findings']['vendor/library@2.4.3'], 'id'));
		$this->assertSame(['medium', 'high'], array_column($result['findings']['vendor/library@2.4.3'], 'severity'));
		$this->assertNull($result['error']);
	}

	public function testARecordWithoutASeverityIsUnknownAndTheFixAboveTheBundledVersionIsChosen(): void {
		$osv = $this->client(
			[['body' => ['results' => [['vulns' => [['id' => 'GHSA-nosev']]]]]]],
			['GHSA-nosev' => ['body' => self::record('GHSA-nosev', 'vendor/library', ['1.9.9', '2.4.8', '2.4.5'], null)]],
		);

		$finding = $osv->check([['name' => 'vendor/library', 'version' => '2.4.3']])['findings']['vendor/library@2.4.3'][0];

		$this->assertSame('unknown', $finding['severity']);
		$this->assertSame('2.4.5', $finding['fixedIn']);
		$this->assertSame([], $finding['cveIds']);
	}

	public function testAMaliciousPackageRecordIsMarkedMalicious(): void {
		$record = self::record('MAL-2026-1', 'vendor/evil', [], null);
		$osv = $this->client([['body' => ['results' => [['vulns' => [['id' => 'MAL-2026-1']]]]]]], ['MAL-2026-1' => ['body' => $record]]);

		$finding = $osv->check([['name' => 'vendor/evil', 'version' => '1.0.0']])['findings']['vendor/evil@1.0.0'][0];

		$this->assertTrue($finding['malicious']);
		$this->assertNull($finding['fixedIn']);
		$this->assertSame('MAL-2026-1', $finding['id']);
	}

	public function testAFailedBatchIsReturnedAsAnErrorWithEveryLibraryUnchecked(): void {
		$osv = $this->client([['status' => 503, 'body' => 'busy']]);

		$result = $osv->check([['name' => 'vendor/a', 'version' => '1.0.0'], ['name' => 'vendor/b', 'version' => '2.0.0']]);

		$this->assertSame([
			'findings' => [],
			'unchecked' => ['vendor/a@1.0.0', 'vendor/b@2.0.0'],
			'asked' => 2,
			'error' => 'OSV returned HTTP 503.',
		], $result);
	}

	public function testAFailedDetailCallLeavesThatLibraryUnchecked(): void {
		$osv = $this->client(
			[['body' => ['results' => [['vulns' => [['id' => 'GHSA-gone']]], ['vulns' => [['id' => 'GHSA-ok']]]]]]],
			['GHSA-ok' => ['body' => self::record('GHSA-ok', 'vendor/b', ['2.0.1'])]],
		);

		$result = $osv->check([['name' => 'vendor/a', 'version' => '1.0.0'], ['name' => 'vendor/b', 'version' => '2.0.0']]);

		$this->assertSame(['vendor/a@1.0.0'], $result['unchecked']);
		$this->assertSame('OSV returned HTTP 404.', $result['error']);
		$this->assertSame(['vendor/b@2.0.0'], array_keys($result['findings']));
	}

	public function testTheDetailCallCapLeavesTheRestUnchecked(): void {
		$osv = $this->client(
			[['body' => ['results' => [['vulns' => [['id' => 'GHSA-1']]], ['vulns' => [['id' => 'GHSA-2']]]]]]],
			[
				'GHSA-1' => ['body' => self::record('GHSA-1', 'vendor/a', ['1.0.1'])],
				'GHSA-2' => ['body' => self::record('GHSA-2', 'vendor/b', ['2.0.1'])],
			],
		);

		$result = $osv->check([['name' => 'vendor/a', 'version' => '1.0.0'], ['name' => 'vendor/b', 'version' => '2.0.0']], 120.0, 1);

		$this->assertSame(['vendor/a@1.0.0'], array_keys($result['findings']));
		$this->assertSame(['vendor/b@2.0.0'], $result['unchecked']);
		$this->assertSame('The check stopped at its limit; some libraries were not checked this run.', $result['error']);
		$this->assertCount(2, $this->requests);
	}

	public function testAnInvalidBatchBodyIsAnError(): void {
		$this->assertSame('OSV returned invalid JSON.', $this->client([['body' => '{oops']])->check([['name' => 'a/b', 'version' => '1']])['error']);
		$this->assertSame('OSV returned an unexpected batch answer.', $this->client([['body' => ['nothing' => true]]])->check([['name' => 'a/b', 'version' => '1']])['error']);
	}

	public function testTheBaseIsTheHttpsOverrideOnly(): void {
		$this->assertSame('https://osv.mirror.example', $this->client([], [], 'https://osv.mirror.example/')->apiBase());
		$this->assertSame(OsvClient::DEFAULT_API_BASE, $this->client([], [], 'http://osv.mirror.example')->apiBase());
		$this->assertSame(OsvClient::DEFAULT_API_BASE, $this->client([], [], '')->apiBase());
	}
}
