// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
// Semver-ish comparison shared by App.vue's version list, range summaries
// and the changelog aggregate range panel. Extracted verbatim from App.vue
// so the comparison logic has a single source of truth that both the UI
// and the utils/changelog.ts range builder can import.

// @spec openspec/specs/auto-update-policies/spec.md
//
// The spec names "Semantic Versioning 2.0.0 (level comparison)" as a standard
// it is built on, and both exports here are that comparison: App.vue uses them
// to decide whether a selected version is a downgrade, and to bound a version
// range. gate-16 flagged them once this file was touched, and it was right --
// they were the only untagged pair in src/utils.
export type VersionCore = { major: number, minor: number, patch: number }

/**
 *
 * @param version
 */
export function parseVersionCore (version: string): VersionCore {
	const [core] = version.split('-', 2)
	const [rawMajor, rawMinor, rawPatch] = core.split('.')

	return {
		major: Number.parseInt(rawMajor || '0', 10) || 0,
		minor: Number.parseInt(rawMinor || '0', 10) || 0,
		patch: Number.parseInt(rawPatch || '0', 10) || 0,
	}
}

/**
 * Orders two numbers or two strings: 1 when `left` is greater, -1 when it is
 * smaller, 0 otherwise (also when either side is NaN).
 *
 * @param left The left value.
 * @param right The right value.
 */
function compareOrdered (left: number | string, right: number | string): number {
	if (left > right) {
		return 1
	}
	if (left < right) {
		return -1
	}
	return 0
}

/**
 * Splits a version core into its numeric parts; an empty part counts as 0.
 *
 * @param core The part of a version before its `-prerelease` suffix.
 */
function coreParts (core: string): number[] {
	return core.split('.').map((part) => Number(part || '0'))
}

/**
 * Compares two version cores part by part; a missing part counts as 0.
 *
 * @param leftCore The left version core.
 * @param rightCore The right version core.
 */
function compareCores (leftCore: string, rightCore: string): number {
	const leftParts = coreParts(leftCore)
	const rightParts = coreParts(rightCore)

	for (let index = 0; index < Math.max(leftParts.length, rightParts.length); index++) {
		const order = compareOrdered(leftParts[index] ?? 0, rightParts[index] ?? 0)
		if (order !== 0) {
			return order
		}
	}
	return 0
}

/**
 * Compares two prerelease identifiers that are both present: numeric ones
 * numerically, a numeric one below an alphanumeric one, the rest as text.
 *
 * @param left The left identifier.
 * @param right The right identifier.
 */
function compareIdentifiers (left: string, right: string): number {
	const leftNumeric = /^\d+$/.test(left)
	const rightNumeric = /^\d+$/.test(right)

	if (leftNumeric && rightNumeric) {
		return compareOrdered(Number(left), Number(right))
	}
	if (leftNumeric) {
		return -1
	}
	if (rightNumeric) {
		return 1
	}
	return compareOrdered(left, right)
}

/**
 * Compares two dot-separated prerelease identifier lists; the shorter list
 * ranks lower when all its identifiers equal the other's.
 *
 * @param leftParts The left identifiers.
 * @param rightParts The right identifiers.
 */
function compareIdentifierLists (leftParts: string[], rightParts: string[]): number {
	const shared = Math.min(leftParts.length, rightParts.length)
	for (let index = 0; index < shared; index++) {
		const order = compareIdentifiers(leftParts[index] as string, rightParts[index] as string)
		if (order !== 0) {
			return order
		}
	}
	return compareOrdered(leftParts.length, rightParts.length)
}

/**
 * Compares two prerelease suffixes; no suffix outranks any suffix.
 *
 * @param leftPre The left suffix, or '' for none.
 * @param rightPre The right suffix, or '' for none.
 */
function comparePrerelease (leftPre: string, rightPre: string): number {
	if (leftPre === rightPre) {
		return 0
	}
	if (!leftPre) {
		return 1
	}
	if (!rightPre) {
		return -1
	}
	return compareIdentifierLists(leftPre.split('.'), rightPre.split('.'))
}

/**
 * Compares two version strings, returning >0 when `left` is newer than
 * `right`, <0 when older, 0 when equal. Handles a `-prerelease` suffix with
 * basic semver precedence rules (numeric identifiers compare numerically,
 * a version without a prerelease suffix outranks one with).
 *
 * @param left The left version.
 * @param right The right version.
 * @spec openspec/specs/auto-update-policies/spec.md
 */
export function compareVersions (left: string, right: string): number {
	const [leftCore, leftPre = ''] = left.split('-', 2)
	const [rightCore, rightPre = ''] = right.split('-', 2)
	const coreOrder = compareCores(leftCore, rightCore)

	return coreOrder !== 0 ? coreOrder : comparePrerelease(leftPre, rightPre)
}
