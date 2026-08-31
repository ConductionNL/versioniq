// SPDX-License-Identifier: EUPL-1.2
// Regression coverage for the version-comparison logic extracted from
// App.vue (add-changelog-visibility) so utils/changelog.ts's range builder
// has a single, independently-tested source of truth.
import { describe, expect, it } from 'vitest'
import { compareVersions } from './versionCompare.ts'

describe('compareVersions', () => {
	it('orders by major/minor/patch', () => {
		expect(compareVersions('2.5.0', '2.4.0')).toBeGreaterThan(0)
		expect(compareVersions('2.4.0', '2.5.0')).toBeLessThan(0)
		expect(compareVersions('2.4.0', '2.4.0')).toBe(0)
	})

	it('treats a release without a prerelease suffix as newer than one with', () => {
		expect(compareVersions('2.5.0', '2.5.0-beta1')).toBeGreaterThan(0)
		expect(compareVersions('2.5.0-beta1', '2.5.0')).toBeLessThan(0)
	})

	it('compares dot-separated numeric prerelease identifiers numerically', () => {
		expect(compareVersions('2.5.0-beta.2', '2.5.0-beta.10')).toBeLessThan(0)
	})
})
