// SPDX-License-Identifier: EUPL-1.2
// Shared OCS request helpers for the Versioniq admin UI panels.
//
// App.vue keeps its own inline copies of the install flow; these helpers are
// used by the settings panels (Sources / Tokens / Trusted sources) so each
// panel can talk to the OCS API without duplicating the plumbing.

export const ocsHeaders: HeadersInit = { 'OCS-APIRequest': 'true' }

/**
 *
 * @param path
 */
export function apiUrl (path: string): string {
	const oc = window.OC as unknown as { webroot?: string }
	const webroot = (typeof oc?.webroot === 'string' ? oc.webroot : '').replace(/\/$/, '')
	return `${window.location.origin}${webroot}${path}`
}

/**
 *
 * @param path
 * @param query
 */
export function withOcsJson (path: string, query: Record<string, string | number | boolean> = {}): string {
	const separator = path.includes('?') ? '&' : '?'
	const params = new URLSearchParams()
	Object.entries(query).forEach(([key, value]) => {
		params.set(key, String(value))
	})
	params.set('format', 'json')
	return `${path}${separator}${params.toString()}`
}

type OcsWrapped<T> = {
	ocs?: {
		meta?: { status?: string, statuscode?: number, message?: string }
		data?: T
	}
	data?: T
}

/**
 * Result of an OCS call: the parsed payload plus an optional error message
 * extracted from the OCS meta (present on 4xx/5xx).
 */
export type OcsResult<T> = { payload: T, error?: string }

/**
 *
 * @param response
 */
async function unwrap <T>(response: Response): Promise<OcsResult<T>> {
	const raw = (await response.json()) as OcsWrapped<T>
	if (typeof raw !== 'object' || raw === null) {
		throw new Error('Unexpected response format')
	}
	const data = (raw.ocs?.data ?? raw.data ?? raw) as T
	const meta = raw.ocs?.meta
	if (meta && (meta.status === 'failure' || (typeof meta.statuscode === 'number' && meta.statuscode >= 400))) {
		return { payload: data, error: meta.message || 'Request failed' }
	}
	return { payload: data }
}

/**
 * Prompts for password re-confirmation when Nextcloud requires it (mirrors the
 * server-side PasswordConfirmationRequired attribute on the write endpoints).
 */
export async function ensurePasswordConfirmation (): Promise<void> {
	const windowOC = window as Window & {
		OC?: {
			PasswordConfirmation?: {
				requiresPasswordConfirmation?: () => boolean
				requirePasswordConfirmation?: (callback: () => void, options?: unknown, rejectCallback?: (error: Error) => void) => void
			}
		}
	}
	const pc = windowOC.OC?.PasswordConfirmation
	if (!pc?.requirePasswordConfirmation) {
		return
	}
	if (pc.requiresPasswordConfirmation && !pc.requiresPasswordConfirmation()) {
		return
	}
	await new Promise<void>((resolve, reject) => {
		pc.requirePasswordConfirmation!(
			() => resolve(),
			undefined,
			() => reject(new Error('Password confirmation was cancelled')),
		)
	})
}

/**
 * GET an OCS endpoint and return the unwrapped payload (+ optional error).
 * An optional AbortSignal lets callers cancel in-flight requests (e.g. a
 * debounced search superseded by newer input) without special-casing every
 * caller — a request cancelled via `signal` rejects with the fetch
 * implementation's standard AbortError.
 *
 * @param path
 * @param query
 * @param signal
 */
export async function ocsGet <T>(path: string, query: Record<string, string | number | boolean> = {}, signal?: AbortSignal): Promise<OcsResult<T>> {
	const response = await fetch(apiUrl(withOcsJson(path, query)), {
		headers: { ...ocsHeaders, Accept: 'application/json' },
		signal,
	})
	return unwrap<T>(response)
}

/**
 * Send a write (POST/PATCH/DELETE) to an OCS endpoint after password
 * confirmation, with a JSON body. Returns the unwrapped payload (+ error).
 *
 * @param method
 * @param path
 * @param body
 */
export async function ocsWrite <T>(method: 'POST' | 'PUT' | 'PATCH' | 'DELETE',
	path: string,
	body: Record<string, unknown> = {}): Promise<OcsResult<T>> {
	await ensurePasswordConfirmation()
	const response = await fetch(apiUrl(withOcsJson(path)), {
		method,
		headers: { ...ocsHeaders, Accept: 'application/json', 'Content-Type': 'application/json' },
		body: JSON.stringify(body),
	})
	return unwrap<T>(response)
}
