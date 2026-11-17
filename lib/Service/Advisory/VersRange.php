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
	 * The VERS answer for parsed constraints: `*` holds every version, `=` and
	 * `!=` decide first, and the ordering constraints form intervals.
	 *
	 * @spec openspec/changes/advisories-ncsc-feed/design.md
	 * @param list<array{0: string, 1: string}> $constraints The constraints.
	 */
	private static function decide(array $constraints, string $installed): bool {
		if ($constraints === []) {
			// `*`: every version.
			return true;
		}

		$equality = self::equality(constraints: $constraints, installed: $installed);
		if ($equality !== null) {
			return $equality;
		}

		$ordered = self::ordered(constraints: $constraints);
		if ($ordered === []) {
			// Only `=` and `!=`: inside when some `!=` stands for "everything
			// but", outside when only `=` clauses missed.
			return in_array('!=', array_column($constraints, 0), true);
		}

		return self::inIntervals(constraints: $ordered, version: $installed);
	}

	/**
	 * True when an `=` names the version, false when a `!=` does, else null.
	 *
	 * @spec openspec/changes/advisories-ncsc-feed/design.md
	 * @param list<array{0: string, 1: string}> $constraints The constraints.
	 */
	private static function equality(array $constraints, string $installed): ?bool {
		foreach ($constraints as [$comparator, $bound]) {
			if (in_array($comparator, ['=', '!='], true) && version_compare($installed, $bound, '==')) {
				return $comparator === '=';
			}
		}

		return null;
	}

	/**
	 * The `<`, `<=`, `>`, `>=` constraints, sorted by version.
	 *
	 * @spec openspec/changes/advisories-ncsc-feed/design.md
	 * @param list<array{0: string, 1: string}> $constraints The constraints.
	 * @return list<array{0: string, 1: string}>
	 */
	private static function ordered(array $constraints): array {
		$ordered = array_values(array_filter(
			$constraints,
			static fn (array $c): bool => !in_array($c[0], ['=', '!='], true)
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
	 *
	 * @spec openspec/changes/advisories-ncsc-feed/design.md
	 */
	private static function inIntervals(array $constraints, string $version): bool {
		$count = count($constraints);
		if ($count === 1) {
			return self::satisfies(version: $version, constraint: $constraints[0]);
		}

		for ($i = 0; $i < $count - 1; $i++) {
			$pair = ['current' => $constraints[$i], 'next' => $constraints[$i + 1], 'first' => $i === 0, 'last' => $i === $count - 2];
			if (self::pairHolds(pair: $pair, version: $version)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether one adjacent pair holds the version: an open lower end before
	 * the first pair, an open upper end after the last, or the closed
	 * interval between a lower and an upper bound.
	 *
	 * @spec openspec/changes/advisories-ncsc-feed/design.md
	 * @param array{current: array{0: string, 1: string}, next: array{0: string, 1: string}, first: bool, last: bool} $pair The pair.
	 */
	private static function pairHolds(array $pair, string $version): bool {
		return self::openBelow(pair: $pair, version: $version)
			|| self::openAbove(pair: $pair, version: $version)
			|| self::between(pair: $pair, version: $version);
	}

	/**
	 * A leading `<` or `<=` the version satisfies.
	 *
	 * @spec openspec/changes/advisories-ncsc-feed/design.md
	 * @param array{current: array{0: string, 1: string}, next: array{0: string, 1: string}, first: bool, last: bool} $pair The pair.
	 */
	private static function openBelow(array $pair, string $version): bool {
		return $pair['first'] && self::isUpper(constraint: $pair['current']) && self::satisfies(version: $version, constraint: $pair['current']);
	}

	/**
	 * A trailing `>` or `>=` the version satisfies.
	 *
	 * @spec openspec/changes/advisories-ncsc-feed/design.md
	 * @param array{current: array{0: string, 1: string}, next: array{0: string, 1: string}, first: bool, last: bool} $pair The pair.
	 */
	private static function openAbove(array $pair, string $version): bool {
		return $pair['last'] && self::isLower(constraint: $pair['next']) && self::satisfies(version: $version, constraint: $pair['next']);
	}

	/**
	 * A lower bound followed by an upper bound, both satisfied.
	 *
	 * @spec openspec/changes/advisories-ncsc-feed/design.md
	 * @param array{current: array{0: string, 1: string}, next: array{0: string, 1: string}, first: bool, last: bool} $pair The pair.
	 */
	private static function between(array $pair, string $version): bool {
		$bounds = !self::isUpper(constraint: $pair['current']) && !self::isLower(constraint: $pair['next']);

		return $bounds && self::satisfies(version: $version, constraint: $pair['current']) && self::satisfies(version: $version, constraint: $pair['next']);
	}

	/**
	 * Whether a constraint is `<` or `<=`.
	 *
	 * @spec openspec/changes/advisories-ncsc-feed/design.md
	 * @param array{0: string, 1: string} $constraint The constraint.
	 */
	private static function isUpper(array $constraint): bool {
		return in_array($constraint[0], ['<', '<='], true);
	}

	/**
	 * Whether a constraint is `>` or `>=`.
	 *
	 * @spec openspec/changes/advisories-ncsc-feed/design.md
	 * @param array{0: string, 1: string} $constraint The constraint.
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
	 *
	 * @spec openspec/changes/advisories-ncsc-feed/design.md
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
	 *
	 * @spec openspec/changes/advisories-ncsc-feed/design.md
	 */
	private static function parse(string $vers): ?array {
		$body = self::body(vers: $vers);
		if ($body === null) {
			return null;
		}

		return $body === '*' ? [] : self::constraints(body: $body);
	}

	/**
	 * The part after `vers:<scheme>/`, or null for `vers:unknown` or anything that is not a range.
	 *
	 * @spec openspec/changes/advisories-ncsc-feed/design.md
	 */
	private static function body(string $vers): ?string {
		if (preg_match('#^vers:([a-z0-9.+-]+)/(.+)$#i', trim($vers), $m) !== 1) {
			return null;
		}

		return strtolower($m[1]) === 'unknown' ? null : trim($m[2]);
	}

	/**
	 * The `|`-joined constraints of a body, or null when one cannot be read.
	 *
	 * @spec openspec/changes/advisories-ncsc-feed/design.md
	 * @return list<array{0: string, 1: string}>|null
	 */
	private static function constraints(string $body): ?array {
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
	 * One constraint as comparator and bound; a bare version means `=`.
	 *
	 * @spec openspec/changes/advisories-ncsc-feed/design.md
	 * @return array{0: string, 1: string}|null
	 */
	private static function constraint(string $raw): ?array {
		$raw = trim($raw);
		$comparator = '=';
		foreach (self::COMPARATORS as $candidate) {
			if (str_starts_with($raw, $candidate)) {
				$comparator = $candidate;
				$raw = substr($raw, strlen($candidate));
				break;
			}
		}
		$bound = self::version(raw: $raw);

		return $bound === null ? null : [$comparator, $bound];
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
