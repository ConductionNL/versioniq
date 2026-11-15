// SPDX-License-Identifier: EUPL-1.2
// Shared OCS request helpers: URL building against the Nextcloud webroot,
// unwrapping the OCS envelope and its failure meta, password confirmation
// before writes, and the exact fetch calls each helper makes.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { apiUrl, ensurePasswordConfirmation, ocsGet, ocsHeaders, ocsWrite, withOcsJson } from './ocs.ts'

type PasswordConfirmation = {
	requiresPasswordConfirmation?: () => boolean
	requirePasswordConfirmation?: (callback: () => void, options?: unknown, rejectCallback?: (error: Error) => void) => void
}

const fetchMock = vi.fn()

/**
 * Sets window.OC for one test.
 *
 * @param oc the OC global to install
 */
function setOC (oc: Record<string, unknown> | undefined): void {
	(window as unknown as { OC?: unknown }).OC = oc
}

/**
 * A fetch Response double whose json() answers the given body.
 *
 * @param body the parsed JSON body
 */
function jsonResponse (body: unknown): Response {
	return { json: async () => body } as unknown as Response
}

beforeEach(() => {
	fetchMock.mockReset()
	vi.stubGlobal('fetch', fetchMock)
	setOC({ webroot: '/nextcloud/' })
})

afterEach(() => {
	vi.unstubAllGlobals()
	setOC(undefined)
})

describe('apiUrl', () => {
	it('prefixes the origin and the webroot without a doubled slash', () => {
		expect(apiUrl('/ocs/v2.php/x')).toBe(`${window.location.origin}/nextcloud/ocs/v2.php/x`)
	})

	it('uses no webroot when OC or its webroot is missing or not a string', () => {
		setOC(undefined)
		expect(apiUrl('/a')).toBe(`${window.location.origin}/a`)
		setOC({ webroot: 42 })
		expect(apiUrl('/a')).toBe(`${window.location.origin}/a`)
	})
})

describe('withOcsJson', () => {
	it('appends the query and format=json with the right separator', () => {
		expect(withOcsJson('/p')).toBe('/p?format=json')
		expect(withOcsJson('/p', { q: 'a b', limit: 5, all: true })).toBe('/p?q=a+b&limit=5&all=true&format=json')
		expect(withOcsJson('/p?pattern=x')).toBe('/p?pattern=x&format=json')
	})

	it('lets an explicit format be overridden to json', () => {
		expect(withOcsJson('/p', { format: 'xml' })).toBe('/p?format=json')
	})
})

describe('ocsGet', () => {
	it('fetches the JSON URL with the OCS headers and unwraps ocs.data', async () => {
		fetchMock.mockResolvedValue(jsonResponse({ ocs: { meta: { status: 'ok', statuscode: 200 }, data: { apps: ['mail'] } } }))
		const controller = new AbortController()

		const result = await ocsGet('/ocs/v2.php/apps/versioniq/api/apps', { installedOnly: 1 }, controller.signal)

		expect(fetchMock).toHaveBeenCalledWith(
			`${window.location.origin}/nextcloud/ocs/v2.php/apps/versioniq/api/apps?installedOnly=1&format=json`,
			{ headers: { 'OCS-APIRequest': 'true', Accept: 'application/json' }, signal: controller.signal },
		)
		expect(result).toEqual({ payload: { apps: ['mail'] } })
	})

	it('falls back to a top-level data field, then to the whole body', async () => {
		fetchMock.mockResolvedValueOnce(jsonResponse({ data: { a: 1 } }))
		fetchMock.mockResolvedValueOnce(jsonResponse({ b: 2 }))

		expect(await ocsGet('/x')).toEqual({ payload: { a: 1 } })
		expect(await ocsGet('/x')).toEqual({ payload: { b: 2 } })
	})

	it('reports the meta message of a failed call with its payload', async () => {
		fetchMock.mockResolvedValue(jsonResponse({ ocs: { meta: { status: 'failure', statuscode: 403, message: 'Forbidden' }, data: { message: 'Forbidden' } } }))

		expect(await ocsGet('/x')).toEqual({ payload: { message: 'Forbidden' }, error: 'Forbidden' })
	})

	it('treats a status code from 400 up as failure even when the status says ok', async () => {
		fetchMock.mockResolvedValueOnce(jsonResponse({ ocs: { meta: { status: 'ok', statuscode: 400 }, data: [] } }))
		fetchMock.mockResolvedValueOnce(jsonResponse({ ocs: { meta: { status: 'ok', statuscode: 399 }, data: [] } }))

		expect(await ocsGet('/x')).toEqual({ payload: [], error: 'Request failed' })
		expect(await ocsGet('/x')).toEqual({ payload: [] })
	})

	it('rejects a body that is not an object', async () => {
		fetchMock.mockResolvedValueOnce(jsonResponse(null))
		fetchMock.mockResolvedValueOnce(jsonResponse('text'))

		await expect(ocsGet('/x')).rejects.toThrow('Unexpected response format')
		await expect(ocsGet('/x')).rejects.toThrow('Unexpected response format')
	})
})

describe('ensurePasswordConfirmation', () => {
	it('does nothing without the PasswordConfirmation API', async () => {
		setOC({})
		await expect(ensurePasswordConfirmation()).resolves.toBeUndefined()
		setOC({ PasswordConfirmation: {} })
		await expect(ensurePasswordConfirmation()).resolves.toBeUndefined()
	})

	it('does not prompt when no confirmation is required', async () => {
		const pc: PasswordConfirmation = {
			requiresPasswordConfirmation: vi.fn(() => false),
			requirePasswordConfirmation: vi.fn(),
		}
		setOC({ PasswordConfirmation: pc })

		await ensurePasswordConfirmation()

		expect(pc.requirePasswordConfirmation).not.toHaveBeenCalled()
	})

	it('prompts and resolves once the password is confirmed', async () => {
		const pc: PasswordConfirmation = {
			requiresPasswordConfirmation: () => true,
			requirePasswordConfirmation: vi.fn((callback: () => void) => callback()),
		}
		setOC({ PasswordConfirmation: pc })

		await expect(ensurePasswordConfirmation()).resolves.toBeUndefined()
		expect(pc.requirePasswordConfirmation).toHaveBeenCalledTimes(1)
	})

	it('prompts when there is no way to ask whether confirmation is required', async () => {
		const pc: PasswordConfirmation = { requirePasswordConfirmation: vi.fn((callback: () => void) => callback()) }
		setOC({ PasswordConfirmation: pc })

		await ensurePasswordConfirmation()

		expect(pc.requirePasswordConfirmation).toHaveBeenCalledTimes(1)
	})

	it('rejects when the prompt is cancelled', async () => {
		setOC({
			PasswordConfirmation: {
				requiresPasswordConfirmation: () => true,
				requirePasswordConfirmation: (_ok: () => void, _options: unknown, cancel: (error: Error) => void) => cancel(new Error('ignored')),
			},
		})

		await expect(ensurePasswordConfirmation()).rejects.toThrow('Password confirmation was cancelled')
	})
})

describe('ocsWrite', () => {
	it('confirms the password, then sends the JSON body with the method', async () => {
		const order: string[] = []
		setOC({
			webroot: '',
			PasswordConfirmation: {
				requiresPasswordConfirmation: () => true,
				requirePasswordConfirmation: (callback: () => void) => {
					order.push('confirm')
					callback()
				},
			},
		})
		fetchMock.mockImplementation(async () => {
			order.push('fetch')
			return jsonResponse({ ocs: { meta: { status: 'ok', statuscode: 200 }, data: { ok: true } } })
		})

		const result = await ocsWrite('PATCH', '/ocs/v2.php/apps/versioniq/api/pats/3', { sharedWithAdmins: true })

		expect(order).toEqual(['confirm', 'fetch'])
		expect(fetchMock).toHaveBeenCalledWith(`${window.location.origin}/ocs/v2.php/apps/versioniq/api/pats/3?format=json`, {
			method: 'PATCH',
			headers: { ...ocsHeaders, Accept: 'application/json', 'Content-Type': 'application/json' },
			body: '{"sharedWithAdmins":true}',
		})
		expect(result).toEqual({ payload: { ok: true } })
	})

	it('sends an empty object when no body is given', async () => {
		fetchMock.mockResolvedValue(jsonResponse({ ocs: { meta: { status: 'ok' }, data: {} } }))

		await ocsWrite('DELETE', '/x?pattern=a')

		expect(fetchMock.mock.calls[0][0]).toBe(`${window.location.origin}/nextcloud/x?pattern=a&format=json`)
		expect(fetchMock.mock.calls[0][1].body).toBe('{}')
	})

	it('sends nothing when the password prompt is cancelled', async () => {
		setOC({
			PasswordConfirmation: {
				requirePasswordConfirmation: (_ok: () => void, _options: unknown, cancel: (error: Error) => void) => cancel(new Error('x')),
			},
		})

		await expect(ocsWrite('POST', '/x', { a: 1 })).rejects.toThrow('Password confirmation was cancelled')
		expect(fetchMock).not.toHaveBeenCalled()
	})

	it('returns the server refusal from the meta', async () => {
		fetchMock.mockResolvedValue(jsonResponse({ ocs: { meta: { status: 'failure', statuscode: 422, message: 'Too broad' }, data: { message: 'Too broad' } } }))

		expect(await ocsWrite('POST', '/x')).toEqual({ payload: { message: 'Too broad' }, error: 'Too broad' })
	})
})
