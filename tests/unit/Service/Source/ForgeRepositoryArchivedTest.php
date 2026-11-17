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
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The forge's `archived` flag on the repository, read with one request.
 *
 * @spec openspec/changes/inventory-lifecycle-signals/specs/lifecycle-signals/spec.md#requirement-abandoned-and-unsupported-apps-are-flagged-on-their-card
 */
#[CoversClass(ForgeReleaseSource::class)]
final class ForgeRepositoryArchivedTest extends TestCase {
	use BuildsByParameterName;

	/** @var list<string> */
	private array $requested = [];

	/**
	 * @param int|\Exception $status The answer's status, or what the request throws.
	 */
	private function source(int|\Exception $status, string $body = ''): ForgeReleaseSource {
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(function (string $url) use ($status, $body): IResponse {
			$this->requested[] = $url;
			if ($status instanceof \Exception) {
				throw $status;
			}
			$response = $this->createMock(IResponse::class);
			$response->method('getStatusCode')->willReturn($status);
			$response->method('getBody')->willReturn($body);

			return $response;
		});
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);
		$resolver = $this->createMock(PatResolver::class);
		$resolver->method('findFor')->willReturn(null);

		return $this->buildByName(ForgeReleaseSource::class, [
			'clientService' => $clients,
			'logger' => $this->createMock(LoggerInterface::class),
			'patResolver' => $resolver,
			'userSession' => $this->createMock(IUserSession::class),
			'forgeRegistry' => new ForgeRegistry($this->createMock(\OCP\IAppConfig::class)),
			'config' => $this->createMock(\OCP\IConfig::class),
		]);
	}

	public function testAnArchivedRepositoryIsArchived(): void {
		$archived = $this->source(200, '{"full_name": "ConductionNL/oldapp", "archived": true}')
			->isArchived(SourceBinding::github('ConductionNL', 'oldapp'));

		$this->assertTrue($archived);
		$this->assertSame(['https://api.github.com/repos/ConductionNL/oldapp'], $this->requested);
	}

	public function testAnActiveRepositoryIsNotArchived(): void {
		$this->assertFalse($this->source(200, '{"archived": false}')->isArchived(SourceBinding::github('ConductionNL', 'deck')));
	}

	public function testAnUnansweredOrUnreadableLookupIsUnknown(): void {
		$binding = SourceBinding::github('ConductionNL', 'deck');

		$this->assertNull($this->source(404, '{"message": "Not Found"}')->isArchived($binding));
		$this->assertNull($this->source(200, 'not json')->isArchived($binding));
		$this->assertNull($this->source(200, '{"name": "deck"}')->isArchived($binding));
		$this->assertNull($this->source(new \RuntimeException('offline'))->isArchived($binding));
	}

	public function testAStoreBindingIsNotAskedAbout(): void {
		$this->assertNull($this->source(200, '{"archived": true}')->isArchived(SourceBinding::appStore()));
		$this->assertSame([], $this->requested);
	}
}
