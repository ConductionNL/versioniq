import type { UpdateChecks } from './UpdateChecksPanel.vue'

// SPDX-License-Identifier: EUPL-1.2
// Covers the update checks on the Settings tab (safety-update-checks 4.1):
// @spec openspec/changes/safety-update-checks/specs/update-checks/spec.md#requirement-admins-and-operators-attach-checks-that-every-dry-run-runs
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import UpdateChecksPanel from './UpdateChecksPanel.vue'
import { ocsWrite } from '../ocs.ts'

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string, vars: Record<string, unknown> = {}) =>
		text.replace(/\{(\w+)\}/g, (_match: string, key: string) => String(vars[key] ?? '')),
}))
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', props: ['disabled'], emits: ['click'], template: '<button type="button" :disabled="disabled" @click="$emit(\'click\')"><slot /></button>' } }))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({ default: { name: 'NcNoteCard', template: '<div><slot /></div>' } }))
vi.mock('../ocs', () => ({ ocsGet: vi.fn(), ocsWrite: vi.fn() }))

const mockedWrite = vi.mocked(ocsWrite)

const ENDPOINT = '/ocs/v2.php/apps/versioniq/api/update-checks'

/**
 * @param over Fields to replace.
 */
function checks (over: Partial<UpdateChecks> = {}): UpdateChecks {
	return {
		verdict: [],
		command: [],
		maxVerdict: 10,
		commandConfigKey: 'versioniq.update_checks',
		...over,
	}
}

const staging = { id: 'a1b2c3', name: 'Staging tests', url: 'https://ci.example.org/verdict/{appId}/{version}', apps: ['openregister'], hasToken: true }

describe('UpdateChecksPanel', () => {
	beforeEach(() => {
		mockedWrite.mockReset()
	})

	it('adds a verdict check and saves it through PUT /api/update-checks', async () => {
		mockedWrite.mockResolvedValue({ payload: checks({ verdict: [{ ...staging, id: 'new1', hasToken: true }] }) })
		const wrapper = mount(UpdateChecksPanel, { props: { checks: checks() } })
		expect(wrapper.find('[data-testid="update-checks-no-verdict"]').exists()).toBe(true)

		await wrapper.find('[data-testid="update-check-add"]').trigger('click')
		await wrapper.find('[data-testid="update-check-name"]').setValue('Staging tests')
		await wrapper.find('[data-testid="update-check-url"]').setValue('https://ci.example.org/verdict/{appId}/{version}')
		await wrapper.find('[data-testid="update-check-apps"]').setValue('openregister, openconnector')
		await wrapper.find('[data-testid="update-check-token"]').setValue('  s3cret  ')
		await wrapper.find('[data-testid="update-checks-save"]').trigger('click')
		await flushPromises()

		expect(mockedWrite).toHaveBeenCalledWith('PUT', ENDPOINT, {
			checks: [{ name: 'Staging tests', url: 'https://ci.example.org/verdict/{appId}/{version}', apps: ['openregister', 'openconnector'], token: 's3cret' }],
		})
		expect(wrapper.find('[data-testid="update-checks-notice"]').exists()).toBe(true)
		// The token field is empty again: the server never sends a token back.
		expect((wrapper.find('[data-testid="update-check-token"]').element as HTMLInputElement).value).toBe('')
	})

	it('labels every field of a check', async () => {
		const wrapper = mount(UpdateChecksPanel, { props: { checks: checks({ verdict: [staging] }) } })

		for (const field of ['name', 'url', 'apps', 'token', 'clear-token']) {
			const input = wrapper.find(`[data-testid="update-check-${field}"]`)
			const id = input.attributes('id')
			expect(id).toBeTruthy()
			expect(wrapper.find(`label[for="${id}"]`).exists()).toBe(true)
		}
	})

	it('edits a stored check by its id and keeps its token unless one is typed', async () => {
		mockedWrite.mockResolvedValue({ payload: checks({ verdict: [{ ...staging, name: 'CI verdict' }] }) })
		const wrapper = mount(UpdateChecksPanel, { props: { checks: checks({ verdict: [staging] }) } })

		expect((wrapper.find('[data-testid="update-check-apps"]').element as HTMLInputElement).value).toBe('openregister')
		expect(wrapper.text()).toContain('stored; leave empty to keep it')
		await wrapper.find('[data-testid="update-check-name"]').setValue('CI verdict')
		await wrapper.find('[data-testid="update-checks-save"]').trigger('click')
		await flushPromises()

		const body = mockedWrite.mock.calls[0][2] as { checks: Array<Record<string, unknown>> }
		expect(body.checks[0]).toEqual({ id: 'a1b2c3', name: 'CI verdict', url: staging.url, apps: ['openregister'] })
		expect(body.checks[0]).not.toHaveProperty('token')
	})

	it('clears a stored token and removes a check', async () => {
		mockedWrite.mockResolvedValue({ payload: checks() })
		const wrapper = mount(UpdateChecksPanel, { props: { checks: checks({ verdict: [staging, { ...staging, id: 'z9', name: 'Other' }] }) } })

		await wrapper.find('[data-testid="update-check-clear-token"]').setValue(true)
		await wrapper.findAll('[data-testid="update-check-remove"]')[1].trigger('click')
		await wrapper.find('[data-testid="update-checks-save"]').trigger('click')
		await flushPromises()

		expect(mockedWrite).toHaveBeenCalledWith('PUT', ENDPOINT, { checks: [{ id: 'a1b2c3', name: 'Staging tests', url: staging.url, apps: ['openregister'], clearToken: true }] })
	})

	it('refuses an address that is not https before sending anything', async () => {
		const wrapper = mount(UpdateChecksPanel, { props: { checks: checks() } })

		await wrapper.find('[data-testid="update-check-add"]').trigger('click')
		await wrapper.find('[data-testid="update-check-name"]').setValue('Plain')
		await wrapper.find('[data-testid="update-check-url"]').setValue('http://ci.example.org/verdict')
		await wrapper.find('[data-testid="update-checks-save"]').trigger('click')
		await flushPromises()

		expect(mockedWrite).not.toHaveBeenCalled()
		expect(wrapper.find('[data-testid="update-checks-error"]').text()).toContain('must start with https://')
	})

	it('shows the server message when the server refuses the checks', async () => {
		mockedWrite.mockResolvedValue({ payload: {} as UpdateChecks, error: 'At most 10 verdict checks can be set.' })
		const wrapper = mount(UpdateChecksPanel, { props: { checks: checks({ verdict: [staging] }) } })

		await wrapper.find('[data-testid="update-checks-save"]').trigger('click')
		await flushPromises()

		expect(wrapper.find('[data-testid="update-checks-error"]').text()).toBe('At most 10 verdict checks can be set.')
	})

	it('stops adding at the maximum', async () => {
		const wrapper = mount(UpdateChecksPanel, { props: { checks: checks({ verdict: [staging], maxVerdict: 1 }) } })

		expect(wrapper.find('[data-testid="update-check-add"]').attributes('disabled')).toBeDefined()
		expect(wrapper.find('[data-testid="update-checks-max"]').text()).toContain('At most 1 verdict checks')
	})

	it('lists command checks read-only with where they are set', () => {
		const wrapper = mount(UpdateChecksPanel, {
			props: {
				checks: checks({
					command: [
						{ name: 'Lint', command: ['/opt/checks/lint.sh', '{path}'], apps: ['openregister'], timeout: 30 },
						{ name: 'Smoke', command: ['/opt/checks/smoke'], apps: [], timeout: 60 },
					],
				}),
			},
		})

		const items = wrapper.findAll('[data-testid="update-check-command"]')
		expect(items).toHaveLength(2)
		expect(items[0].text()).toContain('Lint')
		expect(items[0].text()).toContain('/opt/checks/lint.sh {path}')
		expect(items[0].text()).toContain('Timeout 30 s')
		expect(items[1].text()).toContain('Every app')
		expect(wrapper.find('[data-testid="update-checks-command-note"]').text()).toContain('config.php under versioniq.update_checks')
		// Nothing in the command list can be edited.
		expect(wrapper.find('[data-testid="update-checks-command-list"]').findAll('input, textarea, button')).toHaveLength(0)
	})
})
