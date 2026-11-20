<!-- SPDX-License-Identifier: EUPL-1.2 -->
<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { computed, onMounted, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { ocsGet, ocsWrite } from '../ocs.ts'

/**
 * The Upgrade readiness tab: checks every installed app against a target
 * server major and PHP version. The check runs in a background job, so the
 * page queues it and shows the last stored report with its time and targets,
 * grouped by verdict (inventory-upgrade-readiness D5).
 *
 * @spec openspec/changes/inventory-upgrade-readiness/specs/upgrade-readiness/spec.md#requirement-an-admin-checks-every-installed-app-against-a-target-server-major-and-php-version
 */
type Verdict = 'blocked' | 'updateFirst' | 'unknown' | 'ready' | 'shipped'

type ReadinessRow = {
	id: string
	label: string
	installedVersion: string
	verdict: Verdict
	version: string | null
}

type ReadinessReport = {
	server: string
	php: string
	apps: ReadinessRow[]
}

type StoredReadiness = {
	report: ReadinessReport | null
	checkedAt: number | null
	php: string
}

const ENDPOINT = '/ocs/v2.php/apps/versioniq/api/readiness'

/** The groups in the order an admin acts on them: what blocks first. */
const VERDICTS: Verdict[] = ['blocked', 'updateFirst', 'unknown', 'ready', 'shipped']

const report = ref<ReadinessReport | null>(null)
const checkedAt = ref<number | null>(null)
const server = ref('')
const php = ref('')
const loading = ref(true)
const checking = ref(false)
const queued = ref(false)
const error = ref('')

/**
 * The running server's major plus one, the default server target, or '' when
 * the page does not know the running version.
 */
function nextServerMajor (): string {
	const oc = (window as unknown as { OC?: { config?: { version?: string } } }).OC
	const major = Number.parseInt(String(oc?.config?.version ?? '').split('.')[0] ?? '', 10)
	return Number.isFinite(major) ? String(major + 1) : ''
}

/**
 * @param verdict A report verdict.
 */
function verdictLabel (verdict: Verdict): string {
	return {
		blocked: t('versioniq', 'Blocked'),
		updateFirst: t('versioniq', 'Update first'),
		unknown: t('versioniq', 'Unknown'),
		ready: t('versioniq', 'Ready'),
		shipped: t('versioniq', 'Ships with the server'),
	}[verdict]
}

/**
 * @param verdict A report verdict.
 */
function verdictHint (verdict: Verdict): string {
	return {
		blocked: t('versioniq', 'No listed version supports both targets.'),
		updateFirst: t('versioniq', 'A newer version supports both targets: install it before you upgrade.'),
		unknown: t('versioniq', 'The source gives no range for the versions that matter.'),
		ready: t('versioniq', 'The installed version supports both targets.'),
		shipped: t('versioniq', 'These apps follow the server release.'),
	}[verdict]
}

const groups = computed(() => VERDICTS
	.map((verdict) => ({ verdict, rows: (report.value?.apps ?? []).filter((row) => row.verdict === verdict) }))
	.filter((group) => group.rows.length > 0))

const checkedLabel = computed(() => {
	if (report.value === null || checkedAt.value === null) {
		return ''
	}
	return t('versioniq', 'Last checked {time} against Nextcloud {server} and PHP {php}.', {
		time: new Date(checkedAt.value * 1000).toLocaleString(),
		server: report.value.server,
		php: report.value.php,
	})
})

/**
 * Reads the last stored report and fills the targets with their defaults.
 *
 * @spec openspec/changes/inventory-upgrade-readiness/specs/upgrade-readiness/spec.md#requirement-an-admin-checks-every-installed-app-against-a-target-server-major-and-php-version
 */
async function load (): Promise<void> {
	loading.value = true
	error.value = ''
	try {
		const { payload, error: apiError } = await ocsGet<StoredReadiness>(ENDPOINT)
		if (apiError) {
			error.value = apiError
			return
		}
		report.value = payload.report ?? null
		checkedAt.value = payload.checkedAt ?? null
		server.value ||= nextServerMajor()
		php.value ||= payload.php ?? ''
	} catch (e) {
		error.value = e instanceof Error ? e.message : t('versioniq', 'Could not load the readiness report.')
	} finally {
		loading.value = false
	}
}

/**
 * Queues a check against the two targets; the job stores the report.
 *
 * @spec openspec/changes/inventory-upgrade-readiness/specs/upgrade-readiness/spec.md#requirement-an-admin-checks-every-installed-app-against-a-target-server-major-and-php-version
 */
async function check (): Promise<void> {
	checking.value = true
	error.value = ''
	queued.value = false
	try {
		const { error: apiError } = await ocsWrite('POST', ENDPOINT, { server: server.value.trim(), php: php.value.trim() })
		if (apiError) {
			error.value = apiError
			return
		}
		queued.value = true
	} catch (e) {
		error.value = e instanceof Error ? e.message : t('versioniq', 'Could not start the readiness check.')
	} finally {
		checking.value = false
	}
}

onMounted(load)
</script>

<template>
	<section :class="$style.panel" data-testid="readiness-panel">
		<h3>{{ t('versioniq', 'Upgrade readiness') }}</h3>
		<p :class="$style.hint">
			{{ t('versioniq', 'Check every installed app against the Nextcloud and PHP version you plan to move to.') }}
		</p>

		<NcNoteCard v-if="error" type="error">
			<span data-testid="readiness-error">{{ error }}</span>
		</NcNoteCard>

		<form :class="$style.targets" @submit.prevent="check">
			<label :class="$style.field" for="readiness-server">
				<span>{{ t('versioniq', 'Target Nextcloud major') }}</span>
				<input
					id="readiness-server"
					v-model="server"
					type="text"
					inputmode="numeric"
					data-testid="readiness-server">
			</label>
			<label :class="$style.field" for="readiness-php">
				<span>{{ t('versioniq', 'Target PHP version') }}</span>
				<input
					id="readiness-php"
					v-model="php"
					type="text"
					inputmode="decimal"
					data-testid="readiness-php">
			</label>
			<NcButton
				type="submit"
				variant="primary"
				:disabled="checking || loading"
				data-testid="readiness-check">
				{{ t('versioniq', 'Check') }}
			</NcButton>
		</form>

		<NcNoteCard v-if="queued" type="info">
			<span data-testid="readiness-queued">{{ t('versioniq', 'The check is queued. The report appears here once the background job has run.') }}</span>
			<NcButton variant="tertiary" data-testid="readiness-reload" @click="load">
				{{ t('versioniq', 'Reload the report') }}
			</NcButton>
		</NcNoteCard>

		<p v-if="!loading && report === null" :class="$style.hint" data-testid="readiness-empty">
			{{ t('versioniq', 'No readiness check has run yet.') }}
		</p>

		<template v-if="report !== null">
			<p :class="$style.hint" data-testid="readiness-checked">
				{{ checkedLabel }}
			</p>
			<p v-if="groups.length === 0" :class="$style.hint">
				{{ t('versioniq', 'The report lists no apps.') }}
			</p>
			<table
				v-for="group in groups"
				:key="group.verdict"
				:class="$style.table"
				data-testid="readiness-group"
				:data-verdict="group.verdict">
				<caption :class="$style.caption">
					<strong>{{ verdictLabel(group.verdict) }}</strong>
					<span :class="$style.hint">{{ verdictHint(group.verdict) }}</span>
				</caption>
				<thead>
					<tr>
						<th scope="col">
							{{ t('versioniq', 'App') }}
						</th>
						<th scope="col">
							{{ t('versioniq', 'Installed version') }}
						</th>
						<th v-if="group.verdict === 'updateFirst'" scope="col">
							{{ t('versioniq', 'Version to take') }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="row in group.rows" :key="row.id" data-testid="readiness-row" :data-app="row.id">
						<td>{{ row.label || row.id }}</td>
						<td>{{ row.installedVersion }}</td>
						<td v-if="group.verdict === 'updateFirst'">
							{{ row.version }}
						</td>
					</tr>
				</tbody>
			</table>
		</template>
	</section>
</template>

<style module>
.panel {
	display: flex;
	flex-direction: column;
	padding: 12px 0;
	gap: 16px;
}

.targets {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: 12px;
}

.field {
	display: flex;
	gap: 4px;
	flex-direction: column;
}

.table {
	width: 100%;
	border-collapse: collapse;
}

.table th,
.table td {
	padding: 6px 8px;
	border-bottom: 1px solid var(--color-border);
	text-align: start;
}

.caption {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	padding: 4px 0;
	text-align: start;
}

.hint {
	color: var(--color-text-maxcontrast);
}
</style>
