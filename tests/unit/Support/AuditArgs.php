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

use OCA\Versioniq\Service\Audit\AuditLogger;
use PHPUnit\Framework\Constraint\Callback;
use PHPUnit\Framework\Constraint\Constraint;
use PHPUnit\Framework\Constraint\IsEqual;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Reads and matches AuditLogger::record() calls as the eight values of an
 * audit entry: actor, app, operation, from version, to version, source,
 * status and message. The values may arrive as plain arguments or grouped in
 * value objects; tests written against the eight values hold for both.
 */
final class AuditArgs {
	/** @var array<string, list<string>> value object class => property names, in entry order */
	private const GROUPS = [
		'OCA\\Versioniq\\Service\\Audit\\AuditSubject' => ['actorUid', 'appId', 'operation'],
		'OCA\\Versioniq\\Service\\Audit\\AuditedVersions' => ['fromVersion', 'toVersion', 'sourceId'],
	];

	/**
	 * The eight entry values of one record() call.
	 *
	 * @param list<mixed> $args
	 * @return list<mixed>
	 */
	public static function flatten(array $args): array {
		// A mocked record() receives the default of every omitted optional
		// parameter. An entry about no advisory carries `advisories: null`;
		// leave it out so the flattened entry keeps its pre-advisory shape.
		$advisories = self::position('advisories');
		if ($advisories !== null && array_key_exists($advisories, $args) && $args[$advisories] === null) {
			unset($args[$advisories]);
		}

		$flat = [];
		foreach ($args as $arg) {
			$properties = is_object($arg) ? (self::GROUPS[$arg::class] ?? null) : null;
			if ($properties === null) {
				$flat[] = $arg;
				continue;
			}
			foreach ($properties as $property) {
				$flat[] = $arg->{$property};
			}
		}

		return $flat;
	}

	/**
	 * with() arguments for record() that match the given leading entry values
	 * (plain values or constraints), whichever shape record() has.
	 *
	 * @return list<mixed>
	 */
	public static function expected(mixed ...$values): array {
		$with = [];
		$offset = 0;
		foreach ((new ReflectionMethod(AuditLogger::class, 'record'))->getParameters() as $parameter) {
			if ($offset >= count($values)) {
				break;
			}
			$type = $parameter->getType();
			$properties = $type instanceof ReflectionNamedType ? (self::GROUPS[$type->getName()] ?? null) : null;
			if ($properties === null) {
				$with[] = $values[$offset];
				$offset++;
				continue;
			}
			$group = array_slice($values, $offset, count($properties));
			$with[] = self::groupMatcher($properties, $group);
			$offset += count($properties);
		}

		return $with;
	}

	/**
	 * Calls $logger->record() with the eight entry values, grouped the way
	 * record() takes them.
	 */
	public static function call(AuditLogger $logger, mixed ...$values): void {
		$args = [];
		$offset = 0;
		foreach ((new ReflectionMethod(AuditLogger::class, 'record'))->getParameters() as $parameter) {
			if ($offset >= count($values)) {
				break;
			}
			$type = $parameter->getType();
			$class = $type instanceof ReflectionNamedType ? $type->getName() : '';
			$properties = self::GROUPS[$class] ?? null;
			if ($properties === null) {
				$args[] = $values[$offset];
				$offset++;
				continue;
			}
			$args[] = new $class(...array_slice($values, $offset, count($properties)));
			$offset += count($properties);
		}

		$logger->record(...$args);
	}

	/**
	 * @param list<string> $properties
	 * @param list<mixed> $expected
	 */
	private static function groupMatcher(array $properties, array $expected): Callback {
		return new Callback(static function (mixed $actual) use ($properties, $expected): bool {
			if (!is_object($actual)) {
				return false;
			}
			foreach ($expected as $index => $value) {
				$constraint = $value instanceof Constraint ? $value : new IsEqual($value);
				if ($constraint->evaluate($actual->{$properties[$index]}, '', true) !== true) {
					return false;
				}
			}

			return true;
		});
	}

	/**
	 * The message a record() call was given, by the parameter's position.
	 *
	 * @param list<mixed> $args The arguments a mocked record() received.
	 */
	public static function message(array $args): mixed {
		return $args[self::position('message')] ?? null;
	}

	/**
	 * The position of a record() parameter, or null when it has none by that name.
	 */
	private static function position(string $name): ?int {
		foreach ((new ReflectionMethod(AuditLogger::class, 'record'))->getParameters() as $parameter) {
			if ($parameter->getName() === $name) {
				return $parameter->getPosition();
			}
		}

		return null;
	}
}
