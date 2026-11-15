<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Source;

require_once __DIR__ . '/../../Support/BuildsByParameterName.php';

use OCA\Versioniq\Service\Pat\PatResolver;
use OCA\Versioniq\Service\Source\ForgeRegistry;
use OCA\Versioniq\Service\Source\ForgeReleaseSource;
use OCA\Versioniq\Service\Source\SourceBinding;
use OCA\Versioniq\Tests\Unit\Support\BuildsByParameterName;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Golden test for ForgeReleaseSource's public listings: listVersions(),
 * resolveRelease() and listAdvisories() over a non-forge binding, a failed
 * request and release or advisory lists with every entry shape (junk entries,
 * tags with and without a v, blank and non-string tags, duplicates, drafts,
 * dates, bodies, assets, advisory ids and vulnerabilities). The returned
 * arrays and every request URL are compared, case by case as JSON text, with
 * fixtures/forge-listing.golden.json. Set RECORD_GOLDEN=1 to rewrite it.
 *
 * @covers \OCA\Versioniq\Service\Source\ForgeReleaseSource
 * @uses \OCA\Versioniq\Service\Source\Forge
 * @uses \OCA\Versioniq\Service\Source\ForgeRegistry
 * @uses \OCA\Versioniq\Service\Source\SourceBinding
 * @uses \OCA\Versioniq\Service\Source\ReleaseDate
 * @uses \OCA\Versioniq\Service\Advisory\AdvisoryClauses
 * @uses \OCA\Versioniq\Service\Advisory\AdvisorySeverity
 * @uses \OCA\Versioniq\Service\Pat\PatAccess
 * @uses \OCA\Versioniq\Service\Source\ForgeUrls
 */
final class ForgeListingGoldenTest extends TestCase {
	use BuildsByParameterName;

	private const FIXTURE = __DIR__ . '/fixtures/forge-listing.golden.json';

	/** @var list<string> */
	private array $urls = [];

	/**
	 * Release lists, each the JSON the forge answers with (null: HTTP 500).
	 *
	 * @return array<string, ?string>
	 */
	private static function releaseLists(): array {
		$asset = static fn (string $name): array => ['name' => $name, 'browser_download_url' => 'https://dl.example/' . $name];
		$json = static fn (mixed $value): string => (string)json_encode($value);

		return [
			'failed' => null,
			'empty' => '[]',
			'mixed' => $json([
				'junk', 7, null,
				['tag_name' => 'v2.0.0', 'body' => 'Two', 'published_at' => '2026-02-01T10:00:00Z', 'assets' => [$asset('app-2.0.0.tar.gz')]],
				['tag_name' => '', 'body' => 'blank'],
				['tag_name' => 5],
				['body' => 'no tag'],
				['tag_name' => 'V1.5.0', 'body' => '  ', 'assets' => [$asset('app-1.5.0.tar.gz'), $asset('app-1.5.0.tar.gz.sha256')]],
				['tag_name' => '1.0.0', 'body' => ['not', 'text'], 'published_at' => 'not a date', 'assets' => [$asset('app.zip')]],
				['tag_name' => '2.0.0', 'body' => 'Dup of two', 'published_at' => '2026-02-02T10:00:00Z', 'assets' => [$asset('dup.tar.gz')]],
				['tag_name' => 'v3.0.0-beta.1', 'draft' => true],
				['tag_name' => 'release-4', 'body' => 'odd tag', 'assets' => [$asset('a.tar.gz'), $asset('b.tar.gz')]],
			]),
			'duplicates fill gaps' => $json([
				['tag_name' => 'v1.0.0'],
				['tag_name' => '1.0.0', 'body' => 'late notes', 'published_at' => '2026-01-01T00:00:00Z'],
				['tag_name' => 'V1.0.0', 'body' => 'later notes'],
			]),
		];
	}

	/**
	 * Advisory lists, each the JSON the forge answers with (null: HTTP 500).
	 *
	 * @return array<string, ?string>
	 */
	private static function advisoryLists(): array {
		$json = static fn (mixed $value): string => (string)json_encode($value);

		return [
			'failed' => null,
			'empty' => '[]',
			'mixed' => $json([
				'junk', 3,
				['ghsa_id' => 'GHSA-aaaa', 'severity' => 'high', 'summary' => 'One', 'vulnerabilities' => [['vulnerable_version_range' => '< 2.0.0', 'first_patched_version' => ['identifier' => 'v2.0.0']]]],
				['id' => 'ID-2', 'title' => 'Titled', 'vulnerabilities' => [['first_patched_version' => '1.2.3']]],
				['cve_id' => 'CVE-2026-1', 'summary' => 9, 'vulnerabilities' => 'none'],
				['summary' => 'no id'],
				['ghsa_id' => '', 'id' => 'ID-3'],
				['ghsa_id' => 'GHSA-bbbb', 'vulnerabilities' => [['first_patched_version' => ['identifier' => '']]]],
				['ghsa_id' => 'GHSA-cccc', 'vulnerabilities' => [null, ['vulnerable_version_range' => '>= 1.0']]],
				['ghsa_id' => 'GHSA-dddd', 'severity' => 'CRITICAL', 'vulnerabilities' => [['vulnerable_version_range' => '>= 1.0, < 1.4', 'first_patched_version' => 7]]],
			]),
		];
	}

	public function testEveryCaseMatchesTheGolden(): void {
		$actual = [];
		$forges = [
			'github' => SourceBinding::github('acme', 'tool', '*.tar.gz'),
			'codeberg' => SourceBinding::codeberg('acme', 'tool', 'app-*'),
		];
		$actual['versions app store binding'] = $this->runCall(null, fn (ForgeReleaseSource $s): array => $s->listVersions('tool', SourceBinding::appStore()));
		$actual['resolve app store binding'] = $this->runCall(null, fn (ForgeReleaseSource $s): ?array => $s->resolveRelease('tool', '1.0.0', SourceBinding::appStore()));
		$actual['advisories app store binding'] = $this->runCall(null, fn (ForgeReleaseSource $s): array => $s->listAdvisories('tool', SourceBinding::appStore()));
		foreach ($forges as $forgeName => $binding) {
			foreach (self::releaseLists() as $listName => $body) {
				$actual["versions {$forgeName} {$listName}"] = $this->runCall($body, fn (ForgeReleaseSource $s): array => $s->listVersions('tool', $binding));
				foreach (['2.0.0', 'v2.0.0', '1.5.0', 'V1.5.0', '1.0.0', 'release-4', '4', '9.9.9', ''] as $version) {
					$actual["resolve {$forgeName} {$listName} {$version}"] = $this->runCall($body, fn (ForgeReleaseSource $s): ?array => $s->resolveRelease('tool', $version, $binding));
				}
			}
			foreach (self::advisoryLists() as $listName => $body) {
				$actual["advisories {$forgeName} {$listName}"] = $this->runCall($body, fn (ForgeReleaseSource $s): array => $s->listAdvisories('tool', $binding));
			}
		}

		if (getenv('RECORD_GOLDEN') === '1') {
			if (!is_dir(dirname(self::FIXTURE))) {
				mkdir(dirname(self::FIXTURE));
			}
			$lines = [];
			foreach ($actual as $name => $case) {
				$lines[] = json_encode($name) . ': ' . json_encode($case, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
			}
			file_put_contents(self::FIXTURE, "{\n" . implode(",\n", $lines) . "\n}\n");
			$this->markTestSkipped('Golden fixture recorded.');
		}

		/** @var array<string, mixed> $expected */
		$expected = json_decode((string)file_get_contents(self::FIXTURE), true);
		self::assertSame(array_keys($expected), array_keys($actual), 'case list changed');
		foreach ($actual as $name => $case) {
			self::assertSame(
				json_encode($expected[$name], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
				json_encode($case, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
				"case {$name}",
			);
		}
	}

	/**
	 * @param \Closure(ForgeReleaseSource): mixed $call
	 * @return array<string, mixed>
	 */
	private function runCall(?string $body, \Closure $call): array {
		$this->urls = [];
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(function (string $url) use ($body): IResponse {
			$this->urls[] = $url;
			$response = $this->createMock(IResponse::class);
			$response->method('getStatusCode')->willReturn($body === null ? 500 : 200);
			$response->method('getBody')->willReturn($body ?? '');

			return $response;
		});
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);
		$patResolver = $this->createMock(PatResolver::class);
		$patResolver->method('findFor')->willReturn(null);
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);

		$source = $this->buildByName(ForgeReleaseSource::class, [
			'clientService' => $clientService,
			'patResolver' => $patResolver,
			'userSession' => $userSession,
			'forgeRegistry' => new ForgeRegistry($this->createMock(IAppConfig::class)),
		]);

		return ['result' => $call($source), 'urls' => $this->urls];
	}
}
