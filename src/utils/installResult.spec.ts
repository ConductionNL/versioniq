// SPDX-License-Identifier: EUPL-1.2
// Covers "Unverified install warning" and "Served from cache" (#438 items 4 and 5):
// @spec openspec/specs/external-sources/spec.md
import { describe, expect, it } from 'vitest'
import { normalizeInstallResult, withSourceOverride } from './installResult.ts'

describe('normalizeInstallResult', () => {
	it('keeps the integrity warning and the served-from-cache flag the installer sends', () => {
		const result = normalizeInstallResult({
			appId: 'myapp',
			toVersion: '1.2.0',
			installStatus: 'installed',
			integrityWarning: 'Install proceeded without verification: no checksum asset was published.',
			servedFromCache: true,
		})

		expect(result.integrityWarning).toBe('Install proceeded without verification: no checksum asset was published.')
		expect(result.servedFromCache).toBe(true)
	})

	it('defaults both to empty when the installer did not send them', () => {
		const result = normalizeInstallResult({ appId: 'myapp', toVersion: '1.2.0', installStatus: 'installed' })

		expect(result.integrityWarning).toBe(null)
		expect(result.servedFromCache).toBe(false)
	})
})

describe('withSourceOverride', () => {
	it('adds a trimmed source to the query only when one is given', () => {
		expect(withSourceOverride({ dryRun: '0' }, ' github:acme/app ')).toEqual({ dryRun: '0', source: 'github:acme/app' })
		expect(withSourceOverride({ dryRun: '0' }, '   ')).toEqual({ dryRun: '0' })
	})

	// @spec openspec/changes/safety-rollback-backup-and-preflight/specs/version-management/spec.md
	// @spec openspec/changes/safety-rollback-backup-and-preflight/specs/migration-safety/spec.md
	it('keeps the backup the installer reported, and leaves out a malformed one', () => {
		expect(normalizeInstallResult({ backup: { made: true, sizeBytes: 10, keptUntil: '2026-10-15' } }).backup).toEqual({ made: true, sizeBytes: 10, keptUntil: '2026-10-15' })
		expect(normalizeInstallResult({ backup: { made: false, reason: 'failed', message: 'disk full' } }).backup).toEqual({ made: false, reason: 'failed', message: 'disk full' })
		expect(normalizeInstallResult({ backup: { made: false, reason: 'disabled' } }).backup).toEqual({ made: false, reason: 'disabled' })
		expect(normalizeInstallResult({ backup: { made: true, sizeBytes: 'big' } } as never)).not.toHaveProperty('backup')
		expect(normalizeInstallResult({})).not.toHaveProperty('backup')
	})

	it('keeps the free space the pre-flight found, and leaves it out without one', () => {
		expect(normalizeInstallResult({ space: { appsFree: 9000, tempFree: null } }).space).toEqual({ appsFree: 9000, tempFree: null })
		expect(normalizeInstallResult({ space: { appsFree: 'x' } } as never).space).toEqual({ appsFree: null, tempFree: null })
		expect(normalizeInstallResult({})).not.toHaveProperty('space')
	})
})
