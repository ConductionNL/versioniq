<!-- SPDX-License-Identifier: EUPL-1.2 -->
<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { ocsGet } from '../ocs.ts'

/**
 * The stages of the running install, polled once a second from
 * GET /api/install-progress/{token} while the install request is open
 * (install-live-progress). The install route closes its session, so these
 * polls are not blocked by it. Unmounted when the install returns; the result
 * panel then shows as before.
 *
 * @spec openspec/changes/install-live-progress/specs/version-management/spec.md
 */
const props = defineProps<{
	token: string
	/** Milliseconds between polls; tests shorten it. */
	interval?: number
}>()

interface ProgressStage {
	name: string
	startedAt: number
	endedAt: number | null
	detail?: Record<string, unknown>
}

const stages = ref<ProgressStage[]>([])
const outcome = ref<string | null>(null)
const now = ref(Math.floor(Date.now() / 1000))
let timer: ReturnType<typeof setInterval> | null = null

const LABELS: Record<string, string> = {
	'requested-install': t('versioniq', 'Resolving the release'),
	'release-metadata': t('versioniq', 'Reading the release'),
	'auth-resolution': t('versioniq', 'Checking access to the source'),
	downloading: t('versioniq', 'Downloading'),
	downloaded: t('versioniq', 'Downloaded'),
	'served-from-cache': t('versioniq', 'Using the cached download'),
	checksum: t('versioniq', 'Checking the checksum'),
	'recorded-sha-check': t('versioniq', 'Checking the checksum'),
	'certificate-validated': t('versioniq', 'Checking the certificate'),
	'archive-extracted': t('versioniq', 'Extracting'),
	'info-xml': t('versioniq', 'Validating info.xml'),
	'info-validated': t('versioniq', 'Validating info.xml'),
	'signature-verified': t('versioniq', 'Checking the signature'),
	'migration-diff': t('versioniq', 'Comparing migrations'),
	'filesystem-updated': t('versioniq', 'Swapping the files'),
	finalize: t('versioniq', 'Finishing the install'),
	'pre-migration-repair-steps': t('versioniq', 'Running repair steps'),
	migrations: t('versioniq', 'Running migrations'),
	'post-migration-repair-steps': t('versioniq', 'Running repair steps'),
	'install-repair-steps': t('versioniq', 'Running repair steps'),
}

/**
 * The words for a stage name; an unknown one shows as itself.
 *
 * @param stage The stage.
 */
function label (stage: ProgressStage): string {
	const text = LABELS[stage.name] ?? stage.name
	const percent = stage.detail?.percent
	return typeof percent === 'number' && stage.endedAt === null ? `${text} (${percent} %)` : text
}

const rows = computed(() => stages.value.map((stage, index) => {
	const last = index === stages.value.length - 1
	const failed = last && outcome.value === 'failed'
	const running = last && stage.endedAt === null && outcome.value === null
	return {
		key: `${index}-${stage.name}`,
		label: label(stage),
		state: failed ? 'failed' : (running ? 'running' : 'done'),
		elapsed: running ? Math.max(0, now.value - stage.startedAt) : null,
	}
}))

/**
 * Read the record once; an unknown token (not started yet) leaves the list as it is.
 */
async function poll (): Promise<void> {
	now.value = Math.floor(Date.now() / 1000)
	try {
		const { payload } = await ocsGet<{ stages?: ProgressStage[], outcome?: string | null }>(
			`/ocs/v2.php/apps/versioniq/api/install-progress/${encodeURIComponent(props.token)}`,
		)
		if (Array.isArray(payload?.stages)) {
			stages.value = payload.stages
			outcome.value = payload.outcome ?? null
		}
	} catch {
		// A missed poll is retried on the next tick.
	}
}

onMounted(() => {
	poll()
	timer = setInterval(poll, props.interval ?? 1000)
})

onBeforeUnmount(() => {
	if (timer !== null) {
		clearInterval(timer)
	}
})

defineExpose({ poll })
</script>

<template>
	<div :class="$style.progress" data-testid="install-progress" aria-live="polite">
		<p :class="$style.notice">
			{{ t('versioniq', 'Maintenance mode is on while the app installs. Keep this page open until the install finishes.') }}
		</p>
		<ol :class="$style.stages">
			<li v-for="row in rows"
				:key="row.key"
				:class="$style[row.state]"
				:data-state="row.state">
				<span :class="$style.marker" aria-hidden="true">{{ row.state === 'done' ? '✓' : (row.state === 'failed' ? '✗' : '…') }}</span>
				<span>{{ row.label }}</span>
				<span v-if="row.elapsed !== null" :class="$style.elapsed">{{ t('versioniq', '{seconds} s', { seconds: row.elapsed }) }}</span>
				<span v-if="row.state === 'failed'" :class="$style.elapsed">{{ t('versioniq', 'failed') }}</span>
			</li>
		</ol>
	</div>
</template>

<style module>
.progress {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
	padding: calc(var(--default-grid-baseline) * 3);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.notice {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.stages {
	margin: 0;
	padding: 0;
	list-style: none;
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
}

.stages li {
	display: flex;
	gap: calc(var(--default-grid-baseline) * 2);
}

.marker {
	inline-size: 1.5em;
	text-align: center;
}

.done .marker {
	color: var(--color-success-text, var(--color-success));
}

.failed {
	color: var(--color-error-text, var(--color-error));
}

.running {
	font-weight: bold;
}

.elapsed {
	color: var(--color-text-maxcontrast);
}
</style>
