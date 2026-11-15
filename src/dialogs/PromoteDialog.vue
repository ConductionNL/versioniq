<!--
SPDX-License-Identifier: EUPL-1.2
Promote the version an earlier stage runs to this instance
(install-multi-instance-promotion D6, D7). The dialog reads the plan from the
earlier stage's last manifest, asks for the waiting-period override and the
downgrade acknowledgement when they apply, and sends the version it showed, so
the server refuses when that stage moved on meanwhile. After the install it
offers the last-known-good rollback to the version this instance ran before.
@spec openspec/changes/install-multi-instance-promotion/specs/multi-instance/spec.md#requirement-an-admin-promotes-the-exact-package-an-earlier-stage-runs
@spec openspec/changes/install-multi-instance-promotion/specs/multi-instance/spec.md#requirement-a-version-waits-a-set-number-of-days-on-the-earlier-stage
-->
<script setup lang="ts">
import { n, t } from '@nextcloud/l10n'
import { computed, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { ocsGet, ocsWrite } from '../ocs.ts'

/** What the server plans to install (PromotionService::plan()). */
export type PromotionPlan = {
	connection: { id: string, name: string, stage: string }
	appId: string
	version: string
	sourceId: string
	sha256: string | null
	runningSince: string | null
	installedVersion: string | null
	direction: 'upgrade' | 'downgrade' | 'same'
	minDays: number
	daysLeft: number
	waitMet: boolean
}

type PromotionAnswer = {
	message?: string
	code?: string
	daysLeft?: number
	plan?: PromotionPlan
	previousVersion?: string | null
	promotedFrom?: { id: string, name: string, stage: string }
}

const props = defineProps<{
	open: boolean
	appId: string
	connection: { id: string, name: string, stage: string }
}>()

const emit = defineEmits<{
	'update:open': [value: boolean]
	promoted: [appId: string]
	rollback: [target: { appId: string, version: string }]
}>()

const plan = ref<PromotionPlan | null>(null)
const loading = ref(false)
const promoting = ref(false)
const error = ref('')
const override = ref(false)
const downgradeAcknowledged = ref(false)
const promoted = ref<{ version: string, previousVersion: string | null } | null>(null)

const endpoint = computed(() => `/ocs/v2.php/apps/versioniq/api/app/${encodeURIComponent(props.appId)}/promote`)

const isDowngrade = computed(() => plan.value?.direction === 'downgrade')
const canPromote = computed((): boolean => {
	const current = plan.value
	if (!current || promoting.value || promoted.value !== null || current.direction === 'same') {
		return false
	}
	if (!current.waitMet && !override.value) {
		return false
	}

	return !isDowngrade.value || downgradeAcknowledged.value
})

/**
 * The waiting-period message, or '' when the period is met or off.
 *
 * @spec openspec/changes/install-multi-instance-promotion/specs/multi-instance/spec.md#requirement-a-version-waits-a-set-number-of-days-on-the-earlier-stage
 */
const waitingMessage = computed((): string => {
	const current = plan.value
	if (!current || current.waitMet) {
		return ''
	}
	if (current.runningSince === null) {
		return n('versioniq',
			'It is not known since when {name} runs {version}, so the waiting period of {count} day is not met.',
			'It is not known since when {name} runs {version}, so the waiting period of {count} days is not met.',
			current.minDays,
			{ name: current.connection.name, version: current.version, count: current.minDays })
	}

	return n('versioniq',
		'{version} has not run on {name} long enough: {count} day of the waiting period is left.',
		'{version} has not run on {name} long enough: {count} days of the waiting period are left.',
		current.daysLeft,
		{ name: current.connection.name, version: current.version, count: current.daysLeft })
})

/**
 * Read what the promotion would install, from the earlier stage's last manifest.
 *
 * @spec openspec/changes/install-multi-instance-promotion/specs/multi-instance/spec.md#requirement-an-admin-promotes-the-exact-package-an-earlier-stage-runs
 */
async function loadPlan (): Promise<void> {
	loading.value = true
	error.value = ''
	plan.value = null
	try {
		const { payload, error: apiError } = await ocsGet<PromotionPlan & { message?: string }>(endpoint.value, { connection: props.connection.id })
		if (apiError) {
			error.value = payload?.message || apiError
			return
		}
		plan.value = payload
	} catch (e) {
		error.value = e instanceof Error ? e.message : t('versioniq', 'Could not read what would be promoted.')
	} finally {
		loading.value = false
	}
}

watch(() => props.open, (isOpen) => {
	if (!isOpen) {
		return
	}
	override.value = false
	downgradeAcknowledged.value = false
	promoted.value = null
	loadPlan()
}, { immediate: true })

/**
 * Promote (password-confirmed). A refusal keeps the dialog open with the
 * server's reason, and a newer plan it sends replaces the one shown.
 *
 * @spec openspec/changes/install-multi-instance-promotion/specs/multi-instance/spec.md#requirement-an-admin-promotes-the-exact-package-an-earlier-stage-runs
 */
async function promote (): Promise<void> {
	const current = plan.value
	if (!current || !canPromote.value) {
		return
	}
	promoting.value = true
	error.value = ''
	try {
		const { payload, error: apiError } = await ocsWrite<PromotionAnswer>('POST', endpoint.value, {
			connection: props.connection.id,
			version: current.version,
			allowDowngrade: isDowngrade.value && downgradeAcknowledged.value,
			overrideMinDays: !current.waitMet && override.value,
		})
		if (apiError) {
			error.value = payload?.message || apiError
			if (payload?.plan) {
				plan.value = payload.plan
			}
			return
		}
		promoted.value = { version: current.version, previousVersion: payload?.previousVersion ?? current.installedVersion }
		emit('promoted', props.appId)
	} catch (e) {
		error.value = e instanceof Error ? e.message : t('versioniq', 'Could not promote this app.')
	} finally {
		promoting.value = false
	}
}

const buttons = computed(() => promoted.value !== null
	? [{ label: t('versioniq', 'Close'), type: 'primary' as const, callback: () => emit('update:open', false) }]
	: [
		{ label: t('versioniq', 'Cancel'), type: 'tertiary' as const, callback: () => emit('update:open', false) },
		{ label: t('versioniq', 'Promote'), type: 'primary' as const, disabled: !canPromote.value, callback: promote },
	])
</script>

<template>
	<NcDialog
		:open="open"
		:name="t('versioniq', 'Promote {appId} from {name}', { appId, name: connection.name })"
		:buttons="buttons"
		@update:open="(value: boolean) => emit('update:open', value)">
		<div :class="$style.body" data-testid="promote-dialog">
			<p v-if="loading" data-testid="promote-loading">
				{{ t('versioniq', 'Reading what {name} runs…', { name: connection.name }) }}
			</p>

			<template v-if="plan && promoted === null">
				<p data-testid="promote-summary">
					{{ plan.installedVersion
						? t('versioniq', 'This instance runs {installed}. {name} ({stage}) runs {version}.', { installed: plan.installedVersion, name: plan.connection.name, stage: plan.connection.stage, version: plan.version })
						: t('versioniq', '{appId} is not installed here. {name} ({stage}) runs {version}.', { appId, name: plan.connection.name, stage: plan.connection.stage, version: plan.version }) }}
				</p>
				<p :class="$style.hint">
					{{ t('versioniq', 'Source: {source}. The package is installed from the same source and checked against the checksum {name} recorded.', { source: plan.sourceId, name: plan.connection.name }) }}
				</p>
				<NcNoteCard v-if="plan.direction === 'same'" type="info">
					{{ t('versioniq', 'This instance already runs this version.') }}
				</NcNoteCard>

				<template v-if="waitingMessage">
					<NcNoteCard type="warning">
						<span data-testid="promote-waiting">{{ waitingMessage }}</span>
					</NcNoteCard>
					<NcCheckboxRadioSwitch v-model="override" data-testid="promote-override">
						{{ t('versioniq', 'Promote anyway; the override is written to the audit trail') }}
					</NcCheckboxRadioSwitch>
				</template>

				<template v-if="isDowngrade">
					<NcNoteCard type="warning">
						<span data-testid="promote-downgrade">{{ t('versioniq', '{version} is older than the {installed} this instance runs. Installing it is a downgrade; migrations that ran for {installed} stay.', { version: plan.version, installed: plan.installedVersion ?? '' }) }}</span>
					</NcNoteCard>
					<NcCheckboxRadioSwitch v-model="downgradeAcknowledged" data-testid="promote-downgrade-confirm">
						{{ t('versioniq', 'Install the older version {version}', { version: plan.version }) }}
					</NcCheckboxRadioSwitch>
				</template>
			</template>

			<template v-if="promoted">
				<NcNoteCard type="success">
					<span data-testid="promote-done">{{ t('versioniq', '{appId} {version} is installed, promoted from {name}.', { appId, version: promoted.version, name: connection.name }) }}</span>
				</NcNoteCard>
				<NcButton v-if="promoted.previousVersion"
					variant="secondary"
					data-testid="promote-rollback"
					@click="emit('rollback', { appId, version: promoted.previousVersion })">
					{{ t('versioniq', 'Roll back to {version}', { version: promoted.previousVersion }) }}
				</NcButton>
			</template>

			<p v-if="error" :class="$style.error" data-testid="promote-error">
				{{ error }}
			</p>
		</div>
	</NcDialog>
</template>

<style module>
.body {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.hint {
	margin: 0;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.error {
	margin: 0;
	color: var(--color-error);
	font-size: 13px;
}
</style>
