import { flushPromises, mount } from '@vue/test-utils'
// SPDX-License-Identifier: EUPL-1.2
// Pins the add-token form as an admin uses it: the fields typed into the
// form are what is posted, the success notice shows, the forge picker keeps
// the option id, and an expiry state the panel does not know shows a blank
// badge rather than a wrong one.
// @spec openspec/specs/pat-management/spec.md
import { beforeEach, describe, expect, it, vi } from 'vitest'
import TokensPanel from './TokensPanel.vue'
import { ocsGet, ocsWrite } from '../ocs.ts'

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string, vars: Record<string, unknown> = {}) =>
		text.replace(/\{(\w+)\}/g, (_match: string, key: string) => String(vars[key] ?? '')),
}))

vi.mock('@nextcloud/vue/components/NcButton', () => ({
	default: { name: 'NcButton', props: ['type'], template: '<button :type="type || \'button\'"><slot /></button>' },
}))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({
	default: { name: 'NcNoteCard', props: ['type'], template: '<div :data-type="type"><slot /></div>' },
}))
vi.mock('@nextcloud/vue/components/NcSelect', () => ({
	default: { name: 'NcSelect', props: ['modelValue', 'reduce', 'options'], emits: ['update:modelValue'], template: '<select />' },
}))
vi.mock('@nextcloud/vue/components/NcTextField', () => ({
	default: {
		name: 'NcTextField',
		props: ['modelValue', 'label'],
		emits: ['update:modelValue'],
		template: '<input :aria-label="label" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)">',
	},
}))

vi.mock('../ocs', () => ({
	ocsGet: vi.fn(),
	ocsWrite: vi.fn(),
	ensurePasswordConfirmation: vi.fn(async () => undefined),
}))

const mockedOcsGet = vi.mocked(ocsGet)
const mockedOcsWrite = vi.mocked(ocsWrite)

/**
 * The text field with the given label.
 *
 * @param wrapper The mounted panel.
 * @param label The field's label.
 */
function field (wrapper: ReturnType<typeof mount>, label: string) {
	return wrapper.get(`input[aria-label="${label}"]`)
}

describe('TokensPanel form', () => {
	beforeEach(() => {
		mockedOcsGet.mockReset()
		mockedOcsWrite.mockReset()
	})

	it('posts what was typed into the form and shows the success notice', async () => {
		mockedOcsGet.mockResolvedValue({ payload: { pats: [] } })
		mockedOcsWrite.mockResolvedValue({ payload: {} } as never)
		const wrapper = mount(TokensPanel)
		await flushPromises()

		const forge = wrapper.getComponent({ name: 'NcSelect' })
		expect(forge.props('reduce')({ id: 'gitlab', label: 'GitLab' })).toBe('gitlab')
		forge.vm.$emit('update:modelValue', 'gitlab')
		await field(wrapper, 'Label').setValue('CI')
		await field(wrapper, 'Owner').setValue('acme')
		await field(wrapper, 'Repository (optional — blank covers the whole owner)').setValue('app')
		await field(wrapper, 'Token').setValue('glpat-1')
		await wrapper.get('form').trigger('submit')
		await flushPromises()

		expect(mockedOcsWrite).toHaveBeenCalledWith('POST', '/ocs/v2.php/apps/versioniq/api/pats', { forge: 'gitlab', label: 'CI', targetPattern: 'acme/app', token: 'glpat-1' })
		expect(wrapper.get('[data-type="success"]').text()).toBe('Token added.')
		expect((field(wrapper, 'Token').element as HTMLInputElement).value).toBe('')
	})

	it('renders an empty badge for an expiry state it does not know', async () => {
		mockedOcsGet.mockResolvedValue({ payload: { pats: [{ id: 1, label: 'Odd', forge: 'github', targetPattern: 'acme/*', expiryState: 'revoked', sharedWithAdmins: false }] } })
		const wrapper = mount(TokensPanel)
		await flushPromises()

		const badge = wrapper.get('[data-testid="expiry-badge"]')
		expect(badge.attributes('data-expiry-state')).toBe('revoked')
		expect(badge.text()).toBe('')
	})
})
