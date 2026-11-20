import { mount } from '@vue/test-utils'
// SPDX-License-Identifier: EUPL-1.2
// Covers "Per-version changelog display" ("Expand a version row", "Markdown
// is not an XSS vector"):
// @spec openspec/specs/changelog-visibility/spec.md
import { flushPromises } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import VersionChangelog from './VersionChangelog.vue'

const env = vi.hoisted(() => ({ available: false, language: 'nl' }))
const ocs = vi.hoisted(() => ({ ocsPost: vi.fn() }))

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string, vars: Record<string, string> = {}) => text.replace(/\{(\w+)\}/g, (m, k) => (k in vars ? vars[k] : m)),
	getLanguage: () => env.language,
}))
vi.mock('@nextcloud/initial-state', () => ({
	loadState: (_app: string, key: string, fallback: unknown) => (key === 'notes-translation-available' ? env.available : fallback),
}))
vi.mock('../ocs.ts', () => ocs)

describe('VersionChangelog', () => {
	it('is collapsed by default', () => {
		const wrapper = mount(VersionChangelog, { props: { version: '2.3.0', changelog: 'Fixes LDAP sync' } })

		expect(wrapper.find('[data-testid="changelog-body"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="changelog-toggle"]').attributes('aria-expanded')).toBe('false')
	})

	it('expands to show the release notes on toggle click', async () => {
		const wrapper = mount(VersionChangelog, { props: { version: '2.3.0', changelog: 'Fixes LDAP sync' } })

		await wrapper.get('[data-testid="changelog-toggle"]').trigger('click')

		expect(wrapper.find('[data-testid="changelog-body"]').exists()).toBe(true)
		expect(wrapper.get('[data-testid="changelog-text"]').text()).toBe('Fixes LDAP sync')
		expect(wrapper.get('[data-testid="changelog-toggle"]').attributes('aria-expanded')).toBe('true')
	})

	it('collapses again on a second toggle click', async () => {
		const wrapper = mount(VersionChangelog, { props: { version: '2.3.0', changelog: 'Fixes LDAP sync' } })
		const toggle = wrapper.get('[data-testid="changelog-toggle"]')

		await toggle.trigger('click')
		await toggle.trigger('click')

		expect(wrapper.find('[data-testid="changelog-body"]').exists()).toBe(false)
	})

	it('shows the no-notes placeholder when changelog is null', async () => {
		const wrapper = mount(VersionChangelog, { props: { version: '2.3.0', changelog: null } })

		await wrapper.get('[data-testid="changelog-toggle"]').trigger('click')

		expect(wrapper.find('[data-testid="changelog-text"]').exists()).toBe(false)
		expect(wrapper.get('[data-testid="changelog-placeholder"]').text()).toBe('No release notes provided')
	})

	it('shows the no-notes placeholder when changelog is a blank string', async () => {
		const wrapper = mount(VersionChangelog, { props: { version: '2.3.0', changelog: '   ' } })

		await wrapper.get('[data-testid="changelog-toggle"]').trigger('click')

		expect(wrapper.get('[data-testid="changelog-placeholder"]').exists()).toBe(true)
	})

	it('renders a script-tag body as inert text, never as an executable element', async () => {
		const wrapper = mount(VersionChangelog, {
			props: { version: '2.3.0', changelog: '<script>alert(1)</script>' },
		})

		await wrapper.get('[data-testid="changelog-toggle"]').trigger('click')

		const textNode = wrapper.get('[data-testid="changelog-text"]')
		// Mustache interpolation only — the tag never becomes a DOM element.
		expect(wrapper.find('script').exists()).toBe(false)
		expect(textNode.text()).toBe('<script>alert(1)</script>')
		expect(textNode.html()).toContain('&lt;script&gt;')
	})
})

/**
 * releases-notes-reach 2.3: a Translate button when the instance has a
 * provider and the notes are in another or an unknown language; the
 * translation is labelled and the original is one click away.
 *
 * @spec openspec/changes/releases-notes-reach/specs/changelog-visibility/spec.md
 */
describe('VersionChangelog translation', () => {
	beforeEach(() => {
		env.available = true
		env.language = 'nl'
		ocs.ocsPost.mockReset()
	})

	const open = async (props: Record<string, unknown>) => {
		const wrapper = mount(VersionChangelog, { props: { version: '2.3.0', changelog: 'Fixes LDAP sync', appId: 'deck', ...props } })
		await wrapper.get('[data-testid="changelog-toggle"]').trigger('click')
		return wrapper
	}

	it('offers to translate English notes to the admin language', async () => {
		const wrapper = await open({ changelogLanguage: 'en' })

		expect(wrapper.get('[data-testid="changelog-translate"]').text()).toMatch(/^Translate to /)
		expect(wrapper.get('[data-testid="changelog-translate"]').text()).not.toBe('Translate to {language}')
	})

	it('offers to translate notes of an unknown language', async () => {
		const wrapper = await open({ changelogLanguage: null })

		expect(wrapper.find('[data-testid="changelog-translate"]').exists()).toBe(true)
	})

	it('offers nothing for notes already in the admin language, a regional variant included', async () => {
		env.language = 'pt_BR'
		const wrapper = await open({ changelogLanguage: 'pt' })

		expect(wrapper.find('[data-testid="changelog-translate"]').exists()).toBe(false)
	})

	it('offers nothing without a provider, without notes or without an app', async () => {
		env.available = false
		expect((await open({ changelogLanguage: 'en' })).find('[data-testid="changelog-translate"]').exists()).toBe(false)
		env.available = true
		expect((await open({ changelog: null })).find('[data-testid="changelog-translate"]').exists()).toBe(false)
		expect((await open({ appId: '' })).find('[data-testid="changelog-translate"]').exists()).toBe(false)
	})

	it('shows the machine translation, labelled, and the original again on request', async () => {
		ocs.ocsPost.mockResolvedValue({ payload: { translation: 'Verhelpt LDAP-synchronisatie', machineTranslation: true } })
		const wrapper = await open({ changelogLanguage: 'en', source: ' github:acme/deck ' })

		await wrapper.get('[data-testid="changelog-translate"]').trigger('click')
		await flushPromises()

		expect(ocs.ocsPost).toHaveBeenCalledWith('/ocs/v2.php/apps/versioniq/api/changelog/translate', {
			appId: 'deck', version: '2.3.0', language: 'nl', source: 'github:acme/deck',
		})
		expect(wrapper.get('[data-testid="changelog-machine-label"]').text()).toBe('Machine translation')
		expect(wrapper.get('[data-testid="changelog-translation"]').text()).toBe('Verhelpt LDAP-synchronisatie')
		expect(wrapper.find('[data-testid="changelog-text"]').exists()).toBe(false)

		await wrapper.get('[data-testid="changelog-show-original"]').trigger('click')
		expect(wrapper.get('[data-testid="changelog-text"]').text()).toBe('Fixes LDAP sync')
		expect(wrapper.find('[data-testid="changelog-machine-label"]').exists()).toBe(false)

		// Back to the translation without asking the server again.
		await wrapper.get('[data-testid="changelog-translate"]').trigger('click')
		await flushPromises()
		expect(ocs.ocsPost).toHaveBeenCalledTimes(1)
		expect(wrapper.get('[data-testid="changelog-translation"]').text()).toBe('Verhelpt LDAP-synchronisatie')
	})

	it('sends no source when none is set', async () => {
		ocs.ocsPost.mockResolvedValue({ payload: { translation: 'Vertaald' } })
		const wrapper = await open({ changelogLanguage: 'en' })

		await wrapper.get('[data-testid="changelog-translate"]').trigger('click')
		await flushPromises()

		expect(ocs.ocsPost.mock.calls[0][1]).toEqual({ appId: 'deck', version: '2.3.0', language: 'nl' })
	})

	it("shows the server's message when the translation is refused, and keeps the original", async () => {
		ocs.ocsPost.mockResolvedValue({ payload: { message: 'No machine translation is available.' }, error: 'Request failed' })
		const wrapper = await open({ changelogLanguage: 'en' })

		await wrapper.get('[data-testid="changelog-translate"]').trigger('click')
		await flushPromises()

		expect(wrapper.get('[data-testid="changelog-translate-error"]').text()).toBe('No machine translation is available.')
		expect(wrapper.get('[data-testid="changelog-text"]').text()).toBe('Fixes LDAP sync')
	})

	it('shows a general message when the request fails', async () => {
		ocs.ocsPost.mockRejectedValue(new Error('offline'))
		const wrapper = await open({ changelogLanguage: 'en' })

		await wrapper.get('[data-testid="changelog-translate"]').trigger('click')
		await flushPromises()

		expect(wrapper.get('[data-testid="changelog-translate-error"]').text()).toBe('The release notes could not be translated.')
	})

	it('renders a translated script tag as inert text', async () => {
		ocs.ocsPost.mockResolvedValue({ payload: { translation: '<script>alert(1)</script>' } })
		const wrapper = await open({ changelogLanguage: 'en' })

		await wrapper.get('[data-testid="changelog-translate"]').trigger('click')
		await flushPromises()

		expect(wrapper.find('script').exists()).toBe(false)
		expect(wrapper.get('[data-testid="changelog-translation"]').html()).toContain('&lt;script&gt;')
	})
})
