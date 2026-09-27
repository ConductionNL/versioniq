// SPDX-License-Identifier: EUPL-1.2
// Issue #436: before the first advisory check the page said the job runs
// every 6 hours, whatever interval the admin had saved.
// @spec openspec/specs/security-advisory-correlation/spec.md
import { describe, expect, it, vi } from 'vitest'
import { advisoryFreshnessLabel } from './advisoryFreshness.ts'

// Faithful enough to @nextcloud/l10n: {placeholders} from vars, and n()
// picks the singular or plural text by count and replaces %n.
vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string, vars: Record<string, unknown> = {}) =>
		text.replace(/\{(\w+)\}/g, (_match: string, key: string) => String(vars[key] ?? '')),
	n: (_app: string, singular: string, plural: string, count: number) =>
		(count === 1 ? singular : plural).replace('%n', String(count)),
}))

const base = { unavailable: false, checkedAt: null, intervalHours: 6, nowSeconds: 1_000_000 }

describe('advisoryFreshnessLabel', () => {
	it('names the saved interval before the first check', () => {
		expect(advisoryFreshnessLabel({ ...base, intervalHours: 24 })).toBe('Advisories not checked yet. The background job runs every 24 hours.')
	})

	it('uses the singular for a one hour interval', () => {
		expect(advisoryFreshnessLabel({ ...base, intervalHours: 1 })).toBe('Advisories not checked yet. The background job runs every 1 hour.')
	})

	it('carries no em-dash in any state', () => {
		const labels = [
			advisoryFreshnessLabel({ ...base, unavailable: true }),
			advisoryFreshnessLabel(base),
			advisoryFreshnessLabel({ ...base, checkedAt: base.nowSeconds - 30 }),
			advisoryFreshnessLabel({ ...base, checkedAt: base.nowSeconds - 600 }),
			advisoryFreshnessLabel({ ...base, checkedAt: base.nowSeconds - 7200 }),
		]
		for (const label of labels) {
			expect(label).not.toContain('—')
		}
	})

	it('reports the age of a completed check', () => {
		expect(advisoryFreshnessLabel({ ...base, checkedAt: base.nowSeconds - 600 })).toBe('Advisories checked 10 min ago')
		expect(advisoryFreshnessLabel({ ...base, checkedAt: base.nowSeconds - 7200 })).toBe('Advisories checked 2 h ago')
	})
})
