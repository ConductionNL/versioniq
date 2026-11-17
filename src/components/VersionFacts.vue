<!--
SPDX-License-Identifier: EUPL-1.2
The facts of one version in the picker (releases-version-facts): its downloads,
how installs of it went here, the "Major version" and "Breaking" badges, and
its known issues: the publisher's section from the notes, shown as plain text,
and the notes admins recorded, each removable. Presentational: App.vue owns
the writes and reacts to `add` and `remove`.
@spec openspec/changes/releases-version-facts/specs/changelog-visibility/spec.md#requirement-known-issues-of-a-version-are-shown-before-and-after-installing-it
@spec openspec/changes/releases-version-facts/specs/version-management/spec.md#requirement-every-version-shows-how-widely-it-runs-and-how-installs-of-it-went
-->
<script setup lang="ts">
import type {VersionFactsEntry} from '../utils/versionFacts.ts';

import { t } from '@nextcloud/l10n'
import { computed } from 'vue'
import {
		downloadsText,
	isMajorStep,
	outcomesText} from '../utils/versionFacts.ts'

const props = defineProps<{
	entry: VersionFactsEntry
	installedVersion: string
	/** True while a write is in flight; the buttons are disabled. */
	busy?: boolean
}>()

const emit = defineEmits<{
	add: [version: string]
	remove: [version: string, index: number]
}>()

const downloads = computed(() => downloadsText(props.entry.downloads))
const outcomes = computed(() => outcomesText(props.entry.outcomes))
const major = computed(() => isMajorStep(props.entry.version, props.installedVersion))
const notesIssues = computed(() => (props.entry.knownIssues ?? '').trim())
const adminIssues = computed(() => props.entry.adminKnownIssues ?? [])
const hasIssues = computed(() => notesIssues.value !== '' || adminIssues.value.length > 0)
</script>

<template>
	<div :class="$style.facts" data-testid="version-facts">
		<div :class="$style.line">
			<span v-if="major" :class="$style.badge" data-testid="fact-major">
				{{ t('versioniq', 'Major version') }}
			</span>
			<span
				v-if="entry.breaking === true"
				:class="[$style.badge, $style.breaking]"
				data-testid="fact-breaking"
				:title="t('versioniq', 'The release notes of this version mark it as breaking.')">
				{{ t('versioniq', 'Breaking') }}
			</span>
			<span v-if="downloads" :class="$style.fact" data-testid="fact-downloads">{{ downloads }}</span>
			<span v-if="outcomes" :class="$style.fact" data-testid="fact-outcomes">{{ outcomes }}</span>
			<button
				type="button"
				:class="$style.addButton"
				:disabled="busy"
				data-testid="fact-add-issue"
				@click="emit('add', entry.version)">
				{{ t('versioniq', 'Add known issue') }}
			</button>
		</div>
		<section
			v-if="hasIssues"
			:class="$style.issues"
			:aria-label="t('versioniq', 'Known issues of {version}', { version: entry.version })"
			data-testid="fact-known-issues">
			<p :class="$style.issuesTitle">
				{{ t('versioniq', 'Known issues') }}
			</p>
			<p v-if="notesIssues" :class="$style.notes" data-testid="fact-notes-issues">{{ notesIssues }}</p>
			<ul v-if="adminIssues.length > 0" :class="$style.adminList">
				<li
					v-for="(issue, index) in adminIssues"
					:key="`${index}-${issue.text}`"
					:class="$style.adminItem"
					data-testid="fact-admin-issue">
					<span>{{ issue.text }}</span>
					<span v-if="issue.workaround" :class="$style.workaround">
						{{ t('versioniq', 'Workaround: {workaround}', { workaround: issue.workaround }) }}
					</span>
					<a
						v-if="issue.link"
						:href="issue.link"
						target="_blank"
						rel="noopener noreferrer">{{ t('versioniq', 'More information') }}</a>
					<span v-if="issue.addedBy" :class="$style.addedBy">
						{{ t('versioniq', 'Added by {user}', { user: issue.addedBy }) }}
					</span>
					<button
						type="button"
						:class="$style.removeButton"
						:disabled="busy"
						:aria-label="t('versioniq', 'Remove known issue: {text}', { text: issue.text })"
						data-testid="fact-remove-issue"
						@click="emit('remove', entry.version, index)">
						{{ t('versioniq', 'Remove') }}
					</button>
				</li>
			</ul>
		</section>
	</div>
</template>

<style module>
.facts {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin-top: 4px;
}

.line {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
}

.badge {
	padding: 1px 8px;
	border-radius: var(--border-radius-pill, 12px);
	border: 1px solid var(--color-border-dark);
	color: var(--color-main-text);
	font-size: 12px;
}

.breaking {
	border-color: var(--color-warning);
	background: var(--color-warning-hover, var(--color-background-dark));
	color: var(--color-warning-text, var(--color-main-text));
}

.fact,
.addedBy {
	color: var(--color-text-maxcontrast);
	font-size: 12px;
}

.addButton,
.removeButton {
	min-height: var(--default-clickable-area, 34px);
	padding: 0 8px;
	border: none;
	background: transparent;
	color: var(--color-primary-element);
	font-size: 12px;
	cursor: pointer;
}

.addButton:focus-visible,
.removeButton:focus-visible {
	outline: 2px solid var(--color-primary-element);
	outline-offset: 2px;
}

.issues {
	padding: 8px 10px;
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius-large, 8px);
	background: var(--color-background-hover);
}

.issuesTitle {
	margin: 0 0 4px;
	font-weight: bold;
	font-size: 13px;
}

.notes {
	margin: 0;
	white-space: pre-wrap;
	font-size: 12px;
}

.adminList {
	margin: 4px 0 0;
	padding: 0;
	list-style: none;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.adminItem {
	display: flex;
	flex-wrap: wrap;
	align-items: baseline;
	gap: 6px;
	font-size: 12px;
}

.workaround {
	color: var(--color-main-text);
}
</style>
