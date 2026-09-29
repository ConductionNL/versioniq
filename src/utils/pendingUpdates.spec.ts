import type {PendingUpdate} from './pendingUpdates.ts';

// SPDX-License-Identifier: EUPL-1.2
// inventory-pending-updates: the card badge, the policy flag, the filter and
// the freshness line, from the snapshot GET /api/updates serves.
// @spec openspec/specs/pending-updates/spec.md
import { describe, expect, it, vi } from 'vitest'
import { filterByUpdates, isOutsidePolicy, updateBadge, updatesFreshnessLabel } from './pendingUpdates.ts'

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string, vars: Record<string, unknown> = {}) =>
		text.replace(/\{(\w+)\}/g, (_match: string, key: string) => String(vars[key] ?? '')),
	n: (_app: string, singular: string, plural: string, count: number, vars: Record<string, unknown> = {}) =>
		(count === 1 ? singular : plural).replace('%n', String(count)).replace(/\{(\w+)\}/g, (_match: string, key: string) => String(vars[key] ?? '')),
}))

function entry (overrides: Partial<PendingUpdate> = {}): PendingUpdate {
	return {
	installedVersion: '2.3.0',
	newestVersion: '2.4.1',
	newestCompatibleVersion: '2.4.1',
	linesBehind: 1,
	updateAvailable: true,
	pinned: false,
	sourceId: 'appstore',
	error: null,
		...overrides,
	}
}

describe('updateBadge', () => {
	it('shows no badge for an app that is up to date', () => {
		expect(updateBadge(entry({ newestCompatibleVersion: '2.3.0', linesBehind: 0, updateAvailable: false }))).toBeNull()
	})

	it('names the newest version this server can run', () => {
		expect(updateBadge(entry())).toEqual({ kind: 'update', label: 'Update available: 2.4.1' })
	})

	it('keeps the badge on a pinned app and says the pin holds it', () => {
		expect(updateBadge(entry({ pinned: true }))).toEqual({ kind: 'pinned', label: 'Update available: 2.4.1, held by the pin' })
	})

	it('says an app whose source did not answer was not checked', () => {
		expect(updateBadge(entry({ error: 'HTTP 403', updateAvailable: false, newestVersion: null, newestCompatibleVersion: null }))).toEqual({ kind: 'notChecked', label: 'Updates not checked', title: 'HTTP 403' })
	})

	it('shows nothing when the app is not in the snapshot, so no card reads as up to date before a sweep', () => {
		expect(updateBadge(undefined)).toBeNull()
	})
})

describe('isOutsidePolicy', () => {
	it('flags an app more release lines behind than the limit', () => {
		expect(isOutsidePolicy(entry({ linesBehind: 2 }), 1)).toBe(true)
		expect(isOutsidePolicy(entry({ linesBehind: 1 }), 1)).toBe(false)
	})

	it('flags nothing when the check is off', () => {
		expect(isOutsidePolicy(entry({ linesBehind: 9 }), null)).toBe(false)
	})

	it('treats a limit of zero as "always on the newest release line"', () => {
		expect(isOutsidePolicy(entry({ linesBehind: 1 }), 0)).toBe(true)
	})
})

describe('filterByUpdates', () => {
	const apps = [{ id: 'openregister' }, { id: 'calendar' }, { id: 'deck' }, { id: 'hermiq' }]
	const updates = {
		openregister: entry({ linesBehind: 2 }),
		calendar: entry({ newestCompatibleVersion: '2.3.0', linesBehind: 0, updateAvailable: false }),
		deck: entry({ linesBehind: 1, pinned: true }),
	}

	it('keeps every app for "all"', () => {
		expect(filterByUpdates(apps, updates, 'all', 1).map((app) => app.id)).toEqual(['openregister', 'calendar', 'deck', 'hermiq'])
	})

	it('keeps apps with an update, pinned or not', () => {
		expect(filterByUpdates(apps, updates, 'update', 1).map((app) => app.id)).toEqual(['openregister', 'deck'])
	})

	it('keeps only apps past the limit', () => {
		expect(filterByUpdates(apps, updates, 'outsidePolicy', 1).map((app) => app.id)).toEqual(['openregister'])
	})
})

describe('updatesFreshnessLabel', () => {
	const now = 1_790_000_000

	it('says plainly when no check has run', () => {
		expect(updatesFreshnessLabel({ unavailable: false, checkedAt: null, nowSeconds: now })).toBe('Updates not checked yet. The background job runs every 6 hours.')
	})

	it('says when the fetch itself failed', () => {
		expect(updatesFreshnessLabel({ unavailable: true, checkedAt: null, nowSeconds: now })).toBe('Update status unavailable. Could not reach the server.')
	})

	it('names the age of the last check', () => {
		expect(updatesFreshnessLabel({ unavailable: false, checkedAt: now - 20, nowSeconds: now })).toBe('Updates checked just now')
		expect(updatesFreshnessLabel({ unavailable: false, checkedAt: now - 600, nowSeconds: now })).toBe('Updates checked 10 min ago')
		expect(updatesFreshnessLabel({ unavailable: false, checkedAt: now - 7200, nowSeconds: now })).toBe('Updates checked 2 h ago')
	})
})

describe('the policy label', () => {
	it('uses the plural for more than one release', async () => {
		const { outsidePolicyLabel } = await import('./pendingUpdates.ts')
		expect(outsidePolicyLabel(2)).toBe('Outside the update policy: 2 releases behind')
		expect(outsidePolicyLabel(1)).toBe('Outside the update policy: 1 release behind')
	})
})
