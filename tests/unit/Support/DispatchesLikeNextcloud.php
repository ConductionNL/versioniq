<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Support;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use ReflectionMethod;
use TypeError;

/**
 * Calls a controller method the way Nextcloud's Dispatcher does: each
 * declared parameter is read from the request with getParam(name, default),
 * and a TypeError raised by the call itself (a request value of the wrong
 * type) is answered with an empty 400.
 *
 * A test that goes through this trait sees the same request values whether a
 * controller declares them as method parameters or reads them itself.
 */
trait DispatchesLikeNextcloud {
	/**
	 * @param array<string, mixed> $params the merged route, query and body parameters
	 */
	private function requestWith(array $params): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => $params[$key] ?? $default,
		);
		$request->method('getParams')->willReturn($params);

		return $request;
	}

	private function dispatch(object $controller, string $method, IRequest $request): Response {
		$reflection = new ReflectionMethod($controller, $method);
		$arguments = [];
		foreach ($reflection->getParameters() as $parameter) {
			$arguments[] = $request->getParam($parameter->getName(), $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null);
		}

		try {
			// call_user_func_array() from a strict_types file, as in the Dispatcher:
			// a reflection call would coerce scalars instead of raising the TypeError.
			$response = \call_user_func_array([$controller, $method], $arguments);
		} catch (TypeError $error) {
			if ($error->getFile() === $reflection->getFileName() && $error->getLine() === $reflection->getStartLine()) {
				return new Response(Http::STATUS_BAD_REQUEST);
			}
			throw $error;
		}
		self::assertInstanceOf(Response::class, $response);

		return $response;
	}

	/**
	 * @return array{class: string, status: int, data: mixed}
	 */
	private static function describe(Response $response): array {
		return [
			'class' => (new \ReflectionClass($response))->getShortName(),
			'status' => $response->getStatus(),
			'data' => method_exists($response, 'getData') ? $response->getData() : null,
		];
	}
}
