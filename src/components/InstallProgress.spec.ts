// SPDX-License-Identifier: EUPL-1.2
// install-live-progress task 3.1: the checklist for a running, a finished and a failed install.
// @spec openspec/changes/install-live-progress/specs/version-management/spec.md
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import InstallProgress from './InstallProgress.vue'

const ocsGet = vi.fn()

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string, vars: Record<string, unknown> = {}) =>
		text.replace(/\{(\w+)\}/g, (_match: string, key: string) => String(vars[key] ?? '')),
}))
vi.mock('../ocs.ts', () => ({ ocsGet: (...args: unknown[]) => ocsGet(...args) }))

const NOW = 1_763_100_010

describe('InstallProgress', () => {
	beforeEach(() => {
		vi.useFakeTimers()
		vi.setSystemTime(NOW * 1000)
		ocsGet.mockReset()
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	const rows = (wrapper: ReturnType<typeof mount>) => wrapper.findAll('li').map((li) => [li.attributes('data-state'), li.text()])

	it('shows the finished stages ticked and the running one with its elapsed time', async () => {
		ocsGet.mockResolvedValue({
			payload: {
				stages: [
					{ name: 'downloaded', startedAt: NOW - 8, endedAt: NOW - 4, detail: [] },
					{ name: 'signature-verified', startedAt: NOW - 4, endedAt: null, detail: [] },
				],
				outcome: null,
			},
		})

		const wrapper = mount(InstallProgress, { props: { token: 'tok-tok-tok-tok-tok' } })
		await flushPromises()

		expect(ocsGet).toHaveBeenCalledWith('/ocs/v2.php/apps/versioniq/api/install-progress/tok-tok-tok-tok-tok')
		expect(rows(wrapper)).toEqual([
			['done', '✓Downloaded'],
			['running', '…Checking the signature4 s'],
		])
		expect(wrapper.text()).toContain('Keep this page open until the install finishes.')
		wrapper.unmount()
	})

	it('shows every stage done when the install finished', async () => {
		ocsGet.mockResolvedValue({
			payload: { stages: [{ name: 'downloading', startedAt: NOW - 3, endedAt: NOW - 2, detail: { percent: 100 } }, { name: 'finalize', startedAt: NOW - 2, endedAt: NOW, detail: [] }], outcome: 'installed' },
		})

		const wrapper = mount(InstallProgress, { props: { token: 'tok-tok-tok-tok-tok' } })
		await flushPromises()

		expect(rows(wrapper).map(([state]) => state)).toEqual(['done', 'done'])
		expect(rows(wrapper)[0][1]).toBe('✓Downloading')
		wrapper.unmount()
	})

	it('marks the stage a failed install stopped in as failed, and none after it as done', async () => {
		ocsGet.mockResolvedValue({
			payload: { stages: [{ name: 'downloaded', startedAt: NOW - 5, endedAt: NOW - 3, detail: [] }, { name: 'checksum', startedAt: NOW - 3, endedAt: NOW - 1, detail: [] }], outcome: 'failed' },
		})

		const wrapper = mount(InstallProgress, { props: { token: 'tok-tok-tok-tok-tok' } })
		await flushPromises()

		expect(rows(wrapper)).toEqual([
			['done', '✓Downloaded'],
			['failed', '✗Checking the checksumfailed'],
		])
		wrapper.unmount()
	})

	it('polls once a second and stops when unmounted', async () => {
		ocsGet.mockResolvedValue({ payload: { stages: [{ name: 'downloading', startedAt: NOW, endedAt: null, detail: { percent: 35 } }], outcome: null } })

		const wrapper = mount(InstallProgress, { props: { token: 'tok-tok-tok-tok-tok' } })
		await flushPromises()
		expect(rows(wrapper)[0][1]).toContain('Downloading (35 %)')

		await vi.advanceTimersByTimeAsync(2000)
		expect(ocsGet).toHaveBeenCalledTimes(3)

		wrapper.unmount()
		await vi.advanceTimersByTimeAsync(3000)
		expect(ocsGet).toHaveBeenCalledTimes(3)
	})
})
