// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
// The rollback plan dialog: the plan table, the check results, a run that
// takes a snapshot first and records start and end, a stop on failure with
// "Continue with the rest", and the pinned tick.
// @spec openspec/changes/install-bulk-rollback/specs/migration-safety/spec.md
import type { RollbackStep, StepOutcome } from '../utils/rollbackRun.ts'

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import RollbackPlanDialog from './RollbackPlanDialog.vue'

const ocsWrite = vi.fn()
vi.mock('../ocs.ts', () => ({
	ocsWrite: (...args: unknown[]) => ocsWrite(...args),
	ensurePasswordConfirmation: async () => undefined,
	apiUrl: (path: string) => path,
	ocsHeaders: {},
	withOcsJson: (path: string) => path,
}))
vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string, vars: Record<string, unknown> = {}) =>
		text.replace(/\{(\w+)\}/g, (_match: string, key: string) => String(vars[key] ?? '')),
}))
vi.mock('@nextcloud/vue/components/NcDialog', () => ({
	default: {
		name: 'NcDialog',
		props: ['open', 'name', 'buttons', 'size'],
		emits: ['update:open'],
		template: '<div v-if="open"><slot /><button v-for="b in buttons" :key="b.label" :data-label="b.label" :disabled="b.disabled" @click="b.callback()">{{ b.label }}</button><button data-label="dismiss" @click="$emit(\'update:open\', false)">x</button></div>',
	},
}))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({ default: { name: 'NcNoteCard', props: ['type'], template: '<div :data-type="type"><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcCheckboxRadioSwitch', () => ({
	default: { name: 'NcCheckboxRadioSwitch', props: ['modelValue', 'disabled'], emits: ['update:modelValue'], template: '<label><input type="checkbox" :checked="modelValue" @change="$emit(\'update:modelValue\', $event.target.checked)"><slot /></label>' },
}))

const steps: RollbackStep[] = [
	{ appId: 'opencatalogi', kind: 'downgrade', from: '1.8.2', to: '1.8.0', sourceId: null, pinnedTo: null },
	{ appId: 'openregister', kind: 'downgrade', from: '2.3.4', to: '2.3.0', sourceId: null, pinnedTo: null },
	{ appId: 'mail', kind: 'downgrade', from: '3.1.0', to: '3.0.0', sourceId: null, pinnedTo: null },
	{ appId: 'calendar', kind: 'pinned', from: '4.2.0', to: '4.0.0', sourceId: null, pinnedTo: '4.2.0' },
	{ appId: 'gone', kind: 'removed', from: null, to: '1.0.0', sourceId: null, pinnedTo: null },
]
const snapshot = { id: 's20', takenAt: '2026-09-20T10:00:00+00:00', reason: 'before_auto_update', name: null, appCount: 4 }

/**
 * An install that fails for the given apps.
 *
 * @param failing The apps whose install fails.
 */
function fakeInstall (failing: string[] = []) {
	return vi.fn(async (s: RollbackStep, dryRun: boolean): Promise<StepOutcome> => ({
		appId: s.appId,
		outcome: failing.includes(s.appId) ? 'failed' : (dryRun ? 'checked' : 'succeeded'),
		message: failing.includes(s.appId) ? 'The checksum does not match' : '',
		orphanedMigrations: dryRun && s.appId === 'openregister' ? ['Version2003Date'] : [],
	}))
}

/**
 * The dialog, open on the plan.
 *
 * @param install The install of one step.
 * @param pickedFor The date the snapshot was picked for.
 */
async function openDialog (install = fakeInstall(), pickedFor = '') {
	const wrapper = mount(RollbackPlanDialog, { props: { open: false, snapshot, steps, install, pickedFor } })
	await wrapper.setProps({ open: true })
	return wrapper
}

function outcome (wrapper: Awaited<ReturnType<typeof openDialog>>, app: string): string {
	return wrapper.find(`tr[data-app="${app}"] [data-test="outcome"]`).text()
}

beforeEach(() => {
	ocsWrite.mockReset()
	ocsWrite.mockResolvedValue({ payload: {} })
})

describe('RollbackPlanDialog', () => {
	it('shows one row per app with what happens to it, and the date it was picked for', async () => {
		const wrapper = await openDialog(fakeInstall(), '2026-09-23')

		expect(wrapper.findAll('tbody tr').map((row) => row.findAll('td').slice(0, 4).map((cell) => cell.text()))).toEqual([
			['opencatalogi', '1.8.2', '1.8.0', 'Moves back'],
			['openregister', '2.3.4', '2.3.0', 'Moves back'],
			['mail', '3.1.0', '3.0.0', 'Moves back'],
			['calendar', '4.2.0', '4.0.0', 'Pinned to 4.2.0'],
			['gone', '—', '1.0.0', 'Removed since; left alone'],
		])
		expect(wrapper.text()).toContain('The newest snapshot on or before 2026-09-23 was taken on 2026-09-20T10:00:00+00:00.')
	})

	it('checks every downgrade and shows the migrations it would orphan, changing nothing', async () => {
		const install = fakeInstall()
		const wrapper = await openDialog(install)

		await wrapper.find('[data-label="Check"]').trigger('click')
		await flushPromises()

		expect(install.mock.calls.every(([, dryRun]) => dryRun)).toBe(true)
		expect(outcome(wrapper, 'openregister')).toBe('Would orphan: Version2003Date')
		expect(outcome(wrapper, 'opencatalogi')).toBe('No migrations orphaned')
		expect(ocsWrite).not.toHaveBeenCalled()
	})

	it('takes a snapshot, records the start, installs every move and records the end', async () => {
		const install = fakeInstall()
		const wrapper = await openDialog(install)

		await wrapper.find('[data-label="Run"]').trigger('click')
		await flushPromises()

		expect(ocsWrite.mock.calls.map(([method, path, body]) => [method, path, body])).toEqual([
			['POST', '/ocs/v2.php/apps/versioniq/api/snapshots', { reason: 'before_rollback' }],
			['POST', '/ocs/v2.php/apps/versioniq/api/snapshots/s20/run-log', { phase: 'start', steps: 3 }],
			['POST', '/ocs/v2.php/apps/versioniq/api/snapshots/s20/run-log', { phase: 'end', succeeded: 3, failed: 0, skipped: 1 }],
		])
		expect(install.mock.calls.map(([s, dryRun]) => [s.appId, dryRun])).toEqual([['opencatalogi', false], ['openregister', false], ['mail', false]])
		expect(wrapper.text()).toContain('3 succeeded, 0 failed, 1 skipped')
		expect(wrapper.emitted('finished')).toHaveLength(1)
	})

	it('stops at a failure and offers to continue with the rest', async () => {
		const install = fakeInstall(['openregister'])
		const wrapper = await openDialog(install)

		await wrapper.find('[data-label="Run"]').trigger('click')
		await flushPromises()

		expect(install.mock.calls.map(([s]) => s.appId)).toEqual(['opencatalogi', 'openregister'])
		expect(outcome(wrapper, 'openregister')).toBe('Failed: The checksum does not match')
		expect(wrapper.text()).toContain('The run stopped at openregister.')
		expect(ocsWrite).toHaveBeenCalledTimes(2)

		await wrapper.find('[data-label="Continue with the rest"]').trigger('click')
		await flushPromises()

		expect(install.mock.calls.map(([s]) => s.appId)).toEqual(['opencatalogi', 'openregister', 'mail'])
		expect(ocsWrite.mock.calls[2][2]).toEqual({ phase: 'end', succeeded: 2, failed: 1, skipped: 1 })
	})

	it('stopping at a failure records the end with the rest skipped', async () => {
		const wrapper = await openDialog(fakeInstall(['opencatalogi']))

		await wrapper.find('[data-label="Run"]').trigger('click')
		await flushPromises()
		await wrapper.find('[data-label="Stop here"]').trigger('click')
		await flushPromises()

		expect(ocsWrite.mock.calls[2][2]).toEqual({ phase: 'end', succeeded: 0, failed: 1, skipped: 3 })
	})

	it('includes pinned apps once the pins are ticked', async () => {
		const install = fakeInstall()
		const wrapper = await openDialog(install)

		await wrapper.find('[data-test="move-pins"] input').setValue(true)
		await wrapper.find('[data-label="Run"]').trigger('click')
		await flushPromises()

		expect(install.mock.calls.map(([s]) => s.appId)).toEqual(['opencatalogi', 'openregister', 'mail', 'calendar'])
		expect(ocsWrite.mock.calls[1][2]).toEqual({ phase: 'start', steps: 4 })
	})

	it('shows the error when the snapshot before the run fails and installs nothing', async () => {
		ocsWrite.mockResolvedValueOnce({ payload: {}, error: 'Password confirmation was cancelled' })
		const install = fakeInstall()
		const wrapper = await openDialog(install)

		await wrapper.find('[data-label="Run"]').trigger('click')
		await flushPromises()

		expect(install).not.toHaveBeenCalled()
		expect(wrapper.text()).toContain('Password confirmation was cancelled')
	})
})
