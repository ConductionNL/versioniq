// SPDX-License-Identifier: EUPL-1.2
// The connection dialog (install-multi-instance-promotion D1, D2): a new
// connection is POSTed with its app password, an edit is PUT and an empty
// password keeps the stored one, and a refusal stays on screen.
// @spec openspec/changes/install-multi-instance-promotion/specs/multi-instance/spec.md#requirement-an-admin-connects-other-instances-that-run-versioniq
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import InstanceConnectionDialog from './InstanceConnectionDialog.vue'
import { ocsWrite } from '../ocs.ts'

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string, vars: Record<string, unknown> = {}) =>
		text.replace(/\{(\w+)\}/g, (_match: string, key: string) => String(vars[key] ?? '')),
}))
vi.mock('@nextcloud/vue/components/NcDialog', () => ({
	default: {
		name: 'NcDialog',
		props: ['open', 'name', 'buttons'],
		emits: ['update:open'],
		template: '<div v-if="open" :data-name="name"><slot /><button v-for="b in buttons" :key="b.label" :data-label="b.label" :disabled="b.disabled" @click="b.callback()">{{ b.label }}</button></div>',
	},
}))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({ default: { name: 'NcNoteCard', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcTextField', () => ({
	default: { name: 'NcTextField', props: ['modelValue', 'label', 'disabled'], emits: ['update:modelValue'], template: '<input :value="modelValue" :disabled="disabled" @input="$emit(\'update:modelValue\', $event.target.value)">' },
}))
vi.mock('../ocs', () => ({ ocsWrite: vi.fn() }))

const mockedWrite = vi.mocked(ocsWrite)
const ENDPOINT = '/ocs/v2.php/apps/versioniq/api/instances'

const stored = { id: 'acc', name: 'Acceptance', stage: 'acceptance', order: 10, baseUrl: 'https://acc.example.org', username: 'versioniq-reader' }

describe('InstanceConnectionDialog', () => {
	beforeEach(() => {
		mockedWrite.mockReset()
	})

	it('adds a connection with its app password and reports it saved', async () => {
		mockedWrite.mockResolvedValue({ payload: stored } as never)
		const wrapper = mount(InstanceConnectionDialog, { props: { open: true, connection: null } })
		await wrapper.find('[data-testid="instance-connection-id"]').setValue('acc')
		await wrapper.find('[data-testid="instance-connection-name"]').setValue(' Acceptance ')
		await wrapper.find('[data-testid="instance-connection-stage"]').setValue('acceptance')
		await wrapper.find('[data-testid="instance-connection-order"]').setValue('10')
		await wrapper.find('[data-testid="instance-connection-url"]').setValue('https://acc.example.org')
		await wrapper.find('[data-testid="instance-connection-username"]').setValue('versioniq-reader')
		await wrapper.find('[data-testid="instance-connection-password"]').setValue('app-password')

		await wrapper.find('[data-label="Save connection"]').trigger('click')
		await flushPromises()

		expect(mockedWrite).toHaveBeenCalledWith('POST', ENDPOINT, { ...stored, password: 'app-password' })
		expect(wrapper.emitted('saved')).toEqual([[stored]])
		expect(wrapper.emitted('update:open')).toEqual([[false]])
	})

	it('changes a connection without its id field and sends an empty password to keep the stored one', async () => {
		mockedWrite.mockResolvedValue({ payload: { ...stored, name: 'Acceptatie' } } as never)
		const wrapper = mount(InstanceConnectionDialog, { props: { open: true, connection: stored } })
		expect(wrapper.find('[data-testid="instance-connection-id"]').exists()).toBe(false)
		expect((wrapper.find('[data-testid="instance-connection-password"]').element as HTMLInputElement).value).toBe('')

		await wrapper.find('[data-testid="instance-connection-name"]').setValue('Acceptatie')
		await wrapper.find('[data-label="Save connection"]').trigger('click')
		await flushPromises()

		expect(mockedWrite).toHaveBeenCalledWith('PUT', `${ENDPOINT}/acc`, { ...stored, name: 'Acceptatie', password: '' })
	})

	it('keeps the dialog open with the server\'s reason', async () => {
		mockedWrite.mockResolvedValue({ payload: { message: 'The address must use https.' }, error: 'Request failed' } as never)
		const wrapper = mount(InstanceConnectionDialog, { props: { open: true, connection: null } })
		await wrapper.find('[data-label="Save connection"]').trigger('click')
		await flushPromises()

		expect(wrapper.find('[data-testid="instance-connection-error"]').text()).toBe('The address must use https.')
		expect(wrapper.emitted('saved')).toBeUndefined()
	})
})
