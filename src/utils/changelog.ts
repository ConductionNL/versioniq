// Pure helpers for the aggregate changelog range panel — "Aggregate range
// changelog on target selection" (add-changelog-visibility).
//
// @spec openspec/specs/changelog-visibility/spec.md
import { compareVersions } from './versionCompare.ts'

export type ChangelogSource = {
	version: string
	changelog?: string | null
}

export type ChangelogRangeEntry = {
	version: string
	changelog: string | null
}

/**
 * Builds the ordered list of intermediate releases between the installed
 * version and the selected target, for the aggregate "changes between
 * installed → target" panel.
 *
 * Both directions exclude the numerically-lower endpoint of the pair and
 * include the higher one; only the display order differs:
 *   - Upgrade (target newer): ascending, from just-above-installed towards
 *     the target — "installed 2.3.0 → target 2.5.0" lists 2.4.0, 2.5.0.
 *   - Downgrade (target older): descending, newest first — the releases
 *     being rolled back — "installed 2.5.0 → target 2.3.0" lists 2.5.0,
 *     2.4.0 (2.3.0 itself, the target being returned to, is not listed).
 *
 * Returns an empty list when either endpoint is missing or they are equal
 * (no range to show). Reuses the already-fetched `versions` list — this
 * function issues zero requests.
 *
 * @param installedVersion
 * @param targetVersion
 * @param versions
 */
export function buildChangelogRange(
	installedVersion: string,
	targetVersion: string,
	versions: ChangelogSource[],
): ChangelogRangeEntry[] {
	if (!installedVersion || !targetVersion || installedVersion === targetVersion) {
		return []
	}

	const isUpgrade = compareVersions(targetVersion, installedVersion) > 0
	const low = isUpgrade ? installedVersion : targetVersion
	const high = isUpgrade ? targetVersion : installedVersion

	const entries: ChangelogRangeEntry[] = versions
		.filter((entry) => compareVersions(entry.version, low) > 0 && compareVersions(entry.version, high) <= 0)
		.map((entry) => ({ version: entry.version, changelog: entry.changelog ?? null }))

	entries.sort((a, b) => (isUpgrade ? compareVersions(a.version, b.version) : compareVersions(b.version, a.version)))

	return entries
}
