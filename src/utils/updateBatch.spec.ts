import type { PendingUpdate } from './pendingUpdates.ts'
// SPDX-License-Identifier: EUPL-1.2
// Update all runs in the browser, one app at a time: a failure never stops
// the run, and a stop lets the current install finish.
// @spec openspec/changes/install-one-click-updates/specs/version-management/spec.md
import type { BatchCandidate, BatchRow } from './updateBatch.ts'

import { describe, expect, it, vi } from 'vitest'
import { batchOutcome, quickUpdateTarget, runUpdateBatch, updateAllCandidates } from './updateBatch.ts'

function entry (overrides: Partial<PendingUpdate> = {}): PendingUpdate {
  return {
	installedVersion: '2.3.0',
	newestVersion: '2.4.1',
	newestCompatibleVersion: '2.4.1',
	linesBehind: 1,
	updateAvailable: true,
	pinned: false,
	sourceId: null,
	error: null,
	...overrides,
}
}

const candidate = (appId: string, selected = true): BatchCandidate => ({ appId, fromVersion: '1.0.0', toVersion: '1.1.0', pinned: !selected, selected })

describe('quickUpdateTarget', () => {
	it('offers the newest compatible version', () => {
		expect(quickUpdateTarget(entry())).toBe('2.4.1')
	})

	it.each([
		['no snapshot entry', undefined],
		['a failed check', entry({ error: 'unreachable' })],
		['no update', entry({ updateAvailable: false })],
		['no compatible version', entry({ newestCompatibleVersion: null })],
		['the installed version', entry({ newestCompatibleVersion: '2.3.0' })],
	])('offers nothing for %s', (_label, value) => {
		expect(quickUpdateTarget(value)).toBeNull()
	})
})

describe('updateAllCandidates', () => {
	it('lists apps with an update in id order and leaves pinned apps unticked', () => {
		const list = updateAllCandidates({
			openregister: entry(),
			hermiq: entry({ pinned: true, installedVersion: '1.0.0', newestCompatibleVersion: '1.2.0' }),
			calendar: entry({ updateAvailable: false }),
		})

		expect(list).toEqual([
			{ appId: 'hermiq', fromVersion: '1.0.0', toVersion: '1.2.0', pinned: true, selected: false },
			{ appId: 'openregister', fromVersion: '2.3.0', toVersion: '2.4.1', pinned: false, selected: true },
		])
	})
})

describe('runUpdateBatch', () => {
	it('installs every ticked app in turn and reports each outcome', async () => {
		const order: string[] = []
		const install = vi.fn(async (c: BatchCandidate) => {
			order.push(c.appId)
			return { state: 'updated' as const }
		})
		const rows: BatchRow[] = []

		const result = await runUpdateBatch([candidate('a'), candidate('pinned', false), candidate('b')], { install, onRow: (row) => rows.push(row) })

		expect(order).toEqual(['a', 'b'])
		expect(result.stopped).toBe(false)
		expect(result.rows.map((row) => row.state)).toEqual(['updated', 'updated'])
		expect(rows.map((row) => `${row.appId}:${row.state}`)).toEqual(['a:running', 'a:updated', 'b:running', 'b:updated'])
	})

	it('keeps going after a failure, refused or thrown', async () => {
		const install = vi.fn(async (c: BatchCandidate) => {
			if (c.appId === 'calendar') {
				return { state: 'failed' as const, category: 'checksum', message: 'Checksum mismatch' }
			}
			if (c.appId === 'mail') {
				throw new Error('Network down')
			}
			return { state: 'updated' as const }
		})

		const result = await runUpdateBatch([candidate('calendar'), candidate('mail'), candidate('openregister')], { install })

		expect(install).toHaveBeenCalledTimes(3)
		expect(result.rows).toEqual([
			{ appId: 'calendar', toVersion: '1.1.0', state: 'failed', category: 'checksum', message: 'Checksum mismatch' },
			{ appId: 'mail', toVersion: '1.1.0', state: 'failed', category: undefined, message: 'Network down' },
			{ appId: 'openregister', toVersion: '1.1.0', state: 'updated', category: undefined, message: undefined },
		])
	})

	it('stops after the current install and skips the rest', async () => {
		let stop = false
		const install = vi.fn(async () => {
			stop = true
			return { state: 'updated' as const }
		})

		const result = await runUpdateBatch([candidate('a'), candidate('b'), candidate('c')], { install, shouldStop: () => stop })

		expect(install).toHaveBeenCalledTimes(1)
		expect(result.stopped).toBe(true)
		expect(result.rows.map((row) => row.state)).toEqual(['updated', 'skipped', 'skipped'])
	})
})

describe('batchOutcome', () => {
	it('reads a refused install as failed with its category and hint', () => {
		expect(batchOutcome({ payload: { category: 'checksum', hint: 'Re-download the release', message: 'Checksum mismatch' }, metaMessage: 'Checksum mismatch' }))
			.toEqual({ state: 'failed', category: 'checksum', message: 'Re-download the release' })
	})

	it('reads a cancelled pin choice as skipped', () => {
		expect(batchOutcome(null)).toEqual({ state: 'skipped' })
	})

	it('reads the version that was already installed as up to date', () => {
		expect(batchOutcome({ payload: { fromVersion: '2.4.1', toVersion: '2.4.1', message: 'App already has this version installed.' } })).toEqual({ state: 'upToDate' })
	})

	it('reads a finished install as updated', () => {
		expect(batchOutcome({ payload: { fromVersion: '2.3.0', toVersion: '2.4.1' } })).toEqual({ state: 'updated' })
	})
})
