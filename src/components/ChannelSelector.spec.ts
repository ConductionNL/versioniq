// SPDX-License-Identifier: EUPL-1.2
// @spec openspec/changes/releases-channel-and-prereleases/specs/release-channels/spec.md
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import ChannelSelector from './ChannelSelector.vue'

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string, vars: Record<string, unknown> = {}) =>
		text.replace(/\{(\w+)\}/g, (_match: string, key: string) => String(vars[key] ?? '')),
}))

vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', props: ['disabled'], emits: ['click'], template: '<button type="button" :disabled="disabled" @click="$emit(\'click\')"><slot /></button>' } }))
vi.mock('@nextcloud/vue/components/NcSelect', () => ({ default: { name: 'NcSelect', props: ['modelValue', 'options', 'inputLabel'], emits: ['update:modelValue'], template: '<select />' } }))
vi.mock('@nextcloud/vue/components/NcTextField', () => ({ default: { name: 'NcTextField', props: ['modelValue', 'label'], emits: ['update:modelValue', 'keyup'], template: '<input :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />' } }))

function select (wrapper: ReturnType<typeof mount>) {
	return wrapper.findComponent({ name: 'NcSelect' })
}

describe('ChannelSelector', () => {
	it('offers the default and each of the four channels on an app card, and shows the app channel', () => {
		const wrapper = mount(ChannelSelector, { props: { appId: 'hermiq', channel: 'beta', defaultChannel: 'stable' } })

		const options = select(wrapper).props('options') as Array<{ id: string | null, label: string }>
		expect(options.map((option) => option.id)).toEqual([null, 'server', 'stable', 'beta', 'lts'])
		expect(options[0].label).toBe('Use the default (Stable)')
		expect((select(wrapper).props('modelValue') as { id: string }).id).toBe('beta')
		expect(select(wrapper).props('inputLabel')).toBe('Release channel')
	})

	it('selects "use the default" for an app without its own channel', () => {
		const wrapper = mount(ChannelSelector, { props: { appId: 'hermiq', channel: null, defaultChannel: 'server' } })

		expect((select(wrapper).props('modelValue') as { id: string | null }).id).toBeNull()
	})

	it.each(['server', 'stable', 'beta', 'lts'])('emits the %s channel for the app', (channel) => {
		const wrapper = mount(ChannelSelector, { props: { appId: 'hermiq', channel: null } })
		const option = (select(wrapper).props('options') as Array<{ id: string | null }>).find((entry) => entry.id === channel)

		select(wrapper).vm.$emit('update:modelValue', option)

		expect(wrapper.emitted('change')).toEqual([['hermiq', channel]])
	})

	it('emits null when the app goes back to the default', () => {
		const wrapper = mount(ChannelSelector, { props: { appId: 'hermiq', channel: 'beta' } })

		select(wrapper).vm.$emit('update:modelValue', (select(wrapper).props('options') as unknown[])[0])

		expect(wrapper.emitted('change')).toEqual([['hermiq', null]])
	})

	it('offers only the four channels for the instance default, with no lines field', () => {
		const wrapper = mount(ChannelSelector, { props: { channel: 'lts' } })

		expect((select(wrapper).props('options') as Array<{ id: string }>).map((option) => option.id)).toEqual(['server', 'stable', 'beta', 'lts'])
		expect(select(wrapper).props('inputLabel')).toBe('Default release channel')
		expect(wrapper.find('[data-testid="channel-lts-lines"]').exists()).toBe(false)
	})

	it('shows the lines field only when the app is on long-term support, also through the default', () => {
		expect(mount(ChannelSelector, { props: { appId: 'a', channel: 'stable' } }).find('[data-testid="channel-lts-lines"]').exists()).toBe(false)
		expect(mount(ChannelSelector, { props: { appId: 'a', channel: 'lts' } }).find('[data-testid="channel-lts-lines"]').exists()).toBe(true)
		expect(mount(ChannelSelector, { props: { appId: 'a', channel: null, defaultChannel: 'lts' } }).find('[data-testid="channel-lts-lines"]').exists()).toBe(true)
	})

	it('saves the edited lines as a list, and only once they changed', async () => {
		const wrapper = mount(ChannelSelector, { props: { appId: 'openregister', channel: 'lts', ltsLines: ['2.3'] } })
		const save = wrapper.find('[data-testid="channel-lts-save"]')
		expect(save.attributes('disabled')).toBeDefined()

		await wrapper.find('input').setValue('2.3, 3 ,')
		expect(save.attributes('disabled')).toBeUndefined()
		await save.trigger('click')

		expect(wrapper.emitted('ltsLines')).toEqual([['openregister', ['2.3', '3']]])
	})
})
