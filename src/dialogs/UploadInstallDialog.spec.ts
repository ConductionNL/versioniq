// SPDX-License-Identifier: EUPL-1.2
// Install from a file: the dialog reads the archive first and shows its app,
// version, SHA-256 and signed state; an unsigned archive needs a pasted or
// accepted SHA-256 before Install is enabled; a refusal stays in the dialog.
// @spec openspec/changes/install-manual-upload/tasks.md#task-3.1
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import UploadInstallDialog from './UploadInstallDialog.vue'
import { inspectArchive, installUpload } from '../utils/uploadInstall.ts'

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
vi.mock('@nextcloud/vue/components/NcPasswordField', () => ({
	default: { name: 'NcPasswordField', props: ['modelValue', 'label'], emits: ['update:modelValue'], template: '<input data-testid="password" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)">' },
}))
vi.mock('@nextcloud/vue/components/NcTextField', () => ({
	default: { name: 'NcTextField', props: ['modelValue', 'label', 'helperText'], emits: ['update:modelValue'], template: '<input :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)">' },
}))
vi.mock('@nextcloud/vue/components/NcCheckboxRadioSwitch', () => ({
	default: { name: 'NcCheckboxRadioSwitch', props: ['modelValue', 'type'], emits: ['update:modelValue'], template: '<input type="checkbox" :checked="modelValue" @change="$emit(\'update:modelValue\', $event.target.checked)">' },
}))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({
	default: { name: 'NcNoteCard', props: ['type'], template: '<div class="note"><slot /></div>' },
}))
vi.mock('../utils/enableApp.ts', () => ({ currentLoginName: () => 'admin' }))
vi.mock('../utils/uploadInstall.ts', () => ({ inspectArchive: vi.fn(), installUpload: vi.fn() }))

const SHA = 'a'.repeat(64)
const inspected = (signedByStore: boolean) => ({ token: 'tok', appId: 'hermiq', version: '1.4.1', sha256: SHA, signedByStore })

/**
 * Mounts the dialog with a picked file and a password.
 *
 * @param appId The app the dialog is opened from.
 */
async function picked (appId = 'hermiq') {
	const wrapper = mount(UploadInstallDialog, { props: { open: true, appId } })
	const input = wrapper.find('[data-testid="upload-file"]')
	Object.defineProperty(input.element, 'files', { value: [new File(['x'], 'hermiq-1.4.1.tar.gz')] })
	await input.trigger('change')
	await wrapper.find('[data-testid="password"]').setValue('secret')
	return wrapper
}

const button = (wrapper: Awaited<ReturnType<typeof picked>>, label: string) => wrapper.find(`[data-label="${label}"]`)

describe('UploadInstallDialog', () => {
	beforeEach(() => {
		vi.mocked(inspectArchive).mockReset()
		vi.mocked(installUpload).mockReset()
	})

	it('reads the archive only once a file and the password are given', async () => {
		const wrapper = mount(UploadInstallDialog, { props: { open: true, appId: 'hermiq' } })
		expect(button(wrapper, 'Read archive').attributes('disabled')).toBeDefined()

		const ready = await picked()
		vi.mocked(inspectArchive).mockResolvedValue(inspected(true))
		await button(ready, 'Read archive').trigger('click')
		await flushPromises()

		expect(inspectArchive).toHaveBeenCalledWith(expect.any(File), 'admin', 'secret')
		expect(ready.find('[data-testid="upload-facts"]').text()).toContain('hermiq')
		expect(ready.find('[data-testid="upload-facts"]').text()).toContain('1.4.1')
		expect(ready.find('[data-testid="upload-facts"]').text()).toContain(SHA)
		expect(ready.find('[data-testid="upload-signed"]').text()).toBe('Signed by the App Store')
	})

	it('installs a signed archive without asking for a checksum and reports the outcome', async () => {
		const wrapper = await picked()
		vi.mocked(inspectArchive).mockResolvedValue(inspected(true))
		vi.mocked(installUpload).mockResolvedValue({ ok: true, message: '', payload: { sourceId: 'upload' } })
		await button(wrapper, 'Read archive').trigger('click')
		await flushPromises()

		expect(wrapper.find('[data-testid="upload-unsigned-warning"]').exists()).toBe(false)
		await button(wrapper, 'Install hermiq 1.4.1').trigger('click')
		await flushPromises()

		expect(installUpload).toHaveBeenCalledWith('tok', { expectedSha256: '', acceptShown: false }, 'admin', 'secret')
		expect(wrapper.emitted('installed')).toEqual([[{ sourceId: 'upload' }]])
		expect(wrapper.emitted('update:open')).toEqual([[false]])
	})

	it('warns about an unsigned archive and installs it only with a pasted or accepted checksum', async () => {
		const wrapper = await picked()
		vi.mocked(inspectArchive).mockResolvedValue(inspected(false))
		vi.mocked(installUpload).mockResolvedValue({ ok: true, message: '', payload: {} })
		await button(wrapper, 'Read archive').trigger('click')
		await flushPromises()

		expect(wrapper.find('[data-testid="upload-signed"]').text()).toBe('Not signed by the App Store')
		expect(wrapper.find('[data-testid="upload-unsigned-warning"]').text()).toContain('Allow unsigned uploads')
		expect(button(wrapper, 'Install hermiq 1.4.1').attributes('disabled')).toBeDefined()

		await wrapper.find('[data-testid="upload-expected-sha"]').setValue(SHA)
		expect(button(wrapper, 'Install hermiq 1.4.1').attributes('disabled')).toBeUndefined()
		await button(wrapper, 'Install hermiq 1.4.1').trigger('click')
		await flushPromises()

		expect(installUpload).toHaveBeenCalledWith('tok', { expectedSha256: SHA, acceptShown: false }, 'admin', 'secret')
	})

	it('enables Install for an unsigned archive when the shown checksum is accepted', async () => {
		const wrapper = await picked()
		vi.mocked(inspectArchive).mockResolvedValue(inspected(false))
		await button(wrapper, 'Read archive').trigger('click')
		await flushPromises()

		await wrapper.find('[data-testid="upload-accept-shown"]').setValue(true)

		expect(button(wrapper, 'Install hermiq 1.4.1').attributes('disabled')).toBeUndefined()
	})

	it('keeps the dialog open with the refusal', async () => {
		const wrapper = await picked()
		vi.mocked(inspectArchive).mockResolvedValue(inspected(true))
		vi.mocked(installUpload).mockResolvedValue({ ok: false, message: 'This app is pinned to version 1.4.0.', payload: {} })
		await button(wrapper, 'Read archive').trigger('click')
		await flushPromises()
		await button(wrapper, 'Install hermiq 1.4.1').trigger('click')
		await flushPromises()

		expect(wrapper.find('[data-testid="upload-error"]').text()).toBe('This app is pinned to version 1.4.0.')
		expect(wrapper.emitted('installed')).toBeUndefined()
	})

	it('shows why an archive could not be read', async () => {
		const wrapper = await picked()
		vi.mocked(inspectArchive).mockRejectedValue(new Error('The archive has no appinfo/info.xml.'))
		await button(wrapper, 'Read archive').trigger('click')
		await flushPromises()

		expect(wrapper.find('[data-testid="upload-error"]').text()).toBe('The archive has no appinfo/info.xml.')
		expect(wrapper.find('[data-testid="upload-facts"]').exists()).toBe(false)
	})

	it('says when the archive is another app than the one opened', async () => {
		const wrapper = await picked('deck')
		vi.mocked(inspectArchive).mockResolvedValue(inspected(true))
		await button(wrapper, 'Read archive').trigger('click')
		await flushPromises()

		expect(wrapper.text()).toContain('This archive is hermiq, not deck.')
	})
})
