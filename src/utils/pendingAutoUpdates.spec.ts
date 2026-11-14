// SPDX-License-Identifier: EUPL-1.2
// @spec openspec/changes/inventory-pins-checks-and-pending/specs/auto-update-policies/spec.md#requirement-the-overview-lists-the-updates-that-are-pending-now
import { describe, expect, it } from 'vitest'
import { levelAllows, pendingAutoUpdates } from './pendingAutoUpdates.ts'

function update (installedVersion: string, newest: string | null, extra: Record<string, unknown> = {}) {
  return {
	installedVersion,
	newestVersion: newest,
	newestCompatibleVersion: newest,
	linesBehind: 0,
	updateAvailable: newest !== null,
	pinned: false,
	sourceId: null,
	error: null,
	...extra,
}
}

describe('levelAllows', () => {
	it('lets patch take patches, minor take minors and all take anything', () => {
		expect(levelAllows('patch', '0.4.49', '0.4.50')).toBe(true)
		expect(levelAllows('patch', '0.4.49', '0.5.0')).toBe(false)
		expect(levelAllows('minor', '0.4.49', '0.5.0')).toBe(true)
		expect(levelAllows('minor', '0.4.49', '1.0.0')).toBe(false)
		expect(levelAllows('all', '0.4.49', '1.0.0')).toBe(true)
		expect(levelAllows('none', '0.4.49', '0.4.50')).toBe(false)
	})
})

describe('pendingAutoUpdates', () => {
	it('lists what tonight\'s run will do: scheduled, held by the pin, outside the policy, failed', () => {
		const rows = pendingAutoUpdates(
			{
				dossiq: { level: 'patch' },
				decidiq: { level: 'all' },
				deck: { level: 'patch' },
				mail: { level: 'minor', blockedVersions: [{ version: '4.1.0' }] },
				notes: { level: 'none' },
				calendar: { level: 'all' },
			},
			{
				dossiq: update('0.4.49', '0.4.50'),
				decidiq: update('0.9.3', '0.10.1', { pinned: true }),
				deck: update('1.14.0', '1.15.0'),
				mail: update('4.0.0', '4.1.0'),
				notes: update('1.0.0', '1.1.0'),
				calendar: update('5.0.0', '5.0.0'),
			},
		)

		expect(rows).toEqual([
			{ appId: 'decidiq', version: '0.10.1', state: 'pinned' },
			{ appId: 'deck', version: '1.15.0', state: 'outsidePolicy' },
			{ appId: 'dossiq', version: '0.4.50', state: 'scheduled' },
			{ appId: 'mail', version: '4.1.0', state: 'failed' },
		])
	})

	it('skips apps without a stored update or whose source could not be read', () => {
		expect(pendingAutoUpdates({ a: { level: 'all' }, b: { level: 'all' } }, { b: update('1.0.0', '2.0.0', { error: 'HTTP 403' }) })).toEqual([])
	})
})
