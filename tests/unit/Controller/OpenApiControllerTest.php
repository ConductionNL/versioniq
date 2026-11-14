<?php

/**
 * Unit tests for the OpenAPI document controller.
 *
 * @category Test
 * @package  OCA\Versioniq\Tests\Unit\Controller
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/versioniq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Versioniq\Tests\Unit\Controller;

use OCA\Versioniq\Controller\OpenApiController;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Pins what GET /api/openapi.json serves.
 *
 * @covers \OCA\Versioniq\Controller\OpenApiController
 * @uses \OCA\Versioniq\Service\Installer\AppAutoloader
 */
class OpenApiControllerTest extends TestCase {

	/**
	 * Build the controller over an app directory.
	 *
	 * @param string $appPath The app directory.
	 *
	 * @return OpenApiController The controller.
	 */
	private function controller(string $appPath): OpenApiController {
		$appManager = $this->createMock(originalClassName: IAppManager::class);
		$appManager->method('getAppPath')->willReturn($appPath);
		$appManager->method('getAppVersion')->willReturn('9.8.7');

		return new OpenApiController(
			request: $this->createMock(originalClassName: IRequest::class),
			appManager: $appManager
		);
	}

	/**
	 * The shipped document is served with the installed version, readable from any origin.
	 *
	 * @return void
	 */
	public function testServesTheShippedDocument(): void {
		$response = $this->controller(appPath: dirname(__DIR__, 3))->show();
		$document = json_decode((string)json_encode($response->getData()), true);

		$this->assertSame(expected: 200, actual: $response->getStatus());
		$this->assertSame(expected: '*', actual: $this->headersOf(response: $response)['Access-Control-Allow-Origin']);
		$this->assertSame(expected: '9.8.7', actual: $document['info']['version']);
		$this->assertArrayHasKey(key: '/openapi.json', array: $document['paths']);
	}

	/**
	 * Empty JSON objects in the document stay objects when served.
	 *
	 * @return void
	 */
	public function testEmptyObjectsStayObjects(): void {
		$dir = sys_get_temp_dir() . '/openapi-' . uniqid();
		mkdir($dir);
		file_put_contents($dir . '/openapi.json', '{"openapi":"3.0.3","info":{"version":"0"},"paths":{"/x":{"get":{"security":[{}]}}}}');

		$response = $this->controller(appPath: $dir)->show();
		unlink($dir . '/openapi.json');
		rmdir($dir);

		$this->assertStringContainsString(needle: '"security":[{}]', haystack: (string)json_encode($response->getData()));
	}

	/**
	 * Without a document the endpoint answers 404 rather than an empty document.
	 *
	 * @return void
	 */
	public function testMissingDocumentIsNotFound(): void {
		$response = $this->controller(appPath: sys_get_temp_dir() . '/no-such-app-' . uniqid())->show();

		$this->assertSame(expected: 404, actual: $response->getStatus());
		$this->assertSame(expected: '*', actual: $this->headersOf(response: $response)['Access-Control-Allow-Origin']);
	}

	/**
	 * Documents that are not JSON, or have no `info` object.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function unusableDocuments(): array {
		return [
			'not json' => ['{"openapi":'],
			'no info' => ['{"openapi":"3.0.3","paths":{}}'],
			'info not an object' => ['{"openapi":"3.0.3","info":"1.0","paths":{}}'],
			'a list' => ['[{"info":{}}]'],
		];
	}//end unusableDocuments()

	/**
	 * A document that cannot be read as an OpenAPI document is not found rather than served.
	 *
	 * @param string $contents The document's contents.
	 *
	 * @return void
	 */
	#[DataProvider('unusableDocuments')]
	public function testAnUnusableDocumentIsNotFound(string $contents): void {
		$dir = sys_get_temp_dir() . '/openapi-' . uniqid();
		mkdir($dir);
		file_put_contents($dir . '/openapi.json', $contents);

		$response = $this->controller(appPath: $dir)->show();
		unlink($dir . '/openapi.json');
		rmdir($dir);

		$this->assertSame(expected: 404, actual: $response->getStatus());
		$this->assertSame(expected: ['error' => 'The OpenAPI document is not installed'], actual: $response->getData());
	}//end testAnUnusableDocumentIsNotFound()

	/**
	 * The headers a response set, read without the server container that
	 * Response::getHeaders() needs.
	 *
	 * @param Response $response The response.
	 *
	 * @return array<string,string> The headers by name.
	 */
	private function headersOf(Response $response): array {
		$property = new ReflectionProperty(Response::class, 'headers');

		return $property->getValue($response);
	}
}
