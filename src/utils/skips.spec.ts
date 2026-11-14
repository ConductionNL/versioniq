// SPDX-License-Identifier: EUPL-1.2
// The skip list API helpers.
// @spec openspec/changes/pinning-ranges-and-skips/specs/version-pinning/spec.md#requirement-an-admin-skips-one-version-and-keeps-later-ones
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fetchSkips, skipVersion, unskipVersion } from './skips.ts'

const ocs = vi.hoisted(() => ({ ocsGet: vi.fn(), ocsWrite: vi.fn() }))
vi.mock('../ocs.ts', () => ocs)

describe('skips', () => {
	beforeEach(() => {
		ocs.ocsGet.mockReset()
		ocs.ocsWrite.mockReset()
	})

	it('reads the skip list by version, and none when it cannot be read', async () => {
		ocs.ocsGet.mockResolvedValueOnce({ payload: { skipped: [{ version: '2.3.4', skippedBy: 'admin', skippedAt: 'T', reason: 'r' }] } })
		expect(await fetchSkips('open register')).toEqual({ '2.3.4': { version: '2.3.4', skippedBy: 'admin', skippedAt: 'T', reason: 'r' } })
		expect(ocs.ocsGet).toHaveBeenCalledWith('/ocs/v2.php/apps/versioniq/api/app/open%20register/skips')

		ocs.ocsGet.mockResolvedValueOnce({ payload: {}, error: 'Forbidden' })
		expect(await fetchSkips('openregister')).toEqual({})
		ocs.ocsGet.mockRejectedValueOnce(new Error('down'))
		expect(await fetchSkips('openregister')).toEqual({})
	})

	it('skips with a trimmed reason and unskips, failing on an error', async () => {
		ocs.ocsWrite.mockResolvedValue({ payload: {} })
		await skipVersion('openregister', '2.3.4', ' breaks it ')
		await unskipVersion('openregister', '2.3.4')
		expect(ocs.ocsWrite.mock.calls).toEqual([
			['PUT', '/ocs/v2.php/apps/versioniq/api/app/openregister/skip/2.3.4', { reason: 'breaks it' }],
			['DELETE', '/ocs/v2.php/apps/versioniq/api/app/openregister/skip/2.3.4'],
		])

		ocs.ocsWrite.mockResolvedValue({ payload: {}, error: 'Forbidden' })
		await expect(skipVersion('openregister', '2.3.4', '')).rejects.toThrow('Forbidden')
		await expect(unskipVersion('openregister', '2.3.4')).rejects.toThrow('Forbidden')
	})
})
