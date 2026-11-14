// SPDX-License-Identifier: EUPL-1.2
// Covers the per-install SBOM link of the History tab (inventory-export-sbom-metrics task 3.2):
// @spec openspec/changes/inventory-export-sbom-metrics/specs/inventory-export/spec.md#requirement-every-update-leaves-an-sbom-of-the-state-it-produced
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import HistoryPanel from './HistoryPanel.vue'
import { ocsGet } from '../ocs.ts'
import { downloadSbom } from '../utils/sbomDownload.ts'

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string) => text,
}))
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', emits: ['click'], template: '<button @click="$emit(\'click\')"><slot /></button>' } }))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => ({ default: { name: 'NcLoadingIcon', template: '<div />' } }))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({ default: { name: 'NcNoteCard', template: '<div class="note"><slot /></div>' } }))
vi.mock('../ocs', () => ({ ocsGet: vi.fn() }))
vi.mock('../utils/sbomDownload.ts', () => ({ SBOM_PATH: '/ocs/v2.php/apps/versioniq/api/sbom', downloadSbom: vi.fn() }))

function row (id: number, operation: string, sbomAvailable?: boolean) {
  return {
	id, actorUid: 'alice', appId: 'openregister', operation, fromVersion: '2.3.0', toVersion: '2.4.1', sourceId: null,
	status: 'success', message: null, createdAt: '2026-11-14 02:00:00', ...(sbomAvailable === undefined ? {} : { sbomAvailable }),
}
}

describe('HistoryPanel SBOM link', () => {
	beforeEach(() => {
		vi.mocked(ocsGet).mockReset()
		vi.mocked(downloadSbom).mockReset()
	})

	it('offers the SBOM only on install rows that have one, and downloads it by row id', async () => {
		vi.mocked(ocsGet).mockResolvedValue({ payload: { entries: [row(42, 'install', true), row(41, 'install', false), row(40, 'pin')] } })
		vi.mocked(downloadSbom).mockResolvedValue()
		const wrapper = mount(HistoryPanel)
		await flushPromises()

		const links = wrapper.findAll('[data-testid="history-sbom"]')
		expect(links).toHaveLength(1)
		expect(wrapper.findAll('[data-testid="history-row"]')[0].find('[data-testid="history-sbom"]').exists()).toBe(true)

		await links[0].trigger('click')
		await flushPromises()

		expect(downloadSbom).toHaveBeenCalledWith('/ocs/v2.php/apps/versioniq/api/sbom/42', 'nextcloud-sbom-install-42.cdx.json')
		expect(wrapper.find('.note').exists()).toBe(false)
	})

	it('says so when the download fails', async () => {
		vi.mocked(ocsGet).mockResolvedValue({ payload: { entries: [row(42, 'install', true)] } })
		vi.mocked(downloadSbom).mockRejectedValue(new Error('HTTP 404'))
		const wrapper = mount(HistoryPanel)
		await flushPromises()

		await wrapper.find('[data-testid="history-sbom"]').trigger('click')
		await flushPromises()

		expect(wrapper.find('.note').text()).toBe('Could not download the SBOM of this install.')
	})
})
