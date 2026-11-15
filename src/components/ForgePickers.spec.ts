import { flushPromises, shallowMount } from '@vue/test-utils'
// SPDX-License-Identifier: EUPL-1.2
// Issue #437: Codeberg is retired as a forge of its own. The Sources, Tokens
// and Trusted sources pickers offer GitHub and a self-hosted Forgejo or Gitea
// host instead, and anything still stored under codeberg is shown as retired
// rather than hidden.
// @spec openspec/specs/external-sources/spec.md
import { beforeEach, describe, expect, it, vi } from 'vitest'
import SourcesPanel from './SourcesPanel.vue'
import TokensPanel from './TokensPanel.vue'
import TrustedSourcesPanel from './TrustedSourcesPanel.vue'
import { ocsGet, ocsWrite } from '../ocs.ts'

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string, vars: Record<string, unknown> = {}) =>
		text.replace(/\{(\w+)\}/g, (_match: string, key: string) => String(vars[key] ?? '')),
}))

vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', template: '<button><slot /></button>' } }))
vi.mock('@nextcloud/vue/components/NcCheckboxRadioSwitch', () => ({ default: { name: 'NcCheckboxRadioSwitch', template: '<label><slot /></label>' } }))
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

type Wrapper = ReturnType<typeof shallowMount>

/**
 * The ids offered by the forge picker, the NcSelect whose input label is Forge.
 *
 * @param wrapper the mounted panel
 */
function forgeIds (wrapper: Wrapper): string[] {
	const select = wrapper.findAllComponents({ name: 'NcSelect' })
		.find((candidate) => candidate.vm.$attrs.inputLabel === 'Forge')
	expect(select, 'the panel has a forge picker').toBeDefined()
	const options = select!.vm.$attrs.options as Array<{ id: string }>
	return options.map((option) => option.id)
}

/**
 * Answers each GET the panels make.
 *
 * @param answers payload per URL fragment
 */
function answerGets (answers: Record<string, unknown>): void {
	mockedOcsGet.mockImplementation(async (path: string) => {
		const match = Object.keys(answers).find((fragment) => path.includes(fragment))
		return { payload: match ? answers[match] : {} } as never
	})
}

describe('forge pickers after Codeberg was retired', () => {
	beforeEach(() => {
		mockedOcsGet.mockReset()
	})

	it('the Sources tab offers GitHub and a self-hosted Forgejo or Gitea host, not Codeberg', async () => {
		answerGets({ '/api/forges/forgejo': { host: '', configured: false } })
		const wrapper = shallowMount(SourcesPanel, { props: { apps: [] } })
		await flushPromises()

		expect(forgeIds(wrapper)).toEqual(['github', 'gitlab', 'forgejo'])
		expect(wrapper.text()).not.toContain('Codeberg')
	})

	it('the Tokens tab offers GitHub and a self-hosted Forgejo or Gitea host, not Codeberg', async () => {
		answerGets({ '/api/pats': { pats: [] } })
		const wrapper = shallowMount(TokensPanel)
		await flushPromises()

		expect(forgeIds(wrapper)).toEqual(['github', 'gitlab', 'forgejo'])
	})

	it('the Trusted sources tab offers GitHub and a self-hosted Forgejo or Gitea host, not Codeberg', async () => {
		answerGets({ '/api/sources': { trustedPatterns: [] } })
		const wrapper = shallowMount(TrustedSourcesPanel)
		await flushPromises()

		expect(forgeIds(wrapper)).toEqual(['github', 'gitlab', 'forgejo'])
	})

	it('an app still bound to Codeberg is shown as a retired source to rebind', async () => {
		answerGets({
			'/api/forges/forgejo': { host: '', configured: false },
			'/binding': { sourceId: 'codeberg:Conduction/pipelinq' },
		})
		const wrapper = shallowMount(SourcesPanel, {
			props: { apps: [{ id: 'pipelinq', label: 'Pipelinq' }] },
			global: { renderStubDefaultSlot: true },
		})
		await flushPromises()
		// Selecting the app (here through a Discover-style prefill) loads its binding.
		await wrapper.setProps({ prefill: { appId: 'pipelinq' } })
		await flushPromises()

		const note = wrapper.find('[data-testid="retired-source"]')
		expect(note.exists()).toBe(true)
		expect(note.text()).toContain('retired')
	})

	it('a stored Codeberg token and trusted pattern are marked retired', async () => {
		answerGets({
			'/api/pats': { pats: [{ id: 1, label: 'old', targetPattern: 'Conduction/*', forge: 'codeberg', expiryState: 'ok' }] },
			'/api/sources': { trustedPatterns: ['codeberg:Conduction/*', 'github:ConductionNL/*'] },
		})
		const tokens = shallowMount(TokensPanel)
		const trusted = shallowMount(TrustedSourcesPanel)
		await flushPromises()

		expect(tokens.findAll('[data-testid="retired-forge"]')).toHaveLength(1)
		expect(trusted.findAll('[data-testid="retired-forge"]')).toHaveLength(1)
	})

	it('the Sources tab lets the admin set the self-hosted host', async () => {
		answerGets({ '/api/forges/forgejo': { host: 'https://git.example.org', configured: true } })
		const wrapper = shallowMount(SourcesPanel, { props: { apps: [] } })
		await flushPromises()

		const host = wrapper.findAllComponents({ name: 'NcTextField' })
			.find((field) => field.vm.$attrs.label === 'Self-hosted Forgejo or Gitea host')
		expect(host, 'a host field is shown').toBeDefined()
		expect(host!.vm.$attrs.modelValue).toBe('https://git.example.org')
	})
})

describe('GitLab on the Sources tab (sources-gitlab-forge 4.1)', () => {
	beforeEach(() => {
		mockedOcsGet.mockReset()
		mockedOcsWrite.mockClear()
	})

	/**
	 * The text field with a label.
	 *
	 * @param wrapper the mounted panel
	 * @param label the field's label
	 */
	function field (wrapper: Wrapper, label: string) {
		return wrapper.findAllComponents({ name: 'NcTextField' }).find((candidate) => candidate.vm.$attrs.label === label)
	}

	it('shows a self-managed GitLab host, and leaves the field empty for gitlab.com', async () => {
		answerGets({ '/api/forges/gitlab': { host: 'https://gitlab.example.org', selfManaged: true } })
		const selfManaged = shallowMount(SourcesPanel, { props: { apps: [] } })
		await flushPromises()
		expect(field(selfManaged, 'Self-managed GitLab host')!.vm.$attrs.modelValue).toBe('https://gitlab.example.org')

		answerGets({ '/api/forges/gitlab': { host: 'https://gitlab.com', selfManaged: false } })
		const hosted = shallowMount(SourcesPanel, { props: { apps: [] } })
		await flushPromises()
		expect(field(hosted, 'Self-managed GitLab host')!.vm.$attrs.modelValue).toBe('')
	})

	it('saves the GitLab host to its own endpoint', async () => {
		answerGets({ '/api/forges/gitlab': { host: 'https://gitlab.com', selfManaged: false } })
		mockedOcsWrite.mockResolvedValueOnce({ payload: { host: 'https://gitlab.example.org', selfManaged: true } } as never)
		const wrapper = shallowMount(SourcesPanel, { props: { apps: [] }, global: { renderStubDefaultSlot: true } })
		await flushPromises()

		field(wrapper, 'Self-managed GitLab host')!.vm.$emit('update:modelValue', ' https://gitlab.example.org ')
		await wrapper.find('[data-testid="gitlab-host-form"]').trigger('submit')
		await flushPromises()

		expect(mockedOcsWrite).toHaveBeenCalledWith('PUT', '/ocs/v2.php/apps/versioniq/api/forges/gitlab', { host: 'https://gitlab.example.org' })
		expect(wrapper.text()).toContain('GitLab host saved.')
	})

	it('asks for a group and a project when GitLab is the forge', async () => {
		answerGets({})
		const wrapper = shallowMount(SourcesPanel, { props: { apps: [], prefill: { appId: 'app', forge: 'gitlab' } } })
		await flushPromises()

		expect(field(wrapper, 'Group or namespace')).toBeDefined()
		expect(field(wrapper, 'Project')).toBeDefined()
		expect(field(wrapper, 'Owner')).toBeUndefined()
	})
})
