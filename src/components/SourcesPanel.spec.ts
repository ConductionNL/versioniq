import { flushPromises, shallowMount } from '@vue/test-utils'
// SPDX-License-Identifier: EUPL-1.2
// Issue #438: SourceBindingStore::clear had no caller, so a binding could be
// replaced but never removed. The Sources tab now removes one.
// @spec openspec/specs/external-sources/spec.md
import { beforeEach, describe, expect, it, vi } from 'vitest'
import SourcesPanel from './SourcesPanel.vue'
import { ocsGet, ocsWrite } from '../ocs.ts'

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string, vars: Record<string, unknown> = {}) =>
		text.replace(/\{(\w+)\}/g, (_match: string, key: string) => String(vars[key] ?? '')),
}))

vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', emits: ['click'], template: '<button type="button" @click="$emit(\'click\')"><slot /></button>' } }))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({ default: { name: 'NcNoteCard', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcSelect', () => ({ default: { name: 'NcSelect', template: '<select><slot /></select>' } }))
vi.mock('@nextcloud/vue/components/NcTextField', () => ({ default: { name: 'NcTextField', template: '<input>' } }))

vi.mock('../ocs', () => ({
	ocsGet: vi.fn(),
	ocsWrite: vi.fn(async () => ({ payload: { appId: 'deck', sourceId: 'appstore' } })),
	ensurePasswordConfirmation: vi.fn(async () => undefined),
}))

const mockedOcsGet = vi.mocked(ocsGet)
const mockedOcsWrite = vi.mocked(ocsWrite)

/**
 * Mounts the panel with `deck` selected and bound to the given source.
 *
 * @param sourceId the binding the server reports
 */
async function mountWithBinding (sourceId: string) {
	mockedOcsGet.mockImplementation(async (path: string) => ({
		payload: path.includes('/binding') ? { sourceId } : { host: '', configured: false },
	}) as never)
	const wrapper = shallowMount(SourcesPanel, {
		props: { apps: [{ id: 'deck', label: 'Deck' }] },
		global: { stubs: { NcButton: false } },
	})
	await flushPromises()
	const appSelect = wrapper.findAllComponents({ name: 'NcSelect' }).find((c) => c.vm.$attrs.inputLabel === 'App')
	appSelect!.vm.$emit('update:modelValue', 'deck')
	await flushPromises()
	return wrapper
}

describe('removing a source binding', () => {
	beforeEach(() => {
		mockedOcsGet.mockReset()
		mockedOcsWrite.mockClear()
	})

	it('offers a remove action for a forge binding and deletes it', async () => {
		const wrapper = await mountWithBinding('github:ConductionNL/deck')

		const remove = wrapper.find('[data-testid="remove-binding"]')
		expect(remove.exists()).toBe(true)
		await remove.trigger('click')
		await flushPromises()

		expect(mockedOcsWrite).toHaveBeenCalledWith('DELETE', '/ocs/v2.php/apps/versioniq/api/source/deck/binding')
		expect(wrapper.text()).toContain('appstore')
	})

	it('offers no remove action when the app already reads from the App Store', async () => {
		const wrapper = await mountWithBinding('appstore')

		expect(wrapper.find('[data-testid="remove-binding"]').exists()).toBe(false)
	})
})
