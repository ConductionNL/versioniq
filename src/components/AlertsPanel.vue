<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  The Alerts tab (releases-new-release-alerts, design D2, D3 and D5): which new
  releases reach the bell, the followed apps, the recent releases, and the
  webhook targets with their last delivery and Send test.
  @spec openspec/changes/releases-new-release-alerts/specs/release-alerts/spec.md
  @spec openspec/changes/releases-new-release-alerts/specs/alert-webhooks/spec.md
-->
<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { onMounted, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { ocsGet, ocsWrite } from '../ocs.ts'

type Follow = { appId: string, sourceId: string | null, addedBy: string, addedAt: string }
type Release = { appId: string, version: string, sourceId: string | null, compatible: boolean, detectedAt: number }
type Delivery = { at: string, status: string, error: string | null }
type Target = { id: string, name: string, url: string, format: string, kinds: string[], enabled: boolean, hasSecret: boolean, last: Delivery | null }

const BASE = '/ocs/v2.php/apps/versioniq/api'
const SCOPES = ['forge', 'all', 'off']
const KINDS = ['new_release', 'security', 'auto_update', 'pin_drift', 'token']

const scope = ref('forge')
const follows = ref<Follow[]>([])
const releases = ref<Release[]>([])
const targets = ref<Target[]>([])
const loading = ref(false)
const busy = ref(false)
const error = ref('')
const followAppId = ref('')
const followSource = ref('')
const draft = ref({ name: '', url: '', format: 'json', kinds: ['security'] as string[], secret: '' })

/**
 * The label of a scope.
 *
 * @param value The scope
 */
function scopeLabel (value: string): string {
	return {
		forge: t('versioniq', 'Apps not from the App Store, and followed apps'),
		all: t('versioniq', 'Every app'),
		off: t('versioniq', 'None'),
	}[value] ?? value
}

/**
 * The label of an alert kind.
 *
 * @param kind The kind
 */
function kindLabel (kind: string): string {
	return {
		new_release: t('versioniq', 'New releases'),
		security: t('versioniq', 'Security advisories'),
		auto_update: t('versioniq', 'Automatic updates'),
		pin_drift: t('versioniq', 'Pin drift'),
		token: t('versioniq', 'Access tokens'),
	}[kind] ?? kind
}

/**
 * Runs one request and shows its error, if any; true on success.
 *
 * @param request The request
 */
async function run<T> (request: () => Promise<{ payload: T, error?: string }>): Promise<T | null> {
	busy.value = true
	error.value = ''
	try {
		const { payload, error: apiError } = await request()
		if (apiError) {
			error.value = apiError
			return null
		}
		return payload
	} catch (e) {
		error.value = e instanceof Error ? e.message : t('versioniq', 'The request failed.')
		return null
	} finally {
		busy.value = false
	}
}

/**
 * Loads the scope, the follows, the releases and the targets.
 */
async function load (): Promise<void> {
	loading.value = true
	const alerts = await run(() => ocsGet<{ scope: string, releases: Release[], follows: Follow[] }>(`${BASE}/release-alerts`))
	if (alerts !== null) {
		scope.value = alerts.scope
		releases.value = alerts.releases
		follows.value = alerts.follows
	}
	const hooks = await run(() => ocsGet<{ targets: Target[] }>(`${BASE}/webhooks`))
	if (hooks !== null) {
		targets.value = hooks.targets
	}
	loading.value = false
}

/**
 * Stores a new scope.
 *
 * @param value The scope
 */
async function setScope (value: string): Promise<void> {
	const result = await run(() => ocsWrite<{ scope: string }>('PUT', `${BASE}/release-alerts/scope`, { scope: value }))
	if (result !== null) {
		scope.value = result.scope
	}
}

/**
 * Follows the app in the form.
 */
async function follow (): Promise<void> {
	const result = await run(() => ocsWrite<{ follow: Follow }>('POST', `${BASE}/follow`, { appId: followAppId.value.trim(), sourceId: followSource.value.trim() }))
	if (result !== null) {
		follows.value = [...follows.value.filter((entry) => entry.appId !== result.follow.appId), result.follow]
		followAppId.value = ''
		followSource.value = ''
	}
}

/**
 * Stops following an app.
 *
 * @param appId The app
 */
async function unfollow (appId: string): Promise<void> {
	if (await run(() => ocsWrite<{ appId: string }>('DELETE', `${BASE}/follow/${encodeURIComponent(appId)}`)) !== null) {
		follows.value = follows.value.filter((entry) => entry.appId !== appId)
	}
}

/**
 * Replaces one target in the list.
 *
 * @param target The target
 */
function putTarget (target: Target): void {
	targets.value = [...targets.value.filter((entry) => entry.id !== target.id), target]
}

/**
 * Adds the target in the form.
 */
async function addTarget (): Promise<void> {
	const result = await run(() => ocsWrite<{ target: Target }>('POST', `${BASE}/webhooks`, { ...draft.value, enabled: true }))
	if (result !== null) {
		putTarget(result.target)
		draft.value = { name: '', url: '', format: 'json', kinds: ['security'], secret: '' }
	}
}

/**
 * Switches a target on or off.
 *
 * @param target The target
 */
async function toggle (target: Target): Promise<void> {
	const result = await run(() => ocsWrite<{ target: Target }>('PUT', `${BASE}/webhooks/${target.id}`, { enabled: !target.enabled }))
	if (result !== null) {
		putTarget(result.target)
	}
}

/**
 * Sends the test message to a target.
 *
 * @param target The target
 */
async function sendTest (target: Target): Promise<void> {
	const result = await run(() => ocsWrite<{ target: Target }>('POST', `${BASE}/webhooks/${target.id}/test`))
	if (result !== null) {
		putTarget(result.target)
	}
}

/**
 * Removes a target.
 *
 * @param target The target
 */
async function removeTarget (target: Target): Promise<void> {
	if (await run(() => ocsWrite<{ id: string }>('DELETE', `${BASE}/webhooks/${target.id}`)) !== null) {
		targets.value = targets.value.filter((entry) => entry.id !== target.id)
	}
}

/**
 * The last delivery of a target as a line.
 *
 * @param last The last delivery
 */
function deliveryLine (last: Delivery | null): string {
	if (last === null) {
		return t('versioniq', 'Nothing delivered yet.')
	}
	return last.status === 'succeeded'
		? t('versioniq', 'Last delivery {at}: succeeded', { at: last.at })
		: t('versioniq', 'Last delivery {at}: failed ({error})', { at: last.at, error: last.error ?? '' })
}

onMounted(load)

defineExpose({ load })
</script>

<template>
	<div :class="$style.panel">
		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>
		<NcLoadingIcon v-if="loading" :size="20" />
		<template v-else>
			<section :class="$style.section" data-testid="alerts-scope">
				<h3>{{ t('versioniq', 'New release notifications') }}</h3>
				<p :class="$style.hint">
					{{ t('versioniq', 'Nextcloud already announces App Store updates; by default Versioniq tells you about the other apps.') }}
				</p>
				<label v-for="value in SCOPES" :key="value" :class="$style.choice">
					<input type="radio"
						name="release-alert-scope"
						:value="value"
						:checked="scope === value"
						:disabled="busy"
						@change="setScope(value)">
					{{ scopeLabel(value) }}
				</label>
			</section>

			<section :class="$style.section" data-testid="alerts-follows">
				<h3>{{ t('versioniq', 'Followed apps') }}</h3>
				<ul :class="$style.list">
					<li v-for="entry in follows" :key="entry.appId" :class="$style.row" data-testid="alerts-follow-row">
						<span><code>{{ entry.appId }}</code> {{ entry.sourceId ?? '' }}</span>
						<NcButton variant="tertiary" :disabled="busy" @click="unfollow(entry.appId)">
							{{ t('versioniq', 'Unfollow') }}
						</NcButton>
					</li>
					<li v-if="follows.length === 0" :class="$style.empty">
						{{ t('versioniq', 'No app is followed. Follow one from the Discover tab or by its app id.') }}
					</li>
				</ul>
				<form :class="$style.form" @submit.prevent="follow">
					<label>{{ t('versioniq', 'App id') }}
						<input v-model="followAppId" data-testid="alerts-follow-app" required>
					</label>
					<label>{{ t('versioniq', 'Source (optional)') }}
						<input v-model="followSource" data-testid="alerts-follow-source">
					</label>
					<NcButton type="submit" :disabled="busy || followAppId.trim() === ''">
						{{ t('versioniq', 'Follow releases') }}
					</NcButton>
				</form>
			</section>

			<section :class="$style.section" data-testid="alerts-releases">
				<h3>{{ t('versioniq', 'Recent releases') }}</h3>
				<ul :class="$style.list">
					<li v-for="release in releases.slice(0, 20)" :key="`${release.appId}-${release.version}`" :class="$style.row">
						<span><code>{{ release.appId }}</code> {{ release.version }}</span>
						<span v-if="!release.compatible" :class="$style.hint">{{ t('versioniq', 'It does not run on this server version.') }}</span>
					</li>
					<li v-if="releases.length === 0" :class="$style.empty">
						{{ t('versioniq', 'No new release was detected yet.') }}
					</li>
				</ul>
			</section>

			<section :class="$style.section" data-testid="alerts-targets">
				<h3>{{ t('versioniq', 'Webhook targets') }}</h3>
				<ul :class="$style.list">
					<li v-for="target in targets" :key="target.id" :class="$style.row" data-testid="alerts-target-row">
						<span :class="$style.targetInfo">
							<strong>{{ target.name }}</strong>
							<span :class="$style.hint">{{ target.url }} · {{ target.format }} · {{ target.kinds.map(kindLabel).join(', ') }}</span>
							<span :class="$style.hint" data-testid="alerts-target-last">{{ deliveryLine(target.last) }}</span>
						</span>
						<span>
							<NcButton variant="tertiary" :disabled="busy" @click="sendTest(target)">{{ t('versioniq', 'Send test') }}</NcButton>
							<NcButton variant="tertiary" :disabled="busy" @click="toggle(target)">{{ target.enabled ? t('versioniq', 'Switch off') : t('versioniq', 'Switch on') }}</NcButton>
							<NcButton variant="tertiary" :disabled="busy" @click="removeTarget(target)">{{ t('versioniq', 'Remove') }}</NcButton>
						</span>
					</li>
					<li v-if="targets.length === 0" :class="$style.empty">
						{{ t('versioniq', 'No webhook target yet.') }}
					</li>
				</ul>
				<form :class="$style.form" data-testid="alerts-target-form" @submit.prevent="addTarget">
					<label>{{ t('versioniq', 'Name') }}
						<input v-model="draft.name" data-testid="alerts-target-name" required>
					</label>
					<label>{{ t('versioniq', 'Address (https)') }}
						<input v-model="draft.url" type="url" data-testid="alerts-target-url" required>
					</label>
					<label>{{ t('versioniq', 'Format') }}
						<select v-model="draft.format" data-testid="alerts-target-format">
							<option value="json">JSON</option>
							<option value="chat">{{ t('versioniq', 'Chat (Slack, Mattermost, Rocket.Chat)') }}</option>
						</select>
					</label>
					<fieldset :class="$style.kinds">
						<legend>{{ t('versioniq', 'Alerts to send') }}</legend>
						<label v-for="kind in KINDS" :key="kind">
							<input v-model="draft.kinds" type="checkbox" :value="kind">
							{{ kindLabel(kind) }}
						</label>
					</fieldset>
					<label>{{ t('versioniq', 'Signing secret (optional)') }}
						<input v-model="draft.secret" type="password" autocomplete="new-password" data-testid="alerts-target-secret">
					</label>
					<NcButton type="submit" :disabled="busy || draft.kinds.length === 0">
						{{ t('versioniq', 'Add target') }}
					</NcButton>
				</form>
			</section>
		</template>
	</div>
</template>

<style module>
.panel { display: flex; flex-direction: column; gap: 20px; }

.section { display: flex; flex-direction: column; gap: 8px; }

.hint { color: var(--color-text-maxcontrast); font-size: 13px; }

.choice { display: flex; align-items: center; gap: 6px; }

.list { display: flex; flex-direction: column; gap: 4px; margin: 0; padding: 0; list-style: none; }

.row { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 6px 0; border-bottom: 1px solid var(--color-border); }

.targetInfo { display: flex; flex-direction: column; gap: 2px; }

.empty { color: var(--color-text-maxcontrast); font-style: italic; }

.form { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 8px; }

.form label { display: flex; flex-direction: column; gap: 2px; }

.kinds { display: flex; flex-wrap: wrap; gap: 8px; border: 1px solid var(--color-border); padding: 4px 8px; }
</style>
