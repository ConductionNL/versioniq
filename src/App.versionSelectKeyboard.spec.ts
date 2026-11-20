// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * The version picker's Select button is reachable without a mouse (WCAG 2.1.1).
 *
 * jsdom applies no CSS-module styles, so these tests read App.vue's own style
 * block: the rules are the behaviour. A button with `visibility: hidden` is not
 * focusable, which is how the keyboard path broke while a :focus-visible reveal
 * sat right next to it. The e2e keyboard walk is in tests/e2e/versions.spec.ts.
 *
 * @spec openspec/specs/version-management/spec.md
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const source = readFileSync(resolve(__dirname, 'App.vue'), 'utf8')
const style = source.slice(source.indexOf('<style'))
const template = source.slice(source.indexOf('<template>'), source.lastIndexOf('</template>'))

/**
 * The declarations of the first rule whose selector list is exactly `selector`.
 *
 * @param css The stylesheet.
 * @param selector The selector list, comma-separated selectors on their own lines allowed.
 * @return The rule body, or null when there is no such rule.
 */
function rule(css: string, selector: string): string | null {
	const pattern = new RegExp('(^|\\n)\\s*' + selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&').replace(/,\s*/g, ',\\s*') + '\\s*\\{([^}]*)\\}')
	const match = css.match(pattern)
	return match ? match[2] : null
}

describe('the version picker Select button', () => {
	it('is faded, never hidden, so it stays in the tab order', () => {
		const base = rule(style, '.versionSelectButton')
		expect(base).not.toBeNull()
		expect(base).not.toMatch(/visibility\s*:\s*hidden/)
		expect(base).not.toMatch(/display\s*:\s*none/)
		expect(style).not.toMatch(/\.versionSelectButton[^{]*\{[^}]*visibility\s*:\s*hidden/)
	})

	it('shows when focus is anywhere in its version row, and when it has focus itself, with a focus ring', () => {
		expect(rule(style, '.versionItem:hover .versionSelectButton, .versionItem:focus-within .versionSelectButton')).toMatch(/opacity\s*:\s*1/)

		const focused = rule(style, '.versionSelectButton:focus-visible')
		expect(focused).toMatch(/opacity\s*:\s*1/)
		expect(focused).toMatch(/outline\s*:\s*2px solid var\(--color-primary-element\)/)
	})

	it('is always shown on a touch screen', () => {
		const media = style.match(/@media \(hover: none\)\s*\{([\s\S]*?)\n\}/)
		expect(media).not.toBeNull()
		expect(rule(media![1], '.versionSelectButton')).toMatch(/opacity\s*:\s*1/)
	})

	it('names the version it selects and can be found by tests', () => {
		// The picker has more than one button with this class (Install anyway
		// shares it); this one is the Select button.
		const button = [...template.matchAll(/<button[^>]*versionSelectButton[^>]*>/g)]
			.find((match) => match[0].includes('data-testid="version-select-button"'))
		expect(button).not.toBeNull()
		expect(button![0]).toContain(":aria-label=\"t('versioniq', 'Select version {version}', { version: version.version })\"")
		expect(button![0]).toContain('data-testid="version-select-button"')
		expect(button![0]).toContain('type="button"')
	})
})
