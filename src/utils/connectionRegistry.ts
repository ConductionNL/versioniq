// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The Integrations tab's formatters, its Add integration URL and its row
// filter (adopt-connection-registry).
//
// The rows are integriq's `app_connection` objects (hydra change
// connection-registry, design D8). Versioniq has no @conduction/nextcloud-vue,
// so it carries its own copy of the two formatters. The names are the
// contract's, so the copies across the fleet stay interchangeable.
//
// Pure: the translator is passed in, so this runs under vitest with nothing
// mocked.
//
// @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-versioniq-conn-003-an-admin-reads-the-connections-on-an-integrations-tab

/** This app's id, as integriq stores it on every row it declares. */
export const CONNECTION_APP_ID = 'versioniq'

/**
 * The objects endpoint for this app's rows. `app` is a BARE filter key: the
 * objects endpoint reads `filter[app]` as a filter on nothing and answers
 * with the empty set, without an error.
 */
export const CONNECTIONS_PATH = `/index.php/apps/openregister/api/objects/integriq/app_connection?app=${CONNECTION_APP_ID}&_limit=50`

/**
 * Where Add integration lands: integriq's Connections overview, preset to
 * this app, opening the link-a-source dialog (hydra connection-registry D9).
 */
export const INTEGRIQ_CONNECTIONS_PATH = `/index.php/apps/integriq/connections?app=${CONNECTION_APP_ID}&link=1`

/**
 * The English label for each of the six registry statuses (design D3).
 * `limited` came with hydra#673: the connection works in part.
 */
export const CONNECTION_STATUS_LABELS: Readonly<Record<string, string>> = Object.freeze({
	configured: 'Configured',
	limited: 'Limited',
	unconfigured: 'Not configured',
	simulated: 'Simulated',
	unavailable: 'Not available',
	error: 'Error',
})

/**
 * Maps a registry anchor to the tab that renders it. A hidden tab's anchor
 * scrolls nowhere, so the page selects the tab first.
 */
export const SECTION_TABS: Readonly<Record<string, string>> = Object.freeze({
	'section-sources': 'sources',
	'section-advisories': 'apps',
	'section-integrations': 'integrations',
})

export type ConnectionRow = {
	app?: unknown
	key?: unknown
	title?: unknown
	status?: unknown
	statusMessage?: unknown
	checkedAt?: unknown
	settingsUrl?: unknown
	order?: unknown
}

export type Translate = (source: string) => string

/**
 * The label for a status. An unknown value renders itself, because a status
 * the app cannot name is still a status the admin should see.
 *
 * @param value The row's `status`.
 * @param translate Translates an English source string for this app.
 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-versioniq-conn-003-an-admin-reads-the-connections-on-an-integrations-tab
 */
export function connectionStatus (value: unknown, translate: Translate): string {
	if (typeof value === 'string' && Object.hasOwn(CONNECTION_STATUS_LABELS, value)) {
		return translate(CONNECTION_STATUS_LABELS[value])
	}

	return value === null || value === undefined ? '' : String(value)
}

/**
 * The Open settings link text, or '' when the row has nowhere to send a reader.
 *
 * @param value The row's `settingsUrl`.
 * @param translate Translates an English source string for this app.
 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-versioniq-conn-003-an-admin-reads-the-connections-on-an-integrations-tab
 */
export function connectionSettingsLabel (value: unknown, translate: Translate): string {
	return typeof value === 'string' && value.length > 0 ? translate('Open settings') : ''
}

/**
 * This app's rows from an objects response, in declared order.
 *
 * A row from another app is dropped: if the filter were ever lost, the tab
 * must show fewer rows, never another app's rows as ours.
 *
 * @param body The decoded objects response.
 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-versioniq-conn-003-an-admin-reads-the-connections-on-an-integrations-tab
 */
export function ownConnectionRows (body: unknown): ConnectionRow[] {
	const results = (body as { results?: unknown } | null)?.results
	if (!Array.isArray(results)) {
		return []
	}

	const order = (row: ConnectionRow): number => (typeof row.order === 'number' ? row.order : 100)

	return results
		.filter((row): row is ConnectionRow => typeof row === 'object' && row !== null && (row as ConnectionRow).app === CONNECTION_APP_ID)
		.sort((a, b) => order(a) - order(b))
}

/**
 * The tab a location hash points into, or null when it names no known anchor.
 *
 * @param hash The location hash, with or without the leading `#`.
 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-versioniq-conn-003-an-admin-reads-the-connections-on-an-integrations-tab
 */
export function tabForHash (hash: string): { tab: string, anchor: string } | null {
	const anchor = hash.replace(/^#/, '')

	return Object.hasOwn(SECTION_TABS, anchor) ? { tab: SECTION_TABS[anchor], anchor } : null
}
