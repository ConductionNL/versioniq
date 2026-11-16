// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Install from a file (install-manual-upload): read an uploaded archive, then
// install it by the token that step returned. Both endpoints require STRICT
// password confirmation, so the password travels with each request as Basic
// credentials, as for enabling an app.
import { apiUrl, ocsHeaders, withOcsJson } from '../ocs.ts'
import { basicAuthHeader } from './enableApp.ts'

const BASE = '/ocs/v2.php/apps/versioniq/api/v1/upload'

/** What the server read from an uploaded archive before anything is installed. */
export type InspectedUpload = {
	token: string
	appId: string
	version: string
	sha256: string
	signedByStore: boolean
}

/** The outcome of installing an upload, as the install endpoint reports it. */
export type UploadInstallOutcome = {
	ok: boolean
	message: string
	payload: Record<string, unknown>
}

type OcsBody<T> = { ocs?: { meta?: { status?: string, statuscode?: number, message?: string }, data?: T } }

/**
 * The OCS data of a response and its message, without throwing on a body that is not JSON.
 *
 * @param response The response.
 */
async function readOcs <T>(response: Response): Promise<{ data: T | undefined, message: string }> {
	let body: OcsBody<T> | undefined
	try {
		body = (await response.json()) as OcsBody<T>
	} catch {
		body = undefined
	}
	const data = body?.ocs?.data
	const dataMessage = (data as { message?: unknown } | undefined)?.message
	const message = typeof dataMessage === 'string' && dataMessage !== '' ? dataMessage : (body?.ocs?.meta?.message ?? '')
	return { data, message }
}

/**
 * Uploads an archive and returns what it is; installs nothing.
 *
 * @param file The `.tar.gz` the admin picked.
 * @param user The login name.
 * @param password The password strict confirmation requires.
 * @spec openspec/changes/install-manual-upload/tasks.md#task-3.1
 */
export async function inspectArchive (file: File, user: string, password: string): Promise<InspectedUpload> {
	const form = new FormData()
	form.append('archive', file)
	const response = await fetch(apiUrl(withOcsJson(`${BASE}/inspect`)), {
		method: 'POST',
		headers: { ...(ocsHeaders as Record<string, string>), Accept: 'application/json', Authorization: basicAuthHeader(user, password) },
		body: form,
	})
	const { data, message } = await readOcs<InspectedUpload>(response)
	if (!response.ok || data === undefined || typeof data.token !== 'string') {
		throw new Error(message || `Reading the archive failed (HTTP ${response.status}).`)
	}
	return data
}

/**
 * Installs an inspected upload. A refusal is an outcome, not an exception, so
 * the dialog can show its message in place.
 *
 * @param token The token inspectArchive() returned.
 * @param choices The SHA-256 the admin expects (blank for none) and whether they accept the shown one.
 * @param choices.expectedSha256 The SHA-256 the admin pasted.
 * @param choices.acceptShown Whether the admin accepted the shown SHA-256.
 * @param user The login name.
 * @param password The password strict confirmation requires.
 * @spec openspec/changes/install-manual-upload/tasks.md#task-3.1
 */
export async function installUpload (token: string, choices: { expectedSha256: string, acceptShown: boolean }, user: string, password: string): Promise<UploadInstallOutcome> {
	const response = await fetch(apiUrl(withOcsJson(`${BASE}/${encodeURIComponent(token)}/install`)), {
		method: 'POST',
		headers: {
			...(ocsHeaders as Record<string, string>),
			Accept: 'application/json',
			'Content-Type': 'application/json',
			Authorization: basicAuthHeader(user, password),
		},
		body: JSON.stringify({ expectedSha256: choices.expectedSha256.trim(), acceptShown: choices.acceptShown }),
	})
	const { data, message } = await readOcs<Record<string, unknown>>(response)
	return { ok: response.ok, message: message || (response.ok ? '' : `Installing failed (HTTP ${response.status}).`), payload: data ?? {} }
}
