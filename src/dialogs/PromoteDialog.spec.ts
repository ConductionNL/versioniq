// SPDX-License-Identifier: EUPL-1.2
// The promotion dialog (install-multi-instance-promotion D6, D7): the
// waiting-period message and its override, the downgrade confirmation, the
// version it sends, a refusal kept on screen, and the rollback link after.
// @spec openspec/changes/install-multi-instance-promotion/specs/multi-instance/spec.md#requirement-an-admin-promotes-the-exact-package-an-earlier-stage-runs
// @spec openspec/changes/install-multi-instance-promotion/specs/multi-instance/spec.md#requirement-a-version-waits-a-set-number-of-days-on-the-earlier-stage
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import PromoteDialog from './PromoteDialog.vue'
import { ocsGet, ocsWrite } from '../ocs.ts'

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string, vars: Record<string, unknown> = {}) =>
		text.replace(/\{(\w+)\}/g, (_match: string, key: string) => String(vars[key] ?? '')),
	n: (_app: string, one: string, many: string, count: number, vars: Record<string, unknown> = {}) =>
		(count === 1 ? one : many).replace(/\{(\w+)\}/g, (_match: string, key: string) => String(vars[key] ?? '')),
}))
vi.mock('@nextcloud/vue/components/NcDialog', () => ({
	default: {
		name: 'NcDialog',
		props: ['open', 'name', 'buttons'],
		emits: ['update:open'],
		template: '<div v-if="open" :data-name="name"><slot /><button v-for="b in buttons" :key="b.label" :data-label="b.label" :disabled="b.disabled" @click="b.callback()">{{ b.label }}</button></div>',
	},
}))
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', emits: ['click'], template: '<button type="button" @click="$emit(\'click\')"><slot /></button>' } }))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({ default: { name: 'NcNoteCard', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcCheckboxRadioSwitch', () => ({
	default: {
		name: 'NcCheckboxRadioSwitch',
		props: ['modelValue'],
		emits: ['update:modelValue'],
		template: '<label><input type="checkbox" :checked="modelValue" @change="$emit(\'update:modelValue\', $event.target.checked)"><slot /></label>',
	},
}))
vi.mock('../ocs', () => ({ ocsGet: vi.fn(), ocsWrite: vi.fn() }))

const mockedGet = vi.mocked(ocsGet)
const mockedWrite = vi.mocked(ocsWrite)

const connection = { id: 'acc', name: 'Acceptance', stage: 'acceptance' }
const ENDPOINT = '/ocs/v2.php/apps/versioniq/api/app/openregister/promote'

/**
 * @param overrides Fields that differ from an upgrade whose waiting period is met.
 */
function plan (overrides: Record<string, unknown> = {}) {
	return {
		connection,
		appId: 'openregister',
		version: '2.4.1',
		sourceId: 'github:ConductionNL/openregister',
		sha256: 'abc',
		runningSince: '2026-09-01T00:00:00+00:00',
		installedVersion: '2.3.0',
		direction: 'upgrade',
		minDays: 14,
		daysLeft: 0,
		waitMet: true,
		...overrides,
	}
}

/**
 * @param shown The plan the dialog reads.
 */
async function openWith (shown: ReturnType<typeof plan>) {
	mockedGet.mockResolvedValue({ payload: shown } as never)
	const wrapper = mount(PromoteDialog, { props: { open: true, appId: 'openregister', connection } })
	await flushPromises()
	return wrapper
}

describe('PromoteDialog', () => {
	beforeEach(() => {
		mockedGet.mockReset()
		mockedWrite.mockReset()
	})

	it('reads the plan for the connection and promotes the version it showed', async () => {
		mockedWrite.mockResolvedValue({ payload: { previousVersion: '2.3.0' } } as never)
		const wrapper = await openWith(plan())

		expect(mockedGet).toHaveBeenCalledWith(ENDPOINT, { connection: 'acc' })
		expect(wrapper.find('[data-testid="promote-summary"]').text()).toBe('This instance runs 2.3.0. Acceptance (acceptance) runs 2.4.1.')
		expect(wrapper.find('[data-testid="promote-waiting"]').exists()).toBe(false)

		await wrapper.find('[data-label="Promote"]').trigger('click')
		await flushPromises()

		expect(mockedWrite).toHaveBeenCalledWith('POST', ENDPOINT, { connection: 'acc', version: '2.4.1', allowDowngrade: false, overrideMinDays: false })
		expect(wrapper.find('[data-testid="promote-done"]').text()).toBe('openregister 2.4.1 is installed, promoted from Acceptance.')
		expect(wrapper.emitted('promoted')).toEqual([['openregister']])
	})

	it('names the days left and promotes inside the waiting period only with the override', async () => {
		mockedWrite.mockResolvedValue({ payload: { previousVersion: '2.3.0' } } as never)
		const wrapper = await openWith(plan({ daysLeft: 9, waitMet: false }))

		expect(wrapper.find('[data-testid="promote-waiting"]').text()).toBe('2.4.1 has not run on Acceptance long enough: 9 days of the waiting period are left.')
		expect(wrapper.find('[data-label="Promote"]').attributes('disabled')).toBeDefined()

		await wrapper.find('[data-testid="promote-override"] input').setValue(true)
		expect(wrapper.find('[data-label="Promote"]').attributes('disabled')).toBeUndefined()
		await wrapper.find('[data-label="Promote"]').trigger('click')
		await flushPromises()

		expect(mockedWrite).toHaveBeenCalledWith('POST', ENDPOINT, expect.objectContaining({ overrideMinDays: true, allowDowngrade: false }))
	})

	it('treats an unknown running time as not met', async () => {
		const wrapper = await openWith(plan({ runningSince: null, daysLeft: 14, waitMet: false }))

		expect(wrapper.find('[data-testid="promote-waiting"]').text()).toBe('It is not known since when Acceptance runs 2.4.1, so the waiting period of 14 days is not met.')
		expect(wrapper.find('[data-label="Promote"]').attributes('disabled')).toBeDefined()
	})

	it('asks for the downgrade confirmation and sends it', async () => {
		mockedWrite.mockResolvedValue({ payload: { previousVersion: '2.5.0' } } as never)
		const wrapper = await openWith(plan({ installedVersion: '2.5.0', direction: 'downgrade' }))

		expect(wrapper.find('[data-testid="promote-downgrade"]').text()).toContain('2.4.1 is older than the 2.5.0 this instance runs.')
		expect(wrapper.find('[data-label="Promote"]').attributes('disabled')).toBeDefined()

		await wrapper.find('[data-testid="promote-downgrade-confirm"] input').setValue(true)
		await wrapper.find('[data-label="Promote"]').trigger('click')
		await flushPromises()

		expect(mockedWrite).toHaveBeenCalledWith('POST', ENDPOINT, expect.objectContaining({ allowDowngrade: true }))
	})

	it('offers the rollback to the previous version after the promotion', async () => {
		mockedWrite.mockResolvedValue({ payload: { previousVersion: '2.3.0', promotedFrom: connection } } as never)
		const wrapper = await openWith(plan())
		await wrapper.find('[data-label="Promote"]').trigger('click')
		await flushPromises()

		const rollback = wrapper.find('[data-testid="promote-rollback"]')
		expect(rollback.text()).toBe('Roll back to 2.3.0')
		await rollback.trigger('click')

		expect(wrapper.emitted('rollback')).toEqual([[{ appId: 'openregister', version: '2.3.0' }]])
	})

	it('keeps a refusal on screen with the server\'s reason and the newer plan', async () => {
		mockedWrite.mockResolvedValue({
			payload: { code: 'version_moved', message: 'Acceptance no longer runs 2.4.1; it runs 2.4.2 now.', plan: plan({ version: '2.4.2' }) },
			error: 'Request failed',
		} as never)
		const wrapper = await openWith(plan())
		await wrapper.find('[data-label="Promote"]').trigger('click')
		await flushPromises()

		expect(wrapper.find('[data-testid="promote-error"]').text()).toBe('Acceptance no longer runs 2.4.1; it runs 2.4.2 now.')
		expect(wrapper.find('[data-testid="promote-summary"]').text()).toContain('runs 2.4.2.')
		expect(wrapper.find('[data-testid="promote-done"]').exists()).toBe(false)
		expect(wrapper.emitted('promoted')).toBeUndefined()
	})

	it('shows why there is nothing to plan', async () => {
		mockedGet.mockResolvedValue({ payload: { code: 'not_installed', message: 'Acceptance does not run openregister.' }, error: 'Request failed' } as never)
		const wrapper = mount(PromoteDialog, { props: { open: true, appId: 'openregister', connection } })
		await flushPromises()

		expect(wrapper.find('[data-testid="promote-error"]').text()).toBe('Acceptance does not run openregister.')
		expect(wrapper.find('[data-label="Promote"]').attributes('disabled')).toBeDefined()
	})
})
