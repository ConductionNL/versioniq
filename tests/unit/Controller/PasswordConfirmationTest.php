<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Controller;

use OCA\Versioniq\Controller\ApiController;
use OCA\Versioniq\Controller\ForgeController;
use OCA\Versioniq\Controller\SettingsController;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\Attribute\Route;
use PHPUnit\Framework\TestCase;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;

/**
 * Every write route asks for the admin password (write-guards-and-advisory-alerts,
 * version-management "Every write route asks for the admin password").
 *
 * Reflects over the route attributes of the three controllers that carry write
 * routes, so a route added without `#[PasswordConfirmationRequired]` fails here
 * by name.
 *
 * @coversNothing
 * @spec openspec/changes/write-guards-and-advisory-alerts/specs/version-management/spec.md
 */
final class PasswordConfirmationTest extends TestCase {
	private const WRITE_VERBS = ['POST', 'PUT', 'PATCH', 'DELETE'];

	private const CONTROLLERS = [ApiController::class, ForgeController::class, SettingsController::class];

	/**
	 * The write routes of a controller as "METHOD verb url" by method name.
	 *
	 * @param class-string $controller
	 * @return array<string, list<string>>
	 */
	private static function writeRoutes(string $controller): array {
		$routes = [];
		foreach ((new ReflectionClass($controller))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
			foreach ($method->getAttributes(Route::class, ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
				$arguments = $attribute->getArguments();
				$verb = strtoupper((string)($arguments['verb'] ?? $arguments[1] ?? 'GET'));
				if (in_array($verb, self::WRITE_VERBS, true)) {
					$routes[$method->getName()][] = $verb . ' ' . (string)($arguments['url'] ?? $arguments[2] ?? '');
				}
			}
		}

		return $routes;
	}

	public function testEveryWriteRouteAsksForThePassword(): void {
		$missing = [];
		$count = 0;
		foreach (self::CONTROLLERS as $controller) {
			foreach (self::writeRoutes($controller) as $methodName => $routes) {
				$count++;
				$method = new ReflectionMethod($controller, $methodName);
				if ($method->getAttributes(PasswordConfirmationRequired::class) === []) {
					$missing[] = (new ReflectionClass($controller))->getShortName() . '::' . $methodName . ' (' . implode(', ', $routes) . ')';
				}
			}
		}

		$this->assertGreaterThan(0, $count, 'no write routes found: the reflection no longer sees the route attributes');
		$this->assertSame([], $missing, 'write routes without #[PasswordConfirmationRequired]');
	}

	public function testTheGuardSeesARouteWithoutTheAttribute(): void {
		$controller = new class {
			#[\OCP\AppFramework\Http\Attribute\ApiRoute(verb: 'POST', url: '/api/x')]
			public function unguarded(): void {
			}

			#[\OCP\AppFramework\Http\Attribute\ApiRoute(verb: 'GET', url: '/api/y')]
			public function read(): void {
			}
		};

		$this->assertSame(['unguarded' => ['POST /api/x']], self::writeRoutes($controller::class));
	}
}
