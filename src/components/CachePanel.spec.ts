import { flushPromises, mount } from '@vue/test-utils'
// SPDX-License-Identifier: EUPL-1.2
// Covers "Cache visibility and management" ("Offline badge", "Clear cache"):
// @spec openspec/specs/artifact-cache/spec.md
import { beforeEach, describe, expect, it, vi } from 'vitest'
import CachePanel from './CachePanel.vue'
import { ocsGet, ocsWrite } from '../ocs.ts'

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string, vars: Record<string, unknown> = {}) =>
		text.replace(/\{(\w+)\}/g, (_match: string, key: string) => String(vars[key] ?? '')),
}))

vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', template: '<button @click="$emit(\'click\')"><slot /></button>' } }))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => ({ default: { name: 'NcLoadingIcon', template: '<div />' } }))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({ default: { name: 'NcNoteCard', template: '<div><slot /></div>' } }))

vi.mock('../ocs', () => ({
	ocsGet: vi.fn(),
	ocsWrite: vi.fn(),
}))

const mockedOcsGet = vi.mocked(ocsGet)
const mockedOcsWrite = vi.mocked(ocsWrite)

describe('CachePanel', () => {
	beforeEach(() => {
		mockedOcsGet.mockReset()
		mockedOcsWrite.mockReset()
	})

	it('renders per-app cached versions and total size', async () => {
		mockedOcsGet.mockResolvedValue({
			payload: {
				apps: [{ appId: 'openregister', versions: ['2.3.0', '2.2.0'], sizeBytes: 2048 }],
				totalSizeBytes: 2048,
				keep: 3,
			},
		})

		const wrapper = mount(CachePanel)
		await flushPromises()

		expect(wrapper.text()).toContain('openregister')
		expect(wrapper.text()).toContain('2.0 KB')
	})

	it('shows an empty state when nothing is cached', async () => {
		mockedOcsGet.mockResolvedValue({ payload: { apps: [], totalSizeBytes: 0, keep: 3 } })

		const wrapper = mount(CachePanel)
		await flushPromises()

		expect(wrapper.text()).toContain('Nothing cached yet.')
	})

	it('clears a single app cache and refreshes the summary from the response', async () => {
		mockedOcsGet.mockResolvedValue({
			payload: {
				apps: [{ appId: 'openregister', versions: ['2.3.0'], sizeBytes: 1024 }],
				totalSizeBytes: 1024,
				keep: 3,
			},
		})
		mockedOcsWrite.mockResolvedValue({ payload: { apps: [], totalSizeBytes: 0, keep: 3 } })

		const wrapper = mount(CachePanel)
		await flushPromises()

		await wrapper.find('button').trigger('click')
		await flushPromises()

		expect(mockedOcsWrite).toHaveBeenCalledWith(
			'DELETE',
			expect.stringContaining('appId=openregister'),
		)
		expect(wrapper.text()).toContain('Nothing cached yet.')
	})

	it('surfaces an API error from the summary load', async () => {
		mockedOcsGet.mockResolvedValue({ payload: { apps: [], totalSizeBytes: 0, keep: 3 }, error: 'boom' })

		const wrapper = mount(CachePanel)
		await flushPromises()

		expect(wrapper.text()).toContain('boom')
	})
})
