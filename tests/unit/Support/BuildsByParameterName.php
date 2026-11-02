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

use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * Builds a class the way the DI container does: every constructor parameter is
 * filled by its name from the given map, a collaborator bundle (a final
 * versioniq class whose constructor only promotes public properties) is built
 * the same way, a parameter that may be left out keeps its default, and any
 * other object parameter gets a mock.
 *
 * Tests that build their subject through this trait keep working when
 * collaborators move into or out of a bundle, because the bundle fields keep
 * the parameter names.
 */
trait BuildsByParameterName {
	/**
	 * @template T of object
	 * @param class-string<T> $class
	 * @param array<string, mixed> $named collaborators by constructor parameter name
	 * @return T
	 */
	private function buildByName(string $class, array $named = []): object {
		$reflection = new ReflectionClass($class);
		$constructor = $reflection->getConstructor();
		if ($constructor === null) {
			return $reflection->newInstance();
		}

		$args = [];
		foreach ($constructor->getParameters() as $parameter) {
			$args[] = $this->argumentFor($parameter, $named);
		}

		return $reflection->newInstanceArgs($args);
	}

	/**
	 * @param array<string, mixed> $named
	 */
	private function argumentFor(ReflectionParameter $parameter, array $named): mixed {
		if (array_key_exists($parameter->getName(), $named) === true) {
			return $named[$parameter->getName()];
		}

		$type = $parameter->getType();
		$typeName = $type instanceof ReflectionNamedType ? $type->getName() : '';
		if ($typeName !== '' && $this->isCollaboratorBundle($typeName) === true) {
			return $this->buildByName($typeName, $named);
		}

		if ($parameter->isDefaultValueAvailable() === true) {
			return $parameter->getDefaultValue();
		}

		if ($typeName === '' || $type->isBuiltin() === true) {
			throw new \LogicException('No value for constructor parameter $' . $parameter->getName());
		}

		return $this->createMock($typeName);
	}

	private function isCollaboratorBundle(string $class): bool {
		if (str_starts_with($class, 'OCA\\Versioniq\\') === false || class_exists($class) === false) {
			return false;
		}

		$reflection = new ReflectionClass($class);
		$constructor = $reflection->getConstructor();
		if ($reflection->isFinal() === false || $constructor === null) {
			return false;
		}

		foreach ($constructor->getParameters() as $parameter) {
			if ($parameter->isPromoted() === false) {
				return false;
			}
			if ($reflection->getProperty($parameter->getName())->isPublic() === false) {
				return false;
			}
		}

		return true;
	}
}
