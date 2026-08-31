// SPDX-License-Identifier: EUPL-1.2
// Covers "Aggregate range changelog on target selection" ("Upgrade range
// aggregation", "Downgrade shows what is being undone"):
// @spec openspec/specs/changelog-visibility/spec.md
import { describe, expect, it } from 'vitest'
import { buildChangelogRange } from './changelog.ts'

const versions = [
	{ version: '2.6.0', changelog: 'Notes 2.6.0' },
	{ version: '2.5.0', changelog: 'Notes 2.5.0' },
	{ version: '2.4.0', changelog: null },
	{ version: '2.3.0', changelog: 'Notes 2.3.0' },
	{ version: '2.2.0', changelog: 'Notes 2.2.0' },
]

describe('buildChangelogRange', () => {
	it('returns an empty list when either endpoint is missing', () => {
		expect(buildChangelogRange('', '2.5.0', versions)).toEqual([])
		expect(buildChangelogRange('2.3.0', '', versions)).toEqual([])
	})

	it('returns an empty list when installed and target are equal', () => {
		expect(buildChangelogRange('2.3.0', '2.3.0', versions)).toEqual([])
	})

	it('orders an upgrade ascending, excluding installed and including target', () => {
		const result = buildChangelogRange('2.3.0', '2.5.0', versions)

		expect(result.map((entry) => entry.version)).toEqual(['2.4.0', '2.5.0'])
	})

	it('orders a downgrade newest-first, excluding the target', () => {
		const result = buildChangelogRange('2.5.0', '2.3.0', versions)

		expect(result.map((entry) => entry.version)).toEqual(['2.5.0', '2.4.0'])
	})

	it('carries the changelog text through for each entry', () => {
		const result = buildChangelogRange('2.3.0', '2.5.0', versions)

		expect(result).toEqual([
			{ version: '2.4.0', changelog: null },
			{ version: '2.5.0', changelog: 'Notes 2.5.0' },
		])
	})

	it('normalizes an absent changelog field to null (placeholder entries)', () => {
		const result = buildChangelogRange('2.2.0', '2.3.0', [
			{ version: '2.3.0' },
			{ version: '2.2.0' },
		])

		expect(result).toEqual([{ version: '2.3.0', changelog: null }])
	})

	it('excludes versions outside the range', () => {
		const result = buildChangelogRange('2.4.0', '2.6.0', versions)

		expect(result.map((entry) => entry.version)).toEqual(['2.5.0', '2.6.0'])
	})
})
