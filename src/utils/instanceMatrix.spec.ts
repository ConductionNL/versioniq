// SPDX-License-Identifier: EUPL-1.2
// @spec openspec/changes/install-multi-instance-promotion/specs/multi-instance/spec.md#requirement-the-instances-tab-shows-every-instances-versions-side-by-side
import { describe, expect, it } from 'vitest'
import { canPromoteFrom, daysSince, instanceColumns, instanceRows } from './instanceMatrix.ts'

const self = { name: 'Production', stage: 'production', order: 20 }
const own = { apps: [{ appId: 'a', installedVersion: '1.0.0', runningSince: null }] }

/**
 * @param id The connection id.
 * @param order Its place in the chain.
 * @param version The version of app `a` it runs, or null for no manifest.
 */
function remote (id: string, order: number, version: string | null) {
	return {
		id,
		name: id,
		stage: id,
		order,
		baseUrl: `https://${id}.example.org`,
		username: 'r',
		remote: { manifest: version === null ? null : { apps: [{ appId: 'a', installedVersion: version, runningSince: null }] }, fetchedAt: null, error: null, checkedAt: null },
	}
}

describe('instanceMatrix', () => {
	it('orders the columns by the chain, this instance last among equals', () => {
		const columns = instanceColumns(self, own, [remote('later', 30, '2.0.0'), remote('peer', 20, '1.0.0'), remote('first', 5, '1.0.0')])

		expect(columns.map((column) => column.id ?? 'self')).toEqual(['first', 'peer', 'self', 'later'])
		expect(columns.map((column) => column.isEarlier)).toEqual([true, false, false, false])
	})

	it('leaves a column without a manifest out of "differs" and never promotes from a later stage', () => {
		const columns = instanceColumns(self, own, [remote('down', 10, null), remote('later', 30, '2.0.0')])
		const [row] = instanceRows(columns)

		expect(row.cells.map((cell) => cell?.installedVersion ?? null)).toEqual([null, '1.0.0', '2.0.0'])
		expect(row.differs).toBe(true)
		expect(columns.map((_column, index) => canPromoteFrom(row, columns, index))).toEqual([false, false, false])
	})

	it('promotes from an earlier stage only to a different version', () => {
		const same = instanceColumns(self, own, [remote('acc', 10, '1.0.0')])
		expect(canPromoteFrom(instanceRows(same)[0], same, 0)).toBe(false)

		const ahead = instanceColumns(self, own, [remote('acc', 10, '1.1.0')])
		expect(canPromoteFrom(instanceRows(ahead)[0], ahead, 0)).toBe(true)
	})

	it('counts whole days and never a negative or unreadable age', () => {
		const now = new Date('2026-11-16T12:00:00Z')
		expect(daysSince('2026-11-14T13:00:00Z', now)).toBe(1)
		expect(daysSince('2026-11-17T00:00:00Z', now)).toBe(0)
		expect(daysSince('not a date', now)).toBeNull()
		expect(daysSince(null, now)).toBeNull()
	})
})
