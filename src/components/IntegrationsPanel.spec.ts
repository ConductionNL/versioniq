// SPDX-License-Identifier: EUPL-1.2
// The Integrations tab reads integriq's rows for this app and shows their
// status (adopt-connection-registry).
// @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-versioniq-conn-003-an-admin-reads-the-connections-on-an-integrations-tab
import { flushPromises, shallowMount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import IntegrationsPanel from './IntegrationsPanel.vue'

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string, vars: Record<string, unknown> = {}) =>
		text.replace(/\{(\w+)\}/g, (_match: string, key: string) => String(vars[key] ?? '')),
}))

vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', emits: ['click'], template: '<button @click="$emit(\'click\')"><slot /></button>' } }))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => ({ default: { name: 'NcLoadingIcon', template: '<span />' } }))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({ default: { name: 'NcNoteCard', template: '<div class="note"><slot /></div>' } }))

const fetchMock = vi.fn()

function answer (status: number, body: unknown): void {
	fetchMock.mockResolvedValue({ ok: status >= 200 && status < 300, status, json: async () => body })
}

async function render () {
	const wrapper = shallowMount(IntegrationsPanel, { global: { renderStubDefaultSlot: true } })
	await flushPromises()
	return wrapper
}

describe('IntegrationsPanel', () => {
	beforeEach(() => {
		fetchMock.mockReset()
		vi.stubGlobal('fetch', fetchMock)
	})

	afterEach(() => {
		vi.unstubAllGlobals()
	})

	it('asks integriq\'s register for this app\'s rows only', async () => {
		answer(200, { results: [] })
		await render()

		expect(fetchMock).toHaveBeenCalledTimes(1)
		const url = String(fetchMock.mock.calls[0][0])
		expect(url).toContain('/apps/openregister/api/objects/integriq/app_connection?app=versioniq')
	})

	it('lists the rows in declared order, with the status label and a settings link', async () => {
		answer(200, {
			results: [
				{ app: 'versioniq', key: 'github', title: 'GitHub releases', order: 20, status: 'limited', statusMessage: 'Rate limited', settingsUrl: '/settings/admin/versioniq#section-sources' },
				{ app: 'dossiq', key: 'zgw', title: 'ZGW APIs', order: 1, status: 'configured' },
				{ app: 'versioniq', key: 'appstore', title: 'Nextcloud App Store', order: 10, status: 'configured' },
			],
		})
		const wrapper = await render()

		const rows = wrapper.findAll('[data-testid="integrations-row"]')
		expect(rows.map((row) => row.attributes('data-key'))).toEqual(['appstore', 'github'])
		expect(rows[1].find('[data-testid="integrations-status"]').text()).toBe('Limited')

		// The App Store row has nowhere to send a reader; the GitHub row does.
		expect(rows[0].find('a').exists()).toBe(false)
		const link = rows[1].find('a')
		expect(link.attributes('href')).toMatch(/\/index\.php\/settings\/admin\/versioniq#section-sources$/)
		expect(link.text()).toBe('Open settings')
		expect(link.attributes('aria-label')).toBe('Open settings for GitHub releases')
	})

	it('never links a row to another host', async () => {
		answer(200, { results: [{ app: 'versioniq', key: 'github', title: 'GitHub releases', settingsUrl: '//evil.example/settings' }] })
		const wrapper = await render()

		expect(wrapper.find('[data-testid="integrations-row"] a').exists()).toBe(false)
	})

	it('says when integriq has no rows yet', async () => {
		answer(200, { results: [] })
		const wrapper = await render()

		expect(wrapper.find('[data-testid="integrations-empty"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="integrations-table"]').exists()).toBe(false)
	})

	it('shows an error instead of an empty list when the register refuses', async () => {
		answer(403, {})
		const wrapper = await render()

		expect(wrapper.findComponent({ name: 'NcNoteCard' }).text()).toBe('Could not load the connections.')
		expect(wrapper.find('[data-testid="integrations-empty"]').exists()).toBe(false)
	})

	it('sends Add integration to integriq\'s overview on the link dialog', async () => {
		answer(200, { results: [] })
		const assign = vi.fn()
		vi.stubGlobal('location', { ...window.location, origin: 'https://cloud.example', assign })
		const wrapper = await render()

		await wrapper.find('[data-testid="integrations-add"]').trigger('click')

		expect(assign).toHaveBeenCalledWith('https://cloud.example/index.php/apps/integriq/connections?app=versioniq&link=1')
	})
})
