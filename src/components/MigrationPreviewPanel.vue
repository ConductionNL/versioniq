<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!-- SPDX-License-Identifier: EUPL-1.2 -->
<script setup lang="ts">
import type { MigrationPreview } from '../utils/migrationSafety.ts'

// The database changes an upgrade will make, from an upgrade dry run
// (safety-upgrade-migration-preview, design D5). Receives the preview the
// page fetched; issues no requests of its own. Every value renders as text.
//
// @spec openspec/changes/safety-upgrade-migration-preview/specs/migration-safety/spec.md
import { t } from '@nextcloud/l10n'
import { migrationActionText, migrationPreviewTexts, sizeClassAdvice } from '../utils/migrationSafety.ts'

defineProps<{
	preview: MigrationPreview | null
}>()

const texts = migrationPreviewTexts()

/**
 * A row count as the viewer's locale writes it.
 *
 * @param rows The count, or null when it is not known.
 */
function formatRows (rows: number | null): string {
	return rows === null ? '' : rows.toLocaleString()
}
</script>

<template>
	<section :class="$style.panel" data-testid="migration-preview-panel" :aria-label="t('versioniq', 'Database changes')">
		<p :class="$style.title">
			{{ t('versioniq', 'Database changes') }}
		</p>
		<p v-if="preview === null" data-testid="migration-preview-unreadable">
			{{ texts.unreadable }}
		</p>
		<template v-else>
			<p v-if="preview.estimated" :class="$style.note" data-testid="migration-preview-estimated">
				{{ texts.estimated }}
			</p>
			<p v-if="preview.steps.length === 0" data-testid="migration-preview-none">
				{{ texts.noChanges }}
			</p>
			<ul v-else :class="$style.list">
				<li
					v-for="step in preview.steps"
					:key="step.name"
					:class="$style.item"
					data-testid="migration-preview-step">
					<span :class="$style.step">{{ step.name }}</span>
					<p v-if="!step.describes" :class="$style.note" data-testid="migration-preview-undescribed">
						{{ texts.undescribed }}
					</p>
					<p v-else-if="step.actions.length > 0" data-testid="migration-preview-actions">
						{{ step.actions.map((action) => migrationActionText(action.attribute)).join(', ') }}
					</p>
					<ul v-if="step.tables.length > 0" :class="$style.tables">
						<li
							v-for="table in step.tables"
							:key="table.name"
							data-testid="migration-preview-table">
							<span :class="$style.step">{{ table.name }}</span>
							<span v-if="table.rows !== null" data-testid="migration-preview-rows">
								{{ t('versioniq', '{rows} rows', { rows: formatRows(table.rows) }) }}
							</span>
							<span data-testid="migration-preview-advice">{{ sizeClassAdvice(table.sizeClass) }}</span>
						</li>
					</ul>
					<p v-if="step.dataStep" :class="$style.note" data-testid="migration-preview-data-step">
						{{ texts.dataStep }}
					</p>
				</li>
			</ul>
			<div v-if="preview.repairSteps.length > 0" data-testid="migration-preview-repair-steps">
				<p :class="$style.title">
					{{ texts.repairSteps }}
				</p>
				<ul :class="$style.tables">
					<li v-for="repair in preview.repairSteps" :key="repair" :class="$style.step">
						{{ repair }}
					</li>
				</ul>
			</div>
		</template>
	</section>
</template>

<style module>
.panel {
	margin-top: 10px;
	padding: 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 6px);
	background: var(--color-background-hover);
	color: var(--color-main-text);
}

.title {
	margin: 0 0 6px;
	font-weight: 600;
}

.note {
	color: var(--color-text-maxcontrast);
}

.list {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin: 0;
	padding: 0;
	list-style: none;
}

/* A rule between steps, none above the first. */
.item + .item {
	border-top: 1px solid var(--color-border);
	padding-top: 6px;
}

.tables {
	margin: 4px 0 0;
	padding-inline-start: 16px;
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.tables li {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}

.step {
	font-family: var(--font-face-monospace, ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace);
	font-weight: 600;
}
</style>
