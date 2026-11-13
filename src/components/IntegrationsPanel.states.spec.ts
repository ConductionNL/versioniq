// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
// The Integrations tab without a registry, on a network failure, and how it shows the last check.
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

/**
 * Makes the registry answer with a status and body.
 *
 * @param status the HTTP status
 * @param body the JSON body
 */
function answer (status: number, body: unknown): void {
	fetchMock.mockResolvedValue({ ok: status >= 200 && status < 300, status, json: async () => body })
}

/**
 * Mounts the panel and lets the load settle.
 */
async function render () {
	const wrapper = shallowMount(IntegrationsPanel, { global: { renderStubDefaultSlot: true } })
	await flushPromises()
	return wrapper
}

/**
 * The "Last checked" cell of the only row.
 *
 * @param checkedAt the row's checkedAt value
 */
async function lastChecked (checkedAt: unknown): Promise<string> {
	answer(200, { results: [{ app: 'versioniq', key: 'appstore', title: 'Nextcloud App Store', status: 'configured', checkedAt }] })
	const wrapper = await render()
	return wrapper.findAll('[data-testid="integrations-row"] td')[3].text()
}

describe('IntegrationsPanel states', () => {
	beforeEach(() => {
		fetchMock.mockReset()
		vi.stubGlobal('fetch', fetchMock)
	})

	afterEach(() => {
		vi.unstubAllGlobals()
	})

	it('says integriq has no registry when it answers 404', async () => {
		answer(404, {})
		const wrapper = await render()

		expect(wrapper.findComponent({ name: 'NcNoteCard' }).text()).toBe('Integriq has no connection registry on this instance yet.')
		expect(wrapper.find('[data-testid="integrations-empty"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="integrations-table"]').exists()).toBe(false)
	})

	it('shows an error and no table when the request itself fails', async () => {
		fetchMock.mockRejectedValue(new TypeError('Failed to fetch'))
		const wrapper = await render()

		expect(wrapper.findComponent({ name: 'NcNoteCard' }).text()).toBe('Could not load the connections.')
		expect(wrapper.find('[data-testid="integrations-table"]').exists()).toBe(false)
		expect(wrapper.find('[role="status"]').exists()).toBe(false)
	})

	it('shows Never for a connection that was not checked', async () => {
		expect(await lastChecked(undefined)).toBe('Never')
		expect(await lastChecked('')).toBe('Never')
	})

	it('shows an unparseable check time as it came', async () => {
		expect(await lastChecked('yesterday')).toBe('yesterday')
	})

	it('shows a parseable check time in the local format', async () => {
		const stamp = '2026-06-12T08:30:00Z'

		expect(await lastChecked(stamp)).toBe(new Date(stamp).toLocaleString())
	})
})
