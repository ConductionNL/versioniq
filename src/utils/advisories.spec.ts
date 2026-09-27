// SPDX-License-Identifier: EUPL-1.2
// Covers "Advisories view" (#438 items 2 and 3):
// @spec openspec/specs/security-advisory-correlation/spec.md
import { describe, expect, it, vi } from 'vitest'
import { advisoryBadgeText, highestSeverity, severityLabel, splitAdvisoryRows } from './advisories.ts'

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string, vars: Record<string, unknown> = {}) =>
		text.replace(/\{(\w+)\}/g, (_match: string, key: string) => String(vars[key] ?? '')),
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
