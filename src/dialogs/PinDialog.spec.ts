// SPDX-License-Identifier: EUPL-1.2
// The pin dialog owns its write call: it sends the version and a trimmed,
// optional reason, emits the stored pin and closes on success, and keeps
// itself open with the reason shown on a refusal or error.
// @spec openspec/specs/version-pinning/spec.md
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import PinDialog from './PinDialog.vue'
import { ocsWrite } from '../ocs.ts'

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string, vars: Record<string, unknown> = {}) =>
		text.replace(/\{(\w+)\}/g, (_match: string, key: string) => String(vars[key] ?? '')),
	n: (_app: string, one: string, many: string, count: number, vars: Record<string, unknown> = {}) =>
		(count === 1 ? one : many).replace(/\{(\w+)\}/g, (_match: string, key: string) => String(vars[key] ?? '')),
}))
// The dialog renders its buttons and a dismiss control, so a spec can drive
// every callback and the close (Escape / backdrop) path.
vi.mock('@nextcloud/vue/components/NcDialog', () => ({
	default: {
		name: 'NcDialog',
		props: ['open', 'name', 'buttons'],
		emits: ['update:open'],
		template: '<div v-if="open" :data-name="name"><slot /><button v-for="b in buttons" :key="b.label" :data-label="b.label" :disabled="b.disabled" @click="b.callback()">{{ b.label }}</button><button data-label="dismiss" @click="$emit(\'update:open\', false)">x</button></div>',
	},
}))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({ default: { name: 'NcNoteCard', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcTextField', () => ({
	default: { name: 'NcTextField', props: ['modelValue', 'label', 'disabled'], emits: ['update:modelValue'], template: '<input :value="modelValue" :disabled="disabled" @input="$emit(\'update:modelValue\', $event.target.value)">' },
}))

vi.mock('../ocs', () => ({
	ocsWrite: vi.fn(),
}))

const mockedOcsWrite = vi.mocked(ocsWrite)

const storedPin = { appId: 'ignored', version: '2.4.1', pinnedBy: 'admin', pinnedAt: '2026-11-13T08:00:00+00:00', reason: 'LDAP' }

/**
 * Mounts the dialog closed and opens it, as App.vue does.
 *
 * @param extra extra props
 */
async function openDialog (extra: Record<string, unknown> = {}) {
	const wrapper = mount(PinDialog, { props: { open: false, appId: 'user_ldap', version: '2.4.1', ...extra } })
	await wrapper.setProps({ open: true })
	return wrapper
}

describe('PinDialog', () => {
	beforeEach(() => {
		mockedOcsWrite.mockReset()
	})

	it('names the app and version in its title', async () => {
		const wrapper = await openDialog()

		expect(wrapper.find('[data-name]').attributes('data-name')).toBe('Pin user_ldap at 2.4.1')
	})

	it('pins with a trimmed reason, emits the stored pin for this app and closes', async () => {
		mockedOcsWrite.mockResolvedValue({ payload: { appId: 'user_ldap', pin: storedPin } } as never)
		const wrapper = await openDialog()

		await wrapper.find('input').setValue('  LDAP  ')
		await wrapper.find('[data-label="Pin"]').trigger('click')
		await flushPromises()

		expect(mockedOcsWrite).toHaveBeenCalledWith('PUT', '/ocs/v2.php/apps/versioniq/api/app/user_ldap/pin', { version: '2.4.1', reason: 'LDAP' })
		expect(wrapper.emitted('pinned')).toEqual([[{ ...storedPin, appId: 'user_ldap' }]])
		expect(wrapper.emitted('update:open')).toEqual([[false]])
	})

	it('leaves a blank reason out and encodes the app id', async () => {
		mockedOcsWrite.mockResolvedValue({ payload: { appId: 'a/b', pin: storedPin } } as never)
		const wrapper = await openDialog({ appId: 'a/b' })

		await wrapper.find('input').setValue('   ')
		await wrapper.find('[data-label="Pin"]').trigger('click')
		await flushPromises()

		expect(mockedOcsWrite).toHaveBeenCalledWith('PUT', '/ocs/v2.php/apps/versioniq/api/app/a%2Fb/pin', { version: '2.4.1' })
	})

	it('prefills the reason each time it opens and clears an earlier error', async () => {
		mockedOcsWrite.mockResolvedValue({ payload: {}, error: 'Refused' } as never)
		const wrapper = await openDialog({ initialReason: 'breaks sync' })
		expect((wrapper.find('input').element as HTMLInputElement).value).toBe('breaks sync')

		await wrapper.find('input').setValue('changed')
		await wrapper.find('[data-label="Pin"]').trigger('click')
		await flushPromises()
		expect(wrapper.text()).toContain('Refused')

		await wrapper.setProps({ open: false })
		await wrapper.setProps({ open: true })

		expect((wrapper.find('input').element as HTMLInputElement).value).toBe('breaks sync')
		expect(wrapper.text()).not.toContain('Refused')
	})

	it('opens with an empty reason when none is given', async () => {
		const wrapper = await openDialog({ initialReason: null })

		expect((wrapper.find('input').element as HTMLInputElement).value).toBe('')
	})

	it('shows a refusal and stays open', async () => {
		mockedOcsWrite.mockResolvedValue({ payload: {}, error: 'Only the installed version can be pinned.' } as never)
		const wrapper = await openDialog()

		await wrapper.find('[data-label="Pin"]').trigger('click')
		await flushPromises()

		expect(wrapper.text()).toContain('Only the installed version can be pinned.')
		expect(wrapper.emitted('pinned')).toBeUndefined()
		expect(wrapper.emitted('update:open')).toBeUndefined()
		expect(wrapper.find('input').attributes('disabled')).toBeUndefined()
	})

	it('shows a thrown error, or a fallback for a non-error throw', async () => {
		const wrapper = await openDialog()

		mockedOcsWrite.mockRejectedValueOnce(new Error('Network down'))
		await wrapper.find('[data-label="Pin"]').trigger('click')
		await flushPromises()
		expect(wrapper.text()).toContain('Network down')

		mockedOcsWrite.mockRejectedValueOnce('nope')
		await wrapper.find('[data-label="Pin"]').trigger('click')
		await flushPromises()
		expect(wrapper.text()).toContain('Could not pin this app.')
		expect(wrapper.emitted('pinned')).toBeUndefined()
	})

	it('disables the reason field while saving', async () => {
		let finish: (value: unknown) => void = () => undefined
		mockedOcsWrite.mockReturnValue(new Promise((resolve) => { finish = resolve }) as never)
		const wrapper = await openDialog()

		await wrapper.find('[data-label="Pin"]').trigger('click')
		await flushPromises()
		expect(wrapper.find('input').attributes('disabled')).toBeDefined()

		finish({ payload: { pin: storedPin } })
		await flushPromises()
		expect(wrapper.find('input').attributes('disabled')).toBeUndefined()
	})

	it.each(['Cancel', 'dismiss'])('%s closes without writing', async (label) => {
		const wrapper = await openDialog()

		await wrapper.find(`[data-label="${label}"]`).trigger('click')

		expect(wrapper.emitted('update:open')).toEqual([[false]])
		expect(mockedOcsWrite).not.toHaveBeenCalled()
	})
})
