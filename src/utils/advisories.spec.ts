// SPDX-License-Identifier: EUPL-1.2
// Covers "Advisories view" (#438 items 2 and 3):
// @spec openspec/specs/security-advisory-correlation/spec.md
import { describe, expect, it, vi } from 'vitest'
import { advisoryAnchor, advisoryBadgeText, advisoryHistory, complianceSentence, cveLabel, fixSentence, highestSeverity, publishedLabel, severityLabel, splitAdvisoryRows } from './advisories.ts'

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string, vars: Record<string, unknown> = {}) =>
		text.replace(/\{(\w+)\}/g, (_match: string, key: string) => String(vars[key] ?? '')),
	n: (_app: string, one: string, many: string, count: number, vars: Record<string, unknown> = {}) =>
		(count === 1 ? one : many).replace('%n', String(count)).replace(/\{(\w+)\}/g, (_match: string, key: string) => String(vars[key] ?? '')),
}))

function row (appId: string, state: string, severities: string[]) {
  return {
	appId,
	installedVersion: '1.0.0',
	state,
	advisories: severities.map((severity, i) => ({ id: `GHSA-${appId}-${i}`, severity, summary: `summary ${i}` })),
	recommendedVersion: null,
	error: null,
}
}

describe('highestSeverity', () => {
	it('picks the most severe level, ranking unknown lowest', () => {
		expect(highestSeverity([{ severity: 'low' }, { severity: 'critical' }, { severity: 'medium' }])).toBe('critical')
		expect(highestSeverity([{ severity: 'unknown' }, { severity: 'low' }])).toBe('low')
		expect(highestSeverity([{ severity: 'bogus' }])).toBe('unknown')
		expect(highestSeverity([])).toBe(null)
	})
})

describe('severityLabel', () => {
	it('names each documented level', () => {
		expect(severityLabel('critical')).toBe('Critical')
		expect(severityLabel('medium')).toBe('Medium')
		expect(severityLabel('whatever')).toBe('Unknown severity')
	})
})

describe('advisoryBadgeText', () => {
	it('carries the highest severity, so a critical and a low advisory no longer look the same', () => {
		expect(advisoryBadgeText(row('a', 'pinned-to-vulnerable', ['low', 'critical']) as never)).toBe('Vulnerable version, Critical')
		expect(advisoryBadgeText(row('a', 'advisory-available', ['low']) as never)).toBe('Advisory, Low')
	})
})

describe('splitAdvisoryRows', () => {
	it('separates the server row from app rows and drops apps with nothing to show', () => {
		const { server, apps } = splitAdvisoryRows({
			':server': row(':server', 'pinned-to-vulnerable', ['high']),
			calendar: row('calendar', 'advisory-available', ['low']),
			deck: row('deck', 'none', []),
			mail: row('mail', 'pinned-to-vulnerable', ['medium']),
		} as never)

		expect(server?.appId).toBe(':server')
		// Vulnerable rows first, then by id.
		expect(apps.map((r) => r.appId)).toEqual(['mail', 'calendar'])
	})

	it('returns a null server row when the feed had nothing about the server', () => {
		expect(splitAdvisoryRows({}).server).toBe(null)
	})
})

// advisories-per-app-detail: the compliance sentence, the fix sentence and the history.
// @spec openspec/changes/advisories-per-app-detail/tasks.md#4.2
describe('complianceSentence', () => {
	const base = { reason: null, affected: 0, bySeverity: [], affectedIds: [], unreached: 0, checkedAt: 1 }

	it('says nothing before the status loaded', () => {
		expect(complianceSentence(null)).toBe('')
	})

	it('says compliant', () => {
		expect(complianceSentence({ ...base, status: 'compliant' } as never)).toBe('Compliant')
	})

	it('counts affected apps per highest severity', () => {
		expect(complianceSentence({ ...base, status: 'not_compliant', affected: 1, bySeverity: { medium: 1 }, affectedIds: ['fixtureapp'] } as never))
			.toBe('Not compliant: 1 app runs an affected version (1 medium)')
		expect(complianceSentence({ ...base, status: 'not_compliant', affected: 2, bySeverity: { critical: 1, medium: 1 } } as never))
			.toBe('Not compliant: 2 apps run an affected version (1 critical, 1 medium)')
	})

	it('names each reason for unknown', () => {
		expect(complianceSentence({ ...base, status: 'unknown', reason: 'never_checked' } as never)).toContain('no advisory check has completed yet')
		expect(complianceSentence({ ...base, status: 'unknown', reason: 'stale' } as never)).toContain('out of date')
		expect(complianceSentence({ ...base, status: 'unknown', reason: 'unreached', unreached: 3 } as never)).toBe('Compliance unknown: 3 apps could not be checked')
	})
})

describe('fixSentence', () => {
	const affected = { appId: 'calendar', installedVersion: '4.7.0', state: 'pinned-to-vulnerable', advisories: [], recommendedVersion: '4.8.0', error: null } as const

	it('is empty for an unaffected row', () => {
		expect(fixSentence({ ...affected, state: 'advisory-available', fixStatus: null })).toBe('')
	})

	it('states each reason', () => {
		expect(fixSentence({ ...affected, fixStatus: 'installable' })).toBe('Install 4.8.0 to fix it.')
		expect(fixSentence({ ...affected, fixStatus: 'no_fix_published', recommendedVersion: null })).toBe('No fixed version is published yet.')
		expect(fixSentence({ ...affected, fixStatus: 'not_in_source' })).toContain('4.8.0 is not offered by the source')
		expect(fixSentence({ ...affected, fixStatus: 'needs_newer_server' })).toBe('The fix needs a newer Nextcloud than this server runs.')
		expect(fixSentence({ ...affected, fixStatus: 'installable', heldByPin: true })).toBe('A pin holds this app below the fix 4.8.0.')
		expect(fixSentence({ ...affected, appId: ':server', fixStatus: 'installable' }, true)).toBe('Upgrade Nextcloud to 4.8.0 or later.')
	})
})

describe('advisoryHistory', () => {
	it('falls back to the advisories of an older snapshot, undated', () => {
		const history = advisoryHistory({ appId: 'mail', installedVersion: '1', state: 'pinned-to-vulnerable', advisories: [{ id: 'GHSA-1', severity: 'high', summary: 's' }], recommendedVersion: null, error: null })
		expect(history).toEqual([{ id: 'GHSA-1', severity: 'high', summary: 's', cveIds: [], publishedAt: null, url: null, affectsInstalled: true }])
		expect(cveLabel(history[0])).toBe('No CVE id')
		expect(publishedLabel(history[0])).toBe('Date unknown')
	})

	it('labels CVE ids and dates', () => {
		expect(cveLabel({ cveIds: ['CVE-2026-1111', 'CVE-2026-2222'] })).toBe('CVE-2026-1111, CVE-2026-2222')
		expect(publishedLabel({ publishedAt: '2026-08-01T10:00:00Z' })).toBe('2026-08-01')
		expect(advisoryAnchor('fixtureapp')).toBe('section-advisories-fixtureapp')
	})
})
