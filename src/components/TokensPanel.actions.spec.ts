import { flushPromises, shallowMount } from '@vue/test-utils'
// SPDX-License-Identifier: EUPL-1.2
// Token list actions: loading, sharing with admins, deleting, and the
// token-creation deeplink, including every error path.
// @spec openspec/specs/pat-management/spec.md
import { beforeEach, describe, expect, it, vi } from 'vitest'
import TokensPanel from './TokensPanel.vue'
import { ocsGet, ocsWrite } from '../ocs.ts'

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string, vars: Record<string, unknown> = {}) =>
		text.replace(/\{(\w+)\}/g, (_match: string, key: string) => String(vars[key] ?? '')),
}))

vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', emits: ['click'], template: '<button type="button" @click="$emit(\'click\')"><slot /></button>' } }))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({ default: { name: 'NcNoteCard', props: ['type'], template: '<div :data-type="type"><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcSelect', () => ({ default: { name: 'NcSelect', template: '<select><slot /></select>' } }))
vi.mock('@nextcloud/vue/components/NcTextField', () => ({ default: { name: 'NcTextField', template: '<input>' } }))

vi.mock('../ocs', () => ({
	ocsGet: vi.fn(),
	ocsWrite: vi.fn(),
	ensurePasswordConfirmation: vi.fn(async () => undefined),
}))

const mockedOcsGet = vi.mocked(ocsGet)
const mockedOcsWrite = vi.mocked(ocsWrite)

const PATS = '/ocs/v2.php/apps/versioniq/api/pats'

type Wrapper = ReturnType<typeof shallowMount>

/**
 * Mounts the panel; the token list endpoint answers each given list in turn,
 * repeating the last one.
 *
 * @param lists successive answers of the token list endpoint
 */
async function mountWith (...lists: unknown[][]): Promise<Wrapper> {
	let call = 0
	mockedOcsGet.mockImplementation(async (path: string) => {
		if (path !== PATS) {
			throw new Error(`unexpected GET ${path}`)
		}
		const pats = lists[Math.min(call, lists.length - 1)]
		call++
		return { payload: { pats } } as never
	})
	const wrapper = shallowMount(TokensPanel, {
		global: { stubs: { NcButton: false, NcNoteCard: false }, renderStubDefaultSlot: true },
	})
	await flushPromises()
	return wrapper
}

/**
 * The button with exactly the given text.
 *
 * @param wrapper the mounted panel
 * @param text the button text
 */
function button (wrapper: Wrapper, text: string) {
	return wrapper.findAll('button').find((b) => b.text() === text)!
}

/**
 * The texts of the note cards of one type.
 *
 * @param wrapper the mounted panel
 * @param type error, success or info
 */
function notes (wrapper: Wrapper, type: string): string[] {
	return wrapper.findAll(`[data-type="${type}"]`).map((note) => note.text())
}

const shared = { id: 3, label: 'Team', targetPattern: 'ConductionNL/*', sharedWithAdmins: true, tokenHint: 'a1b2' }
const personal = { id: 4, label: 'Mine', targetPattern: 'me/app', forge: 'forgejo', sharedWithAdmins: false }

describe('TokensPanel list and actions', () => {
	beforeEach(() => {
		mockedOcsGet.mockReset()
		mockedOcsWrite.mockReset()
	})

	it('lists each token with its forge, pattern, hint and share action', async () => {
		const wrapper = await mountWith([shared, personal])

		const rows = wrapper.findAll('li').filter((li) => li.find('strong').exists())
		expect(rows.map((row) => row.find('code').text())).toEqual(['github:ConductionNL/*', 'forgejo:me/app'])
		expect(rows[0].text()).toContain('…a1b2')
		expect(rows[0].text()).toContain('Unshare')
		expect(rows[1].text()).toContain('Share with admins')
		expect(rows[1].text()).not.toContain('…')
		expect(wrapper.text()).not.toContain('No tokens configured.')
	})

	it('shows the empty state when the answer has no token list', async () => {
		mockedOcsGet.mockResolvedValue({ payload: {} } as never)
		const wrapper = shallowMount(TokensPanel)
		await flushPromises()

		expect(wrapper.text()).toContain('No tokens configured.')
	})

	it('shows the load error, or a fallback for a non-error throw', async () => {
		mockedOcsGet.mockRejectedValueOnce(new Error('Server unreachable'))
		const failed = shallowMount(TokensPanel, { global: { stubs: { NcNoteCard: false } } })
		await flushPromises()
		mockedOcsGet.mockRejectedValueOnce('nope')
		const fallback = shallowMount(TokensPanel, { global: { stubs: { NcNoteCard: false } } })
		await flushPromises()

		expect(notes(failed, 'error')).toEqual(['Server unreachable'])
		expect(notes(fallback, 'error')).toEqual(['Could not load tokens.'])
	})

	it('flips the share flag and reloads the list', async () => {
		mockedOcsWrite.mockResolvedValue({ payload: {} } as never)
		const wrapper = await mountWith([personal], [{ ...personal, sharedWithAdmins: true }])

		await button(wrapper, 'Share with admins').trigger('click')
		await flushPromises()

		expect(mockedOcsWrite).toHaveBeenCalledWith('PATCH', `${PATS}/4`, { sharedWithAdmins: true })
		expect(mockedOcsGet).toHaveBeenCalledTimes(2)
		expect(button(wrapper, 'Unshare')).toBeDefined()
	})

	it('unshares a shared token', async () => {
		mockedOcsWrite.mockResolvedValue({ payload: {} } as never)
		const wrapper = await mountWith([shared])

		await button(wrapper, 'Unshare').trigger('click')
		await flushPromises()

		expect(mockedOcsWrite).toHaveBeenCalledWith('PATCH', `${PATS}/3`, { sharedWithAdmins: false })
	})

	it('shows a share refusal without reloading', async () => {
		mockedOcsWrite.mockResolvedValue({ payload: {}, error: 'Only the PAT owner can update it.' } as never)
		const wrapper = await mountWith([shared])

		await button(wrapper, 'Unshare').trigger('click')
		await flushPromises()

		expect(notes(wrapper, 'error')).toEqual(['Only the PAT owner can update it.'])
		expect(mockedOcsGet).toHaveBeenCalledTimes(1)
	})

	it('shows a thrown share error, or a fallback for a non-error throw', async () => {
		const wrapper = await mountWith([shared])

		mockedOcsWrite.mockRejectedValueOnce(new Error('Network down'))
		await button(wrapper, 'Unshare').trigger('click')
		await flushPromises()
		expect(notes(wrapper, 'error')).toEqual(['Network down'])

		mockedOcsWrite.mockRejectedValueOnce('nope')
		await button(wrapper, 'Unshare').trigger('click')
		await flushPromises()
		expect(notes(wrapper, 'error')).toEqual(['Could not update the token.'])
	})

	it('deletes a token and reloads the list', async () => {
		mockedOcsWrite.mockResolvedValue({ payload: {} } as never)
		const wrapper = await mountWith([shared, personal], [personal])

		await wrapper.findAll('li button').filter((b) => b.text() === 'Delete')[0].trigger('click')
		await flushPromises()

		expect(mockedOcsWrite).toHaveBeenCalledWith('DELETE', `${PATS}/3`)
		expect(wrapper.findAll('li strong').map((s) => s.text())).toEqual(['Mine'])
	})

	it('shows a delete refusal and keeps the token', async () => {
		mockedOcsWrite.mockResolvedValue({ payload: {}, error: 'PAT not found.' } as never)
		const wrapper = await mountWith([shared])

		await button(wrapper, 'Delete').trigger('click')
		await flushPromises()

		expect(notes(wrapper, 'error')).toEqual(['PAT not found.'])
		expect(wrapper.findAll('li strong').map((s) => s.text())).toEqual(['Team'])
		expect(mockedOcsGet).toHaveBeenCalledTimes(1)
	})

	it('shows a thrown delete error, or a fallback for a non-error throw', async () => {
		const wrapper = await mountWith([shared])

		mockedOcsWrite.mockRejectedValueOnce(new Error('Network down'))
		await button(wrapper, 'Delete').trigger('click')
		await flushPromises()
		expect(notes(wrapper, 'error')).toEqual(['Network down'])

		mockedOcsWrite.mockRejectedValueOnce(42)
		await button(wrapper, 'Delete').trigger('click')
		await flushPromises()
		expect(notes(wrapper, 'error')).toEqual(['Could not delete the token.'])
	})

	describe('token-creation deeplink', () => {
		it('asks for the chosen forge and shows the link with its instructions', async () => {
			const wrapper = await mountWith([])
			mockedOcsGet.mockResolvedValueOnce({ payload: { url: 'https://github.com/settings/tokens/new', instructions: ['Select repo scope', 'Copy the token'] } } as never)

			await button(wrapper, 'Create a token on GitHub…').trigger('click')
			await flushPromises()

			expect(mockedOcsGet).toHaveBeenLastCalledWith('/ocs/v2.php/apps/versioniq/api/pats/deeplink', { forge: 'github' })
			const info = wrapper.find('[data-type="info"]')
			expect(info.find('a').attributes('href')).toBe('https://github.com/settings/tokens/new')
			expect(info.findAll('li').map((li) => li.text())).toEqual(['Select repo scope', 'Copy the token'])
		})

		it('uses the selected forge and copes with missing instructions', async () => {
			const wrapper = await mountWith([])
			wrapper.findComponent({ name: 'NcSelect' }).vm.$emit('update:modelValue', 'forgejo')
			await flushPromises()
			mockedOcsGet.mockResolvedValueOnce({ payload: { url: 'https://git.example/user/settings/applications' } } as never)

			await button(wrapper, 'Create a token on Self-hosted Forgejo or Gitea…').trigger('click')
			await flushPromises()

			expect(mockedOcsGet).toHaveBeenLastCalledWith('/ocs/v2.php/apps/versioniq/api/pats/deeplink', { forge: 'forgejo' })
			const info = wrapper.find('[data-type="info"]')
			expect(info.find('a').text()).toBe('https://git.example/user/settings/applications')
			expect(info.findAll('li')).toHaveLength(0)
		})

		it('shows no link when the answer carries none', async () => {
			const wrapper = await mountWith([])
			mockedOcsGet.mockResolvedValueOnce({ payload: { instructions: ['x'] } } as never)

			await button(wrapper, 'Create a token on GitHub…').trigger('click')
			await flushPromises()

			expect(wrapper.find('[data-type="info"]').exists()).toBe(false)
			expect(notes(wrapper, 'error')).toEqual([])
		})

		it('clears an earlier link and shows the error when the request fails', async () => {
			const wrapper = await mountWith([])
			mockedOcsGet.mockResolvedValueOnce({ payload: { url: 'https://first' } } as never)
			await button(wrapper, 'Create a token on GitHub…').trigger('click')
			await flushPromises()

			mockedOcsGet.mockRejectedValueOnce(new Error('Unknown PAT kind'))
			await button(wrapper, 'Create a token on GitHub…').trigger('click')
			await flushPromises()
			expect(wrapper.find('[data-type="info"]').exists()).toBe(false)
			expect(notes(wrapper, 'error')).toEqual(['Unknown PAT kind'])

			mockedOcsGet.mockRejectedValueOnce(null)
			await button(wrapper, 'Create a token on GitHub…').trigger('click')
			await flushPromises()
			expect(notes(wrapper, 'error')).toEqual(['Could not build the token-creation link.'])
		})
	})
})
