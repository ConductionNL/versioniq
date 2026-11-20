// SPDX-License-Identifier: EUPL-1.2
// @spec openspec/changes/advisories-bundled-libraries
import { describe, expect, it, vi } from 'vitest'
import { hasLibraryFindings, librariesFreshnessLabel, libraryRows } from './bundledLibraries.ts'

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string, vars: Record<string, unknown> = {}) =>
		text.replace(/\{(\w+)\}/g, (_match: string, key: string) => String(vars[key] ?? '')),
}))

function entry (findings: number) {
  return {
	manifest: 'vendor/composer/installed.json',
	checked: 3,
	skipped: 0,
	error: null,
	findings: Array.from({ length: findings }, (_, i) => ({ package: 'a/b', version: '1', id: `GHSA-${i}`, cveIds: [], severity: 'low', summary: '', fixedIn: null, url: '', malicious: false })),
}
}

describe('bundledLibraries', () => {
	it('badges only an app whose check found something', () => {
		expect(hasLibraryFindings(entry(1))).toBe(true)
		expect(hasLibraryFindings(entry(0))).toBe(false)
		expect(hasLibraryFindings(undefined)).toBe(false)
		expect(hasLibraryFindings(null)).toBe(false)
	})

	it('sorts the apps with findings first, then by id', () => {
		expect(libraryRows({ zeta: entry(0), beta: entry(2), alpha: entry(0), gamma: entry(1) }).map((row) => row.appId))
			.toEqual(['beta', 'gamma', 'alpha', 'zeta'])
	})

	it('describes the age of the last check, and nothing before the first', () => {
		expect(librariesFreshnessLabel(null, 1000)).toBe('')
		expect(librariesFreshnessLabel(1000, 1020)).toBe('Libraries checked just now')
		expect(librariesFreshnessLabel(1000, 1000 + 25 * 60)).toBe('Libraries checked 25 min ago')
		expect(librariesFreshnessLabel(1000, 1000 + 5 * 3600)).toBe('Libraries checked 5 h ago')
		expect(librariesFreshnessLabel(5000, 1000)).toBe('Libraries checked just now')
	})
})
