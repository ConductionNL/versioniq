// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
// The artifact cache panel when the summary or a clear fails, and its size formatting.
// @spec openspec/specs/artifact-cache/spec.md
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import CachePanel from './CachePanel.vue'
import { ocsGet, ocsWrite } from '../ocs.ts'

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string, vars: Record<string, unknown> = {}) =>
		text.replace(/\{(\w+)\}/g, (_match: string, key: string) => String(vars[key] ?? '')),
}))
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', props: ['disabled'], emits: ['click'], template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>' } }))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => ({ default: { name: 'NcLoadingIcon', template: '<div />' } }))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({ default: { name: 'NcNoteCard', template: '<div data-testid="cache-error"><slot /></div>' } }))
vi.mock('../ocs', () => ({ ocsGet: vi.fn(), ocsWrite: vi.fn() }))

const mockedOcsGet = vi.mocked(ocsGet)
const mockedOcsWrite = vi.mocked(ocsWrite)

const CACHE = '/ocs/v2.php/apps/versioniq/api/cache'

const oneApp = {
	apps: [{ appId: 'open register', versions: ['2.3.0'], sizeBytes: 1024 }],
	totalSizeBytes: 1024,
	keep: 3,
}

/**
 * Mounts the panel and lets the summary load.
 */
async function mountPanel () {
	const wrapper = mount(CachePanel)
	await flushPromises()
	return wrapper
}

/**
 * The shown error, or null.
 *
 * @param wrapper the panel
 */
function errorText (wrapper: Awaited<ReturnType<typeof mountPanel>>): string | null {
	const node = wrapper.find('[data-testid="cache-error"]')
	return node.exists() ? node.text() : null
}

describe('CachePanel failures', () => {
	beforeEach(() => {
		mockedOcsGet.mockReset()
		mockedOcsWrite.mockReset()
	})

	it('shows the server refusal of the summary', async () => {
		mockedOcsGet.mockResolvedValue({ payload: oneApp, error: 'Admins only.' } as never)
		const wrapper = await mountPanel()

		expect(errorText(wrapper)).toBe('Admins only.')
		expect(wrapper.find('[data-testid="cache-empty"]').exists()).toBe(true)
	})

	it('shows a thrown error of the summary, or the fallback', async () => {
		mockedOcsGet.mockRejectedValueOnce(new Error('Network down'))
		expect(errorText(await mountPanel())).toBe('Network down')

		mockedOcsGet.mockRejectedValueOnce('boom')
		expect(errorText(await mountPanel())).toBe('Could not load the artifact cache summary.')
	})

	it('encodes the app id when clearing one app and keeps the summary on refusal', async () => {
		mockedOcsGet.mockResolvedValue({ payload: oneApp } as never)
		mockedOcsWrite.mockResolvedValue({ payload: { apps: [], totalSizeBytes: 0, keep: 3 }, error: 'Cache is locked.' } as never)
		const wrapper = await mountPanel()

		await wrapper.find('[data-testid="cache-app-row"] button').trigger('click')
		await flushPromises()

		expect(mockedOcsWrite).toHaveBeenCalledWith('DELETE', `${CACHE}?appId=open%20register`)
		expect(errorText(wrapper)).toBe('Cache is locked.')
		expect(wrapper.findAll('[data-testid="cache-app-row"]')).toHaveLength(1)
	})

	it('shows a thrown error of a clear, then the fallback, and re-enables the buttons', async () => {
		mockedOcsGet.mockResolvedValue({ payload: oneApp } as never)
		mockedOcsWrite.mockRejectedValueOnce(new Error('Disk is read-only'))
		const wrapper = await mountPanel()

		await wrapper.find('[data-testid="cache-clear-all"]').trigger('click')
		await flushPromises()
		expect(mockedOcsWrite).toHaveBeenCalledWith('DELETE', CACHE)
		expect(errorText(wrapper)).toBe('Disk is read-only')

		mockedOcsWrite.mockRejectedValueOnce('boom')
		await wrapper.find('[data-testid="cache-clear-all"]').trigger('click')
		await flushPromises()
		expect(errorText(wrapper)).toBe('Could not clear the artifact cache.')
		expect(wrapper.find('[data-testid="cache-clear-all"]').attributes('disabled')).toBeUndefined()
	})

	it('disables clearing everything when nothing is cached', async () => {
		mockedOcsGet.mockResolvedValue({ payload: { apps: [], totalSizeBytes: 0, keep: 3 } } as never)
		const wrapper = await mountPanel()

		expect(wrapper.find('[data-testid="cache-clear-all"]').attributes('disabled')).toBeDefined()
	})

	it.each([
		[0, '0 B'],
		[512, '512 B'],
		[5 * 1024 * 1024, '5.0 MB'],
		[3 * 1024 * 1024 * 1024, '3.0 GB'],
		[2048 * 1024 * 1024 * 1024, '2048.0 GB'],
	])('formats a total of %i bytes as %s', async (bytes, shown) => {
		mockedOcsGet.mockResolvedValue({ payload: { ...oneApp, totalSizeBytes: bytes } } as never)
		const wrapper = await mountPanel()

		expect(wrapper.find('[data-testid="cache-total-size"]').text()).toBe(`Total cache size: ${shown}`)
	})
})
