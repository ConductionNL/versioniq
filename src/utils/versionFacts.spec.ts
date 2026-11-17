// SPDX-License-Identifier: EUPL-1.2
// The range sentence names the breaking releases between the installed and the
// chosen version, in either direction, and the fact texts read as the spec says.
// @spec openspec/changes/releases-version-facts/specs/changelog-visibility/spec.md#requirement-a-version-its-publisher-marks-as-breaking-is-flagged
import { describe, expect, it, vi } from 'vitest'
import { breakingBetween, breakingSentence, downloadsText, isMajorStep, outcomesText } from './versionFacts.ts'

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string, vars: Record<string, unknown> = {}) =>
		text.replace(/\{(\w+)\}/g, (_match: string, key: string) => String(vars[key] ?? '')),
	n: (_app: string, one: string, many: string, count: number, vars: Record<string, unknown> = {}) =>
		(count === 1 ? one : many).replace(/\{(\w+)\}/g, (_match: string, key: string) => String(vars[key] ?? '')),
}))

const versions = [
	{ version: '2.5.0', breaking: false },
	{ version: '2.4.0', breaking: true },
	{ version: '2.3.0', breaking: true },
	{ version: '2.2.0', breaking: true },
	{ version: '3.0.0', breaking: true },
]

describe('versionFacts', () => {
	it('names the breaking releases above the installed one up to the chosen one', () => {
		expect(breakingBetween(versions, '2.3.0', '2.5.0')).toEqual(['2.4.0'])
		expect(breakingSentence(breakingBetween(versions, '2.3.0', '2.5.0'))).toBe('Includes releases marked as breaking: 2.4.0.')
	})

	it('names them oldest first, and includes the chosen version itself', () => {
		expect(breakingBetween(versions, '2.2.0', '3.0.0')).toEqual(['2.3.0', '2.4.0', '3.0.0'])
	})

	it('reads a downgrade over the same range', () => {
		expect(breakingBetween(versions, '2.5.0', '2.2.0')).toEqual(['2.3.0', '2.4.0'])
	})

	it('says nothing without a range or without breaking releases', () => {
		expect(breakingBetween(versions, '', '2.5.0')).toEqual([])
		expect(breakingBetween(versions, '2.4.0', '2.4.0')).toEqual([])
		expect(breakingBetween(versions, '2.4.0', '2.5.0')).toEqual([])
		expect(breakingSentence([])).toBe('')
	})

	it('flags a higher major than the installed one only', () => {
		expect(isMajorStep('3.0.0', '2.9.9')).toBe(true)
		expect(isMajorStep('2.9.9', '2.0.0')).toBe(false)
		expect(isMajorStep('1.0.0', '2.0.0')).toBe(false)
		expect(isMajorStep('3.0.0', '')).toBe(false)
	})

	it('writes downloads only for a real count', () => {
		expect(downloadsText(0)).toBe('0 downloads')
		expect(downloadsText(null)).toBe('')
		expect(downloadsText(undefined)).toBe('')
		expect(downloadsText(-1)).toBe('')
		expect(downloadsText(Number.NaN)).toBe('')
	})

	it('writes outcomes with failures only when there were any', () => {
		expect(outcomesText({ succeeded: 0, failed: 0 })).toBe('')
		expect(outcomesText(null)).toBe('')
		expect(outcomesText({ succeeded: 4, failed: 0 })).toBe('Installed 4 times here')
		expect(outcomesText({ succeeded: 0, failed: 2 })).toBe('Installed 2 times here, 2 failures')
	})
})
