<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!-- SPDX-License-Identifier: EUPL-1.2 -->
<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { ocsWrite } from '../ocs.ts'
import { errorMessage } from '../utils/errorMessage.ts'

/**
 * The checks that must pass before an update (safety-update-checks): the
 * verdict checks an admin adds here, an https address a CI pipeline answers
 * with 2xx, and the command checks the server operator sets in config.php,
 * shown read-only because a web form must not be able to run programs.
 *
 * The token of a stored verdict check never comes back from the server; the
 * form only says whether there is one, and an empty token field keeps it.
 *
 * @spec openspec/changes/safety-update-checks/specs/update-checks/spec.md#requirement-admins-and-operators-attach-checks-that-every-dry-run-runs
 */
export type VerdictCheck = {
	id: string
	name: string
	url: string
	apps: string[]
	hasToken: boolean
}

export type CommandCheck = {
	name: string
	command: string[]
	apps: string[]
	timeout: number
}

export type UpdateChecks = {
	verdict: VerdictCheck[]
	command: CommandCheck[]
	maxVerdict: number
	commandConfigKey: string
}

type VerdictRow = {
	id: string
	name: string
	url: string
	apps: string
	token: string
	hasToken: boolean
	clearToken: boolean
}

const props = defineProps<{
	checks: UpdateChecks
}>()

const ENDPOINT = '/ocs/v2.php/apps/versioniq/api/update-checks'

const current = ref<UpdateChecks>(props.checks)
const rows = ref<VerdictRow[]>([])
const saving = ref(false)
const error = ref('')
const notice = ref('')

/**
 * @param checks The checks from the server.
 */
function fill (checks: UpdateChecks): void {
	current.value = checks
	rows.value = checks.verdict.map((check) => ({
		id: check.id,
		name: check.name,
		url: check.url,
		apps: check.apps.join(', '),
		token: '',
		hasToken: check.hasToken,
		clearToken: false,
	}))
}

watch(() => props.checks, fill, { immediate: true })

/**
 * Adds an empty verdict check, up to the server's maximum.
 */
function addCheck (): void {
	if (rows.value.length >= current.value.maxVerdict) {
		return
	}
	rows.value.push({ id: '', name: '', url: 'https://', apps: '', token: '', hasToken: false, clearToken: false })
}

/**
 * @param index The row to remove.
 */
function removeCheck (index: number): void {
	rows.value.splice(index, 1)
}

/**
 * Why the form cannot be saved yet, or an empty string when it can.
 */
function formError (): string {
	for (const row of rows.value) {
		if (row.name.trim() === '') {
			return t('versioniq', 'Every check needs a name.')
		}
		if (!/^https:\/\/[^/\s]+/i.test(row.url.trim())) {
			return t('versioniq', 'The address of "{name}" must start with https://.', { name: row.name.trim() })
		}
	}
	return ''
}

/**
 * One row as the server takes it: a new token only when typed, the id so a
 * stored check keeps its token.
 *
 * @param row The form row.
 */
function requestEntry (row: VerdictRow): Record<string, unknown> {
	const entry: Record<string, unknown> = {
		name: row.name.trim(),
		url: row.url.trim(),
		apps: row.apps.split(/[\s,]+/).filter((app) => app !== ''),
	}
	if (row.id !== '') {
		entry.id = row.id
	}
	if (row.token.trim() !== '') {
		entry.token = row.token.trim()
	} else if (row.clearToken) {
		entry.clearToken = true
	}
	return entry
}

/**
 * @spec openspec/changes/safety-update-checks/specs/update-checks/spec.md#requirement-admins-and-operators-attach-checks-that-every-dry-run-runs
 */
async function save (): Promise<void> {
	error.value = formError()
	notice.value = ''
	if (error.value !== '') {
		return
	}
	saving.value = true
	try {
		const { payload, error: apiError } = await ocsWrite<UpdateChecks>('PUT', ENDPOINT, { checks: rows.value.map(requestEntry) })
		if (apiError) {
			error.value = apiError
			return
		}
		fill(payload)
		notice.value = t('versioniq', 'Update checks saved.')
	} catch (e) {
		error.value = errorMessage(e, t('versioniq', 'Could not save the update checks.'))
	} finally {
		saving.value = false
	}
}
</script>

<template>
	<section :class="$style.checks" data-testid="update-checks-panel" aria-labelledby="update-checks-heading">
		<h4 id="update-checks-heading">
			{{ t('versioniq', 'Update checks') }}
		</h4>
		<p :class="$style.hint">
			{{ t('versioniq', 'Every dry run runs the checks that match the app. When a check matches, the nightly job installs a version only after a dry run with its checks passed.') }}
		</p>
		<NcNoteCard v-if="error" type="error">
			<span data-testid="update-checks-error">{{ error }}</span>
		</NcNoteCard>

		<h5>{{ t('versioniq', 'Verdict checks') }}</h5>
		<p :class="$style.hint">
			{{ t('versioniq', 'An https address that must answer 2xx within 15 seconds, for example a CI pipeline that tested the version on a staging server. The placeholders {placeholders} are filled in.', { placeholders: '{appId}, {version}, {fromVersion}' }) }}
		</p>
		<p v-if="rows.length === 0" :class="$style.hint" data-testid="update-checks-no-verdict">
			{{ t('versioniq', 'No verdict checks yet.') }}
		</p>
		<fieldset
			v-for="(row, index) in rows"
			:key="row.id || `new-${index}`"
			:class="$style.row"
			data-testid="update-check-row">
			<legend>{{ row.name || t('versioniq', 'New check') }}</legend>
			<label :class="$style.field" :for="`update-check-name-${index}`">
				<span>{{ t('versioniq', 'Name') }}</span>
				<input
					:id="`update-check-name-${index}`"
					v-model="row.name"
					type="text"
					maxlength="100"
					data-testid="update-check-name">
			</label>
			<label :class="$style.field" :for="`update-check-url-${index}`">
				<span>{{ t('versioniq', 'Address (https only)') }}</span>
				<input
					:id="`update-check-url-${index}`"
					v-model="row.url"
					type="url"
					placeholder="https://ci.example.org/verdict/{appId}/{version}"
					data-testid="update-check-url">
			</label>
			<label :class="$style.field" :for="`update-check-apps-${index}`">
				<span>{{ t('versioniq', 'Apps (app ids, separated by commas; empty means every app)') }}</span>
				<input
					:id="`update-check-apps-${index}`"
					v-model="row.apps"
					type="text"
					data-testid="update-check-apps">
			</label>
			<label :class="$style.field" :for="`update-check-token-${index}`">
				<span>{{ row.hasToken ? t('versioniq', 'Bearer token (stored; leave empty to keep it)') : t('versioniq', 'Bearer token (optional)') }}</span>
				<input
					:id="`update-check-token-${index}`"
					v-model="row.token"
					type="password"
					autocomplete="off"
					data-testid="update-check-token">
			</label>
			<label v-if="row.hasToken" :class="$style.inline" :for="`update-check-clear-token-${index}`">
				<input
					:id="`update-check-clear-token-${index}`"
					v-model="row.clearToken"
					type="checkbox"
					data-testid="update-check-clear-token">
				<span>{{ t('versioniq', 'Remove the stored token') }}</span>
			</label>
			<NcButton
				variant="tertiary"
				:aria-label="t('versioniq', 'Remove check {name}', { name: row.name || t('versioniq', 'New check') })"
				data-testid="update-check-remove"
				@click="removeCheck(index)">
				{{ t('versioniq', 'Remove') }}
			</NcButton>
		</fieldset>
		<div :class="$style.actions">
			<NcButton
				:disabled="rows.length >= current.maxVerdict"
				data-testid="update-check-add"
				@click="addCheck">
				{{ t('versioniq', 'Add verdict check') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="saving"
				data-testid="update-checks-save"
				@click="save">
				{{ t('versioniq', 'Save update checks') }}
			</NcButton>
		</div>
		<p v-if="rows.length >= current.maxVerdict" :class="$style.hint" data-testid="update-checks-max">
			{{ t('versioniq', 'At most {max} verdict checks can be set.', { max: current.maxVerdict }) }}
		</p>
		<p v-if="notice" :class="$style.notice" role="status" data-testid="update-checks-notice">
			{{ notice }}
		</p>

		<h5>{{ t('versioniq', 'Command checks') }}</h5>
		<p :class="$style.hint" data-testid="update-checks-command-note">
			{{ t('versioniq', 'Command checks are set by the server operator in config.php under {key} and cannot be changed here.', { key: current.commandConfigKey }) }}
		</p>
		<p v-if="current.command.length === 0" :class="$style.hint" data-testid="update-checks-no-command">
			{{ t('versioniq', 'No command checks are set.') }}
		</p>
		<ul v-else :class="$style.commands" data-testid="update-checks-command-list">
			<li v-for="(check, index) in current.command" :key="`${check.name}-${index}`" data-testid="update-check-command">
				<strong>{{ check.name }}</strong>
				<code>{{ check.command.join(' ') }}</code>
				<span :class="$style.hint">
					{{ check.apps.length === 0 ? t('versioniq', 'Every app') : check.apps.join(', ') }}
					·
					{{ t('versioniq', 'Timeout {seconds} s', { seconds: check.timeout }) }}
				</span>
			</li>
		</ul>
	</section>
</template>

<style module>
.checks {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.row {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 8px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
}

.field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.inline {
	display: flex;
	align-items: center;
	gap: 8px;
}

.actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}

.commands {
	display: flex;
	flex-direction: column;
	gap: 4px;
	padding: 0;
	list-style: none;
}

.commands li {
	display: flex;
	flex-wrap: wrap;
	align-items: baseline;
	gap: 8px;
}

.hint {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.notice {
	color: var(--color-success-text);
}
</style>
