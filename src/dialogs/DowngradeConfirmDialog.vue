<!--
SPDX-License-Identifier: EUPL-1.2
Confirms a downgrade before Versioniq installs an older release. Names the
app and the exact version transition, summarises how far the range spans, and
— crucially — lists the database migrations the target version does NOT carry,
because files can be rolled back and applied migrations cannot.

Purely a decision surface: the caller (App.vue) owns the install flow and
resolves the promise this dialog's `resolve` event carries. Cancelling and
dismissing are the same answer (false), so a dismissed dialog can never leave
the caller awaiting forever.
@spec openspec/specs/migration-safety/spec.md
-->
<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { computed } from 'vue'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import { orphanedMigrationsSummary } from '../utils/migrationSafety.ts'

const props = defineProps<{
	open: boolean
	appId: string
	fromVersion: string
	toVersion: string
	/** Pre-formatted range summary; empty string hides the row. */
	rangeText: string
	orphanedMigrations: string[] | null
	/** True while an install is in flight — both buttons are disabled. */
	busy: boolean
}>()

const emit = defineEmits<{
	'update:open': [value: boolean]
	resolve: [accept: boolean]
}>()

/**
 *
 * @param accept
 */
function choose (accept: boolean): void {
	emit('update:open', false)
	emit('resolve', accept)
}

const buttons = computed(() => [
	{
		label: t('versioniq', 'Cancel'),
		type: 'tertiary' as const,
		disabled: props.busy,
		callback: () => choose(false),
	},
	{
		label: t('versioniq', 'Downgrade'),
		variant: 'error' as const,
		disabled: props.busy,
		callback: () => choose(true),
	},
])
</script>

<template>
	<NcDialog
		:open="open"
		:name="t('versioniq', 'Confirm downgrade')"
		:buttons="buttons"
		@update:open="(value: boolean) => { if (!value) { choose(false) } }">
		<p :class="$style.downgradeConfirmText">
			<strong>{{ appId }}</strong>
		</p>
		<p :class="$style.versionTransitionRow">
			<span :class="$style.versionChip">{{ fromVersion || '—' }}</span>
			<span :class="$style.versionArrow">→</span>
			<span :class="$style.versionChip">{{ toVersion }}</span>
		</p>
		<p v-if="rangeText" :class="$style.versionRangeSummary">
			<strong>{{ t('versioniq', 'Downgrade info:') }}</strong> {{ rangeText }}
		</p>
		<p :class="$style.versionItemDegradeMessage">
			{{ t('versioniq', 'Downgrading files cannot undo database migrations already applied by the installed version.') }}
		</p>
		<div v-if="orphanedMigrations && orphanedMigrations.length > 0" :class="$style.migrationDiff">
			<p><strong>{{ t('versioniq', 'Database migrations only present in the installed version:') }}</strong></p>
			<ul :class="$style.migrationDiffList">
				<li v-for="migration in orphanedMigrations" :key="migration">
					{{ migration }}
				</li>
			</ul>
		</div>
		<p v-else :class="$style.versionItemDegradeMessage">
			{{ orphanedMigrationsSummary(orphanedMigrations) }}
		</p>
	</NcDialog>
</template>

<style module>
.downgradeConfirmText {
	font-size: 14px;
	line-height: 1.4;
}

.versionTransitionRow {
	margin: 0;
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

.versionRangeSummary {
	margin: 0;
	font-size: 12px;
	color: var(--color-text-light);
}

.versionItemDegradeMessage {
	margin: 8px 0 0;
	padding: 8px 10px;
	border: 1px solid #fdba74;
	background: #ffedd5;
	color: #7c2d12;
	border-radius: 6px;
	font-size: 12px;
	line-height: 1.3;
}

.migrationDiff {
	margin: 8px 0 0;
	padding: 8px 10px;
	border: 1px solid #fdba74;
	background: #ffedd5;
	color: #7c2d12;
	border-radius: 6px;
	font-size: 12px;
	line-height: 1.3;
}

.migrationDiffList {
	margin: 4px 0 0;
	padding-inline-start: 18px;
}
</style>
