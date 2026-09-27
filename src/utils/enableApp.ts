// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
import { apiUrl, ocsHeaders, withOcsJson } from '../ocs.ts'

/**
 * An HTTP Basic authorization header, UTF-8 encoded so a non-ASCII password
 * survives (`btoa` alone throws on it).
 *
 * @param user The login name.
 * @param password The password.
 * @spec openspec/specs/version-management/spec.md
 */
export function basicAuthHeader (user: string, password: string): string {
	const bytes = new TextEncoder().encode(`${user}:${password}`)
	let binary = ''
	bytes.forEach((byte) => {
		binary += String.fromCharCode(byte)
	})
	return `Basic ${btoa(binary)}`
}

/**
 * The login name of the current user, for the strict password check.
 *
 * @spec openspec/specs/version-management/spec.md
 */
export function currentLoginName (): string {
	const oc = (window as Window & { OC?: { getCurrentUser?: () => { uid?: string } | null } }).OC
	return oc?.getCurrentUser?.()?.uid ?? ''
}

/**
 * Enables an installed app through Nextcloud's own endpoint,
 * `POST /ocs/v2.php/cloud/apps/{appId}` (provisioning API, admin-only).
 * That endpoint requires STRICT password confirmation: the password travels
 * with the request as Basic credentials, which is why the caller asks for it
 * rather than relying on a recent confirmation (#433).
 *
 * @param appId The app to enable.
 * @param user The login name.
 * @param password The user's password.
 * @spec openspec/specs/version-management/spec.md
 */
export async function enableApp (appId: string, user: string, password: string): Promise<void> {
	const response = await fetch(apiUrl(withOcsJson(`/ocs/v2.php/cloud/apps/${encodeURIComponent(appId)}`)), {
		method: 'POST',
		headers: {
			...(ocsHeaders as Record<string, string>),
			Accept: 'application/json',
			Authorization: basicAuthHeader(user, password),
		},
	})

	let meta: { status?: string, statuscode?: number, message?: string } | undefined
	try {
		meta = ((await response.json()) as { ocs?: { meta?: typeof meta } })?.ocs?.meta
	} catch {
		meta = undefined
	}
	if (!response.ok || meta?.status === 'failure' || (typeof meta?.statuscode === 'number' && meta.statuscode >= 400)) {
		throw new Error(meta?.message || `Enabling ${appId} failed (HTTP ${response.status}).`)
	}
}
