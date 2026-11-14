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

		if ($constraints === []) {
			// `*`: every version.
			return true;
		}

		foreach ($constraints as [$comparator, $bound]) {
			if ($comparator === '=' && version_compare($installed, $bound, '==') === true) {
				return true;
			}
			if ($comparator === '!=' && version_compare($installed, $bound, '==') === true) {
				return false;
			}
		}

		$ordered = array_values(array_filter(
			$constraints,
			static fn (array $c): bool => $c[0] !== '=' && $c[0] !== '!='
		));
		if ($ordered === []) {
			// Only `=` and `!=`: inside when not excluded and some `!=` stands
			// for "everything but", outside when only `=` clauses missed.
			foreach ($constraints as [$comparator]) {
				if ($comparator === '!=') {
					return true;
				}
			}
			return false;
		}

		usort($ordered, static fn (array $x, array $y): int => version_compare($x[1], $y[1]));

		return self::inIntervals(constraints: $ordered, version: $installed);
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
			$current = $constraints[$i];
			$next = $constraints[$i + 1];
			$currentLess = in_array($current[0], ['<', '<='], true);
			$nextGreater = in_array($next[0], ['>', '>='], true);

			if ($i === 0 && $currentLess && self::satisfies(version: $version, constraint: $current)) {
				return true;
			}
			if ($i === $count - 2 && $nextGreater && self::satisfies(version: $version, constraint: $next)) {
				return true;
			}
			if ($currentLess === false && $nextGreater === false
				&& self::satisfies(version: $version, constraint: $current)
				&& self::satisfies(version: $version, constraint: $next)
			) {
				return true;
			}
		}

		return false;
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
		if (preg_match('#^vers:([a-z0-9.+-]+)/(.+)$#i', trim($vers), $m) !== 1) {
			return null;
		}
		if (strtolower($m[1]) === 'unknown') {
			return null;
		}

		$body = trim($m[2]);
		if ($body === '*') {
			return [];
		}

		$constraints = [];
		foreach (explode('|', $body) as $raw) {
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
			if ($bound === null) {
				return null;
			}
			$constraints[] = [$comparator, $bound];
		}

		return $constraints;
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
