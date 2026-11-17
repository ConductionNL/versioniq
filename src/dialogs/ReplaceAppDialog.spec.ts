// SPDX-License-Identifier: EUPL-1.2
// The replace dialog says what happens to the old app and its data, sends the
// replace request, and keeps the old app's state visible when the install fails.
// @spec openspec/changes/inventory-lifecycle-signals/specs/lifecycle-signals/spec.md#requirement-an-admin-replaces-an-app-with-its-successor-in-one-action
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
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
		template: '<div v-if="open" :data-name="name"><slot /><button v-for="b in buttons" :key="b.label" :data-label="b.label" :disabled="b.disabled" @click="b.callback()">{{ b.label }}</button><button data-label="dismiss" @click="$emit(\'update:open\', false)">x</button></div>',
	},
}))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({ default: { name: 'NcNoteCard', props: ['type'], template: '<div :data-type="type"><slot /></div>' } }))
vi.mock('../ocs.ts', () => ({ ocsWrite: vi.fn() }))
import ReplaceAppDialog from './ReplaceAppDialog.vue'

const props = {
	open: true,
	appId: 'oldname',
	offer: { from: 'oldname', to: 'newname', source: 'appstore', custom: true, version: '1.0.0' },
}

describe('ReplaceAppDialog', () => {
	beforeEach(() => {
		vi.mocked(ocsWrite).mockReset()
	})

	it('says the successor is installed first and the old app\'s data is kept', () => {
		const wrapper = mount(ReplaceAppDialog, { props })

		expect(wrapper.find('[data-name]').attributes('data-name')).toBe('Replace oldname')
		expect(wrapper.find('[data-testid="replace-summary"]').text()).toBe('Versioniq installs newname 1.0.0 from appstore, and disables oldname only after that install succeeded.')
		expect(wrapper.find('[data-testid="replace-data-note"]').text()).toContain('The data of oldname is kept.')
	})

	it('replaces the app and tells the caller', async () => {
		vi.mocked(ocsWrite).mockResolvedValue({ payload: { replaced: true }, error: null } as never)
		const wrapper = mount(ReplaceAppDialog, { props })

		await wrapper.find('[data-label="Replace with newname"]').trigger('click')
		await flushPromises()

		expect(ocsWrite).toHaveBeenCalledWith('POST', '/ocs/v2.php/apps/versioniq/api/v1/app/oldname/replace', { to: 'newname' })
		expect(wrapper.emitted('replaced')).toEqual([['oldname', 'newname']])
		expect(wrapper.emitted('update:open')).toEqual([[false]])
	})

	it('shows a failed install and stays open, saying the old app stays enabled', async () => {
		vi.mocked(ocsWrite).mockResolvedValue({ payload: { replaced: false, message: 'The successor could not be installed; the old app stays enabled.', install: { message: 'SHA-256 mismatch' } }, error: null } as never)
		const wrapper = mount(ReplaceAppDialog, { props })

		await wrapper.find('[data-label="Replace with newname"]').trigger('click')
		await flushPromises()

		expect(wrapper.find('[data-testid="replace-error"]').text()).toBe('newname could not be installed; oldname stays enabled. SHA-256 mismatch')
		expect(wrapper.emitted('replaced')).toBeUndefined()
		expect(wrapper.emitted('update:open')).toBeUndefined()
	})

	it('cancels without a request', async () => {
		const wrapper = mount(ReplaceAppDialog, { props })

		await wrapper.find('[data-label="Cancel"]').trigger('click')

		expect(ocsWrite).not.toHaveBeenCalled()
		expect(wrapper.emitted('update:open')).toEqual([[false]])
	})
})
