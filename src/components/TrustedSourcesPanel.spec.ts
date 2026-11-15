import { flushPromises, shallowMount } from '@vue/test-utils'
// SPDX-License-Identifier: EUPL-1.2
// Trusted sources panel: loading the allowlist, the trust form's checks and
// request body, and removing a pattern, including every error path.
// @spec openspec/specs/external-sources/spec.md
import { beforeEach, describe, expect, it, vi } from 'vitest'
import TrustedSourcesPanel from './TrustedSourcesPanel.vue'
import { ocsGet, ocsWrite } from '../ocs.ts'

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string, vars: Record<string, unknown> = {}) =>
		text.replace(/\{(\w+)\}/g, (_match: string, key: string) => String(vars[key] ?? '')),
}))

vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', emits: ['click'], template: '<button type="button" @click="$emit(\'click\')"><slot /></button>' } }))
vi.mock('@nextcloud/vue/components/NcCheckboxRadioSwitch', () => ({ default: { name: 'NcCheckboxRadioSwitch', template: '<label><slot /></label>' } }))
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

const TRUSTED = '/ocs/v2.php/apps/versioniq/api/trusted-sources'

type Wrapper = ReturnType<typeof shallowMount>

/**
 * Mounts the panel with the given allowlist answered by the sources endpoint.
 *
 * @param trustedPatterns what the server reports
 */
async function mountWith (trustedPatterns: unknown): Promise<Wrapper> {
	mockedOcsGet.mockResolvedValue({ payload: { trustedPatterns } } as never)
	const wrapper = shallowMount(TrustedSourcesPanel, {
		global: { stubs: { NcButton: false, NcNoteCard: false }, renderStubDefaultSlot: true },
	})
	await flushPromises()
	return wrapper
}

/**
 * The text field whose label starts with the given text.
 *
 * @param wrapper the mounted panel
 * @param label the start of the field label
 */
function field (wrapper: Wrapper, label: string) {
	return wrapper.findAllComponents({ name: 'NcTextField' }).find((c) => String(c.vm.$attrs.label).startsWith(label))!
}

/**
 * Fills the trust form and submits it.
 *
 * @param wrapper the mounted panel
 * @param form the values to enter
 * @param form.owner the owner field
 * @param form.repo the repository field
 * @param form.confirm whether the trust checkbox is ticked
 */
async function submitForm (wrapper: Wrapper, form: { owner?: string, repo?: string, confirm?: boolean }): Promise<void> {
	if (form.owner !== undefined) {
		field(wrapper, 'Owner').vm.$emit('update:modelValue', form.owner)
	}
	if (form.repo !== undefined) {
		field(wrapper, 'Repository').vm.$emit('update:modelValue', form.repo)
	}
	if (form.confirm !== undefined) {
		wrapper.findComponent({ name: 'NcCheckboxRadioSwitch' }).vm.$emit('update:modelValue', form.confirm)
	}
	await flushPromises()
	await wrapper.find('form').trigger('submit')
	await flushPromises()
}

/**
 * The texts of the note cards of one type.
 *
 * @param wrapper the mounted panel
 * @param type error or success
 */
function notes (wrapper: Wrapper, type: string): string[] {
	return wrapper.findAll(`[data-type="${type}"]`).map((note) => note.text())
}

/**
 * The patterns listed in the panel.
 *
 * @param wrapper the mounted panel
 */
function listed (wrapper: Wrapper): string[] {
	return wrapper.findAll('li code').map((code) => code.text())
}

describe('TrustedSourcesPanel', () => {
	beforeEach(() => {
		mockedOcsGet.mockReset()
		mockedOcsWrite.mockReset()
	})

	describe('loading the allowlist', () => {
		it('lists the trusted patterns from the sources endpoint', async () => {
			const wrapper = await mountWith(['github:ConductionNL/*', 'forgejo:team/app'])

			expect(mockedOcsGet).toHaveBeenCalledWith('/ocs/v2.php/apps/versioniq/api/sources')
			expect(listed(wrapper)).toEqual(['github:ConductionNL/*', 'forgejo:team/app'])
			expect(wrapper.text()).not.toContain('No trusted sources configured.')
		})

		it('shows the empty state when the answer has no pattern list', async () => {
			const wrapper = await mountWith(undefined)

			expect(listed(wrapper)).toEqual([])
			expect(wrapper.text()).toContain('No trusted sources configured.')
		})

		it('marks only a pattern on a retired forge', async () => {
			const wrapper = await mountWith(['codeberg:old/*', 'github:new/*'])

			const markers = wrapper.findAll('[data-testid="retired-forge"]')
			expect(markers).toHaveLength(1)
			expect(markers[0].element.parentElement!.textContent).toContain('codeberg:old/*')
		})

		it('shows the error of a failed load', async () => {
			mockedOcsGet.mockRejectedValue(new Error('Server unreachable'))
			const wrapper = shallowMount(TrustedSourcesPanel, { global: { stubs: { NcNoteCard: false } } })
			await flushPromises()

			expect(notes(wrapper, 'error')).toEqual(['Server unreachable'])
		})

		it('falls back to a generic message when the load throws a non-error', async () => {
			mockedOcsGet.mockRejectedValue('nope')
			const wrapper = shallowMount(TrustedSourcesPanel, { global: { stubs: { NcNoteCard: false } } })
			await flushPromises()

			expect(notes(wrapper, 'error')).toEqual(['Could not load trusted sources.'])
		})
	})

	describe('adding a pattern', () => {
		it('requires an owner before anything is sent', async () => {
			const wrapper = await mountWith([])

			await submitForm(wrapper, { owner: '   ', confirm: true })

			expect(mockedOcsWrite).not.toHaveBeenCalled()
			expect(notes(wrapper, 'error')).toEqual(['An owner/organisation is required.'])
		})

		it('requires the trust confirmation before anything is sent', async () => {
			const wrapper = await mountWith([])

			await submitForm(wrapper, { owner: 'ConductionNL' })

			expect(mockedOcsWrite).not.toHaveBeenCalled()
			expect(notes(wrapper, 'error')).toEqual(['Please confirm you trust this source before adding it.'])
		})

		it('trusts a whole owner, shows the new list and clears the form', async () => {
			mockedOcsWrite.mockResolvedValue({ payload: { trustedPatterns: ['github:ConductionNL/*'] } } as never)
			const wrapper = await mountWith([])

			await submitForm(wrapper, { owner: ' ConductionNL ', repo: '  ', confirm: true })

			expect(mockedOcsWrite).toHaveBeenCalledWith('POST', TRUSTED, { forge: 'github', owner: 'ConductionNL' })
			expect(listed(wrapper)).toEqual(['github:ConductionNL/*'])
			expect(notes(wrapper, 'success')).toEqual(['Trusted github:ConductionNL/*'])
			expect(notes(wrapper, 'error')).toEqual([])
			expect(field(wrapper, 'Owner').vm.$attrs.modelValue).toBe('')
			expect(field(wrapper, 'Repository').vm.$attrs.modelValue).toBe('')
			expect(wrapper.findComponent({ name: 'NcCheckboxRadioSwitch' }).vm.$attrs.modelValue).toBe(false)
		})

		it('sends a trimmed repository on the chosen forge', async () => {
			mockedOcsWrite.mockResolvedValue({ payload: { trustedPatterns: ['forgejo:team/app'] } } as never)
			const wrapper = await mountWith([])
			wrapper.findComponent({ name: 'NcSelect' }).vm.$emit('update:modelValue', 'forgejo')

			await submitForm(wrapper, { owner: 'team', repo: ' app ', confirm: true })

			expect(mockedOcsWrite).toHaveBeenCalledWith('POST', TRUSTED, { forge: 'forgejo', owner: 'team', repo: 'app' })
			expect(notes(wrapper, 'success')).toEqual(['Trusted forgejo:team/app'])
		})

		it('keeps the current list when the answer carries none', async () => {
			mockedOcsWrite.mockResolvedValue({ payload: {} } as never)
			const wrapper = await mountWith(['github:a/*'])

			await submitForm(wrapper, { owner: 'b', confirm: true })

			expect(listed(wrapper)).toEqual(['github:a/*'])
			expect(notes(wrapper, 'success')).toEqual(['Trusted github:b/*'])
		})

		it('shows the server refusal and keeps the form', async () => {
			mockedOcsWrite.mockResolvedValue({ payload: {}, error: 'That pattern is too broad.' } as never)
			const wrapper = await mountWith([])

			await submitForm(wrapper, { owner: 'ConductionNL', confirm: true })

			expect(notes(wrapper, 'error')).toEqual(['That pattern is too broad.'])
			expect(notes(wrapper, 'success')).toEqual([])
			expect(field(wrapper, 'Owner').vm.$attrs.modelValue).toBe('ConductionNL')
		})

		it('shows a generic message when the request throws a non-error', async () => {
			mockedOcsWrite.mockRejectedValue('offline')
			const wrapper = await mountWith([])

			await submitForm(wrapper, { owner: 'ConductionNL', confirm: true })

			expect(notes(wrapper, 'error')).toEqual(['Could not add the trusted source.'])
		})
	})

	describe('removing a pattern', () => {
		it('deletes the encoded pattern and shows the new list', async () => {
			mockedOcsWrite.mockResolvedValue({ payload: { trustedPatterns: ['github:b/*'] } } as never)
			const wrapper = await mountWith(['github:a/*', 'github:b/*'])

			await wrapper.findAll('li button')[0].trigger('click')
			await flushPromises()

			expect(mockedOcsWrite).toHaveBeenCalledWith('DELETE', `${TRUSTED}?pattern=github%3Aa%2F*`)
			expect(listed(wrapper)).toEqual(['github:b/*'])
		})

		it('keeps the list and shows the refusal when the server refuses', async () => {
			mockedOcsWrite.mockResolvedValue({ payload: {}, error: 'Forbidden' } as never)
			const wrapper = await mountWith(['github:a/*'])

			await wrapper.find('li button').trigger('click')
			await flushPromises()

			expect(listed(wrapper)).toEqual(['github:a/*'])
			expect(notes(wrapper, 'error')).toEqual(['Forbidden'])
		})

		it('shows the thrown error', async () => {
			mockedOcsWrite.mockRejectedValue(new Error('Network down'))
			const wrapper = await mountWith(['github:a/*'])

			await wrapper.find('li button').trigger('click')
			await flushPromises()

			expect(notes(wrapper, 'error')).toEqual(['Network down'])
			expect(wrapper.find('li button').attributes('disabled')).toBeUndefined()
		})
	})
})
