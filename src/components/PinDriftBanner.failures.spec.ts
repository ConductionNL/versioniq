// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
// The drift banner when an Accept action is refused or fails, or there is nothing to accept.
// @spec openspec/specs/version-pinning/spec.md
import { flushPromises, mount } from '@vue/test-utils'
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
	ocsWrite: vi.fn(),
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

const MOVE = 1
const REMOVE = 2

/**
 * Mounts the banner and clicks one of its buttons.
 *
 * @param button which button: 1 accept-move, 2 accept-remove
 * @param pin the pin record
 */
async function clickOn (button: number, pin: Record<string, unknown> = drift) {
	const wrapper = mount(PinDriftBanner, { props: { appId: 'openregister', pin } as never })
	await wrapper.findAll('button')[button].trigger('click')
	await flushPromises()
	return wrapper
}

describe('PinDriftBanner failures', () => {
	beforeEach(() => {
		mockedOcsWrite.mockReset()
	})

	it('does nothing on accept-move when the pin has not drifted', async () => {
		const wrapper = await clickOn(MOVE, { ...drift, driftedTo: null })

		expect(mockedOcsWrite).not.toHaveBeenCalled()
		expect(wrapper.emitted('update:pin')).toBeUndefined()
	})

	it('shows the server refusal of a move and keeps the pin', async () => {
		mockedOcsWrite.mockResolvedValue({ payload: {}, error: 'Version not available.' } as never)
		const wrapper = await clickOn(MOVE)

		expect(wrapper.text()).toContain('Version not available.')
		expect(wrapper.emitted('update:pin')).toBeUndefined()
	})

	it('shows a thrown error of a move', async () => {
		mockedOcsWrite.mockRejectedValue(new Error('Network down'))
		const wrapper = await clickOn(MOVE)

		expect(wrapper.text()).toContain('Network down')
	})

	it('falls back to a generic message when a move fails otherwise', async () => {
		mockedOcsWrite.mockRejectedValue('boom')
		const wrapper = await clickOn(MOVE)

		expect(wrapper.text()).toContain('Could not move the pin.')
	})

	it('shows the server refusal of a removal and keeps the pin', async () => {
		mockedOcsWrite.mockResolvedValue({ payload: {}, error: 'Pin is locked.' } as never)
		const wrapper = await clickOn(REMOVE)

		expect(mockedOcsWrite).toHaveBeenCalledWith('DELETE', '/ocs/v2.php/apps/versioniq/api/app/openregister/pin')
		expect(wrapper.text()).toContain('Pin is locked.')
		expect(wrapper.emitted('update:pin')).toBeUndefined()
	})

	it('shows a thrown error of a removal, or the fallback', async () => {
		mockedOcsWrite.mockRejectedValueOnce(new Error('Network down'))
		const wrapper = await clickOn(REMOVE)
		expect(wrapper.text()).toContain('Network down')

		mockedOcsWrite.mockRejectedValueOnce('boom')
		await wrapper.findAll('button')[REMOVE].trigger('click')
		await flushPromises()
		expect(wrapper.text()).toContain('Could not remove the pin.')
	})

	it('re-enables the buttons after a failed action', async () => {
		mockedOcsWrite.mockRejectedValue('boom')
		const wrapper = await clickOn(REMOVE)

		expect(wrapper.findAllComponents({ name: 'NcButton' }).map((b) => b.attributes('disabled'))).toEqual([undefined, undefined, undefined])
	})
})
