<?php

/**
 * Unit tests for the `/api/v1/` twins of the API routes and the paths openapi.json describes.
 *
 * @category Test
 * @package  OCA\Versioniq\Tests\Unit\Middleware\Support
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/versioniq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Versioniq\Tests\Unit\Middleware\Support;

use OCA\Versioniq\Middleware\Support\ApiPathAliases;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Every unversioned `#[ApiRoute]` has a `/api/v1/` twin on the same method,
 * and openapi.json describes exactly the versioned routes.
 *
 * @covers \OCA\Versioniq\Middleware\Support\ApiPathAliases
 * @uses   \OCA\Versioniq\Middleware\Support\StandardProtocolPaths
 */
class VersionedRoutesTest extends TestCase {

	/**
	 * Paths and their successor, null when the path is not deprecated.
	 *
	 * @return array<string,array{0:string,1:string|null}>
	 */
	public static function successors(): array {
		return [
			'api path' => ['/api/apps', '/api/v1/apps'],
			'request path' => ['/ocs/v2.php/apps/versioniq/api/app/a/pin', '/ocs/v2.php/apps/versioniq/api/v1/app/a/pin'],
			'already v1' => ['/api/v1/apps', null],
			'protocol' => ['/api/health', null],
			'page' => ['/apps/versioniq/settings', null],
		];
	}//end successors()

	/**
	 * Each path has the expected successor.
	 *
	 * @param string $path The path.
	 * @param string|null $successor The expected successor.
	 *
	 * @return void
	 */
	#[DataProvider('successors')]
	public function testTheSuccessorOfAPath(string $path, ?string $successor): void {
		$this->assertSame(expected: $successor, actual: ApiPathAliases::successorOf(path: $path));
	}//end testTheSuccessorOfAPath()

	/**
	 * Every method with an unversioned API route also carries its `/api/v1/` twin, postfixed `v1`.
	 *
	 * @return void
	 */
	public function testEveryApiRouteHasAVersionedTwin(): void {
		$twins = 0;
		foreach (self::routesByMethod() as $method => $routes) {
			foreach ($routes as $route) {
				if (ApiPathAliases::isDeprecated(path: $route['url']) === false) {
					continue;
				}

				$twin = [
					'verb' => $route['verb'],
					'url' => (string)ApiPathAliases::successorOf(path: $route['url']),
					'postfix' => 'v1',
				];
				$this->assertContains(needle: $twin, haystack: $routes, message: $method . ' has no twin of ' . $route['verb'] . ' ' . $route['url']);
				$twins++;
			}
		}

		$this->assertGreaterThan(expected: 0, actual: $twins);
	}//end testEveryApiRouteHasAVersionedTwin()

	/**
	 * openapi.json names the versioned server and describes every versioned route, and no other.
	 *
	 * @return void
	 */
	public function testTheDocumentDescribesTheVersionedRoutes(): void {
		$document = json_decode((string)file_get_contents(dirname(__DIR__, 4) . '/openapi.json'), true);
		$this->assertIsArray(actual: $document);
		$this->assertStringEndsWith(suffix: '/ocs/v2.php/apps/versioniq/api/v1', string: $document['servers'][0]['url']);

		$documented = [];
		foreach ($document['paths'] as $path => $item) {
			foreach (array_keys($item) as $verb) {
				$documented[] = strtoupper($verb) . ' ' . self::shape(path: '/api/v1' . $path);
			}
		}

		$routed = [];
		foreach (self::routesByMethod() as $routes) {
			foreach ($routes as $route) {
				if (str_starts_with($route['url'], '/api/v1/') === true) {
					$routed[] = $route['verb'] . ' ' . self::shape(path: $route['url']);
				}
			}
		}

		sort($documented);
		sort($routed);
		$this->assertSame(expected: $routed, actual: $documented);
	}//end testTheDocumentDescribesTheVersionedRoutes()

	/**
	 * The `#[ApiRoute]` attributes of every controller method.
	 *
	 * @return array<string,list<array{verb:string,url:string,postfix:string|null}>> The routes by method.
	 */
	private static function routesByMethod(): array {
		$routes = [];
		foreach ((array)glob(dirname(__DIR__, 4) . '/lib/Controller/*Controller.php') as $file) {
			$class = new ReflectionClass('OCA\\Versioniq\\Controller\\' . basename((string)$file, '.php'));
			foreach ($class->getMethods() as $method) {
				foreach ($method->getAttributes(ApiRoute::class) as $attribute) {
					$arguments = $attribute->getArguments();
					$routes[$class->getShortName() . '::' . $method->getName()][] = [
						'verb' => (string)$arguments['verb'],
						'url' => (string)$arguments['url'],
						'postfix' => ($arguments['postfix'] ?? null),
					];
				}
			}
		}

		return $routes;
	}//end routesByMethod()

	/**
	 * A path with its placeholder names left out.
	 *
	 * @param string $path The path.
	 *
	 * @return string The shape of the path.
	 */
	private static function shape(string $path): string {
		return (string)preg_replace('#\{[^}]+\}#', '{}', $path);
	}//end shape()
}//end class
