// SPDX-License-Identifier: EUPL-1.2
// Covers "An admin records a patch policy and security fixes go first":
// @spec openspec/changes/autoupdate-security-first/specs/auto-update-policies/spec.md
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import PatchPolicyPanel from './PatchPolicyPanel.vue'
import { ocsGet, ocsWrite } from '../ocs.ts'

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string, vars: Record<string, unknown> = {}) =>
		text.replace(/\{(\w+)\}/g, (_match: string, key: string) => String(vars[key] ?? '')),
}))
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', props: ['disabled'], emits: ['click'], template: '<button type="button" :disabled="disabled" @click="$emit(\'click\')"><slot /></button>' } }))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({ default: { name: 'NcNoteCard', template: '<div><slot /></div>' } }))
vi.mock('../ocs', () => ({ ocsGet: vi.fn(), ocsWrite: vi.fn() }))

const mockedGet = vi.mocked(ocsGet)
const mockedWrite = vi.mocked(ocsWrite)

const allowed = {
	severities: ['critical', 'high', 'medium', 'low', 'unknown'],
	securityHandlings: ['next_window', 'scheduled', 'manual'],
	kinds: ['patch', 'minor', 'major'],
	regularHandlings: ['automatic', 'manual'],
	deadlineMinDays: 1,
	deadlineMaxDays: 365,
	statementMaxLength: 4000,
}

const none = {
	policy: {
		security: Object.fromEntries(allowed.severities.map((severity) => [severity, { deadlineDays: null, handling: 'scheduled' }])),
		regular: { patch: 'automatic', minor: 'automatic', major: 'automatic' },
		statement: '',
		setBy: null,
		setAt: null,
	},
	recorded: false,
	statement: ['No patch policy is recorded yet. Every update follows the app\'s own update policy.'],
	allowed,
}

const savedLines = [
	'Critical security fixes: installed at the next update window. Deadline: 3 days.',
	'Major updates: installed by hand only.',
	'Last changed by alice on 27 September 2026.',
]

const saved = {
	...none,
	policy: {
		...none.policy,
		security: { ...none.policy.security, critical: { deadlineDays: 3, handling: 'next_window' } },
		regular: { patch: 'automatic', minor: 'automatic', major: 'manual' },
		setBy: 'alice',
		setAt: '2026-09-27T10:00:00+00:00',
	},
	recorded: true,
	statement: savedLines,
}

describe('PatchPolicyPanel', () => {
	beforeEach(() => {
		mockedGet.mockReset()
		mockedWrite.mockReset()
	})

	afterEach(() => {
		vi.unstubAllGlobals()
	})

	it('shows the statement the server renders', async () => {
		mockedGet.mockResolvedValue({ payload: none })
		const wrapper = mount(PatchPolicyPanel)
		await flushPromises()

		expect(mockedGet).toHaveBeenCalledWith('/ocs/v2.php/apps/versioniq/api/patch-policy')
		expect(wrapper.find('[data-testid="patch-policy-statement"]').text()).toBe(none.statement[0])
		expect((wrapper.find('[data-testid="patch-policy-handling-critical"]').element as HTMLSelectElement).value).toBe('scheduled')
	})

	it('sends the form as the patch policy and shows the saved sentences', async () => {
		mockedGet.mockResolvedValue({ payload: none })
		mockedWrite.mockResolvedValue({ payload: saved })
		const wrapper = mount(PatchPolicyPanel)
		await flushPromises()

		await wrapper.find('[data-testid="patch-policy-handling-critical"]').setValue('next_window')
		await wrapper.find('[data-testid="patch-policy-deadline-critical"]').setValue('3')
		await wrapper.find('[data-testid="patch-policy-regular-major"]').setValue('manual')
		await wrapper.find('[data-testid="patch-policy-statement-text"]').setValue('Security fixes first.')
		await wrapper.find('[data-testid="patch-policy-save"]').trigger('click')
		await flushPromises()

		expect(mockedWrite).toHaveBeenCalledTimes(1)
		const [method, path, body] = mockedWrite.mock.calls[0]
		expect(method).toBe('PUT')
		expect(path).toBe('/ocs/v2.php/apps/versioniq/api/patch-policy')
		expect(body).toEqual({
			security: {
				critical: { deadlineDays: 3, handling: 'next_window' },
				high: { deadlineDays: null, handling: 'scheduled' },
				medium: { deadlineDays: null, handling: 'scheduled' },
				low: { deadlineDays: null, handling: 'scheduled' },
				unknown: { deadlineDays: null, handling: 'scheduled' },
			},
			regular: { patch: 'automatic', minor: 'automatic', major: 'manual' },
			statement: 'Security fixes first.',
		})
		expect(wrapper.findAll('[data-testid="patch-policy-statement"] li').map((line) => line.text())).toEqual(savedLines)
		expect(wrapper.find('[data-testid="patch-policy-notice"]').text()).toBe('Patch policy saved.')
	})

	it('shows the refusal and keeps the form when the server refuses', async () => {
		mockedGet.mockResolvedValue({ payload: none })
		mockedWrite.mockResolvedValue({ payload: none, error: 'security.critical.deadlineDays must be a whole number from 1 to 365, or empty for none.' })
		const wrapper = mount(PatchPolicyPanel)
		await flushPromises()

		await wrapper.find('[data-testid="patch-policy-deadline-critical"]').setValue('0')
		await wrapper.find('[data-testid="patch-policy-save"]').trigger('click')
		await flushPromises()

		expect(wrapper.find('[data-testid="patch-policy-error"]').text()).toContain('deadlineDays')
		expect((wrapper.find('[data-testid="patch-policy-deadline-critical"]').element as HTMLInputElement).value).toBe('0')
		expect(wrapper.find('[data-testid="patch-policy-notice"]').exists()).toBe(false)
	})

	it('downloads exactly the lines it shows', async () => {
		mockedGet.mockResolvedValue({ payload: saved })
		const blobs: Blob[] = []
		vi.stubGlobal('URL', { createObjectURL: (blob: Blob) => { blobs.push(blob); return 'blob:patch-policy' }, revokeObjectURL: vi.fn() })
		const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})
		const wrapper = mount(PatchPolicyPanel)
		await flushPromises()

		await wrapper.find('[data-testid="patch-policy-download"]').trigger('click')

		expect(click).toHaveBeenCalledTimes(1)
		expect(blobs).toHaveLength(1)
		const shown = wrapper.findAll('[data-testid="patch-policy-statement"] li').map((line) => line.text())
		expect(await blobs[0].text()).toBe(shown.join('\n') + '\n')
		click.mockRestore()
	})

	it('shows a load failure', async () => {
		mockedGet.mockResolvedValue({ payload: none, error: 'Forbidden' })
		const wrapper = mount(PatchPolicyPanel)
		await flushPromises()

		expect(wrapper.find('[data-testid="patch-policy-error"]').text()).toBe('Forbidden')
		expect(wrapper.find('[data-testid="patch-policy-statement"]').exists()).toBe(false)
	})
})
