// SPDX-License-Identifier: EUPL-1.2
// The history rows for changes made outside Versioniq: readable operation
// names and the trigger line (audit-external-changes).
// @spec openspec/changes/audit-external-changes
import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import HistoryPanel from './HistoryPanel.vue'
import { ocsGet } from '../ocs.ts'

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string, vars: Record<string, unknown> = {}) =>
		text.replace(/\{(\w+)\}/g, (_match: string, key: string) => String(vars[key] ?? '')),
}))
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', template: '<button type="button"><slot /></button>' } }))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => ({ default: { name: 'NcLoadingIcon', template: '<span />' } }))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({ default: { name: 'NcNoteCard', template: '<div><slot /></div>' } }))
vi.mock('../ocs', () => ({ ocsGet: vi.fn() }))

function row (id: number, operation: string, message: string | null, extra: Record<string, unknown> = {}) {
  return {
	id,
	actorUid: 'system',
	appId: 'forms',
	operation,
	fromVersion: '4.3.0',
	toVersion: '4.2.1',
	sourceId: null,
	status: 'success',
	message,
	createdAt: '2026-11-13 12:00:00',
	...extra,
}
}

describe('HistoryPanel', () => {
	it('names the operations and shows what triggered a change made outside Versioniq', async () => {
		vi.mocked(ocsGet).mockResolvedValue({
			payload: {
				entries: [
					row(3, 'external_update', 'found by the hourly check: no update event was seen'),
					row(2, 'external_disable', 'POST /settings/apps/disable/forms', { actorUid: 'bob' }),
					row(1, 'reactivate', 'Enabled again after an update left it disabled.'),
				],
			},
			error: null,
		} as never)

		const wrapper = mount(HistoryPanel)
		await flushPromises()

		const rows = wrapper.findAll('[data-testid="history-row"]')
		expect(rows.map((r) => r.attributes('data-operation'))).toEqual(['external_update', 'external_disable', 'reactivate'])
		expect(rows.map((r) => r.find('[data-testid="history-operation"]').text()))
			.toEqual(['Changed outside Versioniq', 'Disabled outside Versioniq', 'Enabled again after an update'])
		expect(rows.map((r) => r.find('[data-testid="history-message"]').text())).toEqual([
			'Trigger: found by the hourly check: no update event was seen',
			'Trigger: POST /settings/apps/disable/forms',
			'Enabled again after an update left it disabled.',
		])
		expect(rows[1].text()).toContain('bob')
	})
})
