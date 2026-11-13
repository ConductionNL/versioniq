
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
// The Sources tab's bind form, the Discover prefill and the self-hosted forge host.
// @spec openspec/specs/external-sources/spec.md
import type { VueWrapper } from '@vue/test-utils'

import { flushPromises, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import SourcesPanel from './SourcesPanel.vue'
import { ocsGet, ocsWrite } from '../ocs.ts'

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string, vars: Record<string, unknown> = {}) =>
		text.replace(/\{(\w+)\}/g, (_match: string, key: string) => String(vars[key] ?? '')),
}))

vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', emits: ['click'], template: '<button type="button" @click="$emit(\'click\')"><slot /></button>' } }))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({ default: { name: 'NcNoteCard', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcSelect', () => ({ default: { name: 'NcSelect', template: '<select><slot /></select>' } }))
vi.mock('@nextcloud/vue/components/NcTextField', () => ({ default: { name: 'NcTextField', template: '<input>' } }))

vi.mock('../ocs', () => ({
	ocsGet: vi.fn(),
	ocsWrite: vi.fn(),
	ensurePasswordConfirmation: vi.fn(async () => undefined),
}))

const mockedOcsGet = vi.mocked(ocsGet)
const mockedOcsWrite = vi.mocked(ocsWrite)

const HOST_PATH = '/ocs/v2.php/apps/versioniq/api/forges/forgejo'

type Host = { host?: string, configured?: boolean }

/**
 * Answers the panel's two reads: the forge host and the current binding.
 *
 * @param host what the host endpoint returns
 * @param sourceId what the binding endpoint returns
 */
function serve (host: Host = { host: '', configured: false }, sourceId = 'appstore'): void {
	mockedOcsGet.mockImplementation(async (path: string) => ({
		payload: path.includes('/binding') ? { sourceId } : host,
	}) as never)
}

/**
 * Mounts the panel and lets its mount-time reads settle.
 *
 * @param props extra props, such as a prefill
 */
async function mountPanel (props: Record<string, unknown> = {}): Promise<VueWrapper> {
	const wrapper = shallowMount(SourcesPanel, {
		props: { apps: [{ id: 'deck', label: 'Deck' }], ...props },
		global: { stubs: { NcButton: false, NcNoteCard: false } },
	})
	await flushPromises()
	return wrapper
}

/**
 * Sets a select or text field by its label.
 *
 * @param wrapper the panel
 * @param name the component name
 * @param label the field's label or inputLabel
 * @param value the new value
 */
async function setField (wrapper: VueWrapper, name: string, label: string, value: string): Promise<void> {
	const field = wrapper.findAllComponents({ name }).find((c) => c.vm.$attrs.inputLabel === label || c.vm.$attrs.label === label)
	field!.vm.$emit('update:modelValue', value)
	await flushPromises()
}

/**
 * Submits the bind form (the first form) or the host form (the second).
 *
 * @param wrapper the panel
 * @param index which form
 */
async function submit (wrapper: VueWrapper, index: number): Promise<void> {
	await wrapper.findAll('form')[index].trigger('submit')
	await flushPromises()
}

/**
 * The calls made with the given method.
 *
 * @param method the HTTP method
 */
function writes (method: string): unknown[][] {
	return mockedOcsWrite.mock.calls.filter((call) => call[0] === method)
}

beforeEach(() => {
	mockedOcsGet.mockReset()
	mockedOcsWrite.mockReset()
	serve()
})

describe('binding a source', () => {
	it('posts a prefilled form from the Discover tab and reports the new source', async () => {
		mockedOcsWrite.mockResolvedValue({ payload: { sourceId: 'github:ConductionNL/deck' } } as never)
		const wrapper = await mountPanel({ prefill: { appId: 'deck', forge: 'github', owner: 'ConductionNL', repo: 'deck', assetPattern: 'deck-*.tar.gz' } })

		await submit(wrapper, 0)

		expect(writes('POST')).toEqual([[
			'POST',
			'/ocs/v2.php/apps/versioniq/api/source/deck/bind',
			{ kind: 'github-release', forge: 'github', owner: 'ConductionNL', repo: 'deck', assetPattern: 'deck-*.tar.gz' },
		]])
		expect(wrapper.text()).toContain('Bound to github:ConductionNL/deck')
		expect(wrapper.emitted('bound')).toEqual([['deck']])
	})

	it('trims the fields and falls back to the default asset pattern', async () => {
		mockedOcsWrite.mockResolvedValue({ payload: { sourceId: 'github:acme/tool' } } as never)
		const wrapper = await mountPanel()
		await setField(wrapper, 'NcSelect', 'App', 'deck')
		await setField(wrapper, 'NcTextField', 'Owner', '  acme ')
		await setField(wrapper, 'NcTextField', 'Repository', ' tool  ')
		await setField(wrapper, 'NcTextField', 'Asset pattern', '   ')

		await submit(wrapper, 0)

		expect(writes('POST')[0][2]).toEqual({ kind: 'github-release', forge: 'github', owner: 'acme', repo: 'tool', assetPattern: '*.tar.gz' })
	})

	it('asks for an app before anything else', async () => {
		const wrapper = await mountPanel()

		await submit(wrapper, 0)

		expect(wrapper.text()).toContain('Select an app first.')
		expect(writes('POST')).toEqual([])
	})

	it('asks for both owner and repository', async () => {
		const wrapper = await mountPanel()
		await setField(wrapper, 'NcSelect', 'App', 'deck')
		await setField(wrapper, 'NcTextField', 'Owner', 'acme')

		await submit(wrapper, 0)

		expect(wrapper.text()).toContain('Owner and repository are required.')
		expect(writes('POST')).toEqual([])
	})

	it('refuses a self-hosted forge until its host is set', async () => {
		const wrapper = await mountPanel({ prefill: { appId: 'deck', forge: 'forgejo', owner: 'acme', repo: 'tool' } })

		await submit(wrapper, 0)

		expect(wrapper.text()).toContain('Set the self-hosted Forgejo or Gitea host below first.')
		expect(writes('POST')).toEqual([])
	})

	it('binds to a self-hosted forge once its host is configured', async () => {
		serve({ host: 'https://git.example.org', configured: true })
		mockedOcsWrite.mockResolvedValue({ payload: { sourceId: 'forgejo:acme/tool' } } as never)
		const wrapper = await mountPanel({ prefill: { appId: 'deck', forge: 'forgejo', owner: 'acme', repo: 'tool' } })

		await submit(wrapper, 0)

		expect(writes('POST')[0][2]).toMatchObject({ forge: 'forgejo', owner: 'acme', repo: 'tool' })
		expect(wrapper.text()).toContain('Bound to forgejo:acme/tool')
	})

	it('shows the server refusal and does not report a binding', async () => {
		mockedOcsWrite.mockResolvedValue({ payload: {}, error: 'Repository is not trusted.' } as never)
		const wrapper = await mountPanel({ prefill: { appId: 'deck', owner: 'acme', repo: 'tool' } })

		await submit(wrapper, 0)

		expect(wrapper.text()).toContain('Repository is not trusted.')
		expect(wrapper.emitted('bound')).toBeUndefined()
	})

	it('shows a thrown error message, or the fallback for anything else', async () => {
		mockedOcsWrite.mockRejectedValueOnce(new Error('Network down'))
		const wrapper = await mountPanel({ prefill: { appId: 'deck', owner: 'acme', repo: 'tool' } })
		await submit(wrapper, 0)
		expect(wrapper.text()).toContain('Network down')

		mockedOcsWrite.mockRejectedValueOnce('boom')
		await submit(wrapper, 0)
		expect(wrapper.text()).toContain('Could not bind the source.')
	})
})

describe('the current binding', () => {
	it('reports when it cannot be read', async () => {
		mockedOcsGet.mockImplementation(async (path: string) => {
			if (path.includes('/binding')) {
				throw new Error('nope')
			}
			return { payload: { host: '', configured: false } } as never
		})
		const wrapper = await mountPanel()
		await setField(wrapper, 'NcSelect', 'App', 'deck')

		expect(wrapper.text()).toContain('nope')
	})

	it('warns about a binding to the retired Codeberg forge', async () => {
		serve(undefined, 'codeberg:acme/deck')
		const wrapper = await mountPanel()
		await setField(wrapper, 'NcSelect', 'App', 'deck')

		expect(wrapper.find('[data-testid="retired-source"]').exists()).toBe(true)
	})

	it('shows no retired-forge warning for a GitHub binding', async () => {
		serve(undefined, 'github:acme/deck')
		const wrapper = await mountPanel()
		await setField(wrapper, 'NcSelect', 'App', 'deck')

		expect(wrapper.find('[data-testid="retired-source"]').exists()).toBe(false)
	})

	it('keeps the binding when the server refuses its removal', async () => {
		serve(undefined, 'github:acme/deck')
		mockedOcsWrite.mockResolvedValue({ payload: {}, error: 'Not allowed.' } as never)
		const wrapper = await mountPanel()
		await setField(wrapper, 'NcSelect', 'App', 'deck')

		await wrapper.find('[data-testid="remove-binding"]').trigger('click')
		await flushPromises()

		expect(wrapper.text()).toContain('Not allowed.')
		expect(wrapper.text()).toContain('github:acme/deck')
		expect(wrapper.emitted('bound')).toBeUndefined()
	})

	it('falls back to a generic message when the removal fails', async () => {
		serve(undefined, 'github:acme/deck')
		mockedOcsWrite.mockRejectedValue('boom')
		const wrapper = await mountPanel()
		await setField(wrapper, 'NcSelect', 'App', 'deck')

		await wrapper.find('[data-testid="remove-binding"]').trigger('click')
		await flushPromises()

		expect(wrapper.text()).toContain('Could not remove the binding.')
	})
})

describe('the self-hosted forge host', () => {
	it('reports when the host cannot be loaded', async () => {
		mockedOcsGet.mockRejectedValue('boom')
		const wrapper = await mountPanel()

		expect(wrapper.text()).toContain('Could not load the self-hosted forge host.')
	})

	it('saves a trimmed host and says it is saved', async () => {
		mockedOcsWrite.mockResolvedValue({ payload: { host: 'https://git.example.org', configured: true } } as never)
		const wrapper = await mountPanel()
		await setField(wrapper, 'NcTextField', 'Self-hosted Forgejo or Gitea host', '  https://git.example.org ')

		await submit(wrapper, 1)

		expect(mockedOcsWrite).toHaveBeenCalledWith('PUT', HOST_PATH, { host: 'https://git.example.org' })
		expect(wrapper.text()).toContain('Self-hosted forge host saved.')
	})

	it('says the host is cleared when the server reports it unconfigured', async () => {
		serve({ host: 'https://git.example.org', configured: true })
		mockedOcsWrite.mockResolvedValue({ payload: { configured: false } } as never)
		const wrapper = await mountPanel()
		await setField(wrapper, 'NcTextField', 'Self-hosted Forgejo or Gitea host', '')

		await submit(wrapper, 1)

		expect(mockedOcsWrite).toHaveBeenCalledWith('PUT', HOST_PATH, { host: '' })
		expect(wrapper.text()).toContain('Self-hosted forge host cleared.')
	})

	it('shows the server refusal for a host', async () => {
		mockedOcsWrite.mockResolvedValue({ payload: {}, error: 'Not a valid URL.' } as never)
		const wrapper = await mountPanel()

		await submit(wrapper, 1)

		expect(wrapper.text()).toContain('Not a valid URL.')
		expect(wrapper.text()).not.toContain('Self-hosted forge host saved.')
	})

	it('falls back to a generic message when saving fails', async () => {
		mockedOcsWrite.mockRejectedValue('boom')
		const wrapper = await mountPanel()

		await submit(wrapper, 1)

		expect(wrapper.text()).toContain('Could not save the self-hosted forge host.')
	})
})
