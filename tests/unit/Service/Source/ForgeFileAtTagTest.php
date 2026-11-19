<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * inventory-upgrade-readiness task 1.2: one file read from the forge at a
 * release tag, through the contents endpoint, without the release archive.
 *
 * @spec openspec/changes/inventory-upgrade-readiness/specs/upgrade-readiness/spec.md#requirement-a-forge-releases-ranges-are-read-without-downloading-the-release
 */

declare(strict_types=1);

namespace OCA\Versioniq\Tests\Unit\Service\Source;

require_once __DIR__ . '/../../Support/BuildsByParameterName.php';

use Exception;
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
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Versioniq\Service\Source\ForgeReleaseSource
 * @covers \OCA\Versioniq\Service\Source\Forge
 */
final class ForgeFileAtTagTest extends TestCase {
	use BuildsByParameterName;

	/** @var list<string> */
	private array $urls = [];

	/**
	 * A source whose client answers each requested URL from the map, in order of the patterns.
	 *
	 * @param array<string, IResponse|Exception> $answers URL suffix => answer.
	 */
	private function source(array $answers): ForgeReleaseSource {
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(
			function (string $url) use ($answers): IResponse {
				$this->urls[] = $url;
				foreach ($answers as $suffix => $answer) {
					if (str_ends_with($url, $suffix)) {
						if ($answer instanceof Exception) {
							throw $answer;
						}
						return $answer;
					}
				}
				return $this->response(404, '{"message":"Not Found"}');
			}
		);
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

	private function response(int $status, string $body): IResponse {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getBody')->willReturn($body);

		return $response;
	}

	private function contents(string $text): IResponse {
		return $this->response(200, json_encode(['type' => 'file', 'encoding' => 'base64', 'content' => chunk_split(base64_encode($text), 60, "\n")], JSON_THROW_ON_ERROR));
	}

	public function testTheFileIsReadAtTheVTagFromTheContentsEndpoint(): void {
		$text = $this->source(['/contents/appinfo/info.xml?ref=v1.4.0' => $this->contents('<info/>')])
			->infoXmlAtTag(SourceBinding::github('ConductionNL', 'hermiq'), '1.4.0');

		$this->assertSame('<info/>', $text);
		$this->assertSame(['https://api.github.com/repos/ConductionNL/hermiq/contents/appinfo/info.xml?ref=v1.4.0'], $this->urls);
	}

	public function testABareTagIsTriedWhenTheVTagHasNoFile(): void {
		$text = $this->source(['?ref=1.4.0' => $this->contents('<info>bare</info>')])
			->infoXmlAtTag(SourceBinding::github('ConductionNL', 'hermiq'), '1.4.0');

		$this->assertSame('<info>bare</info>', $text);
		$this->assertCount(2, $this->urls);
		$this->assertStringEndsWith('?ref=1.4.0', $this->urls[1]);
	}

	public function testNoFileAtEitherTagOrAnUnreadableAnswerIsEmpty(): void {
		$binding = SourceBinding::github('ConductionNL', 'hermiq');

		$this->assertSame('', $this->source([])->infoXmlAtTag($binding, '1.4.0'));
		$this->assertSame('', $this->source(['?ref=v1.4.0' => $this->response(200, '[]')])->infoXmlAtTag($binding, '1.4.0'));
	}

	public function testAFailedRequestIsNullSoTheCallerAsksAgainLater(): void {
		$binding = SourceBinding::github('ConductionNL', 'hermiq');

		$this->assertNull($this->source(['?ref=v1.4.0' => $this->response(403, '{}')])->infoXmlAtTag($binding, '1.4.0'));
		$this->assertNull($this->source(['?ref=v1.4.0' => new Exception('timed out')])->infoXmlAtTag($binding, '1.4.0'));
	}

	public function testAnAppStoreBindingHasNoForgeFile(): void {
		$this->assertSame('', $this->source([])->infoXmlAtTag(SourceBinding::appStore(), '1.4.0'));
		$this->assertSame([], $this->urls);
	}
}
