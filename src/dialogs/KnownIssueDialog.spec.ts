// SPDX-License-Identifier: EUPL-1.2
// The known-issue dialog sends the issue to the version's endpoint, emits the
// returned notes and closes; it refuses an empty issue and a link that is not
// https before sending, and stays open with the server's refusal.
// @spec openspec/changes/releases-version-facts/specs/changelog-visibility/spec.md#requirement-known-issues-of-a-version-are-shown-before-and-after-installing-it
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import KnownIssueDialog from './KnownIssueDialog.vue'
import { ocsWrite } from '../ocs.ts'

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
vi.mock('@nextcloud/vue/components/NcTextField', () => ({
	default: { name: 'NcTextField', props: ['modelValue', 'label', 'disabled', 'error', 'helperText'], emits: ['update:modelValue'], template: '<input :aria-label="label" :value="modelValue" :disabled="disabled" @input="$emit(\'update:modelValue\', $event.target.value)">' },
}))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({
	default: { name: 'NcNoteCard', props: ['type'], template: '<div role="alert" :data-type="type"><slot /></div>' },
}))
vi.mock('../ocs', () => ({
	ocsWrite: vi.fn(),
}))

const mockedOcsWrite = vi.mocked(ocsWrite)

/**
 * Mounts the dialog closed and opens it, as App.vue does.
 */
async function openDialog () {
	const wrapper = mount(KnownIssueDialog, { props: { open: false, appId: 'openregister', version: '2.4.1' } })
	await wrapper.setProps({ open: true })
	return wrapper
}

/**
 * Types into the field with this label.
 *
 * @param wrapper The dialog.
 * @param label The field's label.
 * @param value The text.
 */
async function type (wrapper: Awaited<ReturnType<typeof openDialog>>, label: string, value: string) {
	await wrapper.find(`input[aria-label="${label}"]`).setValue(value)
}

describe('KnownIssueDialog', () => {
	beforeEach(() => {
		mockedOcsWrite.mockReset()
	})

	it('names the app and version', async () => {
		const wrapper = await openDialog()

		expect(wrapper.find('[data-name]').attributes('data-name')).toBe('Known issue in openregister 2.4.1')
	})

	it('saves the issue, emits the version notes and closes', async () => {
		const stored = [{ text: 'Export to CSV times out on large registers', workaround: 'Export per schema', link: null, addedBy: 'alice' }]
		mockedOcsWrite.mockResolvedValue({ payload: { knownIssues: stored } })
		const wrapper = await openDialog()

		await type(wrapper, 'Issue', '  Export to CSV times out on large registers ')
		await type(wrapper, 'Workaround (optional)', 'Export per schema')
		await wrapper.find('[data-label="Save"]').trigger('click')
		await flushPromises()

		expect(mockedOcsWrite).toHaveBeenCalledWith(
			'PUT',
			'/ocs/v2.php/apps/versioniq/api/app/openregister/versions/2.4.1/known-issues',
			{ text: 'Export to CSV times out on large registers', workaround: 'Export per schema' },
		)
		expect(wrapper.emitted('saved')).toEqual([['2.4.1', stored]])
		expect(wrapper.emitted('update:open')).toEqual([[false]])
	})

	it('sends an https link along', async () => {
		mockedOcsWrite.mockResolvedValue({ payload: { knownIssues: [] } })
		const wrapper = await openDialog()

		await type(wrapper, 'Issue', 'Broken')
		await type(wrapper, 'Link (optional, https)', 'https://example.org/1')
		await wrapper.find('[data-label="Save"]').trigger('click')
		await flushPromises()

		expect(mockedOcsWrite.mock.calls[0][2]).toEqual({ text: 'Broken', workaround: '', link: 'https://example.org/1' })
	})

	it('refuses an empty issue and an http link without sending', async () => {
		const wrapper = await openDialog()

		await wrapper.find('[data-label="Save"]').trigger('click')
		expect(wrapper.text()).toContain('Describe the issue.')

		await type(wrapper, 'Issue', 'Broken')
		await type(wrapper, 'Link (optional, https)', 'http://example.org/1')
		await wrapper.find('[data-label="Save"]').trigger('click')
		await flushPromises()

		expect(wrapper.text()).toContain('The link must start with https://.')
		expect(mockedOcsWrite).not.toHaveBeenCalled()
		expect(wrapper.emitted('saved')).toBeUndefined()
	})

	it('stays open with the refusal of the server', async () => {
		mockedOcsWrite.mockResolvedValue({ payload: { knownIssues: [] }, error: 'At most 20 known issues per app.' })
		const wrapper = await openDialog()

		await type(wrapper, 'Issue', 'Broken')
		await wrapper.find('[data-label="Save"]').trigger('click')
		await flushPromises()

		expect(wrapper.text()).toContain('At most 20 known issues per app.')
		expect(wrapper.emitted('saved')).toBeUndefined()
		expect(wrapper.emitted('update:open')).toBeUndefined()
	})

	it('shows a failed request and stays open', async () => {
		mockedOcsWrite.mockRejectedValue(new Error('Password confirmation was cancelled'))
		const wrapper = await openDialog()

		await type(wrapper, 'Issue', 'Broken')
		await wrapper.find('[data-label="Save"]').trigger('click')
		await flushPromises()

		expect(wrapper.text()).toContain('Password confirmation was cancelled')
		expect(wrapper.emitted('update:open')).toBeUndefined()
	})
})
