// SPDX-License-Identifier: EUPL-1.2
// The Instances tab (install-multi-instance-promotion D5): one column per
// instance in chain order, a "Differs" mark and filter, a stale remote with
// its age and error, an older Versioniq, and a promotion from an earlier stage.
// @spec openspec/changes/install-multi-instance-promotion/specs/multi-instance/spec.md#requirement-the-instances-tab-shows-every-instances-versions-side-by-side
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import InstancesPanel from './InstancesPanel.vue'
import { ocsGet, ocsPost, ocsWrite } from '../ocs.ts'

vi.mock('@nextcloud/l10n', () => ({
	getCanonicalLocale: () => 'en-US',
	t: (_app: string, text: string, vars: Record<string, unknown> = {}) =>
		text.replace(/\{(\w+)\}/g, (_match: string, key: string) => String(vars[key] ?? '')),
	n: (_app: string, one: string, many: string, count: number, vars: Record<string, unknown> = {}) =>
		(count === 1 ? one : many).replace(/\{(\w+)\}/g, (_match: string, key: string) => String(vars[key] ?? '')),
}))
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', props: ['disabled'], emits: ['click'], template: '<button type="button" :disabled="disabled" @click="$emit(\'click\')"><slot /></button>' } }))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({ default: { name: 'NcNoteCard', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcCheckboxRadioSwitch', () => ({
	default: {
		name: 'NcCheckboxRadioSwitch',
		props: ['modelValue'],
		emits: ['update:modelValue'],
		template: '<label><input type="checkbox" :checked="modelValue" @change="$emit(\'update:modelValue\', $event.target.checked)"><slot /></label>',
	},
}))
vi.mock('../dialogs/InstanceConnectionDialog.vue', () => ({ default: { name: 'InstanceConnectionDialog', props: ['open', 'connection'], template: '<div v-if="open" data-testid="connection-dialog-stub" />' } }))
vi.mock('../dialogs/PromoteDialog.vue', () => ({
	default: {
		name: 'PromoteDialog',
		props: ['open', 'appId', 'connection'],
		emits: ['rollback'],
		template: '<div v-if="open" data-testid="promote-dialog-stub" :data-app="appId" :data-connection="connection.id" />',
	},
}))
vi.mock('../ocs', () => ({ ocsGet: vi.fn(), ocsPost: vi.fn(), ocsWrite: vi.fn() }))

const mockedGet = vi.mocked(ocsGet)
const mockedPost = vi.mocked(ocsPost)
const mockedWrite = vi.mocked(ocsWrite)

const self = { name: 'Production', stage: 'production', order: 20 }

const ownManifest = {
	instanceName: 'Production',
	stage: 'production',
	apps: [
		{ appId: 'openregister', installedVersion: '2.3.0', runningSince: '2026-08-01T00:00:00+00:00' },
		{ appId: 'pipelinq', installedVersion: '1.2.0', runningSince: null },
	],
}

/**
 * @param remote The remote state of the acceptance connection.
 */
function acceptance (remote: Record<string, unknown>) {
	return { id: 'acc', name: 'Acceptance', stage: 'acceptance', order: 10, baseUrl: 'https://acc.example.org', username: 'versioniq-reader', remote }
}

const freshAcceptance = acceptance({
	manifest: {
		apps: [
			{ appId: 'openregister', installedVersion: '2.4.1', runningSince: '2026-09-01T00:00:00+00:00' },
			{ appId: 'pipelinq', installedVersion: '1.2.0', runningSince: null },
		],
	},
	fetchedAt: '2026-11-15T08:00:00+00:00',
	error: null,
	checkedAt: '2026-11-15T08:00:00+00:00',
})

/**
 * @param instances The connections the list answers with.
 */
function answer (instances: unknown[]) {
	mockedGet.mockImplementation(async (path: string) => path.endsWith('/instance/manifest')
		? { payload: ownManifest }
		: { payload: { self, instances } }) as never
}

describe('InstancesPanel', () => {
	beforeEach(() => {
		mockedGet.mockReset()
		mockedPost.mockReset()
		mockedWrite.mockReset()
		vi.useFakeTimers({ toFake: ['Date'] })
		vi.setSystemTime(new Date('2026-11-16T12:00:00Z'))
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	it('shows a differing row with each instance\'s version in chain order', async () => {
		answer([freshAcceptance])
		const wrapper = mount(InstancesPanel)
		await flushPromises()

		const headers = wrapper.findAll('thead th').map((header) => header.text())
		expect(headers[1]).toContain('Acceptance')
		expect(headers[2]).toContain('Production')
		expect(headers[2]).toContain('production (this instance)')

		const row = wrapper.find('[data-testid="instances-row-openregister"]')
		expect(row.attributes('data-differs')).toBe('true')
		expect(row.find('[data-testid="instances-differs"]').text()).toBe('Differs')
		expect(row.findAll('[data-testid="instances-cell-version"]').map((cell) => cell.text())).toEqual(['2.4.1', '2.3.0'])
		expect(row.text()).toContain('since Sep 1, 2026')

		const same = wrapper.find('[data-testid="instances-row-pipelinq"]')
		expect(same.attributes('data-differs')).toBe('false')
		expect(same.find('[data-testid="instances-differs"]').exists()).toBe(false)
	})

	it('shows only the differing rows when the filter is on', async () => {
		answer([freshAcceptance])
		const wrapper = mount(InstancesPanel)
		await flushPromises()
		expect(wrapper.findAll('tbody tr[data-differs]')).toHaveLength(2)

		await wrapper.find('[data-testid="instances-differs-only"] input').setValue(true)

		const rows = wrapper.findAll('tbody tr[data-differs]')
		expect(rows).toHaveLength(1)
		expect(rows[0].attributes('data-testid')).toBe('instances-row-openregister')
	})

	it('keeps a stale remote\'s last versions with their age and the error', async () => {
		answer([acceptance({
			manifest: { apps: [{ appId: 'openregister', installedVersion: '2.4.1', runningSince: null }] },
			fetchedAt: '2026-11-15T08:00:00+00:00',
			error: 'The instance could not be reached.',
			checkedAt: '2026-11-16T11:00:00+00:00',
		})])
		const wrapper = mount(InstancesPanel)
		await flushPromises()

		const column = wrapper.find('[data-testid="instances-column-acc"]')
		expect(column.find('[data-testid="instances-column-age"]').text()).toBe('Read 1 day ago')
		expect(column.find('[data-testid="instances-column-error"]').text()).toBe('The instance could not be reached.')
		expect(wrapper.find('[data-testid="instances-row-openregister"] [data-testid="instances-cell-version"]').text()).toBe('2.4.1')
	})

	it('shows a remote without the manifest endpoint as an older Versioniq, not as broken', async () => {
		answer([acceptance({ manifest: null, fetchedAt: null, error: 'This instance runs an older Versioniq without instance support.', checkedAt: '2026-11-16T11:00:00+00:00' })])
		const wrapper = mount(InstancesPanel)
		await flushPromises()

		const column = wrapper.find('[data-testid="instances-column-acc"]')
		expect(column.find('[data-testid="instances-column-older"]').text()).toBe('Runs an older Versioniq without instance support')
		expect(column.find('[data-testid="instances-column-error"]').exists()).toBe(false)
		expect(column.find('[data-testid="instances-column-age"]').text()).toBe('Never read')
		// Nothing is known about it, so no row differs because of it.
		expect(wrapper.find('[data-testid="instances-row-openregister"]').attributes('data-differs')).toBe('false')
	})

	it('refreshes through POST /api/instances/refresh and shows the new answer', async () => {
		answer([freshAcceptance])
		mockedPost.mockResolvedValue({ payload: { self, instances: [acceptance({ ...freshAcceptance.remote, manifest: { apps: [{ appId: 'openregister', installedVersion: '2.3.0', runningSince: null }] } })] } } as never)
		const wrapper = mount(InstancesPanel)
		await flushPromises()

		await wrapper.find('[data-testid="instances-refresh"]').trigger('click')
		await flushPromises()

		expect(mockedPost).toHaveBeenCalledWith('/ocs/v2.php/apps/versioniq/api/instances/refresh')
		expect(wrapper.find('[data-testid="instances-row-openregister"]').attributes('data-differs')).toBe('false')
	})

	it('offers a promotion only from an earlier stage that runs another version', async () => {
		answer([freshAcceptance, { id: 'test', name: 'Test', stage: 'test', order: 30, baseUrl: 'https://test.example.org', username: 'r', remote: { manifest: { apps: [{ appId: 'openregister', installedVersion: '2.5.0', runningSince: null }] }, fetchedAt: '2026-11-16T00:00:00+00:00', error: null, checkedAt: null } }])
		const wrapper = mount(InstancesPanel)
		await flushPromises()

		const promote = wrapper.findAll('[data-testid="instances-row-openregister"] [data-testid="instances-promote"]')
		expect(promote).toHaveLength(1)
		expect(promote[0].text()).toBe('Promote from Acceptance')
		expect(wrapper.find('[data-testid="instances-row-pipelinq"] [data-testid="instances-promote"]').exists()).toBe(false)

		await promote[0].trigger('click')
		const dialog = wrapper.find('[data-testid="promote-dialog-stub"]')
		expect(dialog.attributes('data-app')).toBe('openregister')
		expect(dialog.attributes('data-connection')).toBe('acc')
	})

	it('removes a connection through DELETE and drops its column', async () => {
		answer([freshAcceptance])
		mockedWrite.mockResolvedValue({ payload: { id: 'acc' } } as never)
		const wrapper = mount(InstancesPanel)
		await flushPromises()

		await wrapper.find('[data-testid="instances-column-acc"] [data-testid="instances-remove"]').trigger('click')
		await flushPromises()

		expect(mockedWrite).toHaveBeenCalledWith('DELETE', '/ocs/v2.php/apps/versioniq/api/instances/acc')
		expect(wrapper.find('[data-testid="instances-column-acc"]').exists()).toBe(false)
	})

	it('passes a rollback from the promotion result on to the page', async () => {
		answer([freshAcceptance])
		const wrapper = mount(InstancesPanel)
		await flushPromises()
		await wrapper.find('[data-testid="instances-promote"]').trigger('click')

		wrapper.findComponent({ name: 'PromoteDialog' }).vm.$emit('rollback', { appId: 'openregister', version: '2.3.0' })

		expect(wrapper.emitted('rollback')).toEqual([[{ appId: 'openregister', version: '2.3.0' }]])
	})
})
