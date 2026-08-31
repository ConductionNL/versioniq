// SPDX-License-Identifier: EUPL-1.2
// Covers "Global kill switch and window" client-side format validation:
// @spec openspec/specs/auto-update-policies/spec.md
import { describe, expect, it } from 'vitest'
import { AUTO_UPDATE_WINDOW_DEFAULT, isValidAutoUpdateWindow } from './autoUpdateWindow.ts'

describe('isValidAutoUpdateWindow', () => {
	it('accepts the default window', () => {
		expect(isValidAutoUpdateWindow(AUTO_UPDATE_WINDOW_DEFAULT)).toBe(true)
	})

	it('accepts a midnight-crossing window', () => {
		expect(isValidAutoUpdateWindow('23:00-03:00')).toBe(true)
	})

	it('tolerates surrounding whitespace', () => {
		expect(isValidAutoUpdateWindow('  01:00-05:00  ')).toBe(true)
	})

	it('rejects an hour without leading zero', () => {
		expect(isValidAutoUpdateWindow('1:00-05:00')).toBe(false)
	})

	it('rejects an out-of-range hour', () => {
		expect(isValidAutoUpdateWindow('25:00-05:00')).toBe(false)
	})

	it('rejects an out-of-range minute', () => {
		expect(isValidAutoUpdateWindow('01:60-05:00')).toBe(false)
	})

	it('rejects a missing end time', () => {
		expect(isValidAutoUpdateWindow('01:00')).toBe(false)
	})

	it('rejects an empty string', () => {
		expect(isValidAutoUpdateWindow('')).toBe(false)
	})
})
