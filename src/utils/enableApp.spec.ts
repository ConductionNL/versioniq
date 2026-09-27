// SPDX-License-Identifier: EUPL-1.2
// Covers "One-click enable" (#433):
// @spec openspec/specs/version-management/spec.md
import { afterEach, describe, expect, it, vi } from 'vitest'
import { basicAuthHeader, enableApp } from './enableApp.ts'

afterEach(() => {
	vi.unstubAllGlobals()
})

describe('basicAuthHeader', () => {
	it('encodes non-ASCII passwords as UTF-8', () => {
		expect(basicAuthHeader('admin', 'pässword')).toBe(`Basic ${Buffer.from('admin:pässword', 'utf8').toString('base64')}`)
	})
})

describe('enableApp', () => {
	it('enables through Nextcloud\'s own OCS apps endpoint with the password it requires', async () => {
		const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({ ocs: { meta: { status: 'ok', statuscode: 200 }, data: [] } })))
		vi.stubGlobal('fetch', fetchMock)
		vi.stubGlobal('OC', { webroot: '' })

		await enableApp('calendar', 'admin', 'secret')

		const [url, init] = fetchMock.mock.calls[0]
		expect(String(url)).toContain('/ocs/v2.php/cloud/apps/calendar?format=json')
		expect(init.method).toBe('POST')
		expect(init.headers.Authorization).toBe(basicAuthHeader('admin', 'secret'))
		expect(init.headers['OCS-APIRequest']).toBe('true')
	})

	it('throws the server message when Nextcloud refuses', async () => {
		vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(JSON.stringify({ ocs: { meta: { status: 'failure', statuscode: 403, message: 'Password confirmation is required' }, data: [] } }), { status: 403 })))
		vi.stubGlobal('OC', { webroot: '' })

		await expect(enableApp('calendar', 'admin', 'wrong')).rejects.toThrow('Password confirmation is required')
	})
})
