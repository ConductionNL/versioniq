import { mount } from '@vue/test-utils'
// SPDX-License-Identifier: EUPL-1.2
// Covers "Drift response — notify and offer re-pin" (Re-pin / Accept):
// @spec openspec/specs/version-pinning/spec.md
import { beforeEach, describe, expect, it, vi } from 'vitest'
import PinDriftBanner from './PinDriftBanner.vue'
import { ocsWrite } from '../ocs.ts'

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string, vars: Record<string, unknown> = {}) =>
		text.replace(/\{(\w+)\}/g, (_match: string, key: string) => String(vars[key] ?? '')),
}))

vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', template: '<button><slot /></button>' } }))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({ default: { name: 'NcNoteCard', template: '<div><slot /></div>' } }))

vi.mock('../ocs', () => ({
	ocsWrite: vi.fn(async () => ({ payload: {} })),
}))

const mockedOcsWrite = vi.mocked(ocsWrite)

const drift = {
	appId: 'openregister',
	version: '2.3.0',
	pinnedBy: 'alice',
	pinnedAt: '2026-06-11T12:00:00+00:00',
	driftedTo: '2.5.0',
	driftedAt: '2026-06-12T00:00:00+00:00',
}

describe('PinDriftBanner', () => {
	beforeEach(() => {
		mockedOcsWrite.mockReset()
	})

	it('emits repinRequested with the pinned version, without calling the API itself', async () => {
		const wrapper = mount(PinDriftBanner, { props: { appId: 'openregister', pin: drift } })

		const buttons = wrapper.findAll('button')
		await buttons[0].trigger('click')

		expect(wrapper.emitted('repinRequested')).toEqual([['openregister', '2.3.0']])
		expect(mockedOcsWrite).not.toHaveBeenCalled()
	})

	it('accept-move PUTs the pin at the drifted version and emits update:pin', async () => {
		mockedOcsWrite.mockResolvedValue({ payload: { appId: 'openregister', pin: { ...drift, version: '2.5.0', driftedTo: null, driftedAt: null } } })
		const wrapper = mount(PinDriftBanner, { props: { appId: 'openregister', pin: drift } })

		const buttons = wrapper.findAll('button')
		await buttons[1].trigger('click')
		await wrapper.vm.$nextTick()

		expect(mockedOcsWrite).toHaveBeenCalledWith(
			'PUT',
			'/ocs/v2.php/apps/versioniq/api/app/openregister/pin',
			{ version: '2.5.0' },
		)
		const emitted = wrapper.emitted('update:pin')
		expect(emitted).toBeTruthy()
		expect(emitted?.[0][0]).toBe('openregister')
	})

	it('accept-remove DELETEs the pin and emits update:pin with null', async () => {
		mockedOcsWrite.mockResolvedValue({ payload: { appId: 'openregister', unpinned: true } })
		const wrapper = mount(PinDriftBanner, { props: { appId: 'openregister', pin: drift } })

		const buttons = wrapper.findAll('button')
		await buttons[2].trigger('click')
		await wrapper.vm.$nextTick()

		expect(mockedOcsWrite).toHaveBeenCalledWith(
			'DELETE',
			'/ocs/v2.php/apps/versioniq/api/app/openregister/pin',
		)
		expect(wrapper.emitted('update:pin')).toEqual([['openregister', null]])
	})
})
