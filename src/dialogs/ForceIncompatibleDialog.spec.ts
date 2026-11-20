// SPDX-License-Identifier: EUPL-1.2
// "Install anyway" / "Enable anyway" explains the risk and the password step,
// and resolves true only on the explicit button.
// @spec openspec/changes/install-force-incompatible/specs/version-management/spec.md
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import ForceIncompatibleDialog from './ForceIncompatibleDialog.vue'

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string, vars: Record<string, unknown> = {}) =>
		text.replace(/\{(\w+)\}/g, (_match: string, key: string) => String(vars[key] ?? '')),
}))
vi.mock('@nextcloud/vue/components/NcDialog', () => ({
	default: {
		name: 'NcDialog',
		props: ['open', 'name', 'buttons'],
		emits: ['update:open'],
		template: '<div v-if="open" :data-name="name"><slot /><button v-for="b in buttons" :key="b.label" :data-label="b.label" @click="b.callback()">{{ b.label }}</button><button data-label="dismiss" @click="$emit(\'update:open\', false)">x</button></div>',
	},
}))

const props = { open: true, appId: 'hermiq', version: '1.4.0', mode: 'install' as const }

describe('ForceIncompatibleDialog', () => {
	it('warns what the override does and that it is lifted at the next major', () => {
		const wrapper = mount(ForceIncompatibleDialog, { props })

		expect(wrapper.find('[data-name]').attributes('data-name')).toBe('Run hermiq 1.4.0 on a Nextcloud it does not declare')
		expect(wrapper.find('[data-testid="force-warning"]').text()).toContain('The publisher of hermiq 1.4.0 has not declared support for this Nextcloud version.')
		expect(wrapper.find('[data-testid="force-warning"]').text()).toContain('app_install_overwrite')
		expect(wrapper.text()).toContain('Versioniq lifts the override at the next major Nextcloud upgrade')
	})

	it('says the password is asked before anything changes', () => {
		const wrapper = mount(ForceIncompatibleDialog, { props })

		expect(wrapper.find('[data-testid="force-password-step"]').text()).toBe('You will be asked for your password before anything changes.')
	})

	it.each([
		['install', 'Install anyway'],
		['enable', 'Enable anyway'],
	] as const)('in %s mode the confirm button reads %s and resolves true', async (mode, label) => {
		const wrapper = mount(ForceIncompatibleDialog, { props: { ...props, mode } })

		await wrapper.find(`[data-label="${label}"]`).trigger('click')

		expect(wrapper.emitted('update:open')).toEqual([[false]])
		expect(wrapper.emitted('resolve')).toEqual([[true]])
	})

	it.each(['Cancel', 'dismiss'])('%s resolves false', async (label) => {
		const wrapper = mount(ForceIncompatibleDialog, { props })

		await wrapper.find(`[data-label="${label}"]`).trigger('click')

		expect(wrapper.emitted('resolve')).toEqual([[false]])
	})
})
