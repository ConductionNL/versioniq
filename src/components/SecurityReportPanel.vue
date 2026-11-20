<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!-- SPDX-License-Identifier: EUPL-1.2 -->
<script setup lang="ts">
import type { SecurityReport } from '../utils/securityReport.ts'

import { t } from '@nextcloud/l10n'
import { computed, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { ocsGet } from '../ocs.ts'
import { download } from '../utils/auditExport.ts'
import { errorMessage } from '../utils/errorMessage.ts'
import { lastMonth, reportCsv, SEVERITIES } from '../utils/securityReport.ts'

// The remediation report on the History tab (audit-security-reports D6):
// per severity what opened and how it was resolved against the deadline,
// and the monthly trend.
// @spec openspec/changes/audit-security-reports/specs/security-advisory-correlation/spec.md

const REPORT_ENDPOINT = '/ocs/v2.php/apps/versioniq/api/reports/security'

const initial = lastMonth(new Date())
const fromDate = ref(initial.from)
const toDate = ref(initial.to)
const report = ref<SecurityReport | null>(null)
const isLoading = ref(false)
const error = ref('')

const timeZone = (() => {
	try {
		return Intl.DateTimeFormat().resolvedOptions().timeZone || ''
	} catch {
		return ''
	}
})()

const severityLabels: Record<string, string> = {
	critical: t('versioniq', 'Critical'),
	high: t('versioniq', 'High'),
	medium: t('versioniq', 'Medium'),
	low: t('versioniq', 'Low'),
	unknown: t('versioniq', 'Unknown'),
}

const missingDeadlines = computed(() => {
	const deadlines = report.value?.deadlines ?? {}
	return SEVERITIES.filter((severity) => deadlines[severity] === null || deadlines[severity] === undefined)
})

/**
 * A share as a percentage, or a dash when it is unavailable.
 *
 * @param share the share between 0 and 1, or null
 */
function percent (share: number | null | undefined): string {
	return share === null || share === undefined ? '—' : `${Math.round(share * 100)}%`
}

/**
 * A count or number that may be unavailable.
 *
 * @param value the value, or null
 */
function figure (value: number | null | undefined): string {
	return value === null || value === undefined ? '—' : String(value)
}

/**
 * Read the report for the chosen period.
 */
async function load (): Promise<void> {
	if (!fromDate.value || !toDate.value) {
		return
	}
	isLoading.value = true
	error.value = ''
	try {
		const query: Record<string, string> = { from: fromDate.value, to: toDate.value }
		if (timeZone) {
			query.timeZone = timeZone
		}
		const { payload, error: apiError } = await ocsGet<SecurityReport>(REPORT_ENDPOINT, query)
		if (apiError) {
			error.value = apiError
			report.value = null
			return
		}
		report.value = payload
	} catch (e) {
		error.value = errorMessage(e, t('versioniq', 'Could not load the security report.'))
		report.value = null
	} finally {
		isLoading.value = false
	}
}

/**
 * Save the report's tables as CSV.
 */
function exportCsv (): void {
	if (report.value) {
		download(`versioniq-security-report-${fromDate.value}-${toDate.value}.csv`, 'text/csv', reportCsv(report.value))
	}
}

watch([fromDate, toDate], () => {
	void load()
}, { immediate: true })
</script>

<template>
	<section :class="$style.panel" data-testid="security-report">
		<h3>{{ t('versioniq', 'Security report') }}</h3>
		<p :class="$style.hint">
			{{ t('versioniq', 'How fast advisories that affected this instance were fixed, per severity, against the patch policy deadlines.') }}
		</p>
		<div :class="$style.filters">
			<label :class="$style.filter">
				<span>{{ t('versioniq', 'From') }}</span>
				<input v-model="fromDate" type="date" data-testid="report-from">
			</label>
			<label :class="$style.filter">
				<span>{{ t('versioniq', 'To') }}</span>
				<input v-model="toDate" type="date" data-testid="report-to">
			</label>
			<NcButton variant="secondary" :disabled="!report" data-testid="report-export-csv" @click="exportCsv">
				{{ t('versioniq', 'Export CSV') }}
			</NcButton>
		</div>
		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>
		<p v-if="isLoading" :class="$style.loading" role="status" aria-live="polite">
			<NcLoadingIcon :size="20" />
			<span>{{ t('versioniq', 'Loading the security report…') }}</span>
		</p>
		<template v-else-if="report">
			<p :class="$style.hint" data-testid="report-coverage">
				{{ report.trackingSince
					? t('versioniq', 'Tracking since {date}. The history keeps {days} days.', { date: report.trackingSince.slice(0, 10), days: report.retentionDays })
					: t('versioniq', 'No advisory has been tracked yet. The history keeps {days} days.', { days: report.retentionDays }) }}
			</p>
			<NcNoteCard v-if="missingDeadlines.length > 0" type="info" data-testid="report-deadline-hint">
				{{ t('versioniq', 'No deadline is set for {severities}, so the share fixed within the deadline is unavailable. Set deadlines in the patch policy.', { severities: missingDeadlines.map((severity) => severityLabels[severity]).join(', ') }) }}
			</NcNoteCard>
			<div :class="$style.tableWrapper">
				<table :class="$style.table" data-testid="report-severities">
					<caption>{{ t('versioniq', 'Per severity') }}</caption>
					<thead>
						<tr>
							<th scope="col">{{ t('versioniq', 'Severity') }}</th>
							<th scope="col">{{ t('versioniq', 'Opened') }}</th>
							<th scope="col">{{ t('versioniq', 'Fixed by update') }}</th>
							<th scope="col">{{ t('versioniq', 'Dismissed') }}</th>
							<th scope="col">{{ t('versioniq', 'Within deadline') }}</th>
							<th scope="col">{{ t('versioniq', 'Open past deadline') }}</th>
							<th scope="col">{{ t('versioniq', 'Median days to fix') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="severity in SEVERITIES" :key="severity" :data-severity="severity">
							<th scope="row">{{ severityLabels[severity] }}</th>
							<td>{{ figure(report.severities[severity]?.opened) }}</td>
							<td>{{ figure(report.severities[severity]?.resolvedByUpdate) }}</td>
							<td>{{ figure(report.severities[severity]?.resolvedByDismissal) }}</td>
							<td data-cell="within">{{ percent(report.severities[severity]?.withinDeadlineShare) }}</td>
							<td>{{ figure(report.severities[severity]?.openPastDeadline) }}</td>
							<td>{{ figure(report.severities[severity]?.medianDays) }}</td>
						</tr>
					</tbody>
				</table>
			</div>
			<div :class="$style.tableWrapper">
				<table :class="$style.table" data-testid="report-trend">
					<caption>{{ t('versioniq', 'Per month') }}</caption>
					<thead>
						<tr>
							<th scope="col">{{ t('versioniq', 'Month') }}</th>
							<th scope="col">{{ t('versioniq', 'Opened') }}</th>
							<th scope="col">{{ t('versioniq', 'Resolved') }}</th>
							<th scope="col">{{ t('versioniq', 'Within deadline') }}</th>
							<th scope="col">{{ t('versioniq', 'Median days to fix') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="month in report.trend" :key="month.month" :data-month="month.month">
							<th scope="row">{{ month.month }}</th>
							<td>{{ month.opened }}</td>
							<td>{{ month.resolved }}</td>
							<td>{{ percent(month.withinDeadlineShare) }}</td>
							<td>{{ figure(month.medianDays) }}</td>
						</tr>
					</tbody>
				</table>
			</div>
		</template>
	</section>
</template>

<style module>
.panel { display: flex; flex-direction: column; gap: 12px; margin-top: 24px; }

.hint { color: var(--color-text-maxcontrast); font-size: 13px; margin: 0; }

.filters { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 8px 16px; }

.filter { display: flex; align-items: center; gap: 6px; }

.loading { display: flex; align-items: center; gap: 8px; color: var(--color-text-maxcontrast); }

.tableWrapper { overflow-x: auto; }

.table { border-collapse: collapse; min-width: 100%; }

.table caption { text-align: start; font-weight: bold; padding-bottom: 4px; color: var(--color-main-text); }

.table th,
.table td { border-bottom: 1px solid var(--color-border); padding: 6px 8px; text-align: start; }
</style>
