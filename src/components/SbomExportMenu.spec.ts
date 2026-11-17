// SPDX-License-Identifier: EUPL-1.2
// Covers the Export SBOM menu (inventory-export-sbom-metrics task 2.2):
// @spec openspec/changes/inventory-export-sbom-metrics/specs/inventory-export/spec.md#requirement-an-admin-exports-the-inventory-as-an-sbom
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import SbomExportMenu from './SbomExportMenu.vue'
import { fileNameOf } from '../utils/sbomDownload.ts'

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string) => text,
}))
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', emits: ['click'], template: '<button type="button" @click="$emit(\'click\')"><slot /></button>' } }))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({ default: { name: 'NcNoteCard', template: '<div class="note"><slot /></div>' } }))

const fetchMock = vi.fn()
const clicks: string[] = []

describe('SbomExportMenu', () => {
	beforeEach(() => {
		fetchMock.mockReset()
		clicks.length = 0
		vi.stubGlobal('fetch', fetchMock)
		;(window as unknown as { OC: unknown }).OC = { webroot: '/cloud' }
		URL.createObjectURL = vi.fn(() => 'blob:sbom')
		URL.revokeObjectURL = vi.fn()
		vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (this: HTMLAnchorElement) {
			clicks.push(this.download)
		})
	})

	afterEach(() => {
		vi.unstubAllGlobals()
		vi.restoreAllMocks()
	})

	it.each([
		['cyclonedx', 'nextcloud-sbom-20261114-100000.cdx.json'],
		['spdx', 'nextcloud-sbom-20261114-100000.spdx.json'],
	])('downloads the %s SBOM from the sbom route with the OCS header', async (format, name) => {
		fetchMock.mockResolvedValue(new Response('{}', { status: 200, headers: { 'Content-Disposition': `attachment; filename="${name}"` } }))
		const wrapper = mount(SbomExportMenu)

		await wrapper.find(`[data-testid="sbom-export-${format}"]`).trigger('click')
		await flushPromises()

		expect(fetchMock).toHaveBeenCalledWith(`${window.location.origin}/cloud/ocs/v2.php/apps/versioniq/api/sbom?format=${format}`, { headers: { 'OCS-APIRequest': 'true' } })
		expect(clicks).toEqual([name])
		expect(wrapper.find('.note').exists()).toBe(false)
	})

	it('names the menu and says so when the export fails', async () => {
		fetchMock.mockResolvedValue(new Response('', { status: 403 }))
		const wrapper = mount(SbomExportMenu)

		expect(wrapper.find('[role="group"]').attributes('aria-label')).toBe('Export SBOM')
		await wrapper.find('[data-testid="sbom-export-spdx"]').trigger('click')
		await flushPromises()

		expect(clicks).toEqual([])
		expect(wrapper.find('.note').text()).toBe('Could not export the SBOM.')
	})

	it('reads the file name from the disposition, else the fallback', () => {
		expect(fileNameOf('attachment; filename="a.cdx.json"', 'x')).toBe('a.cdx.json')
		expect(fileNameOf(null, 'fallback.json')).toBe('fallback.json')
	})
})
