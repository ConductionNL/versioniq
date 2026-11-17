// SPDX-License-Identifier: EUPL-1.2
// @spec openspec/changes/releases-channel-and-prereleases/specs/release-channels/spec.md
import { describe, expect, it, vi } from 'vitest'
import { channelLabel, noLtsLinesMarked, offChannelTags, parseLtsLines, pickerVersions } from './channelFilter.ts'

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string) => text,
}))

const listed = [
	{ version: '2.6.0-beta.1', preRelease: true, lts: false, onChannel: false },
	{ version: '2.5.2', preRelease: false, lts: false, onChannel: true },
	{ version: '2.5.1' },
]

describe('pickerVersions', () => {
	it('lists only the versions on the channel, and unstamped ones, while the switch is off', () => {
		expect(pickerVersions(listed, false).map((v) => v.version)).toEqual(['2.5.2', '2.5.1'])
	})

	it('lists every version once "Show all versions" is on', () => {
		expect(pickerVersions(listed, true).map((v) => v.version)).toEqual(['2.6.0-beta.1', '2.5.2', '2.5.1'])
	})
})

describe('offChannelTags', () => {
	it('tags an off-channel pre-release "Pre-release"', () => {
		expect(offChannelTags(listed[0], 'stable')).toEqual(['Pre-release'])
	})

	it('tags an off-channel version on the long-term support channel "Not long-term support"', () => {
		expect(offChannelTags({ version: '2.4.1', preRelease: false, lts: false, onChannel: false }, 'lts')).toEqual(['Not long-term support'])
		expect(offChannelTags({ version: '2.6.0-rc.1', preRelease: true, lts: false, onChannel: false }, 'lts')).toEqual(['Pre-release', 'Not long-term support'])
	})

	it('tags nothing that is on the channel or unstamped', () => {
		expect(offChannelTags(listed[1], 'stable')).toEqual([])
		expect(offChannelTags(listed[2], 'lts')).toEqual([])
	})
})

describe('noLtsLinesMarked', () => {
	it('is true on the long-term support channel with no line from an admin or a publisher', () => {
		expect(noLtsLinesMarked('lts', [], listed)).toBe(true)
	})

	it('is false once an admin or a publisher marked a line, or on another channel', () => {
		expect(noLtsLinesMarked('lts', ['2.3'], listed)).toBe(false)
		expect(noLtsLinesMarked('lts', [], [{ version: '2.3.9', lts: true }])).toBe(false)
		expect(noLtsLinesMarked('stable', [], listed)).toBe(false)
	})
})

describe('channelLabel and parseLtsLines', () => {
	it('names each channel, and passes an unknown one through', () => {
		expect(['server', 'stable', 'beta', 'lts', 'odd'].map(channelLabel)).toEqual([
			'Same as the server', 'Stable', 'Beta (includes pre-releases)', 'Long-term support', 'odd',
		])
	})

	it('splits the lines field on commas and drops blanks', () => {
		expect(parseLtsLines(' 2.3, 3 ,, ')).toEqual(['2.3', '3'])
		expect(parseLtsLines('')).toEqual([])
	})
})
