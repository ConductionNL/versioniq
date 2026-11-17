<?php

/**
 * GitLab releases read through ForgeReleaseSource (sources-gitlab-forge task 2.1).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Versioniq\Tests\Unit\Service\Source;

require_once __DIR__ . '/../../Support/BuildsByParameterName.php';

use OCA\Versioniq\Service\Pat\PatManager;
use OCA\Versioniq\Service\Pat\PatResolver;
use OCA\Versioniq\Service\Source\ForgeRegistry;
use OCA\Versioniq\Service\Source\ForgeReleaseSource;
use OCA\Versioniq\Service\Source\SourceBinding;
use OCA\Versioniq\Tests\Unit\Support\BuildsByParameterName;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A recorded GitLab releases payload: versions, changelogs, the selected asset and its checksum.
 */
#[CoversClass(ForgeReleaseSource::class)]
final class ForgeReleaseSourceGitlabTest extends TestCase {
	use BuildsByParameterName;

	/**
	 * The URL the source asked for.
	 *
	 * @var string|null
	 */
	private ?string $requested = null;

	/**
	 * What GitLab answers for GET /projects/:id/releases, trimmed to the fields read.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function recordedReleases(): array {
		return [
			[
				'name' => 'Pipelinq 1.4.0',
				'tag_name' => 'v1.4.0',
				'description' => "## Changes\n- GitLab sources",
				'created_at' => '2026-10-01T08:00:00.000Z',
				'released_at' => '2026-10-02T09:30:00.000Z',
				'upcoming_release' => false,
				'assets' => [
					'count' => 4,
					'sources' => [
						['format' => 'zip', 'url' => 'https://gitlab.example.org/group/sub/app/-/archive/v1.4.0/app-v1.4.0.zip'],
					],
					'links' => [
						[
							'id' => 11,
							'name' => 'app-1.4.0.tar.gz',
							'url' => 'https://gitlab.example.org/group/sub/app/-/package_files/9/download',
							'direct_asset_url' => 'https://gitlab.example.org/group/sub/app/-/releases/v1.4.0/downloads/app-1.4.0.tar.gz',
							'link_type' => 'package',
						],
						[
							'id' => 12,
							'name' => 'app-1.4.0.tar.gz.sha256',
							'url' => 'https://gitlab.example.org/group/sub/app/-/package_files/10/download',
							'link_type' => 'other',
						],
					],
				],
			],
			[
				'tag_name' => '1.3.0',
				'description' => '   ',
				'released_at' => '2026-09-01T09:00:00.000Z',
				'assets' => ['links' => []],
			],
			[
				'tag_name' => 'v1.5.0',
				'description' => 'Next',
				'released_at' => '2026-12-01T09:00:00.000Z',
				'upcoming_release' => true,
				'assets' => ['links' => [['name' => 'app-1.5.0.tar.gz', 'url' => 'https://gitlab.example.org/x']]],
			],
		];
	}

	/**
	 * The source over a client that answers with the recorded payload.
	 *
	 * @return ForgeReleaseSource
	 */
	private function source(): ForgeReleaseSource {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(200);
		$response->method('getBody')->willReturn(json_encode(self::recordedReleases(), JSON_THROW_ON_ERROR));

		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(function (string $url) use ($response): IResponse {
			$this->requested = $url;
			return $response;
		});
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$patResolver = $this->createMock(PatResolver::class);
		$patResolver->method('findFor')->willReturn(null);
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);

		return $this->buildByName(ForgeReleaseSource::class, [
			'clientService' => $clientService,
			'logger' => $this->createMock(LoggerInterface::class),
			'patResolver' => $patResolver,
			'patManager' => $this->createMock(PatManager::class),
			'userSession' => $userSession,
			'forgeRegistry' => new ForgeRegistry($this->createMock(\OCP\IAppConfig::class)),
			'config' => $this->createMock(\OCP\IConfig::class),
		]);
	}

	/**
	 * Versions, changelogs and dates come from tag_name, description and released_at.
	 *
	 * @return void
	 */
	public function testVersionsAndChangelogsFromAGitlabPayload(): void {
		$result = $this->source()->listVersions('app', SourceBinding::gitlab('group/sub/app'));

		$this->assertNull($result['error']);
		$this->assertStringContainsString('/projects/group%2Fsub%2Fapp/releases', (string)$this->requested);
		$byVersion = array_column($result['versions'], null, 'version');
		$this->assertSame(['1.5.0', '1.4.0', '1.3.0'], array_column($result['versions'], 'version'));
		$this->assertSame("## Changes\n- GitLab sources", $byVersion['1.4.0']['changelog']);
		$this->assertNull($byVersion['1.3.0']['changelog']);
		$this->assertNotNull($byVersion['1.4.0']['releasedAt']);
		$this->assertStringStartsWith('2026-10-02', (string)$byVersion['1.4.0']['releasedAt']);
		$this->assertNull($byVersion['1.5.0']['releasedAt'], 'An upcoming release is not released yet.');
	}

	/**
	 * The matching link is the download (its direct asset URL) and its sibling .sha256 the checksum.
	 *
	 * @return void
	 */
	public function testTheSelectedAssetAndItsChecksum(): void {
		$release = $this->source()->resolveRelease('app', '1.4.0', SourceBinding::gitlab('group/sub/app'));

		$this->assertNotNull($release);
		$this->assertSame('1.4.0', $release['version']);
		$this->assertSame('https://gitlab.example.org/group/sub/app/-/releases/v1.4.0/downloads/app-1.4.0.tar.gz', $release['download']);
		$this->assertSame('https://gitlab.example.org/group/sub/app/-/package_files/10/download', $release['sha256Url']);
	}

	/**
	 * A release without a matching link cannot be installed, as for GitHub.
	 *
	 * @return void
	 */
	public function testAReleaseWithoutAMatchingLinkIsNotInstallable(): void {
		$release = $this->source()->resolveRelease('app', '1.3.0', SourceBinding::gitlab('group/sub/app'));

		$this->assertTrue($release === null || ($release['download'] ?? null) === null);
	}

	/**
	 * The normaliser keeps non-release entries as they are and drops malformed links' URLs.
	 *
	 * @return void
	 */
	public function testTheNormaliserIsDefensive(): void {
		$this->assertSame('x', ForgeReleaseSource::fromGitlabRelease('x'));
		$this->assertSame(
			['tag_name' => 'v1', 'body' => null, 'published_at' => null, 'assets' => [['name' => 'a', 'browser_download_url' => null]]],
			ForgeReleaseSource::fromGitlabRelease(['tag_name' => 'v1', 'assets' => ['links' => [['name' => 'a', 'url' => ''], 'junk']]])
		);
	}
}
