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
 * Compares two version strings, returning >0 when `left` is newer than
 * `right`, <0 when older, 0 when equal. Handles a `-prerelease` suffix with
 * basic semver precedence rules (numeric identifiers compare numerically,
 * a version without a prerelease suffix outranks one with).
 *
 * @param left
 * @param right
 */
export function compareVersions (left: string, right: string): number {
	const [leftCore, leftPre = ''] = left.split('-', 2)
	const [rightCore, rightPre = ''] = right.split('-', 2)
	const leftParts = leftCore.split('.').map((part) => Number(part || '0'))
	const rightParts = rightCore.split('.').map((part) => Number(part || '0'))

	for (let index = 0; index < Math.max(leftParts.length, rightParts.length); index++) {
		const leftPart = leftParts[index] ?? 0
		const rightPart = rightParts[index] ?? 0

		if (leftPart > rightPart) {
			return 1
		}
		if (leftPart < rightPart) {
			return -1
		}
	}

	if (leftPre === rightPre) {
		return 0
	}
	if (!leftPre) {
		return 1
	}
	if (!rightPre) {
		return -1
	}

	const leftPreParts = leftPre.split('.')
	const rightPreParts = rightPre.split('.')
	for (let index = 0; index < Math.max(leftPreParts.length, rightPreParts.length); index++) {
		const leftPart = leftPreParts[index]
		const rightPart = rightPreParts[index]

		if (leftPart === undefined) {
			return -1
		}
		if (rightPart === undefined) {
			return 1
		}

		const leftNumeric = /^\d+$/.test(leftPart)
		const rightNumeric = /^\d+$/.test(rightPart)

		if (leftNumeric && rightNumeric) {
			const leftNum = Number(leftPart)
			const rightNum = Number(rightPart)
			if (leftNum > rightNum) {
				return 1
			}
			if (leftNum < rightNum) {
				return -1
			}
			continue
		}

		if (leftNumeric) {
			return -1
		}
		if (rightNumeric) {
			return 1
		}

		if (leftPart > rightPart) {
			return 1
		}
		if (leftPart < rightPart) {
			return -1
		}
	}

	return 0
}
