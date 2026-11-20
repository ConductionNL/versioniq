// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
// Running a rollback plan in the browser: the install query of each step,
// plan order, a stop at the first failure, continuing after it, a check of
// downgrades only, a stop when asked, and the run-log counts.
// @spec openspec/changes/install-bulk-rollback/specs/migration-safety/spec.md
import type { RollbackStep, StepOutcome } from './rollbackRun.ts'

import { afterEach, describe, expect, it, vi } from 'vitest'
import { installStep, isDowngrade, runCounts, runRollback, stepQuery } from './rollbackRun.ts'

function step (appId: string, kind: RollbackStep['kind'], from: string | null, to: string | null, pinnedTo: string | null = null): RollbackStep {
	return { appId, kind, from, to, sourceId: `github:c/${appId}`, pinnedTo }
}

const plan: RollbackStep[] = [
	step('opencatalogi', 'downgrade', '1.8.2', '1.8.0'),
	step('openregister', 'downgrade', '2.3.4', '2.3.0'),
	step('mail', 'upgrade', '3.0.0', '3.1.0'),
	step('calendar', 'pinned', '4.2.0', '4.0.0', '4.2.0'),
	step('deck', 'unchanged', '1.0.0', '1.0.0'),
	step('gone', 'removed', null, '1.0.0'),
]

/**
 * An install that fails for the given apps.
 *
 * @param failing The apps whose install fails.
 */
function fakeInstall (failing: string[] = []) {
	return vi.fn(async (s: RollbackStep, dryRun: boolean): Promise<StepOutcome> => ({
		appId: s.appId,
		outcome: failing.includes(s.appId) ? 'failed' : (dryRun ? 'checked' : 'succeeded'),
		message: failing.includes(s.appId) ? 'checksum mismatch' : '',
		orphanedMigrations: dryRun ? ['Version2003Date'] : [],
	}))
}

afterEach(() => {
	vi.unstubAllGlobals()
})

describe('rollbackRun', () => {
	it('builds the install query from the snapshot: source, downgrade acknowledgement and repin', () => {
		expect(stepQuery(plan[0], false)).toEqual({ debug: '0', dryRun: '0', targetVersion: '1.8.0', source: 'github:c/opencatalogi', allowDowngrade: '1' })
		expect(stepQuery(plan[2], true)).toEqual({ debug: '0', dryRun: '1', targetVersion: '3.1.0', source: 'github:c/mail' })
		expect(stepQuery({ ...plan[3], sourceId: null }, false)).toEqual({ debug: '0', dryRun: '0', targetVersion: '4.0.0', allowDowngrade: '1', overridePin: 'repin' })
		expect(isDowngrade(plan[2])).toBe(false)
		expect(isDowngrade(plan[5])).toBe(false)
	})

	it('installs every move in plan order and leaves pinned, unchanged and removed apps alone', async () => {
		const install = fakeInstall()
		const result = await runRollback(plan, { movePins: false, dryRun: false, install })

		expect(install.mock.calls.map(([s]) => s.appId)).toEqual(['opencatalogi', 'openregister', 'mail'])
		expect(result.failedAt).toBeNull()
		expect(runCounts(plan, { outcomes: result.outcomes, movePins: false })).toEqual({ succeeded: 3, failed: 0, skipped: 1 })
	})

	it('moves pinned apps only when the pins move', async () => {
		const install = fakeInstall()
		await runRollback(plan, { movePins: true, dryRun: false, install })

		expect(install.mock.calls.map(([s]) => s.appId)).toContain('calendar')
	})

	it('stops at the first failure, and continuing runs the rest', async () => {
		const install = fakeInstall(['openregister'])
		const first = await runRollback(plan, { movePins: false, dryRun: false, install })

		expect(first.failedAt).toBe(1)
		expect(install.mock.calls.map(([s]) => s.appId)).toEqual(['opencatalogi', 'openregister'])

		const rest = await runRollback(plan, { movePins: false, dryRun: false, startAt: 2, install })
		expect(install.mock.calls.map(([s]) => s.appId)).toEqual(['opencatalogi', 'openregister', 'mail'])
		const all = new Map([...first.outcomes, ...rest.outcomes])
		expect(runCounts(plan, { outcomes: all, movePins: false })).toEqual({ succeeded: 2, failed: 1, skipped: 1 })
	})

	it('checks only the downgrades and never stops a check', async () => {
		const install = fakeInstall(['opencatalogi'])
		const result = await runRollback(plan, { movePins: false, dryRun: true, install })

		expect(install.mock.calls.map(([s, dryRun]) => [s.appId, dryRun])).toEqual([['opencatalogi', true], ['openregister', true]])
		expect(result.outcomes.get(1)?.orphanedMigrations).toEqual(['Version2003Date'])
	})

	it('stops after the current install when asked', async () => {
		const install = fakeInstall()
		let stop = false
		const result = await runRollback(plan, { movePins: false, dryRun: false, shouldStop: () => stop, onStep: () => { stop = true }, install })

		expect(install).toHaveBeenCalledTimes(1)
		expect(result.interrupted).toBe(true)
	})

	it('reads a refused install as failed with its message, and a dry run with its migrations', async () => {
		const fetchMock = vi.fn()
			.mockResolvedValueOnce({ ok: false, json: async () => ({ ocs: { meta: { status: 'failure', statuscode: 502 }, data: { message: 'The checksum does not match' } } }) })
			.mockResolvedValueOnce({ ok: true, json: async () => ({ ocs: { meta: { status: 'ok', statuscode: 200 }, data: { orphanedMigrations: ['Version2003Date'] } } }) })
			.mockRejectedValueOnce(new Error('network down'))
		vi.stubGlobal('fetch', fetchMock)

		expect(await installStep(plan[0], false)).toEqual({ appId: 'opencatalogi', outcome: 'failed', message: 'The checksum does not match', orphanedMigrations: [] })
		expect(await installStep(plan[1], true)).toEqual({ appId: 'openregister', outcome: 'checked', message: '', orphanedMigrations: ['Version2003Date'] })
		expect((await installStep(plan[2], false)).outcome).toBe('failed')
		const [url, init] = fetchMock.mock.calls[0]
		expect(String(url)).toContain('/apps/versioniq/api/app/opencatalogi/versions/1.8.0/install?')
		expect(String(url)).toContain('allowDowngrade=1')
		expect(String(url)).toContain('source=github%3Ac%2Fopencatalogi')
		expect(init.method).toBe('POST')
	})
})
