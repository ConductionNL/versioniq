// SPDX-License-Identifier: EUPL-1.2
// Install from a file: both requests carry the password as Basic credentials
// (strict confirmation); a refused install is an outcome, not an exception.
// @spec openspec/changes/install-manual-upload/tasks.md#task-3.1
import { afterEach, describe, expect, it, vi } from 'vitest'
import { basicAuthHeader } from './enableApp.ts'
import { inspectArchive, installUpload } from './uploadInstall.ts'

afterEach(() => {
	vi.unstubAllGlobals()
})

/**
 * @param data The OCS data.
 * @param status The HTTP status.
 */
function ocs (data: unknown, status = 200): Response {
	return new Response(JSON.stringify({ ocs: { meta: { status: status < 400 ? 'ok' : 'failure', statuscode: status }, data } }), { status })
}

describe('inspectArchive', () => {
	it('posts the archive as multipart field "archive" with the password and returns what was read', async () => {
		const read = { token: 'tok', appId: 'hermiq', version: '1.4.1', sha256: 'a'.repeat(64), signedByStore: false }
		const fetchMock = vi.fn().mockResolvedValue(ocs(read))
		vi.stubGlobal('fetch', fetchMock)
		vi.stubGlobal('OC', { webroot: '' })
		const file = new File(['bytes'], 'hermiq.tar.gz')

		expect(await inspectArchive(file, { user: 'admin', password: 'secret' })).toEqual(read)

		const [url, init] = fetchMock.mock.calls[0]
		expect(String(url)).toContain('/ocs/v2.php/apps/versioniq/api/v1/upload/inspect?format=json')
		expect(init.method).toBe('POST')
		expect(init.headers.Authorization).toBe(basicAuthHeader('admin', 'secret'))
		expect((init.body as FormData).get('archive')).toBeInstanceOf(File)
	})

	it('throws the reason the server gives', async () => {
		vi.stubGlobal('fetch', vi.fn().mockResolvedValue(ocs({ message: 'The archive has no appinfo/info.xml.' }, 422)))
		vi.stubGlobal('OC', { webroot: '' })

		await expect(inspectArchive(new File(['x'], 'x.tar.gz'), { user: 'admin', password: 'secret' })).rejects.toThrow('The archive has no appinfo/info.xml.')
	})
})

describe('installUpload', () => {
	it('posts the choices for the token and reports success', async () => {
		const fetchMock = vi.fn().mockResolvedValue(ocs({ sourceId: 'upload', installStatus: 'installed' }))
		vi.stubGlobal('fetch', fetchMock)
		vi.stubGlobal('OC', { webroot: '' })

		const outcome = await installUpload({ token: 'tok', expectedSha256: ' abc ', acceptShown: false }, { user: 'admin', password: 'secret' })

		expect(outcome).toEqual({ ok: true, message: '', payload: { sourceId: 'upload', installStatus: 'installed' } })
		const [url, init] = fetchMock.mock.calls[0]
		expect(String(url)).toContain('/api/v1/upload/tok/install?format=json')
		expect(JSON.parse(init.body)).toEqual({ expectedSha256: 'abc', acceptShown: false })
		expect(init.headers.Authorization).toBe(basicAuthHeader('admin', 'secret'))
	})

	it('returns a refusal with its message', async () => {
		vi.stubGlobal('fetch', vi.fn().mockResolvedValue(ocs({ message: 'Unsigned uploads are off.' }, 403)))
		vi.stubGlobal('OC', { webroot: '' })

		const outcome = await installUpload({ token: 'tok', expectedSha256: '', acceptShown: true }, { user: 'admin', password: 'secret' })

		expect(outcome.ok).toBe(false)
		expect(outcome.message).toBe('Unsigned uploads are off.')
	})
})
