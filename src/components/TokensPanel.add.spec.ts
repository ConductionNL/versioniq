import { flushPromises, shallowMount } from '@vue/test-utils'
// SPDX-License-Identifier: EUPL-1.2
// Pins adding a token: the required fields, the request, the form reset and the errors.
// @spec openspec/specs/pat-management/spec.md
import { beforeEach, describe, expect, it, vi } from 'vitest'
import TokensPanel from './TokensPanel.vue'
import { ocsGet, ocsWrite } from '../ocs.ts'

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string, vars: Record<string, unknown> = {}) =>
		text.replace(/\{(\w+)\}/g, (_match: string, key: string) => String(vars[key] ?? '')),
}))

// The real @nextcloud/vue components ship dist CSS side-effect imports that
// Vitest's default deps handling can't resolve; stub them so this spec stays
// focused on TokensPanel's own badge logic.
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', template: '<button><slot /></button>' } }))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({ default: { name: 'NcNoteCard', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcSelect', () => ({ default: { name: 'NcSelect', template: '<select><slot /></select>' } }))
vi.mock('@nextcloud/vue/components/NcTextField', () => ({ default: { name: 'NcTextField', template: '<input>' } }))

vi.mock('../ocs', () => ({
	ocsGet: vi.fn(),
	ocsWrite: vi.fn(async () => ({ payload: {} })),
	ensurePasswordConfirmation: vi.fn(async () => undefined),
}))

const mockedOcsGet = vi.mocked(ocsGet)
const mockedOcsWrite = vi.mocked(ocsWrite)

type Panel = Record<string, unknown> & { addToken: () => Promise<void> }

/**
 * Mount the panel, fill the form and add the token.
 *
 * @param form The form values.
 * @param answer What the POST answers, or an Error it throws.
 */
async function add (form: Record<string, string>, answer: unknown = { payload: {} }) {
	mockedOcsGet.mockResolvedValue({ payload: { pats: [] } })
	if (answer instanceof Error) {
		mockedOcsWrite.mockRejectedValue(answer)
	} else {
		mockedOcsWrite.mockResolvedValue(answer as never)
	}
	const wrapper = shallowMount(TokensPanel)
	await flushPromises()
	const vm = wrapper.vm as unknown as Panel
	Object.assign(vm, { error: 'old', notice: 'old' }, form)
	mockedOcsGet.mockClear()
	await vm.addToken()
	await flushPromises()

	return {
		writes: mockedOcsWrite.mock.calls,
		reloads: mockedOcsGet.mock.calls.length,
		state: { error: vm.error, notice: vm.notice, loading: vm.loading, label: vm.label, owner: vm.owner, repo: vm.repo, token: vm.token, forge: vm.forge },
	}
}

const filled = { forge: 'gitlab', label: ' CI ', owner: ' acme ', repo: ' app ', token: ' glpat-1 ' }

describe('TokensPanel addToken', () => {
	beforeEach(() => {
		mockedOcsGet.mockReset()
		mockedOcsWrite.mockReset()
	})

	it('posts the trimmed token for owner/repo, clears the form and reloads', async () => {
		const result = await add(filled)

		expect(result.writes).toEqual([['POST', '/ocs/v2.php/apps/versioniq/api/pats', { forge: 'gitlab', label: 'CI', targetPattern: 'acme/app', token: 'glpat-1' }]])
		expect(result.reloads).toBe(1)
		expect(result.state).toEqual({ error: '', notice: 'Token added.', loading: false, label: '', owner: '', repo: '', token: '', forge: 'gitlab' })
	})

	it('targets every repository of the owner when no repository is given', async () => {
		const result = await add({ ...filled, repo: '  ' })

		expect((result.writes[0][2] as Record<string, unknown>).targetPattern).toBe('acme/*')
	})

	it('refuses without a label, an owner or a token', async () => {
		for (const missing of ['label', 'owner', 'token']) {
			const result = await add({ ...filled, [missing]: ' ' })

			expect(result.writes).toEqual([])
			expect(result.state.error).toBe('Label, owner and token are required.')
			expect(result.state.notice).toBe('')
			expect(result.state.loading).toBe(false)
			mockedOcsWrite.mockReset()
		}
	})

	it('shows the refusal and keeps the form when the server refuses', async () => {
		const result = await add(filled, { payload: {}, error: 'Token rejected' })

		expect(result.reloads).toBe(0)
		expect(result.state).toEqual({ error: 'Token rejected', notice: '', loading: false, label: ' CI ', owner: ' acme ', repo: ' app ', token: ' glpat-1 ', forge: 'gitlab' })
	})

	it('shows the thrown message, or a fallback for a non-error throw', async () => {
		const thrown = await add(filled, new Error('Network down'))
		mockedOcsWrite.mockReset()
		mockedOcsWrite.mockImplementation(async () => { throw 'nope' })
		mockedOcsGet.mockResolvedValue({ payload: { pats: [] } })
		const wrapper = shallowMount(TokensPanel)
		await flushPromises()
		const vm = wrapper.vm as unknown as Panel
		Object.assign(vm, filled)
		await vm.addToken()

		expect([thrown.state.error, thrown.state.loading]).toEqual(['Network down', false])
		expect([vm.error, vm.loading]).toEqual(['Could not add the token.', false])
	})
})
