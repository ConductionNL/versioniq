import { flushPromises, shallowMount } from '@vue/test-utils'
// SPDX-License-Identifier: EUPL-1.2
// Covers "Expiry state in the PAT API and UI" ("Badges reflect state"):
// @spec openspec/specs/pat-management/spec.md
import { beforeEach, describe, expect, it, vi } from 'vitest'
import TokensPanel from './TokensPanel.vue'
import { ocsGet } from '../ocs.ts'

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

type MockPat = {
	id: number
	label: string
	targetPattern: string
	expiryState: 'ok' | 'expiring' | 'expired' | 'unknown'
	daysRemaining: number | null
}

async function renderWithPat (pat: MockPat) {
	mockedOcsGet.mockResolvedValue({ payload: { pats: [pat] } })
	const wrapper = shallowMount(TokensPanel)
	await flushPromises()
	return wrapper
}

describe('TokensPanel expiry badges', () => {
	beforeEach(() => {
		mockedOcsGet.mockReset()
	})

	it('shows no expiry badge for an ok token', async () => {
		const wrapper = await renderWithPat({
			id: 1, label: 'ok-token', targetPattern: 'ConductionNL/*', expiryState: 'ok', daysRemaining: 40,
		})

		expect(wrapper.find('[data-testid="expiry-badge"]').exists()).toBe(false)
	})

	it('shows a warning badge with days remaining for an expiring token', async () => {
		const wrapper = await renderWithPat({
			id: 2, label: 'expiring-token', targetPattern: 'ConductionNL/*', expiryState: 'expiring', daysRemaining: 5,
		})

		const badge = wrapper.find('[data-testid="expiry-badge"]')
		expect(badge.exists()).toBe(true)
		expect(badge.attributes('data-expiry-state')).toBe('expiring')
		expect(badge.text()).toContain('5')
	})

	it('shows an error badge for an expired token', async () => {
		const wrapper = await renderWithPat({
			id: 3, label: 'expired-token', targetPattern: 'ConductionNL/*', expiryState: 'expired', daysRemaining: -2,
		})

		const badge = wrapper.find('[data-testid="expiry-badge"]')
		expect(badge.exists()).toBe(true)
		expect(badge.attributes('data-expiry-state')).toBe('expired')
	})

	it('shows a neutral badge for a token with unknown expiry', async () => {
		const wrapper = await renderWithPat({
			id: 4, label: 'unknown-token', targetPattern: 'ConductionNL/*', expiryState: 'unknown', daysRemaining: null,
		})

		const badge = wrapper.find('[data-testid="expiry-badge"]')
		expect(badge.exists()).toBe(true)
		expect(badge.attributes('data-expiry-state')).toBe('unknown')
		expect(badge.text().toLowerCase()).toContain('unknown')
	})
})
