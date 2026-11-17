<!-- SPDX-License-Identifier: EUPL-1.2 -->
<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { onMounted, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { ocsGet, ocsWrite } from '../ocs.ts'

/**
 * The patch policy on the Settings tab (autoupdate-security-first D3, D5):
 * per advisory severity a deadline and how its fix is handled, per regular
 * kind of update automatic or by hand, a free text statement, and the policy
 * as the sentences the server renders, with a text download of those lines.
 *
 * @spec openspec/changes/autoupdate-security-first/specs/auto-update-policies/spec.md
 */
type SecurityRow = { deadlineDays: number | null, handling: string }
type Policy = {
	security: Record<string, SecurityRow>
	regular: Record<string, string>
	statement: string
	setBy: string | null
	setAt: string | null
}
export type PatchPolicyView = {
	policy: Policy
	recorded: boolean
	statement: string[]
	allowed: {
		severities: string[]
		securityHandlings: string[]
		kinds: string[]
		regularHandlings: string[]
		deadlineMinDays: number
		deadlineMaxDays: number
		statementMaxLength: number
	}
}

const ENDPOINT = '/ocs/v2.php/apps/versioniq/api/patch-policy'

const view = ref<PatchPolicyView | null>(null)
const security = ref<Record<string, { deadlineDays: string, handling: string }>>({})
const regular = ref<Record<string, string>>({})
const statement = ref('')
const saving = ref(false)
const error = ref('')
const notice = ref('')

/**
 * @param data The patch policy from the server.
 * @spec openspec/changes/autoupdate-security-first/specs/auto-update-policies/spec.md
 */
function fill (data: PatchPolicyView): void {
	view.value = data
	security.value = Object.fromEntries(data.allowed.severities.map((severity) => {
		const row = data.policy.security[severity] ?? { deadlineDays: null, handling: 'scheduled' }
		return [severity, { deadlineDays: row.deadlineDays === null ? '' : String(row.deadlineDays), handling: row.handling }]
	}))
	regular.value = Object.fromEntries(data.allowed.kinds.map((kind) => [kind, data.policy.regular[kind] ?? 'automatic']))
	statement.value = data.policy.statement
}

/**
 * The body PUT /api/patch-policy receives: an empty deadline is none.
 *
 * @spec openspec/changes/autoupdate-security-first/specs/auto-update-policies/spec.md
 */
function payload (): Record<string, unknown> {
	return {
		security: Object.fromEntries(Object.entries(security.value).map(([severity, row]) => {
			const days = String(row.deadlineDays).trim()
			return [severity, { deadlineDays: days === '' ? null : Number(days), handling: row.handling }]
		})),
		regular: { ...regular.value },
		statement: statement.value,
	}
}

/**
 * @param severity The advisory severity.
 * @spec openspec/changes/autoupdate-security-first/specs/auto-update-policies/spec.md
 */
function severityLabel (severity: string): string {
	return {
		critical: t('versioniq', 'Critical security fixes'),
		high: t('versioniq', 'High security fixes'),
		medium: t('versioniq', 'Medium security fixes'),
		low: t('versioniq', 'Low security fixes'),
		unknown: t('versioniq', 'Security fixes of unknown severity'),
	}[severity] ?? severity
}

/**
 * @param handling How a security fix is handled.
 * @spec openspec/changes/autoupdate-security-first/specs/auto-update-policies/spec.md
 */
function securityHandlingLabel (handling: string): string {
	return {
		next_window: t('versioniq', 'At the next update window'),
		scheduled: t('versioniq', 'With the regular automatic updates'),
		manual: t('versioniq', 'By hand'),
	}[handling] ?? handling
}

/**
 * @param kind The regular kind of update.
 * @spec openspec/changes/autoupdate-security-first/specs/auto-update-policies/spec.md
 */
function kindLabel (kind: string): string {
	return {
		patch: t('versioniq', 'Patch updates'),
		minor: t('versioniq', 'Minor updates'),
		major: t('versioniq', 'Major updates'),
	}[kind] ?? kind
}

/**
 * @param handling How a regular update is handled.
 * @spec openspec/changes/autoupdate-security-first/specs/auto-update-policies/spec.md
 */
function regularHandlingLabel (handling: string): string {
	return {
		automatic: t('versioniq', 'Automatically, as each app\'s policy allows'),
		manual: t('versioniq', 'By hand'),
	}[handling] ?? handling
}

/**
 * The statement lines as the text file the download saves: the same lines
 * the page shows, one per line.
 *
 * @param lines The statement lines.
 * @spec openspec/changes/autoupdate-security-first/specs/auto-update-policies/spec.md
 */
function statementText (lines: string[]): string {
	return lines.join('\n') + '\n'
}

/**
 * @spec openspec/changes/autoupdate-security-first/specs/auto-update-policies/spec.md
 */
function download (): void {
	if (!view.value) {
		return
	}
	const url = URL.createObjectURL(new Blob([statementText(view.value.statement)], { type: 'text/plain;charset=utf-8' }))
	const link = document.createElement('a')
	link.href = url
	link.download = 'patch-policy.txt'
	link.click()
	URL.revokeObjectURL(url)
}

/**
 * @spec openspec/changes/autoupdate-security-first/specs/auto-update-policies/spec.md
 */
async function load (): Promise<void> {
	error.value = ''
	try {
		const { payload: data, error: apiError } = await ocsGet<PatchPolicyView>(ENDPOINT)
		if (apiError) {
			error.value = apiError
			return
		}
		fill(data)
	} catch (e) {
		error.value = e instanceof Error ? e.message : t('versioniq', 'Could not load the patch policy.')
	}
}

/**
 * @spec openspec/changes/autoupdate-security-first/specs/auto-update-policies/spec.md
 */
async function save (): Promise<void> {
	saving.value = true
	error.value = ''
	notice.value = ''
	try {
		const { payload: data, error: apiError } = await ocsWrite<PatchPolicyView>('PUT', ENDPOINT, payload())
		if (apiError) {
			error.value = apiError
			return
		}
		fill(data)
		notice.value = t('versioniq', 'Patch policy saved.')
	} catch (e) {
		error.value = e instanceof Error ? e.message : t('versioniq', 'Could not save the patch policy.')
	} finally {
		saving.value = false
	}
}

onMounted(load)
</script>

<template>
	<section :class="$style.panel" data-testid="patch-policy-panel">
		<h3>{{ t('versioniq', 'Patch policy') }}</h3>
		<p :class="$style.hint">
			{{ t('versioniq', 'How each kind of patch is handled. Security fixes go before any other update; the deadlines are recorded for reports.') }}
		</p>
		<NcNoteCard v-if="error" type="error">
			<span data-testid="patch-policy-error">{{ error }}</span>
		</NcNoteCard>

		<template v-if="view">
			<ul :class="$style.statement" data-testid="patch-policy-statement">
				<li v-for="(line, index) in view.statement" :key="index">
					{{ line }}
				</li>
			</ul>
			<div>
				<NcButton variant="secondary" data-testid="patch-policy-download" @click="download">
					{{ t('versioniq', 'Download as text') }}
				</NcButton>
			</div>

			<h4>{{ t('versioniq', 'Security fixes') }}</h4>
			<fieldset v-for="severity in view.allowed.severities"
				:key="severity"
				:class="$style.row"
				:data-testid="`patch-policy-security-${severity}`">
				<legend>{{ severityLabel(severity) }}</legend>
				<label :class="$style.field" :for="`patch-policy-handling-${severity}`">
					<span>{{ t('versioniq', 'Installed') }}</span>
					<select :id="`patch-policy-handling-${severity}`" v-model="security[severity].handling" :data-testid="`patch-policy-handling-${severity}`">
						<option v-for="handling in view.allowed.securityHandlings" :key="handling" :value="handling">
							{{ securityHandlingLabel(handling) }}
						</option>
					</select>
				</label>
				<label :class="$style.field" :for="`patch-policy-deadline-${severity}`">
					<span>{{ t('versioniq', 'Deadline in days ({min}-{max}, empty for none)', { min: view.allowed.deadlineMinDays, max: view.allowed.deadlineMaxDays }) }}</span>
					<input :id="`patch-policy-deadline-${severity}`"
						v-model="security[severity].deadlineDays"
						type="number"
						:min="view.allowed.deadlineMinDays"
						:max="view.allowed.deadlineMaxDays"
						:data-testid="`patch-policy-deadline-${severity}`">
				</label>
			</fieldset>

			<h4>{{ t('versioniq', 'Regular updates') }}</h4>
			<label v-for="kind in view.allowed.kinds"
				:key="kind"
				:class="$style.field"
				:for="`patch-policy-regular-${kind}`">
				<span>{{ kindLabel(kind) }}</span>
				<select :id="`patch-policy-regular-${kind}`" v-model="regular[kind]" :data-testid="`patch-policy-regular-${kind}`">
					<option v-for="handling in view.allowed.regularHandlings" :key="handling" :value="handling">
						{{ regularHandlingLabel(handling) }}
					</option>
				</select>
			</label>

			<label :class="$style.field" for="patch-policy-statement-text">
				<span>{{ t('versioniq', 'Statement (at most {max} characters)', { max: view.allowed.statementMaxLength }) }}</span>
				<textarea id="patch-policy-statement-text"
					v-model="statement"
					rows="4"
					:maxlength="view.allowed.statementMaxLength"
					data-testid="patch-policy-statement-text" />
			</label>

			<p v-if="notice" :class="$style.notice" data-testid="patch-policy-notice">
				{{ notice }}
			</p>
			<div>
				<NcButton variant="primary"
					:disabled="saving"
					data-testid="patch-policy-save"
					@click="save">
					{{ t('versioniq', 'Save patch policy') }}
				</NcButton>
			</div>
		</template>
	</section>
</template>

<style module>
.panel {
	display: flex;
	flex-direction: column;
	gap: 12px;
	max-width: 720px;
	padding: 12px 0;
}

.statement {
	display: flex;
	flex-direction: column;
	gap: 2px;
	margin: 0;
	padding: 8px 12px;
	list-style: none;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-background-hover);
}

.row {
	display: flex;
	flex-wrap: wrap;
	gap: 12px;
	border: none;
	margin: 0;
	padding: 0;
}

.field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.hint {
	color: var(--color-text-maxcontrast);
}

.notice {
	color: var(--color-success-text);
}
</style>
