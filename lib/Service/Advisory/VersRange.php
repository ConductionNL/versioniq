<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Advisory;

/**
 * Evaluates a `vers:` version range, the form CSAF documents such as NCSC-NL's
 * use in `product_version_range` (advisories-ncsc-feed D4).
 *
 * Comparators `<`, `<=`, `>`, `>=`, `=` and `!=` joined by `|`, with `*` for
 * every version, evaluated with the VERS algorithm: `=` and `!=` decide first,
 * and the remaining constraints, sorted by version, form intervals read in
 * pairs. A range that does not say which versions it covers
 * (`vers:unknown/...`) or cannot be parsed answers null, never a guess: a
 * document that does not state its versions must not mark an installed
 * version affected.
 *
 * @spec openspec/changes/advisories-ncsc-feed/design.md
 * @psalm-api
 */
final class VersRange {

	/** @var list<string> Longest first, so `<=` is not read as `<`. */
	private const COMPARATORS = ['<=', '>=', '!=', '<', '>', '='];

	/**
	 * Whether the version is in the range, or null when the range does not say.
	 *
	 * @param string $vers The range, e.g. `vers:generic/>=27.0.0|<27.1.11`.
	 * @param string $version The installed version.
	 *
	 * @return bool|null True inside, false outside, null when unknown or unparseable.
	 *
	 * @spec openspec/changes/advisories-ncsc-feed/design.md
	 */
	public static function matches(string $vers, string $version): ?bool {
		$constraints = self::parse(vers: $vers);
		$installed = self::version(raw: $version);
		if ($constraints === null || $installed === null) {
			return null;
		}

		return self::decide(constraints: $constraints, installed: $installed);
	}

	/**
	 * The verdict for parsed constraints on a valid version.
	 *
	 * `*` holds every version. An `=` or `!=` on the installed version decides
	 * at once, in order. With only `=` and `!=` left, a `!=` stands for
	 * "everything but" and an `=` that missed is outside. The rest is read as
	 * VERS intervals.
	 *
	 * @param list<array{0: string, 1: string}> $constraints The constraints.
	 * @param string $installed The installed version.
	 *
	 * @return bool True inside, false outside.
	 */
	private static function decide(array $constraints, string $installed): bool {
		if ($constraints === []) {
			return true;
		}

		$equality = self::equalityVerdict(constraints: $constraints, installed: $installed);
		if ($equality !== null) {
			return $equality;
		}

		$ordered = self::ordered(constraints: $constraints);
		if ($ordered === []) {
			return in_array('!=', array_column($constraints, 0), true);
		}

		return self::inIntervals(constraints: $ordered, version: $installed);
	}

	/**
	 * The verdict of the first `=` or `!=` on the installed version, if any.
	 *
	 * @param list<array{0: string, 1: string}> $constraints The constraints.
	 * @param string $installed The installed version.
	 *
	 * @return bool|null True for `=`, false for `!=`, null when none names this version.
	 */
	private static function equalityVerdict(array $constraints, string $installed): ?bool {
		foreach ($constraints as [$comparator, $bound]) {
			if (in_array($comparator, ['=', '!='], true) === true && version_compare($installed, $bound, '==') === true) {
				return $comparator === '=';
			}
		}

		return null;
	}

	/**
	 * The `<`, `<=`, `>`, `>=` constraints, sorted by version.
	 *
	 * @param list<array{0: string, 1: string}> $constraints The constraints.
	 *
	 * @return list<array{0: string, 1: string}> The ordered constraints.
	 */
	private static function ordered(array $constraints): array {
		$ordered = array_values(array_filter(
			$constraints,
			static fn (array $c): bool => in_array($c[0], ['=', '!='], true) === false
		));
		usort($ordered, static fn (array $x, array $y): int => version_compare($x[1], $y[1]));

		return $ordered;
	}

	/**
	 * The VERS pairwise reading of sorted `<`, `<=`, `>`, `>=` constraints.
	 *
	 * @param list<array{0: string, 1: string}> $constraints Sorted by version.
	 * @param string $version The installed version.
	 *
	 * @return bool Whether the version falls in one of the intervals.
	 */
	private static function inIntervals(array $constraints, string $version): bool {
		$count = count($constraints);
		if ($count === 1) {
			return self::satisfies(version: $version, constraint: $constraints[0]);
		}

		for ($i = 0; $i < $count - 1; $i++) {
			if (self::pairAdmits(constraints: $constraints, index: $i, version: $version) === true) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the pair at this index admits the version: an open start below
	 * the first bound, an open end above the last, or a closed interval.
	 *
	 * @param list<array{0: string, 1: string}> $constraints Sorted by version.
	 * @param int $index The index of the pair's first constraint.
	 * @param string $version The installed version.
	 *
	 * @return bool Whether the pair admits it.
	 */
	private static function pairAdmits(array $constraints, int $index, string $version): bool {
		$current = $constraints[$index];
		$next = $constraints[$index + 1];

		return ($index === 0 && self::admitsUpTo(constraint: $current, version: $version))
			|| ($index === count($constraints) - 2 && self::admitsFrom(constraint: $next, version: $version))
			|| self::betweenAdmits(current: $current, next: $next, version: $version);
	}

	/**
	 * Whether an upper bound (`<`, `<=`) admits the version.
	 *
	 * @param array{0: string, 1: string} $constraint The constraint.
	 * @param string $version The version.
	 *
	 * @return bool The answer; false for a lower bound.
	 */
	private static function admitsUpTo(array $constraint, string $version): bool {
		return self::isUpper(constraint: $constraint) && self::satisfies(version: $version, constraint: $constraint);
	}

	/**
	 * Whether a lower bound (`>`, `>=`) admits the version.
	 *
	 * @param array{0: string, 1: string} $constraint The constraint.
	 * @param string $version The version.
	 *
	 * @return bool The answer; false for an upper bound.
	 */
	private static function admitsFrom(array $constraint, string $version): bool {
		return self::isLower(constraint: $constraint) && self::satisfies(version: $version, constraint: $constraint);
	}

	/**
	 * Whether a lower bound followed by an upper bound both admit the version.
	 *
	 * @param array{0: string, 1: string} $current The first constraint.
	 * @param array{0: string, 1: string} $next The second constraint.
	 * @param string $version The version.
	 *
	 * @return bool The answer.
	 */
	private static function betweenAdmits(array $current, array $next, string $version): bool {
		return self::isUpper(constraint: $current) === false && self::isLower(constraint: $next) === false
			&& self::satisfies(version: $version, constraint: $current)
			&& self::satisfies(version: $version, constraint: $next);
	}

	/**
	 * Whether a constraint is an upper bound.
	 *
	 * @param array{0: string, 1: string} $constraint The constraint.
	 *
	 * @return bool True for `<` and `<=`.
	 */
	private static function isUpper(array $constraint): bool {
		return in_array($constraint[0], ['<', '<='], true);
	}

	/**
	 * Whether a constraint is a lower bound.
	 *
	 * @param array{0: string, 1: string} $constraint The constraint.
	 *
	 * @return bool True for `>` and `>=`.
	 */
	private static function isLower(array $constraint): bool {
		return in_array($constraint[0], ['>', '>='], true);
	}

	/**
	 * Whether a version satisfies one comparator.
	 *
	 * @param string $version The version.
	 * @param array{0: string, 1: string} $constraint The comparator and bound.
	 *
	 * @return bool The answer.
	 */
	private static function satisfies(string $version, array $constraint): bool {
		$operator = ['<' => '<', '<=' => '<=', '>' => '>', '>=' => '>='][$constraint[0]];
		return version_compare($version, $constraint[1], $operator);
	}

	/**
	 * The constraints of a range: [] for `*`, null when unknown or unparseable.
	 *
	 * @param string $vers The range.
	 *
	 * @return list<array{0: string, 1: string}>|null The constraints.
	 */
	private static function parse(string $vers): ?array {
		$body = self::body(vers: $vers);
		if ($body === null) {
			return null;
		}

		if ($body === '*') {
			return [];
		}

		$constraints = [];
		foreach (explode('|', $body) as $raw) {
			$constraint = self::constraint(raw: $raw);
			if ($constraint === null) {
				return null;
			}

			$constraints[] = $constraint;
		}

		return $constraints;
	}

	/**
	 * The part of a range after its scheme, or null when it is not a range or its scheme is unknown.
	 *
	 * @param string $vers The range.
	 *
	 * @return string|null The constraints part, trimmed.
	 */
	private static function body(string $vers): ?string {
		if (preg_match('#^vers:([a-z0-9.+-]+)/(.+)$#i', trim($vers), $m) !== 1 || strtolower($m[1]) === 'unknown') {
			return null;
		}

		return trim($m[2]);
	}

	/**
	 * One constraint: its comparator, `=` when it names none, and its bound.
	 *
	 * @param string $raw The constraint as written.
	 *
	 * @return array{0: string, 1: string}|null The constraint, or null when the bound is not a version.
	 */
	private static function constraint(string $raw): ?array {
		[$comparator, $rest] = self::comparator(raw: trim($raw));
		$bound = self::version(raw: $rest);
		if ($bound === null) {
			return null;
		}

		return [$comparator, $bound];
	}

	/**
	 * The comparator a constraint starts with and what follows it.
	 *
	 * @param string $raw The trimmed constraint.
	 *
	 * @return array{0: string, 1: string} The comparator and the rest.
	 */
	private static function comparator(string $raw): array {
		foreach (self::COMPARATORS as $candidate) {
			if (str_starts_with($raw, $candidate) === true) {
				return [$candidate, substr($raw, strlen($candidate))];
			}
		}

		return ['=', $raw];
	}

	/**
	 * A version as `version_compare` reads it, or null when it is not one.
	 *
	 * @param string $raw The text.
	 *
	 * @return string|null The version.
	 */
	private static function version(string $raw): ?string {
		$raw = ltrim(trim($raw), 'vV');
		if (preg_match('/^\d+(\.\d+)*([.-]?[0-9A-Za-z.]+)?$/', $raw) !== 1) {
			return null;
		}
		return $raw;
	}
}
