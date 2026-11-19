// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
// The Upgrade readiness tab: empty, queued and filled (inventory-upgrade-readiness 3.1).
// @spec openspec/changes/inventory-upgrade-readiness/specs/upgrade-readiness/spec.md#requirement-an-admin-checks-every-installed-app-against-a-target-server-major-and-php-version
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import ReadinessPanel from './ReadinessPanel.vue'
import { ocsGet, ocsWrite } from '../ocs.ts'

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string, vars: Record<string, unknown> = {}) =>
		text.replace(/\{(\w+)\}/g, (_match: string, key: string) => String(vars[key] ?? '')),
}))
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', props: ['disabled', 'type'], emits: ['click'], template: '<button :type="type || \'button\'" :disabled="disabled" @click="$emit(\'click\')"><slot /></button>' } }))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({ default: { name: 'NcNoteCard', template: '<div><slot /></div>' } }))
vi.mock('../ocs', () => ({ ocsGet: vi.fn(), ocsWrite: vi.fn() }))

const mockedGet = vi.mocked(ocsGet)
const mockedWrite = vi.mocked(ocsWrite)
const ENDPOINT = '/ocs/v2.php/apps/versioniq/api/readiness'

const report = {
	server: '33',
	php: '8.2',
	apps: [
		{ id: 'calendar', label: 'Calendar', installedVersion: '5.0.0', verdict: 'ready', version: null },
		{ id: 'oldapp', label: 'Old app', installedVersion: '1.0.0', verdict: 'blocked', version: null },
		{ id: 'files', label: 'Files', installedVersion: '33.0.0', verdict: 'shipped', version: null },
		{ id: 'openregister', label: 'OpenRegister', installedVersion: '2.3.0', verdict: 'updateFirst', version: '2.5.0' },
	],
}

/**
 * Mounts the panel and lets the load settle.
 */
async function mountPanel () {
	const wrapper = mount(ReadinessPanel)
	await flushPromises()
	return wrapper
}

describe('ReadinessPanel', () => {
	beforeEach(() => {
		mockedGet.mockReset()
		mockedWrite.mockReset()
		;(window as unknown as { OC: unknown }).OC = { config: { version: '32.0.1.2' } }
	})

	afterEach(() => {
		delete (window as unknown as { OC?: unknown }).OC
	})

	it('says no check has run, with the running major plus one and this PHP as defaults', async () => {
		mockedGet.mockResolvedValue({ payload: { report: null, checkedAt: null, php: '8.3' } } as never)
		const wrapper = await mountPanel()

		expect(mockedGet).toHaveBeenCalledWith(ENDPOINT)
		expect(wrapper.find('[data-testid="readiness-empty"]').text()).toBe('No readiness check has run yet.')
		expect((wrapper.find('[data-testid="readiness-server"]').element as HTMLInputElement).value).toBe('33')
		expect((wrapper.find('[data-testid="readiness-php"]').element as HTMLInputElement).value).toBe('8.3')
		expect(wrapper.findAll('[data-testid="readiness-group"]')).toHaveLength(0)
	})

	it('queues a check with both targets and says the report comes later', async () => {
		mockedGet.mockResolvedValue({ payload: { report: null, checkedAt: null, php: '8.2' } } as never)
		mockedWrite.mockResolvedValue({ payload: { queued: true, server: '34', php: '8.4' } } as never)
		const wrapper = await mountPanel()

		await wrapper.find('[data-testid="readiness-server"]').setValue(' 34 ')
		await wrapper.find('[data-testid="readiness-php"]').setValue('8.4')
		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(mockedWrite).toHaveBeenCalledWith('POST', ENDPOINT, { server: '34', php: '8.4' })
		expect(wrapper.find('[data-testid="readiness-queued"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="readiness-error"]').exists()).toBe(false)
	})

	it('shows the server refusal instead of the queued notice', async () => {
		mockedGet.mockResolvedValue({ payload: { report: null, checkedAt: null, php: '8.2' } } as never)
		mockedWrite.mockResolvedValue({ payload: {}, error: 'Give a server major such as 33 and a PHP version such as 8.4' } as never)
		const wrapper = await mountPanel()

		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(wrapper.find('[data-testid="readiness-error"]').text()).toBe('Give a server major such as 33 and a PHP version such as 8.4')
		expect(wrapper.find('[data-testid="readiness-queued"]').exists()).toBe(false)
	})

	it('groups a filled report by verdict, blocked first, with the time and targets', async () => {
		mockedGet.mockResolvedValue({ payload: { report, checkedAt: 1_790_000_000, php: '8.2' } } as never)
		const wrapper = await mountPanel()

		const groups = wrapper.findAll('[data-testid="readiness-group"]')
		expect(groups.map((group) => group.attributes('data-verdict'))).toEqual(['blocked', 'updateFirst', 'ready', 'shipped'])
		expect(groups[0].find('caption').text()).toContain('Blocked')
		expect(groups[0].find('[data-testid="readiness-row"]').attributes('data-app')).toBe('oldapp')

		const update = groups[1].find('[data-testid="readiness-row"]')
		expect(update.attributes('data-app')).toBe('openregister')
		expect(update.findAll('td').map((cell) => cell.text())).toEqual(['OpenRegister', '2.3.0', '2.5.0'])
		expect(groups[1].findAll('th').map((cell) => cell.text())).toEqual(['App', 'Installed version', 'Version to take'])
		expect(groups[2].findAll('th')).toHaveLength(2)

		const checked = wrapper.find('[data-testid="readiness-checked"]').text()
		expect(checked).toContain('against Nextcloud 33 and PHP 8.2')
		expect(checked).toContain(new Date(1_790_000_000 * 1000).toLocaleString())
		expect(wrapper.find('[data-testid="readiness-empty"]').exists()).toBe(false)
	})

	it('shows a load failure', async () => {
		mockedGet.mockRejectedValue(new Error('Network down'))
		const wrapper = await mountPanel()

		expect(wrapper.find('[data-testid="readiness-error"]').text()).toBe('Network down')
	})
})
