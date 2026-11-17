// SPDX-License-Identifier: EUPL-1.2
// @spec openspec/changes/inventory-lifecycle-signals/specs/lifecycle-signals/spec.md#requirement-admins-are-warned-before-the-server-leaves-support
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ServerEolDate from './ServerEolDate.vue'
import { ocsGet } from '../ocs.ts'

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string, vars: Record<string, string> = {}) => text.replace(/\{(\w+)\}/g, (_m, key: string) => vars[key] ?? ''),
}))
vi.mock('../ocs.ts', () => ({ ocsGet: vi.fn() }))

describe('ServerEolDate', () => {
	beforeEach(() => {
		vi.mocked(ocsGet).mockReset()
	})

	it('shows the running major and its end-of-life date', async () => {
		vi.mocked(ocsGet).mockResolvedValue({ payload: { major: 31, eolDate: '2026-06-30' }, error: null } as never)

		const wrapper = mount(ServerEolDate)
		await flushPromises()

		expect(ocsGet).toHaveBeenCalledWith('/ocs/v2.php/apps/versioniq/api/v1/lifecycle/server')
		expect(wrapper.find('[data-testid="server-eol-date"]').text()).toBe('Nextcloud 31 reaches end of life on 2026-06-30.')
	})

	it('shows nothing when the date is not known', async () => {
		vi.mocked(ocsGet).mockResolvedValue({ payload: { major: 31, eolDate: null }, error: null } as never)

		const wrapper = mount(ServerEolDate)
		await flushPromises()

		expect(wrapper.find('[data-testid="server-eol-date"]').exists()).toBe(false)
	})

	it('shows nothing when the request fails', async () => {
		vi.mocked(ocsGet).mockRejectedValue(new Error('offline'))

		const wrapper = mount(ServerEolDate)
		await flushPromises()

		expect(wrapper.find('[data-testid="server-eol-date"]').exists()).toBe(false)
	})
})
