// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
import { describe, expect, it, vi } from 'vitest'
import { repinApp } from './repin.ts'

// #431: Nextcloud's updater moves a pinned app UP, so re-pinning means
// installing an OLDER version. Without allowDowngrade the server refuses it
// with 409, which is what the banner showed in the usual case.
describe('repinApp', () => {
	it('asks for the downgrade and sends allowDowngrade when the pinned version is older', async () => {
		const confirmDowngrade = vi.fn().mockResolvedValue(true)
		const requestInstall = vi.fn().mockResolvedValue({})

		const result = await repinApp('deck', '1.2.0', '1.3.0', { requestInstall, confirmDowngrade })

		expect(confirmDowngrade).toHaveBeenCalledWith('deck', '1.3.0', '1.2.0')
		expect(requestInstall).toHaveBeenCalledWith('deck', '1.2.0', true)
		expect(result.installed).toBe(true)
	})

	it('installs nothing when the admin cancels the downgrade', async () => {
		const confirmDowngrade = vi.fn().mockResolvedValue(false)
		const requestInstall = vi.fn()

		const result = await repinApp('deck', '1.2.0', '1.3.0', { requestInstall, confirmDowngrade })

		expect(requestInstall).not.toHaveBeenCalled()
		expect(result.installed).toBe(false)
	})

	it('does not ask when the drift went down and re-pin is an upgrade', async () => {
		const confirmDowngrade = vi.fn()
		const requestInstall = vi.fn().mockResolvedValue({})

		await repinApp('deck', '1.3.0', '1.2.0', { requestInstall, confirmDowngrade })

		expect(confirmDowngrade).not.toHaveBeenCalled()
		expect(requestInstall).toHaveBeenCalledWith('deck', '1.3.0', false)
	})
})
