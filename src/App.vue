<script setup lang="ts">
import type {PrefillBindPayload} from './components/DiscoverPanel.vue';
import type {PolicyLevel} from './components/PolicySelector.vue';
import type { PinRecord } from './dialogs/PinDialog.vue'
import type {LkgRecord} from './utils/migrationSafety.ts';

import { t } from '@nextcloud/l10n'
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import CachePanel from './components/CachePanel.vue'
import ChangelogRangePanel from './components/ChangelogRangePanel.vue'
import DiscoverPanel from './components/DiscoverPanel.vue'
import HistoryPanel from './components/HistoryPanel.vue'
import PinDriftBanner from './components/PinDriftBanner.vue'
import PolicySelector from './components/PolicySelector.vue'
import SourcesPanel from './components/SourcesPanel.vue'
import TokensPanel from './components/TokensPanel.vue'
import TrustedSourcesPanel from './components/TrustedSourcesPanel.vue'
import VersionChangelog from './components/VersionChangelog.vue'
import DowngradeConfirmDialog from './dialogs/DowngradeConfirmDialog.vue'
import PinDialog from './dialogs/PinDialog.vue'
import PinOverrideDialog from './dialogs/PinOverrideDialog.vue'
import ShaMismatchDialog from './dialogs/ShaMismatchDialog.vue'
import { AUTO_UPDATE_WINDOW_DEFAULT, isValidAutoUpdateWindow } from './utils/autoUpdateWindow.ts'
import { buildChangelogRange } from './utils/changelog.ts'
import { shouldOfferLkgRollback } from './utils/migrationSafety.ts'
import { compareVersions, parseVersionCore } from './utils/versionCompare.ts'

type AppOption = {
	id: string
	label: string
	description: string
	summary: string
	preview: string
	isCore: boolean
	manageable?: boolean
	warning?: string | null
	installedVersion?: string | null
	lkg?: LkgRecord | null
}

type AppVersion = {
	version: string
	changelog?: string | null
	recordedSha?: string | null
	cachedOffline?: boolean
}

type AdvisoryRecord = {
	id: string
	severity: string
	summary: string
}

type AdvisoryCorrelation = {
	appId: string
	installedVersion: string | null
	state: 'none' | 'advisory-available' | 'pinned-to-vulnerable'
	advisories: AdvisoryRecord[]
	recommendedVersion: string | null
	error: string | null
}

type InstallDebugEntry = {
	stage: string
	data?: unknown
}

type InstallResult = {
	appId: string
	fromVersion?: string | null
	toVersion: string
	installedVersion?: string | null
	updateType?: string
	message: string
	dryRun: boolean
	installStatus: string
	stage?: string | null
	category?: string | null
	hint?: string | null
	debug?: InstallDebugEntry[]
	recordedShaMatched?: boolean | null
	orphanedMigrations?: string[] | null
}

const isLoading = ref(true)
const apps = ref<AppOption[]>([])
const advisories = ref<Record<string, AdvisoryCorrelation>>({})
const appFilter = ref('')
const showFilters = ref(false)
const coreAppsVisibility = ref<'show' | 'hide'>('show')
const updateChannel = ref('')
const selectedApp = ref('')
const versions = ref<AppVersion[]>([])
const versionFilter = ref('')
const hasCheckedVersions = ref(false)
const isCheckingVersions = ref(false)
const isInstallingVersion = ref(false)
const installedVersion = ref('')
const availableSource = ref('')
const errorMessage = ref('')
const selectedVersion = ref('')
const safeModeEnabled = ref(true)
const debugModeEnabled = ref(false)
// Independent of debugModeEnabled — see MODIFIED "Debug Mode": dryRun is now
// its own explicit request parameter, decoupled from debug/verbosity.
const dryRunEnabled = ref(false)
const isDowngradeConfirmOpen = ref(false)
const downgradeConfirmFromVersion = ref('')
const downgradeConfirmToVersion = ref('')
const downgradeConfirmApp = ref('')
let downgradeResolve: ((value: boolean) => void) | null = null
// Migration diff for the downgrade dialog — fetched via a dry-run preview
// before the dialog opens; see "Migration diff on downgrade".
const downgradeOrphanedMigrations = ref<string[] | null>(null)
// Suppresses the safe-mode auto-clear watcher while "Roll back to last
// known good" programmatically selects a (necessarily older) version.
const suppressSafeModeAutoClear = ref(false)
/**
 * Ceiling for the three non-blocking loaders fired after the app list renders
 * (advisories, pins, policies).
 *
 * Without one, `fetch` waits forever. Measured (issue #160): all three were
 * left permanently suspended at their `await fetch`, so `pins` never left `{}`
 * and pin badges, advisory badges and auto-update policy state were silently
 * absent — with no error, no console output and no failed request, because a
 * promise that never settles reaches neither the success path nor the catch.
 *
 * 8s is far above any healthy response here (every other endpoint on this page
 * answers in ~60ms) so a timeout means something is genuinely wrong, and the
 * catch then reports it instead of the UI quietly missing a feature.
 *
 * ⚠️ It was 20s, and that made the abort UNOBSERVABLE: the e2e assertion that
 * watches for the pin badge gives up at 15s, so the timeout fired after the
 * test had already failed and the catch never ran within the window. A bound
 * must fit inside the bound that contains it — the same arithmetic error as a
 * retry that outlasts its job cap. Keep this below the 15s expect timeout in
 * playwright.config.ts.
 */
const BACKGROUND_FETCH_TIMEOUT_MS = 8_000

const safeModeStorageKey = 'versioniq_safe_mode'
const debugModeStorageKey = 'versioniq_debug_mode'
const dryRunStorageKey = 'versioniq_dry_run_mode'

/**
 * The same three keys under the pre-rename `app_versions` app id.
 *
 * localStorage is the ADMIN'S OWN BROWSER STORE, and the `app_versions` ->
 * `versioniq` rename cuts it off exactly the way it cuts off oc_appconfig —
 * except that no server-side repair step can reach into a browser. Without a
 * fallback, `getItem(newKey)` returns null on the first load after the rename
 * and each toggle silently reverts to its shipped default. Safe mode is the
 * one that matters: an admin who deliberately turned it OFF would find it
 * back ON with nothing to explain why.
 *
 * So reads fall back to the old key (see readStoredFlag) while writes only
 * ever go to the new one — the old entries are left in place rather than
 * removed, so rolling back to the previous app id still finds them.
 */
const legacyStorageKeys: Record<string, string> = {
	versioniq_safe_mode: 'app_versions_safe_mode',
	versioniq_debug_mode: 'app_versions_debug_mode',
	versioniq_dry_run_mode: 'app_versions_dry_run_mode',
}

/**
 * Read one persisted UI flag, preferring the current key and falling back to
 * the pre-rename one. Returns null when neither is set.
 *
 * @param key The current (post-rename) localStorage key.
 */
function readStoredFlag (key: string): string | null {
	const current = window?.localStorage?.getItem(key) ?? null
	if (current !== null) {
		return current
	}
	const legacyKey = legacyStorageKeys[key]
	return legacyKey === undefined ? null : (window?.localStorage?.getItem(legacyKey) ?? null)
}
const lastInstallDebug = ref<InstallDebugEntry[]>([])
const lastInstallResult = ref<InstallResult | null>(null)
const hasInstallResult = ref(false)
const installRequestFromVersion = ref('')
const installRequestToVersion = ref('')

// Pinning (see "Pin an installed app to its current version", "Drift
// detection", "Drift response — notify and offer re-pin"). Pins are fetched
// once and kept in a per-appId map, mirroring the `advisories` pattern.
const pins = ref<Record<string, PinRecord>>({})
const isPinDialogOpen = ref(false)
const pinDialogAppId = ref('')
const pinDialogVersion = ref('')
const isPinOverrideDialogOpen = ref(false)
const pinOverrideAppId = ref('')
const pinOverridePinnedVersion = ref('')
const pinOverrideTargetVersion = ref('')
let pinOverrideResolve: ((choice: 'repin' | 'unpin' | 'cancel') => void) | null = null

// SHA-256 mismatch on reinstall (trust-on-first-use enforcement) — see
// "Recorded SHA-256 enforced on reinstall".
const isShaMismatchDialogOpen = ref(false)
const shaMismatchAppId = ref('')
const shaMismatchVersion = ref('')
const shaMismatchExpectedSha = ref('')
const shaMismatchActualSha = ref('')
let shaMismatchResolve: ((accept: boolean) => void) | null = null

// Auto-update policies (see "Per-app update policy", "Nightly policy
// execution through the standard installer", "Global kill switch and
// window"). Policies and the two global settings are fetched together from
// GET /api/policies, mirroring the `pins`/`advisories` per-appId map pattern.
type PolicyRecord = { appId: string, level: PolicyLevel, setBy: string, setAt: string }
const policies = ref<Record<string, PolicyRecord>>({})
const isSavingPolicy = ref(false)
const autoUpdateEnabled = ref(false)
const savedAutoUpdateEnabled = ref(false)
const autoUpdateWindowInput = ref(AUTO_UPDATE_WINDOW_DEFAULT)
const savedAutoUpdateWindow = ref(AUTO_UPDATE_WINDOW_DEFAULT)
const isSavingAutoUpdateSettings = ref(false)
const autoUpdateSettingsError = ref('')
const autoUpdateSettingsNotice = ref('')

// Admin-settings tabs: the existing apps→versions→install view plus the
// source / token / trusted-source management panels, and the Discover tab
// (multi-source search over the previously-unreachable discovery backend).
const tabs = [
	{ id: 'apps' },
	{ id: 'history' },
	{ id: 'sources' },
	{ id: 'tokens' },
	{ id: 'trusted' },
	{ id: 'discover' },
	{ id: 'cache' },
]
const currentTab = ref('apps')
const tablistEl = ref<HTMLElement | null>(null)

// Literal strings (not interpolated) so they remain extractable for translation.
/**
 *
 * @param id
 */
function tabLabel (id: string): string {
  return {
	apps: t('versioniq', 'Apps'),
	history: t('versioniq', 'History'),
	sources: t('versioniq', 'Sources'),
	tokens: t('versioniq', 'Tokens'),
	trusted: t('versioniq', 'Trusted sources'),
	discover: t('versioniq', 'Discover'),
	cache: t('versioniq', 'Artifact cache'),
}[id] ?? id
}

// Prefill applied to the Sources bind form when a Discover hit's install
// action is activated; see "Hits route into existing flows".
const sourcesPrefill = ref<PrefillBindPayload | null>(null)

/**
 * Routes an installed Discover hit into the Apps tab with its version picker
 * expanded; see "Hits route into existing flows" ("Installed hit opens the
 * picker").
 *
 * @param appId
 * @spec openspec/specs/app-discovery/spec.md
 */
async function onDiscoverOpenApp (appId: string): Promise<void> {
	currentTab.value = 'apps'
	appFilter.value = appId
	await onPickApp(appId)
}

/**
 * Routes a not-installed Discover hit's installable candidate into the
 * Sources bind flow, prefilled; see "Hits route into existing flows"
 * ("Installable candidate prefills bind").
 *
 * @param payload
 * @spec openspec/specs/app-discovery/spec.md
 */
function onDiscoverPrefillBind (payload: PrefillBindPayload): void {
	sourcesPrefill.value = payload
	currentTab.value = 'sources'
}

/**
 * Routes a non-installable Discover hit to the Trusted sources tab; see
 * "Hits route into existing flows" ("Non-installable explains why").
 *
 * @spec openspec/specs/app-discovery/spec.md
 */
function onDiscoverOpenTrusted (): void {
	currentTab.value = 'trusted'
}

// Per-app detail view within the "Apps" tab: the version picker (default) or
// that app's audit history; see "Per-app history tab".
const appDetailTab = ref<'versions' | 'history'>('versions')

// WAI-ARIA tablist keyboard support: Left/Right (and Home/End) move between
// tabs and move focus to the newly selected tab, per the tabs pattern.
/**
 *
 * @param event
 */
async function onTabKeydown (event: KeyboardEvent): Promise<void> {
	const keys = ['ArrowRight', 'ArrowLeft', 'Home', 'End']
	if (!keys.includes(event.key)) {
		return
	}
	event.preventDefault()
	const index = tabs.findIndex((tab) => tab.id === currentTab.value)
	let next = index
	if (event.key === 'ArrowRight') {
		next = (index + 1) % tabs.length
	} else if (event.key === 'ArrowLeft') {
		next = (index - 1 + tabs.length) % tabs.length
	} else if (event.key === 'Home') {
		next = 0
	} else if (event.key === 'End') {
		next = tabs.length - 1
	}
	currentTab.value = tabs[next].id
	await nextTick()
	const buttons = tablistEl.value?.querySelectorAll<HTMLElement>('[role="tab"]')
	buttons?.[next]?.focus()
}

type VersionRangeInfo = {
	major: number
	minor: number
	patch: number
	direction: 'upgrade' | 'degrade'
	from: string
	to: string
}

const changeActionLabel = computed(() => {
	if (!selectedApp.value || !selectedVersion.value) {
		return ''
	}

	if (!installedVersion.value) {
		return 'Install'
	}

	const comparison = compareVersions(selectedVersion.value, installedVersion.value)
	if (comparison > 0) {
		return 'Update'
	}

	if (comparison < 0) {
		return 'Degrade'
	}

	return ''
})

const hasSidebarSelect = computed(() => !isLoading.value)
const sidebarLabel = computed(() => hasSidebarSelect.value ? 'Select an app from store' : 'Loading…')
const hasInfoPanel = computed(() => selectedApp.value || installedVersion.value || versions.value.length > 0 || availableSource.value || errorMessage.value || hasCheckedVersions.value)
const hasSplitLayout = computed(() => Boolean(selectedApp.value || installedVersion.value || hasInstallResult.value))
const isSafeMode = computed(() => safeModeEnabled.value)
const includeDebug = computed(() => debugModeEnabled.value)
const dryRunRequested = computed(() => dryRunEnabled.value)

/**
 *
 * @param path
 */
function apiUrl (path: string): string {
	const oc = window.OC as unknown as {
		webroot?: string
	}
	const webroot = (typeof oc?.webroot === 'string' ? oc.webroot : '').replace(/\/$/, '')
	return `${window.location.origin}${webroot}${path}`
}

const ocsHeaders: HeadersInit = { 'OCS-APIRequest': 'true' }

/**
 *
 * @param path
 * @param query
 */
function withOcsJson (path: string, query: Record<string, string | number | boolean> = {}): string {
	const separator = path.includes('?') ? '&' : '?'
	const params = new URLSearchParams()
	Object.entries(query).forEach(([key, value]) => {
		params.set(key, String(value))
	})
	params.set('format', 'json')

	return `${path}${separator}${params.toString()}`
}

/**
 *
 * @param response
 */
async function unwrapOcsResponse <T, >(response: Response): Promise<T> {
	if (!response.ok) {
		// Keep payload-based failures parseable for callers that return useful data with 4xx.
	}

	const raw = await response.json()
	if (typeof raw !== 'object' || raw === null) {
		throw new Error('Unexpected response format')
	}

	const meta = (raw as { ocs?: { meta?: { status?: string, statuscode?: number, message?: string } } }).ocs?.meta
	if (meta && (meta.status === 'failure' || (typeof meta.statuscode === 'number' && meta.statuscode >= 400))) {
		throw new Error(meta.message || 'OCS request failed')
	}

	return (raw.ocs?.data ?? raw) as T
}

type OcsWrapped<T> = {
	ocs?: {
		meta?: {
			status?: string
			statuscode?: number
			message?: string
		}
		data?: T
	}
	data?: T
}

/**
 *
 * @param response
 */
async function unwrapOcsResponseWithMeta <T, >(response: Response): Promise<{ payload: T, metaMessage?: string }> {
	const raw = (await response.json()) as OcsWrapped<T>
	if (typeof raw !== 'object' || raw === null) {
		throw new Error('Unexpected response format')
	}

	const data = (raw.ocs?.data ?? raw.data ?? raw) as T
	const meta = raw.ocs?.meta
	if (meta && (meta.status === 'failure' || (typeof meta.statuscode === 'number' && meta.statuscode >= 400))) {
		return {
			payload: data,
			metaMessage: meta.message || 'OCS request failed',
		}
	}

	return { payload: data }
}

/**
 *
 * @param payload
 * @param payload.appId
 * @param payload.fromVersion
 * @param payload.toVersion
 * @param payload.installedVersion
 * @param payload.updateType
 * @param payload.message
 * @param payload.dryRun
 * @param payload.installStatus
 * @param payload.stage
 * @param payload.category
 * @param payload.hint
 * @param payload.debug
 * @param payload.recordedShaMatched
 */
function normalizeInstallResult (payload: {
	appId?: string
	fromVersion?: string | null
	toVersion?: string
	installedVersion?: string | null
	updateType?: string
	message?: string
	dryRun?: boolean
	installStatus?: string
	stage?: string | null
	category?: string | null
	hint?: string | null
	debug?: unknown
	recordedShaMatched?: boolean
}): InstallResult {
	const normalizedUpdateType = payload.updateType ?? 'none'
	const normalizedFrom = payload.fromVersion ?? null
	const normalizedTo = payload.toVersion || ''
	const resolvedMessage = payload.message || 'Install completed.'
	const shouldForceDowngradeMessage = normalizedUpdateType === 'downgrade'
		|| (normalizedFrom !== null && normalizedTo !== '' && compareVersions(normalizedTo, normalizedFrom) < 0)
	const finalMessage = shouldForceDowngradeMessage
		? (resolvedMessage === 'App updated.'
			? 'App downgraded.'
			: resolvedMessage)
		: resolvedMessage

	return {
		appId: payload.appId || '',
		fromVersion: normalizedFrom,
		toVersion: normalizedTo,
		installedVersion: payload.installedVersion ?? null,
		updateType: normalizedUpdateType,
		message: finalMessage,
		dryRun: Boolean(payload.dryRun),
		installStatus: payload.installStatus || 'failed',
		stage: payload.stage ?? null,
		category: payload.category ?? null,
		hint: payload.hint ?? null,
		debug: Array.isArray(payload.debug) ? payload.debug as InstallDebugEntry[] : [],
		recordedShaMatched: payload.recordedShaMatched ?? null,
	}
}

const installStatusTone = computed<'success' | 'warning' | 'error' | 'info'>(() => {
	const result = lastInstallResult.value
	if (!result) {
		return 'info'
	}

	if (result.installStatus === 'dry-run' || result.installStatus === 'reverted') {
		return 'warning'
	}

	if (result.installStatus === 'failed' || result.installStatus === 'error' || result.installStatus === 'installed-but-broken') {
		return 'error'
	}

	return 'success'
})

const installStatusLabel = computed(() => {
	const status = lastInstallResult.value?.installStatus
	if (status === 'dry-run') {
		return 'Dry run'
	}
	if (status === 'reverted') {
		return 'Reverted'
	}
	if (status === 'installed-but-broken') {
		return 'Installed but broken'
	}
	switch (installStatusTone.value) {
	case 'warning':
		return 'Dry run'
	case 'error':
		return 'Failed'
	default:
		return 'Done'
	}
})

/**
 *
 */
async function checkUpdateChannel (): Promise<void> {
	try {
		const response = await fetch(apiUrl(withOcsJson('/ocs/v2.php/apps/versioniq/api/update-channel')), { headers: { ...ocsHeaders, Accept: 'application/json' } })
		const payload = await unwrapOcsResponse<{ updateChannel: string }>(response)
		updateChannel.value = payload.updateChannel || ''
	} catch {
		updateChannel.value = ''
	}
}

/**
 *
 */
async function loadApps (): Promise<void> {
	try {
		const response = await fetch(apiUrl(withOcsJson('/ocs/v2.php/apps/versioniq/api/apps')), { headers: { ...ocsHeaders, Accept: 'application/json' } })
		const payload = await unwrapOcsResponse<{ apps: AppOption[] }>(response)
		apps.value = payload.apps || []
	} catch (error) {
		errorMessage.value = error instanceof Error ? error.message : 'Could not fetch app list.'
	}
}

// Advisory correlation is fetched separately from the app list so a slow or
// unreachable advisory source never delays the (fast) app list. The badge
// appears once this resolves. Read-only — it never changes a version.
//
// The endpoint returns a STORED snapshot written by the 6-hourly
// AdvisoryRefreshJob, not a live correlation, so `checkedAt` travels with it
// and is rendered. An empty map has three quite different causes — swept and
// found nothing, never swept because cron has not run, or the fetch failed —
// and without the timestamp all three render as "no advisories", which reads
// as reassurance the data does not support.
/**
 *
 */
// Unix seconds of the last completed sweep; null means none has completed.
const advisoriesCheckedAt = ref<number | null>(null)
// True only when the fetch itself failed — never merely because the map is empty.
const advisoriesUnavailable = ref(false)

async function loadAdvisories (): Promise<void> {
	try {
		const response = await fetch(apiUrl(withOcsJson('/ocs/v2.php/apps/versioniq/api/advisories')), { headers: { ...ocsHeaders, Accept: 'application/json' }, signal: AbortSignal.timeout(BACKGROUND_FETCH_TIMEOUT_MS) })
		const payload = await unwrapOcsResponse<{ advisories: Record<string, AdvisoryCorrelation>, checkedAt: number | null }>(response)
		advisories.value = payload.advisories || {}
		advisoriesCheckedAt.value = payload.checkedAt ?? null
		advisoriesUnavailable.value = false
	} catch {
		// Non-fatal: the app list stays usable without advisory badges. The
		// flag keeps "we could not ask" distinct from "nothing to report".
		advisories.value = {}
		advisoriesCheckedAt.value = null
		advisoriesUnavailable.value = true
	}
}

// ── Advisory check settings ───────────────────────────────────────────────
// The supported range comes from the SERVER rather than being hardcoded here:
// a client that pins its own bounds drifts from the server the first time the
// range changes, and then rejects values the server would have accepted.
const advisoryIntervalInput = ref('6')
const advisoryDigestEnabled = ref(true)
const advisoryMinInterval = ref(1)
const advisoryMaxInterval = ref(24)
const advisorySavedInterval = ref('6')
const advisorySavedDigest = ref(true)
const isSavingAdvisorySettings = ref(false)
const advisorySettingsError = ref('')
const advisorySettingsNotice = ref('')

const isAdvisoryIntervalValid = computed((): boolean => {
	const raw = advisoryIntervalInput.value.trim()
	if (!/^\d+$/.test(raw)) {
		return false
	}
	const hours = Number(raw)
	return hours >= advisoryMinInterval.value && hours <= advisoryMaxInterval.value
})

const isAdvisorySettingsDirty = computed((): boolean =>
	advisoryIntervalInput.value.trim() !== advisorySavedInterval.value
	|| advisoryDigestEnabled.value !== advisorySavedDigest.value)

/**
 *
 */
async function loadAdvisorySettings (): Promise<void> {
	try {
		const response = await fetch(apiUrl(withOcsJson('/ocs/v2.php/apps/versioniq/api/advisory/settings')), { headers: { ...ocsHeaders, Accept: 'application/json' }, signal: AbortSignal.timeout(BACKGROUND_FETCH_TIMEOUT_MS) })
		const payload = await unwrapOcsResponse<{ intervalHours: number, minIntervalHours: number, maxIntervalHours: number, digestEnabled: boolean }>(response)
		advisoryMinInterval.value = payload.minIntervalHours
		advisoryMaxInterval.value = payload.maxIntervalHours
		advisoryIntervalInput.value = String(payload.intervalHours)
		advisorySavedInterval.value = String(payload.intervalHours)
		advisoryDigestEnabled.value = payload.digestEnabled
		advisorySavedDigest.value = payload.digestEnabled
	} catch {
		// Non-fatal: the settings control simply keeps its defaults.
	}
}

/**
 *
 */
async function saveAdvisorySettings (): Promise<void> {
	isSavingAdvisorySettings.value = true
	advisorySettingsError.value = ''
	advisorySettingsNotice.value = ''
	try {
		const response = await fetch(apiUrl(withOcsJson('/ocs/v2.php/apps/versioniq/api/advisory/settings')), {
			method: 'PUT',
			headers: { ...ocsHeaders, 'Content-Type': 'application/json', Accept: 'application/json' },
			body: JSON.stringify({
				intervalHours: advisoryIntervalInput.value.trim(),
				// '1'/'0' rather than a JSON boolean: PHP casts a JSON `false`
				// to '' and the server would read that as "unspecified".
				digestEnabled: advisoryDigestEnabled.value ? '1' : '0',
			}),
		})
		const payload = await unwrapOcsResponse<{ intervalHours: number, digestEnabled: boolean }>(response)
		advisoryIntervalInput.value = String(payload.intervalHours)
		advisorySavedInterval.value = String(payload.intervalHours)
		advisoryDigestEnabled.value = payload.digestEnabled
		advisorySavedDigest.value = payload.digestEnabled
		advisorySettingsNotice.value = t('versioniq', 'Advisory settings saved.')
	} catch (error) {
		advisorySettingsError.value = error instanceof Error ? error.message : String(error)
	} finally {
		isSavingAdvisorySettings.value = false
	}
}

/**
 * How the advisory data should describe itself. Deliberately says something
 * in all three states rather than falling silent when there is nothing to
 * report, because silence is what made #160 invisible for so long.
 */
const advisoryFreshnessLabel = computed((): string => {
	if (advisoriesUnavailable.value) {
		return t('versioniq', 'Advisory status unavailable — could not reach the server')
	}
	if (advisoriesCheckedAt.value === null) {
		return t('versioniq', 'Advisories not checked yet — the background job runs every 6 hours')
	}

	const ageMinutes = Math.max(0, Math.round((Date.now() / 1000 - advisoriesCheckedAt.value) / 60))
	if (ageMinutes < 1) {
		return t('versioniq', 'Advisories checked just now')
	}
	if (ageMinutes < 60) {
		return t('versioniq', 'Advisories checked {minutes} min ago', { minutes: ageMinutes })
	}

	return t('versioniq', 'Advisories checked {hours} h ago', { hours: Math.round(ageMinutes / 60) })
})

const advisoryFor = (appId: string): AdvisoryCorrelation | null => advisories.value[appId] ?? null

// Badge shown next to a version with a recorded SHA-256 on the binding — see
// "Recorded digests are binding-scoped and surfaced". After a reinstall that
// verified the digest against that same version, the badge is upgraded to
// reflect the fresh verification instead of just "on record".
/**
 *
 * @param version
 */
function recordedShaBadgeLabel (version: string): string {
	const lastResult = lastInstallResult.value
	if (lastResult?.recordedShaMatched === true && lastResult.toVersion === version) {
		return t('versioniq', 'Matches first-install checksum')
	}
	return t('versioniq', 'Checksum recorded')
}

/**
 *
 * @param state
 */
function advisoryBadgeLabel (state: AdvisoryCorrelation['state']): string {
	if (state === 'pinned-to-vulnerable') {
		return t('versioniq', 'Vulnerable version')
	}
	if (state === 'advisory-available') {
		return t('versioniq', 'Advisory')
	}
	return ''
}

// Pins, like advisories, are fetched separately from the app list and never
// block it; read-only for badges, writes go through PinDialog / the drift
// banner / the pin-override dialog. See "Honest pin presentation".
/**
 *
 */
async function loadPins (): Promise<void> {
	try {
		const response = await fetch(apiUrl(withOcsJson('/ocs/v2.php/apps/versioniq/api/pins')), { headers: { ...ocsHeaders, Accept: 'application/json' }, signal: AbortSignal.timeout(BACKGROUND_FETCH_TIMEOUT_MS) })
		const payload = await unwrapOcsResponse<{ pins: PinRecord[] }>(response)
		const map: Record<string, PinRecord> = {}
		for (const pin of payload.pins || []) {
			map[pin.appId] = pin
		}
		pins.value = map
	} catch (error) {
		// NOT a bare `catch {}`. A silent catch here is why this took seven
		// eliminated hypotheses to chase: pins ends up `{}` and the app-card
		// badge simply never renders, with nothing anywhere saying why — no
		// failed request, no page error, no console output (issue #160).
		pins.value = {}
		// eslint-disable-next-line no-console
		console.error('[versioniq] loadPins failed; pin badges will not render:', error)
	}
}

const pinFor = (appId: string): PinRecord | null => pins.value[appId] ?? null

/**
 *
 * @param pin
 */
function pinTooltip (pin: PinRecord | null): string {
	if (!pin) {
		return ''
	}
	const parts = [t('versioniq', 'Pinned by {user} on {date}', { user: pin.pinnedBy, date: pin.pinnedAt })]
	if (pin.reason) {
		parts.push(pin.reason)
	}
	return parts.join(' — ')
}

// Auto-update policies + global settings, fetched once and kept in a
// per-appId map, same pattern as pins/advisories; read-only badges here,
// writes go through onPolicyChange()/saveAutoUpdateSettings().
/**
 *
 */
async function loadPolicies (): Promise<void> {
	try {
		const response = await fetch(apiUrl(withOcsJson('/ocs/v2.php/apps/versioniq/api/policies')), { headers: { ...ocsHeaders, Accept: 'application/json' }, signal: AbortSignal.timeout(BACKGROUND_FETCH_TIMEOUT_MS) })
		const payload = await unwrapOcsResponse<{ policies?: PolicyRecord[], autoUpdateEnabled?: boolean, autoUpdateWindow?: string }>(response)
		const map: Record<string, PolicyRecord> = {}
		for (const policy of payload.policies || []) {
			map[policy.appId] = policy
		}
		policies.value = map
		autoUpdateEnabled.value = Boolean(payload.autoUpdateEnabled)
		savedAutoUpdateEnabled.value = autoUpdateEnabled.value
		autoUpdateWindowInput.value = payload.autoUpdateWindow || AUTO_UPDATE_WINDOW_DEFAULT
		savedAutoUpdateWindow.value = autoUpdateWindowInput.value
	} catch {
		// Non-fatal: the app list stays usable without policy badges.
		policies.value = {}
	}
}

const policyLevelFor = (appId: string): PolicyLevel => policies.value[appId]?.level ?? 'none'

/**
 *
 * @param appId
 * @param level
 */
async function onPolicyChange (appId: string, level: PolicyLevel): Promise<void> {
	if (isSavingPolicy.value) {
		return
	}
	isSavingPolicy.value = true
	errorMessage.value = ''
	try {
		await ensurePasswordConfirmation()
		if (level === 'none') {
			const response = await fetch(apiUrl(withOcsJson(`/ocs/v2.php/apps/versioniq/api/app/${encodeURIComponent(appId)}/policy`)), {
				method: 'DELETE',
				headers: { ...ocsHeaders, Accept: 'application/json', 'Content-Type': 'application/json' },
			})
			await unwrapOcsResponse(response)
			const next = { ...policies.value }
			delete next[appId]
			policies.value = next
		} else {
			const response = await fetch(apiUrl(withOcsJson(`/ocs/v2.php/apps/versioniq/api/app/${encodeURIComponent(appId)}/policy`, { level })), {
				method: 'PUT',
				headers: { ...ocsHeaders, Accept: 'application/json', 'Content-Type': 'application/json' },
				body: JSON.stringify({ level }),
			})
			const payload = await unwrapOcsResponse<{ appId: string, policy: PolicyRecord }>(response)
			policies.value = { ...policies.value, [appId]: { ...payload.policy, appId } }
		}
	} catch (e) {
		errorMessage.value = e instanceof Error ? e.message : t('versioniq', 'Could not update the auto-update policy.')
	} finally {
		isSavingPolicy.value = false
	}
}

const isAutoUpdateWindowValid = computed(() => isValidAutoUpdateWindow(autoUpdateWindowInput.value))
const isAutoUpdateSettingsDirty = computed(() => (
	autoUpdateEnabled.value !== savedAutoUpdateEnabled.value
	|| autoUpdateWindowInput.value.trim() !== savedAutoUpdateWindow.value
))

/**
 *
 */
async function saveAutoUpdateSettings (): Promise<void> {
	autoUpdateSettingsError.value = ''
	autoUpdateSettingsNotice.value = ''
	if (!isAutoUpdateWindowValid.value) {
		autoUpdateSettingsError.value = t('versioniq', 'Window must be in HH:MM-HH:MM format.')
		return
	}

	isSavingAutoUpdateSettings.value = true
	try {
		await ensurePasswordConfirmation()
		const window = autoUpdateWindowInput.value.trim()
		const response = await fetch(apiUrl(withOcsJson('/ocs/v2.php/apps/versioniq/api/auto-update/settings', {
			enabled: autoUpdateEnabled.value ? '1' : '0',
			window,
		})), {
			method: 'PUT',
			headers: { ...ocsHeaders, Accept: 'application/json', 'Content-Type': 'application/json' },
			// '1'/'0', NOT a JSON boolean — and this is load-bearing.
			//
			// `enabled` is a declared string parameter on the controller, so
			// Nextcloud casts whatever arrives to string. PHP casts `false` to
			// the EMPTY STRING, not to "0", and readBinaryBool() answers an
			// unrecognised string with its DEFAULT — which here is the current
			// stored value. Sending a real `false` therefore asked the server
			// to keep whatever it already had: switching the kill switch OFF
			// silently did nothing, while the request returned 200.
			//
			// The query string above already sends '1'/'0'; this makes the body
			// agree with it so the value cannot depend on which one wins.
			body: JSON.stringify({ enabled: autoUpdateEnabled.value ? '1' : '0', window }),
		})
		const payload = await unwrapOcsResponse<{ autoUpdateEnabled?: boolean, autoUpdateWindow?: string }>(response)
		autoUpdateEnabled.value = Boolean(payload.autoUpdateEnabled)
		autoUpdateWindowInput.value = payload.autoUpdateWindow || window
		savedAutoUpdateEnabled.value = autoUpdateEnabled.value
		savedAutoUpdateWindow.value = autoUpdateWindowInput.value
		autoUpdateSettingsNotice.value = t('versioniq', 'Automatic update settings saved.')
	} catch (e) {
		autoUpdateSettingsError.value = e instanceof Error ? e.message : t('versioniq', 'Could not save automatic update settings.')
	} finally {
		isSavingAutoUpdateSettings.value = false
	}
}

/**
 *
 * @param appId
 * @param version
 */
function openPinDialog (appId: string, version: string): void {
	pinDialogAppId.value = appId
	pinDialogVersion.value = version
	isPinDialogOpen.value = true
}

/**
 *
 * @param pin
 */
function onPinned (pin: PinRecord): void {
	pins.value = { ...pins.value, [pin.appId]: pin }
}

/**
 *
 * @param appId
 * @param pin
 */
function onPinDriftUpdated (appId: string, pin: PinRecord | null): void {
	const next = { ...pins.value }
	if (pin) {
		next[appId] = pin
	} else {
		delete next[appId]
	}
	pins.value = next
}

/**
 *
 * @param appId
 */
async function unpinApp (appId: string): Promise<void> {
	try {
		await ensurePasswordConfirmation()
		const response = await fetch(apiUrl(withOcsJson(`/ocs/v2.php/apps/versioniq/api/app/${encodeURIComponent(appId)}/pin`)), {
			method: 'DELETE',
			headers: { ...ocsHeaders, Accept: 'application/json', 'Content-Type': 'application/json' },
		})
		await unwrapOcsResponse(response)
		const next = { ...pins.value }
		delete next[appId]
		pins.value = next
	} catch (e) {
		errorMessage.value = e instanceof Error ? e.message : t('versioniq', 'Could not unpin this app.')
	}
}

/**
 *
 */
function resetSelectedAppState (): void {
	versions.value = []
	selectedVersion.value = ''
	versionFilter.value = ''
	hasCheckedVersions.value = false
	installedVersion.value = ''
	availableSource.value = ''
	lastInstallDebug.value = []
	lastInstallResult.value = null
	hasInstallResult.value = false
	appDetailTab.value = 'versions'
}

/**
 *
 * @param preserveInstallResult
 */
async function checkVersions (preserveInstallResult = false): Promise<void> {
	const appId = selectedApp.value.trim()
	versions.value = []
	selectedVersion.value = ''
	versionFilter.value = ''
	hasCheckedVersions.value = false
	if (!preserveInstallResult) {
		lastInstallDebug.value = []
		lastInstallResult.value = null
		hasInstallResult.value = false
	}

	if (!appId) {
		return
	}

	isCheckingVersions.value = true
	errorMessage.value = ''
	availableSource.value = ''
	installedVersion.value = ''

	try {
		const url = withOcsJson(`/ocs/v2.php/apps/versioniq/api/app/${encodeURIComponent(appId)}/versions`)
		const response = await fetch(apiUrl(url), { headers: { ...ocsHeaders, Accept: 'application/json' } })
		const payload = await unwrapOcsResponse<{
			availableVersions?: AppVersion[]
			versions?: AppVersion[]
			installedVersion: string | null
			source?: string
			sourceId?: string
			error?: string
		}>(response)
		versions.value = payload.availableVersions || payload.versions || []
		installedVersion.value = payload.installedVersion || ''
		availableSource.value = payload.sourceId || payload.source || ''
		errorMessage.value = payload.error ?? ''
		hasCheckedVersions.value = true
	} catch (error) {
		errorMessage.value = error instanceof Error ? error.message : 'Could not fetch app versions.'
		availableSource.value = ''
	} finally {
		isCheckingVersions.value = false
	}
}

/**
 *
 * @param version
 */
function isDowngradeBlockedBySafeMode (version: string): boolean {
	if (!isSafeMode.value || !installedVersion.value || !version) {
		return false
	}
	return compareVersions(version, installedVersion.value) < 0
}

/**
 *
 */
async function ensurePasswordConfirmation (): Promise<void> {
	const windowOC = window as Window & {
		OC?: {
			PasswordConfirmation?: {
				requiresPasswordConfirmation?: () => boolean
				requirePasswordConfirmation?: (callback: () => void, options?: unknown, rejectCallback?: (error: Error) => void) => void
			}
		}
	}

	const passwordConfirmation = windowOC.OC?.PasswordConfirmation
	if (!passwordConfirmation?.requirePasswordConfirmation) {
		return
	}

	if (passwordConfirmation.requiresPasswordConfirmation && !passwordConfirmation.requiresPasswordConfirmation()) {
		return
	}

	await new Promise<void>((resolve, reject) => {
		passwordConfirmation.requirePasswordConfirmation(
			() => resolve(),
			undefined,
			() => reject(new Error('Password confirmation was cancelled')),
		)
	})
}

/**
 *
 * @param appId
 */
function onSelectApp (appId: string) {
	selectedApp.value = appId
	resetSelectedAppState()
}

// A source was (re)bound via the Sources panel; refresh versions if that app is selected.
/**
 *
 * @param appId
 */
async function onPanelBound (appId: string): Promise<void> {
	if (selectedApp.value === appId) {
		await checkVersions(true)
	}
}

/**
 *
 * @param appId
 */
async function onPickApp (appId: string) {
	if (!appId || isCheckingVersions.value || isInstallingVersion.value) {
		return
	}

	onSelectApp(appId)
	await checkVersions()
}

/**
 *
 */
function clearSelectedApp () {
	selectedApp.value = ''
	errorMessage.value = ''
	resetSelectedAppState()
}

const filteredApps = computed(() => {
	const filter = appFilter.value.trim().toLowerCase()
	const visibleApps = coreAppsVisibility.value === 'hide'
		? apps.value.filter((app) => !app.isCore)
		: apps.value

	if (filter === '') {
		return visibleApps
	}

	return visibleApps.filter((app) => {
		return app.label.toLowerCase().includes(filter) || app.id.toLowerCase().includes(filter)
	})
})

const selectedAppOption = computed(() => {
	return apps.value.find((app) => app.id === selectedApp.value) ?? null
})

/**
 *
 * @param app
 */
function appCardDescription (app: AppOption): string {
	return app.summary || app.description || 'No description available.'
}

/**
 *
 * @param app
 */
function appCardFallback (app: AppOption): string {
	const source = (app.label || app.id).trim()
	if (source === '') {
		return '?'
	}

	return source.charAt(0).toUpperCase()
}

const filteredVersions = computed(() => {
	const filter = versionFilter.value.trim().toLowerCase()
	const list = isSafeMode.value && installedVersion.value
		? versions.value.filter((version) => !isDowngradeBlockedBySafeMode(version.version))
		: versions.value

	if (filter === '') {
		return list
	}

	return list.filter((version) => version.version.toLowerCase().includes(filter))
})

const visibleVersions = computed(() => {
	if (selectedVersion.value) {
		const selected = versions.value.find((version) => version.version === selectedVersion.value)
		return selected ? [selected] : []
	}

	return filteredVersions.value
})

/**
 *
 * @param value
 */
function debugValueToString (value: unknown): string {
	if (value === null) {
		return 'null'
	}

	if (typeof value === 'string') {
		return value
	}

	if (typeof value === 'number' || typeof value === 'boolean') {
		return String(value)
	}

	if (typeof value === 'bigint') {
		return value.toString()
	}

	return JSON.stringify(value)
}

/**
 *
 * @param value
 * @param depth
 */
function formatDebugLines (value: unknown, depth = 0): string[] {
	const indent = ' '.repeat(depth * 2)
	const lines: string[] = []

	if (value === null || value === undefined) {
		lines.push(`${indent}—`)
		return lines
	}

	if (Array.isArray(value)) {
		if (value.length === 0) {
			lines.push(`${indent}[]`)
			return lines
		}

		value.forEach((entry, index) => {
			if (entry === null || entry === undefined) {
				lines.push(`${indent}[${index}]: —`)
				return
			}

			if (typeof entry === 'object') {
				lines.push(`${indent}[${index}]:`)
				lines.push(...formatDebugLines(entry, depth + 1))
			} else {
				lines.push(`${indent}[${index}]: ${debugValueToString(entry)}`)
			}
		})

		return lines
	}

	if (typeof value === 'object') {
		const objectValue = value as Record<string, unknown>
		const keys = Object.keys(objectValue)
		if (keys.length === 0) {
			lines.push(`${indent}{}`)
			return lines
		}

		for (const key of keys) {
			const nested = objectValue[key]
			if (nested === null || nested === undefined) {
				lines.push(`${indent}${key}: —`)
				continue
			}

			if (typeof nested === 'object') {
				lines.push(`${indent}${key}:`)
				lines.push(...formatDebugLines(nested, depth + 1))
				continue
			}

			lines.push(`${indent}${key}: ${debugValueToString(nested)}`)
		}
		return lines
	}

	lines.push(`${indent}${debugValueToString(value)}`)
	return lines
}

/**
 *
 * @param value
 */
function debugHasData (value: unknown): boolean {
	if (value === null || value === undefined) {
		return false
	}

	if (typeof value === 'string') {
		return value.trim() !== ''
	}

	return true
}

/**
 *
 * @param value
 */
function debugToTextLines (value: unknown): string[] {
	const lines = formatDebugLines(value)
	if (lines.length === 0) {
		return ['—']
	}

	return lines
}

/**
 *
 * @param from
 * @param to
 */
function getVersionRangeSummary (from: string, to: string): VersionRangeInfo | null {
	if (!from || !to || from === to) {
		return null
	}

	const direction = compareVersions(to, from)
	const comparison = direction === 0 ? 0 : (direction > 0 ? 1 : -1)
	const low = comparison <= 0 ? to : from
	const high = comparison <= 0 ? from : to

	const inRange = versions.value.filter((entry) => {
		return compareVersions(entry.version, low) >= 0 && compareVersions(entry.version, high) <= 0
	})

	const majors = new Set<number>()
	const minors = new Set<string>()
	for (const entry of inRange) {
		const parsed = parseVersionCore(entry.version)
		majors.add(parsed.major)
		minors.add(`${parsed.major}.${parsed.minor}`)
	}

	const fromParsed = parseVersionCore(low)
	const toParsed = parseVersionCore(high)
	const patch = fromParsed.major === toParsed.major && fromParsed.minor === toParsed.minor
		? Math.abs(toParsed.patch - fromParsed.patch)
		: 0

	if (majors.size === 0) {
		const major = Math.abs(toParsed.major - fromParsed.major)
		const minor = comparison === 0
			? 0
			: (toParsed.major === fromParsed.major ? Math.abs(toParsed.minor - fromParsed.minor) : Math.abs(toParsed.minor - fromParsed.minor))

		return {
			major,
			minor,
			patch,
			direction: comparison > 0 ? 'upgrade' : 'degrade',
			from,
			to,
		}
	}

	return {
		major: Math.max(0, majors.size - 1),
		minor: Math.max(0, minors.size - 1),
		patch,
		direction: comparison > 0 ? 'upgrade' : 'degrade',
		from,
		to,
	}
}

const selectedVersionRange = computed(() => {
	if (!installedVersion.value || !selectedVersion.value || installedVersion.value === selectedVersion.value) {
		return null
	}

	return getVersionRangeSummary(installedVersion.value, selectedVersion.value)
})

const downgradeVersionRange = computed(() => getVersionRangeSummary(downgradeConfirmFromVersion.value, downgradeConfirmToVersion.value))

// Aggregate "changes between installed → target" panel — reuses the
// already-fetched `versions` array, zero extra requests; see "Aggregate
// range changelog on target selection".
const changelogRange = computed(() => buildChangelogRange(installedVersion.value, selectedVersion.value, versions.value))

/**
 *
 * @param summary
 */
function versionRangeText (summary: VersionRangeInfo | null): string {
	if (!summary) {
		return ''
	}

	if (summary.major === 0 && summary.minor === 0 && summary.patch > 0) {
		return `${summary.direction === 'upgrade' ? 'Upgrade' : 'Downgrade'} stays within major/minor and changes ${summary.patch} patch version step${summary.patch === 1 ? '' : 's'}.`
	}

	return `${summary.direction === 'upgrade' ? 'Upgrade' : 'Downgrade'} crosses ${summary.major} major and ${summary.minor} minor version step${summary.minor === 1 ? '' : 's'}.`
}

// Previews the migration diff for a downgrade via a dry-run install request
// before the confirmation dialog opens, so the dialog can name the exact
// migrations the target lacks instead of only warning generically; see
// "Migration diff on downgrade". The dry-run evaluates and reports the
// downgrade guard without requiring `allowDowngrade` — see "Server-side
// downgrade guard". A request failure degrades to `null` (generic warning),
// consistent with a server-side diff failure — it never blocks the downgrade.
/**
 *
 * @param appId
 * @param version
 */
async function fetchDowngradePreview (appId: string, version: string): Promise<string[] | null> {
	try {
		const { payload } = await requestInstall(appId, version, undefined, false, false, false, true)

		return Array.isArray(payload.orphanedMigrations) ? payload.orphanedMigrations : null
	} catch {
		return null
	}
}

/**
 *
 * @param appId
 * @param fromVersion
 * @param toVersion
 */
async function confirmDowngrade (appId: string, fromVersion: string, toVersion: string): Promise<boolean> {
	if (downgradeResolve) {
		downgradeResolve(false)
		downgradeResolve = null
	}

	downgradeConfirmApp.value = appId
	downgradeConfirmFromVersion.value = fromVersion
	downgradeConfirmToVersion.value = toVersion
	downgradeOrphanedMigrations.value = await fetchDowngradePreview(appId, toVersion)
	return new Promise<boolean>((resolve) => {
		downgradeResolve = resolve
		isDowngradeConfirmOpen.value = true
	})
}

// The single exit from the confirmation dialog. Cancel, the Downgrade button
// and dismissing all arrive here, so the awaiting promise is always settled —
// a dismissed dialog can never leave the install flow hanging.
/**
 *
 * @param accept
 */
function onDowngradeResolved (accept: boolean): void {
	isDowngradeConfirmOpen.value = false
	downgradeResolve?.(accept)
	downgradeResolve = null
}

/**
 *
 * @param version
 */
function onSelectVersion (version: string): void {
	if (isDowngradeBlockedBySafeMode(version)) {
		errorMessage.value = 'Safe mode is enabled. Disable it to downgrade.'
		return
	}

	selectedVersion.value = version
	errorMessage.value = ''
}

type InstallApiPayload = {
	appId: string
	toVersion: string
	fromVersion?: string
	installedVersion?: string
	updateType?: string
	message?: string
	dryRun?: boolean
	installStatus?: string
	debug?: unknown
	category?: string
	code?: string
	pinnedVersion?: string
	expectedSha?: string
	actualSha?: string
	recordedShaMatched?: boolean
	orphanedMigrations?: string[] | null
}

/**
 *
 * @param appId
 * @param version
 * @param overridePin
 * @param pinRequested
 * @param acceptNewSha
 * @param allowDowngrade
 * @param forceDryRun
 */
async function requestInstall (appId: string,
	version: string,
	overridePin?: 'repin' | 'unpin',
	pinRequested = false,
	acceptNewSha = false,
	allowDowngrade = false,
	forceDryRun = false): Promise<{ payload: InstallApiPayload, metaMessage?: string }> {
	// dryRun is sent explicitly and independently of debug — see MODIFIED
	// "Debug Mode". debug now controls diagnostic verbosity only.
	const query: Record<string, string> = {
		debug: includeDebug.value ? '1' : '0',
		dryRun: (forceDryRun || dryRunRequested.value) ? '1' : '0',
		targetVersion: version,
	}
	if (overridePin) {
		query.overridePin = overridePin
	}
	if (pinRequested) {
		query.pin = '1'
	}
	if (acceptNewSha) {
		query.acceptNewSha = '1'
	}
	if (allowDowngrade) {
		query.allowDowngrade = '1'
	}
	const endpoint = withOcsJson(
		`/ocs/v2.php/apps/versioniq/api/app/${encodeURIComponent(appId)}/versions/${encodeURIComponent(version)}/install`,
		query,
	)
	const response = await fetch(apiUrl(endpoint), {
		method: 'POST',
		headers: {
			...ocsHeaders,
			Accept: 'application/json',
			'Content-Type': 'application/json',
		},
		body: JSON.stringify({ version }),
	})

	return unwrapOcsResponseWithMeta<InstallApiPayload>(response)
}

// Offers Re-pin / Unpin-and-install / Cancel when the install endpoint
// refuses to overwrite a pin (409); see "Pins are enforced on Versioniq's
// own install path".
/**
 *
 * @param appId
 * @param pinnedVersion
 * @param targetVersion
 */
function confirmPinOverride (appId: string, pinnedVersion: string, targetVersion: string): Promise<'repin' | 'unpin' | 'cancel'> {
	if (pinOverrideResolve) {
		pinOverrideResolve('cancel')
		pinOverrideResolve = null
	}

	pinOverrideAppId.value = appId
	pinOverridePinnedVersion.value = pinnedVersion
	pinOverrideTargetVersion.value = targetVersion

	return new Promise((resolve) => {
		pinOverrideResolve = resolve
		isPinOverrideDialogOpen.value = true
	})
}

/**
 *
 * @param choice
 */
function onPinOverrideResolve (choice: 'repin' | 'unpin' | 'cancel'): void {
	pinOverrideResolve?.(choice)
	pinOverrideResolve = null
}

// Offers "Accept new checksum and install" / Cancel when the install endpoint
// refuses to reinstall because the downloaded artifact does not match the
// SHA-256 recorded at first install (422, code "sha_mismatch"); see
// "Recorded SHA-256 enforced on reinstall".
/**
 *
 * @param appId
 * @param version
 * @param expectedSha
 * @param actualSha
 */
function confirmShaMismatch (appId: string, version: string, expectedSha: string, actualSha: string): Promise<boolean> {
	if (shaMismatchResolve) {
		shaMismatchResolve(false)
		shaMismatchResolve = null
	}

	shaMismatchAppId.value = appId
	shaMismatchVersion.value = version
	shaMismatchExpectedSha.value = expectedSha
	shaMismatchActualSha.value = actualSha

	return new Promise((resolve) => {
		shaMismatchResolve = resolve
		isShaMismatchDialogOpen.value = true
	})
}

/**
 *
 * @param accept
 */
function onShaMismatchResolve (accept: boolean): void {
	shaMismatchResolve?.(accept)
	shaMismatchResolve = null
}

// Re-pin from the drift banner reinstalls the pinned version through this
// same install path (source resolution, allowlist, integrity checks all
// apply) — see "Re-pin reinstalls the pinned version". No override is
// needed: the target equals the pin's own version.
/**
 *
 * @param appId
 * @param version
 */
async function onRepinRequested (appId: string, version: string): Promise<void> {
	if (isInstallingVersion.value) {
		return
	}
	isInstallingVersion.value = true
	errorMessage.value = ''
	try {
		await ensurePasswordConfirmation()
		const { metaMessage } = await requestInstall(appId, version)
		if (metaMessage) {
			errorMessage.value = metaMessage
		}
		await loadPins()
		if (selectedApp.value === appId) {
			await checkVersions(true)
		}
	} catch (e) {
		errorMessage.value = e instanceof Error ? e.message : t('versioniq', 'Could not re-pin this app.')
	} finally {
		isInstallingVersion.value = false
	}
}

/**
 *
 */
async function performInstall (): Promise<void> {
	if (!selectedApp.value || !selectedVersion.value || isInstallingVersion.value) {
		return
	}

	const selectedAppValue = selectedApp.value
	const selectedVersionValue = selectedVersion.value
	const requestedFromVersion = installedVersion.value
	const requestedToVersion = selectedVersionValue
	const isDowngrade = installedVersion.value !== '' && compareVersions(selectedVersionValue, installedVersion.value) < 0

	if (isDowngrade) {
		const proceed = await confirmDowngrade(selectedAppValue, installedVersion.value, selectedVersionValue)

		if (!proceed) {
			return
		}
	}

	isInstallingVersion.value = true
	errorMessage.value = ''
	hasInstallResult.value = false
	lastInstallResult.value = null
	lastInstallDebug.value = []
	installRequestFromVersion.value = requestedFromVersion
	installRequestToVersion.value = requestedToVersion

	try {
		await ensurePasswordConfirmation()

		// Acknowledged above via confirmDowngrade(); carried on every retry
		// below so a pin-override or SHA-accept round trip doesn't re-trip the
		// server-side downgrade guard — see "Server-side downgrade guard".
		let installResponse = await requestInstall(selectedAppValue, selectedVersionValue, undefined, false, false, isDowngrade)

		if (installResponse.metaMessage && installResponse.payload?.category === 'pinned') {
			const choice = await confirmPinOverride(selectedAppValue, installResponse.payload.pinnedVersion || '', selectedVersionValue)
			if (choice === 'cancel') {
				hasInstallResult.value = false
				return
			}
			installResponse = await requestInstall(selectedAppValue, selectedVersionValue, choice, false, false, isDowngrade)
		}

		if (installResponse.metaMessage && installResponse.payload?.code === 'sha_mismatch') {
			const accept = await confirmShaMismatch(
				selectedAppValue,
				selectedVersionValue,
				installResponse.payload.expectedSha || '',
				installResponse.payload.actualSha || '',
			)
			if (!accept) {
				hasInstallResult.value = false
				return
			}
			installResponse = await requestInstall(selectedAppValue, selectedVersionValue, undefined, false, true, isDowngrade)
		}

		const { payload, metaMessage } = installResponse
		const result = normalizeInstallResult(payload)
		const requestedFrom = installRequestFromVersion.value
		const requestedTo = installRequestToVersion.value
		lastInstallResult.value = {
			...result,
			fromVersion: result.fromVersion ?? requestedFrom,
			toVersion: result.toVersion && requestedTo && result.toVersion === requestedFrom && requestedTo !== requestedFrom
				? requestedTo
				: result.toVersion || requestedTo,
			installedVersion: result.installedVersion ?? requestedFrom,
		}
		lastInstallDebug.value = result.debug ?? []
		hasInstallResult.value = true

		if (metaMessage) {
			// Failure: prefer the structured backend payload over the generic
			// OCS meta message. Show the actionable hint first, then the
			// "what happened" message; fall back to metaMessage only when the
			// backend supplied neither. Preserve the backend installStatus
			// (e.g. reverted / installed-but-broken) instead of forcing failed.
			const structured = lastInstallResult.value
			const backendMessage = structured && structured.message && structured.message !== 'Install completed.'
				? structured.message
				: ''
			const hint = structured?.hint || ''
			errorMessage.value = hint || backendMessage || metaMessage
			if (structured) {
				lastInstallResult.value = {
					...structured,
					message: backendMessage || metaMessage,
					installStatus: structured.installStatus || 'failed',
				}
			}
		} else {
			selectedApp.value = ''
			installedVersion.value = ''
			availableSource.value = ''
			selectedVersion.value = ''
			await checkVersions(true)
			await loadPins()
		}
	} catch (error) {
		errorMessage.value = error instanceof Error ? error.message : 'Could not install selected version.'
		hasInstallResult.value = true
		lastInstallResult.value = {
			appId: selectedAppValue,
			fromVersion: requestedFromVersion || null,
			toVersion: requestedToVersion,
			message: errorMessage.value,
			dryRun: false,
			installStatus: 'failed',
			updateType: 'none',
		}
	} finally {
		isInstallingVersion.value = false
	}
}

// "Roll back to last known good" — pure client routing through the standard
// install flow: select the app, pick the recorded lkg version, and let
// performInstall() run its normal downgrade confirmation (migration diff
// preview + dialog) and the server-side downgrade guard. No special install
// path; see "Last-known-good version record" — Scenario "One-click rollback
// target". The safe-mode auto-clear watcher is suppressed for this
// programmatic selection since a rollback target is, by construction, an
// intentional downgrade.
/**
 *
 * @param appId
 * @param version
 */
async function rollbackToLastKnownGood (appId: string, version: string): Promise<void> {
	if (isInstallingVersion.value || isCheckingVersions.value) {
		return
	}

	onSelectApp(appId)
	await checkVersions()

	suppressSafeModeAutoClear.value = true
	try {
		selectedVersion.value = version
		errorMessage.value = ''
		await performInstall()
	} finally {
		suppressSafeModeAutoClear.value = false
	}
}

onMounted(async () => {
	const storedSafeMode = readStoredFlag(safeModeStorageKey)
	if (storedSafeMode !== null) {
		safeModeEnabled.value = storedSafeMode !== 'false'
	}
	const storedDebugMode = readStoredFlag(debugModeStorageKey)
	if (storedDebugMode !== null) {
		debugModeEnabled.value = storedDebugMode === 'true'
	}
	const storedDryRunMode = readStoredFlag(dryRunStorageKey)
	if (storedDryRunMode !== null) {
		dryRunEnabled.value = storedDryRunMode === 'true'
	}

	// Access is enforced server-side: the page is an admin-only ISettings
	// section and every OCS endpoint guards on isAdmin(). No client-side admin
	// probe is needed — load the data directly so a flaky probe can never blank
	// out the panel for a confirmed admin.
	try {
		await checkUpdateChannel()
		await loadApps()
	} catch (error) {
		// A `finally` WITHOUT a `catch` re-throws, and the three non-blocking
		// loaders below are plain statements in the same function — so anything
		// thrown here silently skipped ALL of them.
		//
		// Measured on CI (issue #160): the browser requested update-channel and
		// apps, then NOTHING — no /api/pins, no /api/advisories, no
		// /api/policies. `pins` therefore stayed `{}` and the app-card badge's
		// `v-if="pinFor(app.id)"` never matched, which is why
		// pinning.spec.ts:70 failed on every run since the suite began running.
		//
		// Catching does not depend on knowing WHAT throws: whatever it is, the
		// page must still load its pins, advisories and policies. The message is
		// surfaced rather than swallowed so the underlying throw stays visible.
		errorMessage.value = error instanceof Error ? error.message : 'Could not initialise the app list.'
	} finally {
		isLoading.value = false
	}
	// Kick off advisory correlation, pin state, and auto-update policies after
	// the list renders (non-blocking). Each handles its own failures; the extra
	// catch guards a SYNCHRONOUS throw before the first await, which would
	// otherwise take the following calls down with it.
	//
	// ⚠️ These three do NOT currently complete — see issue #160. Traced with
	// console markers: execution demonstrably reaches this line and all three
	// are invoked, yet none reaches its success path or its catch, so each is
	// still parked on its `await fetch`. Consequence: pin badges, advisory
	// badges and auto-update policy state are silently absent.
	void loadAdvisories().catch(() => undefined)
	void loadPins().catch(() => undefined)
	void loadPolicies().catch(() => undefined)
	void loadAdvisorySettings().catch(() => undefined)
})

watch([safeModeEnabled, installedVersion, selectedVersion], () => {
	if (typeof window === 'undefined') {
		return
	}

	window.localStorage?.setItem(safeModeStorageKey, safeModeEnabled.value ? 'true' : 'false')

	if (suppressSafeModeAutoClear.value) {
		return
	}

	if (safeModeEnabled.value && isDowngradeBlockedBySafeMode(selectedVersion.value)) {
		selectedVersion.value = ''
	}
}, { deep: false })

watch(debugModeEnabled, () => {
	if (typeof window === 'undefined') {
		return
	}

	window.localStorage?.setItem(debugModeStorageKey, debugModeEnabled.value ? 'true' : 'false')
})

watch(dryRunEnabled, () => {
	if (typeof window === 'undefined') {
		return
	}

	window.localStorage?.setItem(dryRunStorageKey, dryRunEnabled.value ? 'true' : 'false')
})
</script>

<template>
	<div :class="$style.section">
		<!--
			SKIP LINK (WCAG 2.2 AA 2.4.1 Bypass Blocks).

			This page is long — app list, version list, history, sources, tokens,
			policies — and every one of those panels sits between the top of the
			document and the content a keyboard user usually wants. Without a
			bypass they tab through all of it on every visit.

			Not <NcContent>: that is the shell for a full app with its own
			navigation sidebar, and this view is rendered inside Nextcloud's
			settings page, which already provides one. Wrapping in NcContent here
			would nest a second app shell inside the first.

			Visible only on focus, so it costs sighted mouse users nothing and
			appears exactly when a keyboard user reaches it.
		-->
		<a :class="$style.skipLink" href="#versioniq-main">
			{{ t('versioniq', 'Skip to main content') }}
		</a>
		<div id="versioniq-main" :class="$style.content">
			<DowngradeConfirmDialog
				:open="isDowngradeConfirmOpen"
				:appId="downgradeConfirmApp"
				:fromVersion="downgradeConfirmFromVersion"
				:toVersion="downgradeConfirmToVersion"
				:rangeText="downgradeVersionRange ? versionRangeText(downgradeVersionRange) : ''"
				:orphanedMigrations="downgradeOrphanedMigrations"
				:busy="isInstallingVersion"
				@update:open="isDowngradeConfirmOpen = $event"
				@resolve="onDowngradeResolved" />
			<PinDialog
				:open="isPinDialogOpen"
				:appId="pinDialogAppId"
				:version="pinDialogVersion"
				@update:open="isPinDialogOpen = $event"
				@pinned="onPinned" />
			<PinOverrideDialog
				:open="isPinOverrideDialogOpen"
				:appId="pinOverrideAppId"
				:pinnedVersion="pinOverridePinnedVersion"
				:targetVersion="pinOverrideTargetVersion"
				@update:open="isPinOverrideDialogOpen = $event"
				@resolve="onPinOverrideResolve" />
			<ShaMismatchDialog
				:open="isShaMismatchDialogOpen"
				:appId="shaMismatchAppId"
				:version="shaMismatchVersion"
				:expectedSha="shaMismatchExpectedSha"
				:actualSha="shaMismatchActualSha"
				@update:open="isShaMismatchDialogOpen = $event"
				@resolve="onShaMismatchResolve" />
			<h2>{{ t('versioniq', 'Versioniq') }}</h2>
			<div :class="$style.well">
				<div ref="tablistEl"
					:class="$style.tabs"
					role="tablist"
					:aria-label="t('versioniq', 'Versioniq sections')"
					@keydown="onTabKeydown">
					<NcButton v-for="tab in tabs"
						:id="`${tab.id}-tab`"
						:key="tab.id"
						role="tab"
						:aria-selected="currentTab === tab.id ? 'true' : 'false'"
						:aria-controls="`${tab.id}-panel`"
						:tabindex="currentTab === tab.id ? 0 : -1"
						:variant="currentTab === tab.id ? 'primary' : 'tertiary'"
						@click="currentTab = tab.id">
						{{ tabLabel(tab.id) }}
					</NcButton>
				</div>
				<p v-show="currentTab === 'apps'"
					:class="$style.advisoryFreshness"
					data-testid="advisory-freshness">
					{{ advisoryFreshnessLabel }}
				</p>
				<div v-show="currentTab === 'apps'"
					id="apps-panel"
					role="tabpanel"
					aria-labelledby="apps-tab"
					:class="$style.layout">
					<main :class="$style.mainContent">
						<div :class="$style.settingsPanel">
							<p v-if="updateChannel" :class="$style.updateChannel">
								Update channel: <strong>{{ updateChannel }}</strong>
							</p>
							<div :class="$style.settingsToggles">
								<label :class="$style.safeMode">
									<input
										v-model="safeModeEnabled"
										type="checkbox"
										:class="$style.safeModeCheckbox"
										:disabled="isInstallingVersion">
									<span>Safe mode (block downgrades and respects update channel)</span>
								</label>
								<label :class="$style.safeMode">
									<input
										v-model="dryRunEnabled"
										type="checkbox"
										:class="$style.safeModeCheckbox"
										:disabled="isInstallingVersion">
									<span>Dry run (evaluate the install, apply no changes)</span>
								</label>
								<label :class="$style.safeMode">
									<input
										v-model="debugModeEnabled"
										type="checkbox"
										:class="$style.safeModeCheckbox"
										:disabled="isInstallingVersion">
									<span>Show install debug output</span>
								</label>
							</div>
							<div :class="$style.autoUpdateSettings" data-testid="auto-update-settings">
								<h3>{{ t('versioniq', 'Automatic updates') }}</h3>
								<p :class="$style.hint">
									{{ t('versioniq', 'When enabled, Versioniq installs qualifying newer versions per app policy during the nightly window, honoring pins and reporting every outcome.') }}
								</p>
								<label :class="$style.safeMode">
									<input
										v-model="autoUpdateEnabled"
										type="checkbox"
										:class="$style.safeModeCheckbox"
										data-testid="auto-update-kill-switch"
										:disabled="isSavingAutoUpdateSettings">
									<span>{{ t('versioniq', 'Enable automatic updates') }}</span>
								</label>
								<label :class="$style.filterField" for="auto-update-window">
									<span :class="$style.filterFieldLabel">{{ t('versioniq', 'Update window (HH:MM-HH:MM, server time)') }}</span>
									<input
										id="auto-update-window"
										v-model="autoUpdateWindowInput"
										type="text"
										data-testid="auto-update-window"
										placeholder="01:00-05:00"
										:class="$style.appFilterInput"
										:disabled="isSavingAutoUpdateSettings">
								</label>
								<p v-if="autoUpdateWindowInput.trim() !== '' && !isAutoUpdateWindowValid" :class="$style.autoUpdateWindowError" data-testid="auto-update-window-error">
									{{ t('versioniq', 'Use the HH:MM-HH:MM format, e.g. 01:00-05:00.') }}
								</p>
								<p v-if="autoUpdateSettingsError" :class="$style.autoUpdateWindowError">
									{{ autoUpdateSettingsError }}
								</p>
								<p v-if="autoUpdateSettingsNotice" :class="$style.autoUpdateSettingsNotice">
									{{ autoUpdateSettingsNotice }}
								</p>
								<NcButton
									variant="primary"
									data-testid="auto-update-settings-save"
									:disabled="isSavingAutoUpdateSettings || !isAutoUpdateSettingsDirty || !isAutoUpdateWindowValid"
									@click="saveAutoUpdateSettings">
									{{ t('versioniq', 'Save') }}
								</NcButton>

								<h3 :class="$style.advisorySettingsHeading">{{ t('versioniq', 'Security advisory checks') }}</h3>
								<p :class="$style.hint">
									{{ t('versioniq', 'Versioniq checks published Nextcloud security advisories against your installed versions and notifies administrators immediately when an installed version is affected.') }}
								</p>
								<label :class="$style.filterField" for="advisory-interval">
									<span :class="$style.filterFieldLabel">
										{{ t('versioniq', 'Check every (hours, {min}-{max})', { min: advisoryMinInterval, max: advisoryMaxInterval }) }}
									</span>
									<input
										id="advisory-interval"
										v-model="advisoryIntervalInput"
										type="number"
										:min="advisoryMinInterval"
										:max="advisoryMaxInterval"
										data-testid="advisory-interval"
										:class="$style.appFilterInput"
										:disabled="isSavingAdvisorySettings">
								</label>
								<p v-if="!isAdvisoryIntervalValid" :class="$style.autoUpdateWindowError" data-testid="advisory-interval-error">
									{{ t('versioniq', 'Enter a whole number of hours between {min} and {max}.', { min: advisoryMinInterval, max: advisoryMaxInterval }) }}
								</p>
								<label :class="$style.safeMode">
									<input
										v-model="advisoryDigestEnabled"
										type="checkbox"
										data-testid="advisory-digest-enabled"
										:disabled="isSavingAdvisorySettings">
									<span>{{ t('versioniq', 'Send a weekly digest of non-urgent advisories') }}</span>
								</label>
								<p v-if="advisorySettingsError" :class="$style.autoUpdateWindowError" data-testid="advisory-settings-error">
									{{ advisorySettingsError }}
								</p>
								<p v-if="advisorySettingsNotice" :class="$style.autoUpdateSettingsNotice" data-testid="advisory-settings-notice">
									{{ advisorySettingsNotice }}
								</p>
								<NcButton
									variant="primary"
									data-testid="advisory-settings-save"
									:disabled="isSavingAdvisorySettings || !isAdvisorySettingsDirty || !isAdvisoryIntervalValid"
									@click="saveAdvisorySettings">
									{{ t('versioniq', 'Save') }}
								</NcButton>
							</div>
						</div>
						<div :class="[$style.contentRow, { [$style.contentRowSplit]: hasSplitLayout }]">
							<div :class="[$style.leftColumn, { [$style.leftColumnFull]: !hasSplitLayout }]">
								<div :class="$style.selectSection">
									<label :class="$style.label" for="app-filter">Pick an installed App</label>
									<div :class="$style.filterToolbar">
										<button
											type="button"
											:class="$style.filterToggleButton"
											@click="showFilters = !showFilters">
											{{ showFilters ? 'Hide filters' : 'Show filters' }}
										</button>
									</div>
									<div v-if="showFilters" :class="$style.filterPanel">
										<label :class="$style.filterField">
											<span :class="$style.filterFieldLabel">Core apps</span>
											<select v-model="coreAppsVisibility" :class="$style.filterSelect">
												<option value="show">Show core apps</option>
												<option value="hide">Hide core apps</option>
											</select>
										</label>
									</div>
									<input
										id="app-filter"
										v-model="appFilter"
										type="text"
										placeholder="Search apps"
										:class="$style.appFilterInput"
										:disabled="!hasSidebarSelect || isLoading || apps.length === 0 || isCheckingVersions || isInstallingVersion"
										:aria-label="sidebarLabel">
									<div
										v-if="!selectedApp"
										:class="[$style.appCardList, { [$style.appCardListSplit]: hasSplitLayout }]">
										<article
											v-for="app in filteredApps"
											:key="app.id"
											:data-app-id="app.id"
											:class="[$style.appCard, { [$style.appCardSelected]: selectedApp === app.id, [$style.appCardCore]: app.isCore }]">
											<div :class="$style.appCardBody">
												<div :class="$style.appCardHeader">
													<div :class="$style.appCardTitleBlock">
														<div :class="$style.appCardTitleRow">
															<p :class="$style.appCardTitle">
																{{ app.label }}
															</p>
															<span v-if="app.isCore" :class="$style.appCardCoreFlag">CORE</span>
															<span
																v-if="pinFor(app.id)"
																:class="$style.pinBadge"
																data-testid="pin-badge"
																:title="pinTooltip(pinFor(app.id))">
																📌 {{ t('versioniq', 'Pinned {version}', { version: pinFor(app.id)?.version ?? '' }) }}
															</span>
															<span
																v-if="advisoryFor(app.id)?.state && advisoryFor(app.id)?.state !== 'none'"
																:class="[$style.advisoryBadge, { [$style.advisoryBadgeVulnerable]: advisoryFor(app.id)?.state === 'pinned-to-vulnerable' }]"
																:title="advisoryFor(app.id)?.advisories?.[0]?.summary ?? ''">
																⚠ {{ advisoryBadgeLabel(advisoryFor(app.id)?.state ?? 'none') }}
															</span>
														</div>
														<p :class="$style.appCardMeta">
															{{ app.id }}
														</p>
														<p
															v-if="advisoryFor(app.id)?.state === 'pinned-to-vulnerable'"
															:class="$style.advisoryDetail">
															{{ advisoryFor(app.id)?.advisories?.[0]?.id ?? '' }}
															<template v-if="advisoryFor(app.id)?.recommendedVersion">
																· {{ t('versioniq', 'safe version: {version}', { version: advisoryFor(app.id)?.recommendedVersion ?? '' }) }}
															</template>
														</p>
													</div>
													<div :class="$style.appCardMedia">
														<img
															v-if="app.preview"
															:src="app.preview"
															:alt="`${app.label} icon`"
															:class="$style.appCardIcon">
														<div v-else :class="$style.appCardFallbackIcon" aria-hidden="true">
															{{ appCardFallback(app) }}
														</div>
													</div>
												</div>
												<p :class="$style.appCardDescription">
													{{ appCardDescription(app) }}
												</p>
												<p
													v-if="app.warning"
													:class="[$style.appCardWarning, { [$style.appCardWarningBlocking]: app.manageable === false }]">
													⚠ {{ app.warning }}
												</p>
												<PolicySelector
													v-if="!app.isCore"
													:appId="app.id"
													:level="policyLevelFor(app.id)"
													:autoUpdateEnabled="autoUpdateEnabled"
													:disabled="isSavingPolicy"
													@change="onPolicyChange" />
											</div>
											<button
												v-if="!app.isCore"
												type="button"
												:class="$style.appCardButton"
												:disabled="isCheckingVersions || isInstallingVersion"
												@click="onPickApp(app.id)">
												{{ selectedApp === app.id && isCheckingVersions ? 'Loading…' : 'Choose app' }}
											</button>
											<button
												v-if="!app.isCore && app.lkg && shouldOfferLkgRollback(app)"
												type="button"
												:class="$style.appCardButton"
												:disabled="isCheckingVersions || isInstallingVersion"
												:title="t('versioniq', 'Roll back to the last version that finalized cleanly through Versioniq')"
												@click="rollbackToLastKnownGood(app.id, app.lkg.version)">
												{{ t('versioniq', 'Roll back to {version}', { version: app.lkg.version }) }}
											</button>
										</article>
									</div>
									<p v-if="!selectedApp && filteredApps.length === 0" :class="$style.noFilterResult">
										No apps match your filter.
									</p>
								</div>
								<div
									:class="[$style.infoPanel, { [$style.infoPanelOpen]: hasInfoPanel }]">
									<div v-if="selectedApp || installedVersion" :class="$style.installed">
										<div v-if="selectedApp" :class="$style.selectedApp">
											<span :class="$style.installedLabel">Selected app</span>
											<span :class="$style.installedValue">{{ selectedAppOption?.label || selectedApp }}</span>
											<span v-if="selectedAppOption?.label && selectedAppOption.id !== selectedAppOption.label" :class="$style.installedSubvalue">{{ selectedApp }}</span>
											<button
												type="button"
												:class="$style.changeAppButton"
												:disabled="isCheckingVersions || isInstallingVersion"
												@click="clearSelectedApp">
												Choose another app
											</button>
											<div :class="$style.appDetailTabs" role="tablist" :aria-label="t('versioniq', 'App detail sections')">
												<button
													type="button"
													role="tab"
													:aria-selected="appDetailTab === 'versions' ? 'true' : 'false'"
													:class="[$style.appDetailTabButton, { [$style.appDetailTabButtonActive]: appDetailTab === 'versions' }]"
													@click="appDetailTab = 'versions'">
													{{ t('versioniq', 'Versions') }}
												</button>
												<button
													type="button"
													role="tab"
													:aria-selected="appDetailTab === 'history' ? 'true' : 'false'"
													:class="[$style.appDetailTabButton, { [$style.appDetailTabButtonActive]: appDetailTab === 'history' }]"
													@click="appDetailTab = 'history'">
													{{ t('versioniq', 'History') }}
												</button>
											</div>
										</div>
										<div v-if="installedVersion" :class="$style.installedCurrent">
											<span :class="$style.installedLabel">Current installed</span>
											<span :class="$style.installedValue">{{ installedVersion }}</span>
											<span
												v-if="pinFor(selectedApp)"
												:class="$style.pinBadge"
												data-testid="pin-badge-detail"
												:title="pinTooltip(pinFor(selectedApp))">
												📌 {{ t('versioniq', 'Pinned {version}', { version: pinFor(selectedApp)?.version ?? '' }) }}
											</span>
											<button
												v-if="pinFor(selectedApp)"
												type="button"
												:class="$style.changeAppButton"
												@click="unpinApp(selectedApp)">
												{{ t('versioniq', 'Unpin') }}
											</button>
											<button
												v-else
												type="button"
												:class="$style.changeAppButton"
												@click="openPinDialog(selectedApp, installedVersion)">
												{{ t('versioniq', 'Pin this version') }}
											</button>
										</div>
										<PinDriftBanner
											v-if="pinFor(selectedApp)?.driftedTo"
											:appId="selectedApp"
											:pin="pinFor(selectedApp)!"
											@update:pin="onPinDriftUpdated"
											@repinRequested="onRepinRequested" />
										<div v-if="selectedVersion" :class="$style.selectedVersion">
											<span :class="$style.installedLabel">Selected version</span>
											<span :class="$style.versionTransition">
												<span :class="$style.versionChip">{{ installedVersion || '—' }}</span>
												<span :class="$style.versionArrow">→</span>
												<span :class="$style.versionChip">{{ selectedVersion }}</span>
											</span>
										</div>
										<p
											v-if="selectedVersionRange"
											:class="$style.versionSummary">
											{{ versionRangeText(selectedVersionRange) }}
										</p>
										<p
											v-if="selectedVersionRange?.direction === 'degrade'"
											:class="$style.versionDegradeSummary">
											Downgrade path detected.
										</p>
										<ChangelogRangePanel :entries="changelogRange" />
									</div>
									<template v-if="appDetailTab === 'versions'">
										<div v-if="versions.length > 0" :class="$style.versionListContainer">
											<!-- aria-label, not a placeholder. A placeholder is not an
											     accessible name: it is announced inconsistently and
											     DISAPPEARS the moment the field has content, so a
											     screen-reader user reviewing a filled form is told
											     nothing about what the field is (WCAG 2.2 AA 3.3.2
											     Labels or Instructions, 4.1.2 Name, Role, Value). -->
											<input
												v-if="!selectedVersion"
												v-model="versionFilter"
												type="text"
												:aria-label="t('versioniq', 'Filter versions')"
												placeholder="Filter versions"
												:class="$style.versionFilterInput"
												:disabled="isInstallingVersion">
											<div :class="$style.versionListWrapper">
												<transition-group
													name="versionFade"
													tag="ul"
													:class="$style.versionList">
													<li v-for="version in visibleVersions" :key="version.version" :class="$style.versionItem">
														<div :class="$style.versionItemMain">
															<span>{{ version.version }}</span>
															<span
																v-if="version.cachedOffline"
																:class="$style.cachedOfflineBadge"
																data-testid="cached-offline-badge"
																:title="t('versioniq', 'A verified copy of this version is cached locally and can be used if the source becomes unreachable.')">
																{{ t('versioniq', 'Available offline') }}
															</span>
															<span
																v-if="version.recordedSha"
																:class="$style.recordedShaBadge"
																:title="version.recordedSha">
																{{ recordedShaBadgeLabel(version.version) }}
															</span>
															<button
																v-if="selectedVersion !== version.version"
																type="button"
																:class="$style.versionSelectButton"
																:disabled="isInstallingVersion"
																@click="onSelectVersion(version.version)">
																Select
															</button>
															<span
																v-else
																:class="$style.selectedVersionFlag">
																Selected
															</span>
														</div>
														<VersionChangelog :version="version.version" :changelog="version.changelog ?? null" />
														<div
															v-if="selectedVersion === version.version && selectedVersion !== ''"
															:class="$style.versionActionGroup">
															<p
																v-if="changeActionLabel === 'Degrade'"
																:class="$style.versionDegradeWarning">
																Warning! Downgrading can result in breaking the database if earlier updates or migrations added database columns. Only do this when u can fix the database or are sure no migrations have been executed since the version u downgrade to!
															</p>
															<div :class="$style.versionItemActions">
																<button
																	v-if="changeActionLabel"
																	type="button"
																	:class="[$style.versionActionButton, changeActionLabel === 'Update' ? $style.versionActionUpdateButton : (changeActionLabel === 'Degrade' ? $style.versionActionDegradeButton : '')]"
																	:aria-busy="isInstallingVersion"
																	:disabled="isInstallingVersion"
																	@click="performInstall">
																	<span v-if="isInstallingVersion" :class="$style.spinner" aria-hidden="true" />
																	{{ isInstallingVersion ? 'Installing…' : changeActionLabel }}
																</button>
																<button
																	type="button"
																	:class="$style.versionDeselectButton"
																	:disabled="isInstallingVersion"
																	@click="selectedVersion = ''">
																	Pick other
																</button>
															</div>
														</div>
													</li>
												</transition-group>
												<p v-if="filteredVersions.length === 0" :class="$style.noFilterResult">
													No versions match your filter.
												</p>
											</div>
										</div>
										<p v-if="isCheckingVersions"
											:class="$style.checkingNote"
											role="status"
											aria-live="polite">
											<NcLoadingIcon :size="20" />
											<span>{{ t('versioniq', 'Fetching available versions from the source — this can take a few seconds…') }}</span>
										</p>
										<p v-if="availableSource" :class="$style.note">
											Versions source: {{ availableSource }}
										</p>
										<p v-else-if="hasCheckedVersions" :class="$style.note">
											No versions available for this app.
										</p>
										<p v-if="errorMessage" :class="$style.error">
											{{ errorMessage }}
										</p>
									</template>
									<HistoryPanel v-else-if="selectedApp" :key="selectedApp" :appId="selectedApp" />
								</div>
							</div>
							<div v-if="hasSplitLayout && appDetailTab === 'versions'" :class="$style.rightColumn">
								<div v-if="hasInstallResult && lastInstallResult" :class="$style.resultPanel">
									<p :class="$style.versionSummary">
										Install result
									</p>
									<p :class="[$style.resultStatus, $style[`resultStatus${installStatusTone.charAt(0).toUpperCase() + installStatusTone.slice(1)}`]]">
										{{ installStatusLabel }}
									</p>
									<p :class="$style.resultMessage">
										{{ lastInstallResult.message }}
									</p>
									<p v-if="lastInstallResult.hint" :class="$style.resultHint">
										{{ lastInstallResult.hint }}
									</p>
									<div :class="$style.resultGrid">
										<div>
											<span>App</span>
											<strong>{{ lastInstallResult.appId || '-' }}</strong>
										</div>
										<div>
											<span>Transition</span>
											<strong>{{ lastInstallResult.fromVersion || 'N/A' }} → {{ lastInstallResult.toVersion }}</strong>
										</div>
										<div>
											<span>Mode</span>
											<strong>{{ lastInstallResult.installStatus === 'dry-run' ? 'Dry-run (no write)' : (lastInstallResult.dryRun ? 'Dry-run' : 'Live install') }}</strong>
										</div>
										<div>
											<span>Result</span>
											<strong>{{ lastInstallResult.installedVersion || lastInstallResult.toVersion }}</strong>
										</div>
										<div v-if="lastInstallResult.category">
											<span>Failure category</span>
											<strong>{{ lastInstallResult.category }}</strong>
										</div>
										<div v-if="lastInstallResult.stage">
											<span>Failed at stage</span>
											<strong>{{ lastInstallResult.stage }}</strong>
										</div>
									</div>
									<div
										v-if="debugModeEnabled && lastInstallDebug.length > 0"
										:class="$style.debugPanel">
										<p :class="$style.debugSubtitle">
											Install debug ({{ lastInstallDebug.length }} step(s))
										</p>
										<div :class="$style.debugTimeline">
											<article
												v-for="(entry, entryIndex) in lastInstallDebug"
												:key="`${entry.stage}-${entryIndex}`"
												:class="$style.debugStep">
												<p :class="$style.debugStepHeader">
													<span :class="$style.debugStepIndex">{{ entryIndex + 1 }}</span>
													<span :class="$style.debugStepStage">{{ entry.stage }}</span>
												</p>
												<p v-if="!debugHasData(entry.data)" :class="$style.debugNoData">
													No details
												</p>
												<details v-else :class="$style.debugStepDetails" :open="entryIndex === 0">
													<summary :class="$style.debugStepSummary">
														View details
													</summary>
													<ul :class="$style.debugOutput">
														<li
															v-for="(line, lineIndex) in debugToTextLines(entry.data)"
															:key="`${entry.stage}-line-${lineIndex}`"
															:class="$style.debugOutputLine">
															{{ line }}
														</li>
													</ul>
												</details>
											</article>
										</div>
									</div>
								</div>
							</div>
						</div>
					</main>
				</div>
				<HistoryPanel v-if="currentTab === 'history'"
					id="history-panel"
					role="tabpanel"
					aria-labelledby="history-tab" />
				<SourcesPanel v-show="currentTab === 'sources'"
					id="sources-panel"
					role="tabpanel"
					aria-labelledby="sources-tab"
					:apps="apps"
					:prefill="sourcesPrefill"
					@bound="onPanelBound" />
				<TokensPanel v-show="currentTab === 'tokens'"
					id="tokens-panel"
					role="tabpanel"
					aria-labelledby="tokens-tab" />
				<TrustedSourcesPanel v-show="currentTab === 'trusted'"
					id="trusted-panel"
					role="tabpanel"
					aria-labelledby="trusted-tab" />
				<DiscoverPanel v-show="currentTab === 'discover'"
					id="discover-panel"
					role="tabpanel"
					aria-labelledby="discover-tab"
					@openApp="onDiscoverOpenApp"
					@prefillBind="onDiscoverPrefillBind"
					@openTrusted="onDiscoverOpenTrusted" />
				<CachePanel v-show="currentTab === 'cache'"
					id="cache-panel"
					role="tabpanel"
					aria-labelledby="cache-tab" />
			</div>
		</div>
	</div>
</template>

<style module>
.section {
	display: block;
}

/*
 * Off-screen until focused, then placed over the content.
 *
 * `position: absolute; left: -9999px` and NOT `display: none` or
 * `visibility: hidden`: both of those remove the element from the tab order
 * entirely, which would make the skip link unreachable by the only users it
 * exists for — a bypass affordance nobody can focus is decoration.
 */
.skipLink {
	position: absolute;
	inset-inline-start: -9999px;
	z-index: 100;
	padding: 8px 16px;
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.skipLink:focus {
	inset-inline-start: 8px;
	top: 8px;
	outline: 2px solid var(--color-primary-element);
}

.content {
	margin: 0;
}

.well {
	border: 1px solid var(--color-border);
	border-radius: 8px;
	background: var(--color-main-background);
	padding: 16px;
	margin-top: 8px;
}

/* Separates the advisory settings from the auto-update block above, which is
   a different subject sharing the same panel. */
.advisorySettingsHeading {
	margin-top: 24px;
}

/* Freshness line for the advisory snapshot. Muted, because it is context for
   the badges rather than a finding of its own — but always present, since the
   age of a security answer is part of the answer. */
.advisoryFreshness {
	margin: 0 0 12px;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.tabs {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
	margin-bottom: 16px;
	border-bottom: 1px solid var(--color-border);
	padding-bottom: 12px;
}

.layout {
	width: 100%;
}

.updateChannel {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.mainContent {
	width: 100%;
	box-sizing: border-box;
}

.settingsPanel {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding-bottom: 16px;
	border-bottom: 1px solid var(--color-border);
}

.settingsToggles {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: 12px;
}

.autoUpdateSettings {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: 10px;
	max-width: 420px;
}

.autoUpdateSettings h3 {
	margin: 0;
}

.autoUpdateSettings .hint {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	margin: 0;
}

.autoUpdateWindowError {
	color: var(--color-error-text, #c9291b);
	font-size: 13px;
	margin: 0;
}

.autoUpdateSettingsNotice {
	color: var(--color-success-text, #2d7d46);
	font-size: 13px;
	margin: 0;
}

.selectSection {
	display: flex;
	flex-direction: column;
	gap: 6px;
	margin-top: 12px;
}

.filterToolbar {
	display: flex;
	align-items: center;
	justify-content: flex-start;
}

.filterToggleButton {
	align-self: flex-start;
}

.filterPanel {
	display: flex;
	flex-direction: column;
	gap: 10px;
	padding: 10px 12px;
	border: 1px solid var(--color-border-dark);
	border-radius: 8px;
	background: var(--color-main-background);
}

.filterField {
	display: flex;
	flex-direction: column;
	gap: 6px;
	max-width: 260px;
}

.filterFieldLabel {
	font-size: 12px;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.filterSelect {
	width: 100%;
	box-sizing: border-box;
	border: 1px solid var(--color-border-dark);
	border-radius: 6px;
	padding: 8px 10px;
	background: var(--color-main-background);
}

.appFilterInput {
	width: 100%;
	box-sizing: border-box;
	border: 1px solid var(--color-border-dark);
	border-radius: 6px;
	padding: 8px 10px;
}

.appCardList {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(min(100%, 240px), 1fr));
	gap: 16px;
	overflow-y: visible;
	overflow-x: hidden;
	padding-inline-end: 4px;
	align-content: start;
}

.appCardListSplit {
	max-height: 360px;
	overflow-y: auto;
}

.appCard {
	display: flex;
	flex-direction: column;
	justify-content: space-between;
	gap: 12px;
	padding: 12px;
	border: 1px solid var(--color-border-dark);
	border-radius: 8px;
	background: var(--color-main-background);
	min-height: 124px;
	min-width: 0;
	box-shadow: 0 6px 18px rgba(15, 23, 42, 0.1);
}

.appCardMedia {
	display: flex;
	align-items: center;
	margin-inline-start: auto;
	flex-shrink: 0;
}

.appCardSelected {
	border-color: var(--color-primary-element);
	box-shadow: 0 0 0 1px color-mix(in srgb, var(--color-primary-element) 30%, transparent);
}

.appCardCore {
	border-color: #ef4444;
	box-shadow: 0 6px 18px rgba(127, 29, 29, 0.12);
}

.appCardIcon,
.appCardFallbackIcon {
	width: 48px;
	height: 48px;
	border-radius: 10px;
	border: 1px solid var(--color-border-dark);
	background: color-mix(in srgb, var(--color-main-background) 92%, var(--color-primary-element) 8%);
}

.appCardIcon {
	display: block;
	object-fit: contain;
	padding: 6px;
	box-sizing: border-box;
}

.appCardFallbackIcon {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	font-weight: 700;
	font-size: 18px;
	color: var(--color-primary-element);
}

.appCardBody {
	display: flex;
	flex-direction: column;
	gap: 6px;
	min-width: 0;
}

.appCardHeader {
	display: flex;
	align-items: flex-start;
	gap: 12px;
	min-width: 0;
	justify-content: space-between;
}

.appCardTitleBlock {
	display: flex;
	flex-direction: column;
	gap: 6px;
	min-width: 0;
}

.appCardTitleRow {
	display: flex;
	align-items: center;
	gap: 8px;
	min-width: 0;
}

.appCardTitle,
.appCardMeta {
	margin: 0;
}

.appCardTitle {
	font-weight: 700;
	color: var(--color-main-text);
	/* `word-break: break-word` is deprecated; `overflow-wrap: anywhere` is its
	   behavioural equivalent. It breaks an otherwise-unbreakable run — a long
	   app id with no spaces — without breaking ordinary words mid-character
	   the way `word-break: break-all` would. */
	overflow-wrap: anywhere;
}

.appCardMeta {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
	word-break: break-all;
}

.appCardCoreFlag {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	padding: 2px 8px;
	border-radius: 9999px;
	background: #fee2e2;
	border: 1px solid #ef4444;
	color: #991b1b;
	font-size: 11px;
	font-weight: 700;
	letter-spacing: 0.04em;
	flex-shrink: 0;
}

.pinBadge {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	padding: 2px 8px;
	border-radius: 9999px;
	background: var(--color-primary-element-light, #e0e7ff);
	color: var(--color-primary-element-text, var(--color-main-text));
	border: 1px solid var(--color-primary-element);
	font-size: 11px;
	font-weight: 700;
	letter-spacing: 0.02em;
	flex-shrink: 0;
}

.advisoryBadge {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	padding: 2px 8px;
	border-radius: 9999px;
	background: var(--color-warning, #f0a020);
	color: var(--color-warning-text, #000);
	font-size: 11px;
	font-weight: 700;
	letter-spacing: 0.02em;
	flex-shrink: 0;
}

.advisoryBadgeVulnerable {
	background: var(--color-error, #d32f2f);
	color: var(--color-primary-text, #fff);
}

.advisoryDetail {
	margin: 2px 0 0;
	font-size: 12px;
	font-weight: 600;
	color: var(--color-error-text, var(--color-error, #d32f2f));
}

.appCardDescription {
	margin: 0;
	font-size: 13px;
	line-height: 1.35;
	color: var(--color-text-maxcontrast);
}

.appCardWarning {
	margin: 4px 0 0;
	font-size: 12px;
	line-height: 1.35;
	color: var(--color-warning-text, var(--color-text-maxcontrast));
}

.appCardWarningBlocking {
	color: var(--color-error-text, var(--color-error));
	font-weight: 600;
}

.appCardButton {
	align-self: flex-start;
}

.installedSubvalue {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
}

.changeAppButton {
	align-self: flex-start;
	margin-top: 8px;
}

.appDetailTabs {
	display: flex;
	gap: 4px;
	margin-top: 10px;
	border-bottom: 1px solid var(--color-border);
}

.appDetailTabButton {
	appearance: none;
	-webkit-appearance: none;
	background: transparent;
	border: none;
	border-bottom: 2px solid transparent;
	padding: 6px 10px;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	cursor: pointer;
}

.appDetailTabButtonActive {
	color: var(--color-main-text);
	border-bottom-color: var(--color-primary-element);
	font-weight: 600;
}

.contentRow {
	display: block;
	margin-top: 8px;
}

.contentRowSplit {
	display: flex;
	gap: 16px;
	align-items: stretch;
}

.leftColumn,
.rightColumn {
	flex: 1 1 0;
	min-width: 0;
}

.leftColumnFull {
	width: 100%;
}

.rowInline {
	display: flex;
	align-items: center;
	flex-wrap: wrap;
	gap: 10px;
}

.installed {
	border-inline-start: 4px solid var(--color-border-dark);
	padding: 8px 10px;
	width: 100%;
	margin: 0;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.infoPanel {
	margin-top: 8px;
	width: 100%;
	overflow: visible;
	max-height: 0;
	opacity: 0;
	transform: scaleX(0);
	transform-origin: right center;
	pointer-events: none;
	background: var(--color-main-background);
	border: 1px solid var(--color-border-dark);
	border-inline-start-width: 4px;
	border-radius: 6px;
	padding: 10px;
	display: flex;
	flex-direction: column;
	gap: 8px;
	box-sizing: border-box;
	transition:
		max-height 0.28s ease,
		opacity 0.2s ease,
		transform 0.28s ease;
}

.infoPanelOpen {
	opacity: 1;
	transform: scaleX(1);
	max-height: calc(100vh - 160px);
	pointer-events: auto;
}

.selectedApp,
.installedCurrent {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.installedLabel {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	margin-inline-end: 6px;
}

.installedValue {
	font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
	font-weight: 600;
	font-size: 14px;
}

.versionList {
	padding-inline-start: 20px;
	margin: 8px 0 0;
}

.versionItem {
	width: 100%;
	display: flex;
	flex-direction: column;
	align-items: stretch;
	gap: 6px;
	justify-content: flex-start;
	padding: 6px 0;
	transition:
		opacity 0.18s ease,
		transform 0.18s ease,
		max-height 0.18s ease;
	overflow: visible;
}

.versionItemMain {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 10px;
	width: 100%;
}

.recordedShaBadge {
	display: inline-flex;
	align-items: center;
	font-size: 11px;
	font-weight: 600;
	color: var(--color-success-text, var(--color-success));
	background: var(--color-success-hover, rgba(0, 158, 116, 0.1));
	border: 1px solid var(--color-success);
	border-radius: 9999px;
	padding: 1px 8px;
	line-height: 1.4;
	white-space: nowrap;
}

.cachedOfflineBadge {
	display: inline-flex;
	align-items: center;
	font-size: 11px;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	background: var(--color-background-hover);
	border: 1px solid var(--color-border);
	border-radius: 9999px;
	padding: 1px 8px;
	line-height: 1.4;
	white-space: nowrap;
}

.versionActionGroup {
	flex-direction: column;
	gap: 8px;
	display: flex;
	width: 100%;
}

.versionDegradeWarning {
	margin: 0;
	padding: 8px 10px;
	border: 1px solid #fdba74;
	background: #ffedd5;
	color: #7c2d12;
	border-radius: 6px;
	font-size: 12px;
	line-height: 1.3;
}

.versionItemActions {
	margin-top: 8px;
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 10px;
	width: 100%;
}

.versionSelectButton {
	line-height: 1.1;
	margin: 2px 0;
	visibility: hidden;
	opacity: 0;
	transition: opacity 0.12s ease;
	padding: 3px 10px;
	border: 1px solid var(--color-primary-element);
	color: var(--color-primary-element);
	border-radius: 6px;
	background: var(--color-main-background);
	font-size: 12px;
}

/* ORDER MATTERS HERE, and it is not cosmetic. `.versionItem:hover
   .versionSelectButton` (0,2,0) outranks the single-class pseudo rules below
   it, so with those written first the descending order let the hover-descendant
   rule win ties and a keyboard user's :focus-visible reveal could be overridden
   by whatever the mouse state happened to be. Ascending specificity keeps the
   keyboard affordance reachable — stylelint's no-descending-specificity is
   pointing at a real accessibility bug, not at tidiness. */
.versionSelectButton:focus-visible {
	visibility: visible;
	opacity: 1;
}

.versionSelectButton:hover {
	filter: brightness(1.05);
}

.versionItem:hover .versionSelectButton {
	visibility: visible;
	opacity: 1;
}

.selectedVersionFlag {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	font-size: 12px;
	font-weight: 600;
	color: #1f2937;
	background: #e0f2fe;
	border: 1px solid #38bdf8;
	border-radius: 9999px;
	padding: 2px 10px;
	line-height: 1.3;
	margin-inline-start: auto;
}

.versionActionButton {
	appearance: none;
	-webkit-appearance: none;
	border-radius: 6px;
	padding: 3px 10px;
	font-size: 12px;
	line-height: 1.1;
	cursor: pointer;
	box-sizing: border-box;
	flex: 1 1 0;
	width: calc(50% - 5px);
}

.versionActionUpdateButton {
	color: #166534 !important;
	border: 1px solid #22c55e !important;
	background: #dcfce7 !important;
}

.versionActionUpdateButton:hover {
	background: #bbf7d0 !important;
}

.versionActionDegradeButton {
	color: #991b1b !important;
	border: 1px solid #ef4444 !important;
	background: #fee2e2 !important;
}

.versionActionDegradeButton:hover {
	background: #fecaca !important;
}

:global(.versionFade-move),
:global(.versionFade-enter-active),
:global(.versionFade-leave-active) {
	transition: all 0.2s ease;
}

:global(.versionFade-enter-from),
:global(.versionFade-leave-to) {
	opacity: 0;
	transform: translateY(-4px);
}

:global(.versionFade-leave-active) {
	position: absolute;
}

.versionListContainer {
	max-height: calc(100vh - 420px);
	min-height: 120px;
	overflow: hidden;
	overflow-x: hidden;
	width: 100%;
	display: flex;
	flex-direction: column;
	padding-inline-end: 4px;
}

.versionListWrapper {
	width: 100%;
	max-height: calc(100vh - 460px);
	min-height: 80px;
	flex: 1;
	overflow-y: scroll;
	overflow-x: hidden;
	scrollbar-gutter: stable;
	scrollbar-width: thin;
	scrollbar-color: var(--color-text-maxcontrast) var(--color-background-dark);
}

.versionFilterInput {
	width: 100%;
	box-sizing: border-box;
	border: 1px solid var(--color-border-dark);
	border-radius: 6px;
	padding: 6px 8px;
	margin-bottom: 8px;
}

.noFilterResult {
	margin: 0;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.versionListWrapper::-webkit-scrollbar {
	width: 8px;
}

.versionListWrapper::-webkit-scrollbar-track {
	background: var(--color-background-dark);
	border-radius: 4px;
}

.versionListWrapper::-webkit-scrollbar-thumb {
	background: var(--color-text-maxcontrast);
	border-radius: 4px;
}

.versionListWrapper::-webkit-scrollbar-thumb:hover {
	background: var(--color-text-light);
}

:global(.versionFade-move) {
	transition: transform 0.2s ease;
}

.label {
	font-weight: 600;
}

.safeMode {
	display: inline-flex;
	gap: 8px;
	align-items: center;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	width: 100%;
}

.safeModeCheckbox {
	accent-color: var(--color-primary-element);
}

.versionDeselectButton {
	box-sizing: border-box;
	flex: 1 1 0;
	width: calc(50% - 5px);
}

.spinner {
	display: inline-block;
	width: 0.95em;
	height: 0.95em;
	border: 2px solid rgba(255, 255, 255, 0.35);
	border-top-color: currentColor;
	border-radius: 50%;
	margin-inline-end: 7px;
	vertical-align: -1px;
	animation: spin 0.85s linear infinite;
}

@keyframes spin {
	to {
		transform: rotate(360deg);
	}
}

.selectedVersion {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.versionTransition {
	display: flex;
	align-items: center;
	gap: 8px;
	font-size: 14px;
}

.versionChip {
	font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
	font-weight: 600;
	display: inline-flex;
	align-items: center;
	justify-content: center;
	padding: 2px 8px;
	border: 1px solid var(--color-border-dark);
	border-radius: 9999px;
	background: var(--color-main-background);
}

.versionArrow {
	font-weight: 700;
	color: var(--color-text-light);
}

.versionSummary {
	margin: 0;
	font-size: 12px;
	color: var(--color-text-light);
}

.debugPanel {
	margin-top: 0;
	height: 100%;
	display: flex;
	flex-direction: column;
	gap: 6px;
	border: 1px solid var(--color-border);
	border-radius: 6px;
	padding: 8px;
	background: var(--color-main-background);
}

.resultPanel {
	border: 1px solid var(--color-border);
	border-radius: 6px;
	padding: 8px;
	background: var(--color-main-background);
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.resultStatus {
	margin: 0;
	align-self: flex-start;
	padding: 3px 10px;
	border-radius: 9999px;
	font-size: 11px;
	font-weight: 700;
	color: var(--color-main-background);
}

.resultStatusSuccess {
	background: #16a34a;
}

.resultStatusWarning {
	background: #ea580c;
}

.resultStatusError {
	background: #dc2626;
}

.resultStatusInfo {
	background: #475569;
}

.resultMessage {
	margin: 0;
	font-size: 13px;
	font-weight: 600;
}

.resultHint {
	margin: 4px 0 0;
	font-size: 13px;
	line-height: 1.4;
	color: var(--color-text-maxcontrast);
}

.resultGrid {
	display: grid;
	grid-template-columns: repeat(2, minmax(0, 1fr));
	gap: 8px;
	border: 1px solid var(--color-border-dark);
	border-radius: 6px;
	padding: 8px;
}

.resultGrid div {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.resultGrid span {
	font-size: 11px;
	color: var(--color-text-maxcontrast);
}

.resultGrid strong {
	font-size: 12px;
	word-break: break-all;
}

.debugSubtitle {
	margin: 0;
	font-size: 11px;
	color: var(--color-text-maxcontrast);
}

.debugTimeline {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.debugStep {
	border: 1px solid var(--color-border-dark);
	border-radius: 6px;
	padding: 6px 8px;
	background: color-mix(in srgb, var(--color-main-background) 96%, white 4%);
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.debugStepHeader {
	margin: 0;
	display: flex;
	align-items: center;
	gap: 8px;
	font-size: 12px;
}

.debugStepIndex {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	min-width: 20px;
	height: 20px;
	border-radius: 9999px;
	font-weight: 700;
	font-size: 11px;
	padding: 0 6px;
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
}

.debugStepStage {
	font-weight: 600;
}

.debugStepDetails {
	margin: 0;
}

.debugStepSummary {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	cursor: pointer;
}

.debugOutput {
	list-style: none;
	max-height: 260px;
	overflow: auto;
	margin: 4px 0 0;
	padding: 8px;
	background: #0f172a;
	color: #e2e8f0;
	border-radius: 4px;
	border: 1px solid #1e293b;
	font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
	font-size: 12px;
	line-height: 1.35;
}

.debugOutputLine {
	margin: 0;
	padding: 0;
	line-height: 1.35;
	white-space: pre;
	font-family: inherit;
}

.debugNoData {
	margin: 0;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.versionDegradeSummary {
	margin: 2px 0 0;
	color: #7c2d12;
	font-size: 12px;
	font-weight: 600;
}

.note {
	font-size: 12px;
	margin: 2px 0 0;
	color: var(--color-text-maxcontrast);
}

.checkingNote {
	display: flex;
	align-items: center;
	gap: 8px;
	margin: 8px 0 0;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.error {
	margin: 12px 0 0;
	color: var(--color-error);
	font-size: 13px;
}

/*
 * REDUCED-MOTION FALLBACK (WCAG 2.2 AA 2.3.3 Animation from Interactions).
 *
 * This stylesheet drives eight transitions/animations — the version-list fade,
 * the select-button reveal, the spinner. `prefers-reduced-motion: reduce` is
 * set by people for whom vestibular motion causes actual symptoms, and it is an
 * OS-level setting, not a preference toggle in this app. Honouring it is not
 * optional politeness.
 *
 * Near-zero rather than `none`: a 0.01ms duration still FIRES transitionend and
 * animationend, so any handler waiting on one of those keeps working. Setting
 * `animation: none` silently strips those events and hangs whatever was
 * listening — a fix that trades a motion problem for a dead UI.
 */
@media (prefers-reduced-motion: reduce) {
	*,
	*::before,
	*::after {
		animation-duration: 0.01ms !important;
		animation-iteration-count: 1 !important;
		transition-duration: 0.01ms !important;
		scroll-behavior: auto !important;
	}
}
</style>
