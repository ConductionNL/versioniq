// SPDX-License-Identifier: EUPL-1.2
// The Integrations tab over integriq's connection registry
// (adopt-connection-registry, hydra connection-registry D8 and D9).
//
// The tab resolves statuses, a settings link and an anchor by NAME. A
// misspelled name renders a raw enum, a dead link, or a hash that opens the
// wrong tab, and none of them logs a thing.
//
// @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-versioniq-conn-003-an-admin-reads-the-connections-on-an-integrations-tab
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import {
	CONNECTION_STATUS_LABELS,
	CONNECTIONS_PATH,
	connectionSettingsLabel,
	connectionStatus,
	INTEGRIQ_CONNECTIONS_PATH,
	ownConnectionRows,
	SECTION_TABS,
	tabForHash,
} from './connectionRegistry.ts'

const ROOT = resolve(__dirname, '../..')
const read = (...parts: string[]): string => readFileSync(resolve(ROOT, ...parts), 'utf8')

/** A translator that marks what it translated, so a missing call shows. */
const translate = (source: string): string => `t:${source}`

describe('connection formatters', () => {
	it('labels all six statuses, limited included', () => {
		expect(Object.keys(CONNECTION_STATUS_LABELS).sort()).toEqual(
			['configured', 'error', 'limited', 'simulated', 'unavailable', 'unconfigured'],
		)
		expect(connectionStatus('configured', translate)).toBe('t:Configured')
		expect(connectionStatus('limited', translate)).toBe('t:Limited')
		expect(connectionStatus('unconfigured', translate)).toBe('t:Not configured')
		expect(connectionStatus('simulated', translate)).toBe('t:Simulated')
		expect(connectionStatus('unavailable', translate)).toBe('t:Not available')
		expect(connectionStatus('error', translate)).toBe('t:Error')
	})

	// A connection that works in part is neither working nor broken, so it
	// must not borrow either label.
	it('keeps limited apart from configured, not available and error', () => {
		const limited = connectionStatus('limited', translate)
		expect(limited).not.toBe(connectionStatus('configured', translate))
		expect(limited).not.toBe(connectionStatus('unavailable', translate))
		expect(limited).not.toBe(connectionStatus('error', translate))
	})

	it('renders an unknown status as itself and a missing one as empty', () => {
		expect(connectionStatus('degraded', translate)).toBe('degraded')
		expect(connectionStatus('toString', translate)).toBe('toString')
		expect(connectionStatus(null, translate)).toBe('')
		expect(connectionStatus(undefined, translate)).toBe('')
	})

	it('offers Open settings only when the row has a settings link', () => {
		expect(connectionSettingsLabel('/settings/admin/versioniq#section-sources', translate)).toBe('t:Open settings')
		expect(connectionSettingsLabel('', translate)).toBe('')
		expect(connectionSettingsLabel(undefined, translate)).toBe('')
	})

	it('ships an English and a Dutch catalogue entry for every label the tab shows', () => {
		const en = JSON.parse(read('l10n', 'en.json')).translations
		const nl = JSON.parse(read('l10n', 'nl.json')).translations
		const panel = read('src', 'components', 'IntegrationsPanel.vue')
		const panelLabels = [...panel.matchAll(/t\('versioniq', '([^']+)'/g)].map((m) => m[1])
		expect(panelLabels.length).toBeGreaterThan(5)

		for (const label of [...Object.values(CONNECTION_STATUS_LABELS), 'Open settings', ...panelLabels]) {
			expect(en[label], `en: ${label}`).toBe(label)
			expect(nl[label], `nl: ${label}`).toBeTruthy()
		}
		expect(nl.Limited).toBe('Beperkt')
		// The browser reads the .js catalogue, never the .json one.
		expect(read('l10n', 'nl.js')).toContain('"Limited": "Beperkt"')
	})
})

describe('the rows and the links', () => {
	it('reads this app\'s rows with a bare filter key, and sends Add integration to integriq', () => {
		expect(CONNECTIONS_PATH).toBe('/index.php/apps/openregister/api/objects/integriq/app_connection?app=versioniq&_limit=50')
		expect(CONNECTIONS_PATH).not.toContain('filter[')
		expect(INTEGRIQ_CONNECTIONS_PATH).toBe('/index.php/apps/integriq/connections?app=versioniq&link=1')
	})

	it('drops another app\'s rows and sorts by the declared order', () => {
		const rows = ownConnectionRows({
			results: [
				{ app: 'versioniq', key: 'advisories', order: 30 },
				{ app: 'dossiq', key: 'zgw', order: 10 },
				{ app: 'versioniq', key: 'appstore', order: 10 },
				{ app: 'versioniq', key: 'github', order: 20 },
				null,
			],
		})
		expect(rows.map((row) => row.key)).toEqual(['appstore', 'github', 'advisories'])
	})

	it('answers no rows for a body without results', () => {
		expect(ownConnectionRows({})).toEqual([])
		expect(ownConnectionRows(null)).toEqual([])
	})

	it('opens the tab that renders each declared anchor', () => {
		const declaration = JSON.parse(read('lib', 'Settings', 'connections.json'))
		const anchors = declaration.connections
			.map((c: { settingsUrl?: string }) => c.settingsUrl)
			.filter((url: string | undefined): url is string => typeof url === 'string')
			.map((url: string) => url.slice(url.indexOf('#')))
		expect(anchors).toEqual(['#section-sources', '#section-advisories'])

		for (const anchor of anchors) {
			expect(tabForHash(anchor), anchor).not.toBeNull()
		}
		expect(tabForHash('#section-sources')).toEqual({ tab: 'sources', anchor: 'section-sources' })
		expect(tabForHash('section-advisories')).toEqual({ tab: 'apps', anchor: 'section-advisories' })
		expect(tabForHash('#section-integrations')).toEqual({ tab: 'integrations', anchor: 'section-integrations' })
		expect(tabForHash('#versioniq-main')).toBeNull()
		expect(tabForHash('#toString')).toBeNull()
	})

	it('maps each anchor to a tab App.vue declares', () => {
		const app = read('src', 'App.vue')
		for (const tab of Object.values(SECTION_TABS)) {
			expect(app, tab).toMatch(new RegExp(`\\{ id: '${tab}' \\}`))
		}
	})
})
