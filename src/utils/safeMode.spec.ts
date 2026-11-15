// SPDX-License-Identifier: EUPL-1.2
// Issue #434: the safe mode label promised to respect the update channel,
// but only downgrades were blocked, so a stable-channel admin could still
// install beta and release-candidate versions.
import { describe, expect, it } from 'vitest'
import { isBlockedBySafeMode, isPreRelease } from './safeMode.ts'

const stable = { enabled: true, installedVersion: '2.0.0', updateChannel: 'stable' }

describe('isBlockedBySafeMode', () => {
	it('blocks a downgrade', () => {
		expect(isBlockedBySafeMode('1.9.0', stable)).toBe(true)
	})

	it('allows a newer stable release', () => {
		expect(isBlockedBySafeMode('2.1.0', stable)).toBe(false)
	})

	it('blocks a newer pre-release on the stable channel', () => {
		expect(isBlockedBySafeMode('2.1.0-beta.1', stable)).toBe(true)
		expect(isBlockedBySafeMode('2.1.0-rc1', stable)).toBe(true)
		expect(isBlockedBySafeMode('2.1.0-alpha', stable)).toBe(true)
	})

	it('blocks a newer pre-release on the production and enterprise channels', () => {
		expect(isBlockedBySafeMode('2.1.0-beta.1', { ...stable, updateChannel: 'production' })).toBe(true)
		expect(isBlockedBySafeMode('2.1.0-beta.1', { ...stable, updateChannel: 'enterprise' })).toBe(true)
	})

	it('allows a newer pre-release on the beta and daily channels', () => {
		expect(isBlockedBySafeMode('2.1.0-beta.1', { ...stable, updateChannel: 'beta' })).toBe(false)
		expect(isBlockedBySafeMode('2.1.0-beta.1', { ...stable, updateChannel: 'daily' })).toBe(false)
	})

	it('does not filter pre-releases when the channel is unknown', () => {
		expect(isBlockedBySafeMode('2.1.0-beta.1', { ...stable, updateChannel: '' })).toBe(false)
	})

	it('blocks nothing when safe mode is off', () => {
		expect(isBlockedBySafeMode('1.9.0', { ...stable, enabled: false })).toBe(false)
		expect(isBlockedBySafeMode('2.1.0-beta.1', { ...stable, enabled: false })).toBe(false)
	})

	it('still filters pre-releases when no version is installed yet', () => {
		expect(isBlockedBySafeMode('2.1.0-beta.1', { ...stable, installedVersion: '' })).toBe(true)
		expect(isBlockedBySafeMode('2.1.0', { ...stable, installedVersion: '' })).toBe(false)
	})
})

describe('isPreRelease', () => {
	it('recognises a semver pre-release suffix', () => {
		expect(isPreRelease('1.0.0-beta.2')).toBe(true)
		expect(isPreRelease('v1.0.0-RC1')).toBe(true)
		expect(isPreRelease('1.0.0')).toBe(false)
		expect(isPreRelease('v1.0.0')).toBe(false)
	})
})

// releases-channel-and-prereleases: the version's own onChannel replaces the update-channel rule.
// @spec openspec/changes/releases-channel-and-prereleases/specs/release-channels/spec.md
describe('isBlockedBySafeMode with the app release channel', () => {
	const stableServer = { enabled: true, installedVersion: '2.4.0', updateChannel: 'stable' }

	it('blocks a version that is not on the app channel, even a newer stable one', () => {
		expect(isBlockedBySafeMode('2.5.0', stableServer, false)).toBe(true)
	})

	it('allows a pre-release on an app that follows beta, on a stable server', () => {
		expect(isBlockedBySafeMode('2.5.0-rc.1', stableServer, true)).toBe(false)
	})

	it('keeps blocking a downgrade that is on the channel', () => {
		expect(isBlockedBySafeMode('2.3.9', stableServer, true)).toBe(true)
	})

	it('keeps the update-channel rule when the server did not stamp the version', () => {
		expect(isBlockedBySafeMode('2.5.0-rc.1', stableServer)).toBe(true)
	})

	it('blocks nothing when safe mode is off', () => {
		expect(isBlockedBySafeMode('2.5.0', { ...stableServer, enabled: false }, false)).toBe(false)
	})
})
