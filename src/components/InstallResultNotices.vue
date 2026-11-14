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
withDefaults(defineProps<{
	integrityWarning: string | null
	servedFromCache: boolean
	checks?: CheckResult[]
}>(), {
	checks: () => [],
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
</script>

<template>
	<div v-if="integrityWarning || servedFromCache || checks.length > 0" :class="$style.notices">
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
