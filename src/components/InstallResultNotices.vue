<!-- SPDX-License-Identifier: EUPL-1.2 -->
<script setup lang="ts">
import type { CheckResult } from '../utils/installResult.ts'

import { t } from '@nextcloud/l10n'

/**
 * What the installer said about how it got the artifact: an install that went
 * ahead without checksum verification, and one served from the local cache.
 * Both were in the install payload and never shown (#438). A dry run also
 * lists each update check it ran, passed, failed or skipped
 * (safety-update-checks).
 *
 * @spec openspec/specs/external-sources/spec.md
 * @spec openspec/changes/safety-update-checks/specs/update-checks/spec.md#requirement-admins-and-operators-attach-checks-that-every-dry-run-runs
 */
const props = withDefaults(defineProps<{
	integrityWarning: string | null
	servedFromCache: boolean
	checks?: CheckResult[]
	space?: { appsFree: number | null, tempFree: number | null } | null
}>(), {
	checks: () => [],
	space: null,
})

/**
 * The visible outcome of a check: a mark and a word, so it never rests on colour alone.
 *
 * @param check The check result.
 */
function outcomeLabel (check: CheckResult): string {
	if (check.outcome === 'passed') {
		return t('versioniq', 'Passed')
	}
	if (check.outcome === 'skipped') {
		return t('versioniq', 'Skipped')
	}
	return t('versioniq', 'Failed')
}

/**
 * @param check The check result.
 */
function outcomeMark (check: CheckResult): string {
	if (check.outcome === 'passed') {
		return '✓'
	}
	return check.outcome === 'skipped' ? '–' : '✗'
}

/**
 * The detail to show: for a skipped check the reason, translated.
 *
 * @param check The check result.
 */
function detailOf (check: CheckResult): string {
	return check.outcome === 'skipped' ? t('versioniq', 'Runs from the command line and the nightly job only.') : check.detail
}

/**
 * A byte count in the largest unit that keeps it above one, one decimal.
 *
 * @param bytes The count.
 */
function formatBytes (bytes: number): string {
	const units = ['B', 'KB', 'MB', 'GB', 'TB']
	let value = bytes
	let unit = 0
	while (value >= 1024 && unit < units.length - 1) {
		value /= 1024
		unit++
	}

	return `${unit === 0 ? value : value.toFixed(1)} ${units[unit]}`
}

/**
 * The "Environment checked" line of the progress: the free space the
 * pre-flight found and that the app folder is writable
 * (safety-rollback-backup-and-preflight D2). A folder whose free space could
 * not be read says so.
 *
 * @spec openspec/changes/safety-rollback-backup-and-preflight/specs/version-management/spec.md
 */
function environmentText (): string {
	const space = props.space
	const free = (bytes: number | null) => bytes === null ? t('versioniq', 'unknown') : formatBytes(bytes)

	return t('versioniq', 'Environment checked: {apps} free in the apps folder, {temp} free in the temp folder; the app folder is writable.', {
		apps: free(space?.appsFree ?? null),
		temp: free(space?.tempFree ?? null),
	})
}
</script>

<template>
	<div v-if="integrityWarning || servedFromCache || checks.length > 0 || space" :class="$style.notices">
		<p v-if="space" :class="$style.note" data-testid="install-environment-checked">
			{{ environmentText() }}
		</p>
		<p
			v-if="integrityWarning"
			:class="$style.warning"
			role="alert"
			data-testid="install-integrity-warning">
			<strong>{{ t('versioniq', 'Installed without checksum verification.') }}</strong>
			{{ integrityWarning }}
		</p>
		<p v-if="servedFromCache" :class="$style.note" data-testid="install-served-from-cache">
			{{ t('versioniq', 'The package came from the local artifact cache, not from the source.') }}
		</p>
		<div v-if="checks.length > 0" data-testid="install-checks">
			<p :class="$style.checksHeading">
				{{ t('versioniq', 'Update checks') }}
			</p>
			<ul :class="$style.checks">
				<li
					v-for="(check, index) in checks"
					:key="`${check.name}-${index}`"
					:class="[$style.check, $style[`check-${check.outcome}`]]"
					:data-outcome="check.outcome"
					data-testid="install-check">
					<span :class="$style.mark" aria-hidden="true">{{ outcomeMark(check) }}</span>
					<strong>{{ check.name }}</strong>
					<span :class="$style.outcome">{{ outcomeLabel(check) }}</span>
					<span v-if="detailOf(check)" :class="$style.detail">{{ detailOf(check) }}</span>
				</li>
			</ul>
		</div>
	</div>
</template>

<style module>
.notices {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin: 8px 0;
}

.warning {
	padding: 8px 12px;
	border-inline-start: 4px solid var(--color-warning);
	background: var(--color-warning-hover, var(--color-background-hover));
	color: var(--color-main-text);
}

.note {
	color: var(--color-text-maxcontrast);
}

.checksHeading {
	font-weight: bold;
}

.checks {
	display: flex;
	flex-direction: column;
	gap: 4px;
	padding: 0;
	list-style: none;
}

.check {
	display: flex;
	flex-wrap: wrap;
	align-items: baseline;
	gap: 8px;
	padding: 4px 8px;
	border-inline-start: 4px solid var(--color-border);
	color: var(--color-main-text);
}

.check-passed {
	border-inline-start-color: var(--color-success);
}

.check-failed {
	border-inline-start-color: var(--color-error);
}

.mark {
	font-weight: bold;
}

.outcome {
	color: var(--color-text-maxcontrast);
}

.detail {
	flex-basis: 100%;
	font-family: var(--font-face-monospace, monospace);
	font-size: 12px;
	white-space: pre-wrap;
	overflow-wrap: anywhere;
	color: var(--color-text-maxcontrast);
}
</style>
