<!-- SPDX-License-Identifier: EUPL-1.2 -->
<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { onMounted, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { ocsGet, ocsWrite } from '../ocs.ts'

/**
 * The proposed automatic updates on the Apps tab (autoupdate-proposed-updates
 * D3, D4): what the next run plans per app, with its status, planned date and
 * release notes, the reason for apps without a proposal, and Approve and
 * Decline for exactly the proposed version. The list is the stored proposal
 * of the last update check; the time it was computed is shown with it.
 *
 * @spec openspec/changes/autoupdate-proposed-updates/specs/auto-update-policies/spec.md
 */
export type Proposal = {
	appId: string
	fromVersion: string
	toVersion: string
	kind: string
	status: 'planned' | 'in_notice' | 'awaiting_approval' | 'approved' | 'declined'
	proposedAt: string
	plannedFor: string | null
	announcedAt: string | null
	releaseNotes: string
	approvedBy: string | null
	approvedAt: string | null
	declinedBy: string | null
	declinedAt: string | null
	declineReason: string | null
}

type Listing = { proposals: Proposal[], reasons: { appId: string, reason: string }[], computedAt: string | null }

const props = defineProps<{
	labels?: Record<string, string>
}>()

const ENDPOINT = '/ocs/v2.php/apps/versioniq/api/auto-update/proposals'

const listing = ref<Listing | null>(null)
const error = ref('')
const busy = ref('')
const notesOpen = ref<Record<string, boolean>>({})
const declineReasons = ref<Record<string, string>>({})

/**
 * @param appId The app id.
 * @spec openspec/changes/autoupdate-proposed-updates/specs/auto-update-policies/spec.md
 */
function labelFor (appId: string): string {
	return props.labels?.[appId] ?? appId
}

/**
 * @param iso An ISO time.
 * @spec openspec/changes/autoupdate-proposed-updates/specs/auto-update-policies/spec.md
 */
function formatTime (iso: string | null): string {
	if (!iso) {
		return ''
	}
	const date = new Date(iso)
	return Number.isNaN(date.getTime()) ? iso : date.toLocaleString()
}

/**
 * @param proposal The proposal.
 * @spec openspec/changes/autoupdate-proposed-updates/specs/auto-update-policies/spec.md
 */
function statusText (proposal: Proposal): string {
	switch (proposal.status) {
	case 'approved':
		return t('versioniq', 'Approved by {user}', { user: proposal.approvedBy ?? '' })
	case 'declined':
		return proposal.declineReason
			? t('versioniq', 'Declined by {user}: {reason}', { user: proposal.declinedBy ?? '', reason: proposal.declineReason })
			: t('versioniq', 'Declined by {user}', { user: proposal.declinedBy ?? '' })
	case 'awaiting_approval':
		return t('versioniq', 'Awaiting approval since {date}', { date: formatTime(proposal.proposedAt) })
	case 'in_notice':
		return t('versioniq', 'Announced, in the notice period')
	default:
		return t('versioniq', 'Planned')
	}
}

/**
 * @param reason The planner's reason for an app without a proposal.
 * @spec openspec/changes/autoupdate-proposed-updates/specs/auto-update-policies/spec.md
 */
function reasonText (reason: string): string {
	return {
		pinned: t('versioniq', 'Pinned, so it is not updated automatically.'),
		blocked: t('versioniq', 'An earlier automatic attempt at this version failed.'),
		not_due: t('versioniq', 'Not due in the next run.'),
		limit: t('versioniq', 'Held back by the run limit.'),
		no_candidate: t('versioniq', 'No newer version within the policy.'),
		source_error: t('versioniq', 'The source could not be read.'),
	}[reason] ?? reason
}

/**
 * @spec openspec/changes/autoupdate-proposed-updates/specs/auto-update-policies/spec.md
 */
async function load (): Promise<void> {
	error.value = ''
	try {
		const { payload, error: apiError } = await ocsGet<Listing>(ENDPOINT)
		if (apiError) {
			error.value = apiError
			return
		}
		listing.value = {
			proposals: Array.isArray(payload?.proposals) ? payload.proposals : [],
			reasons: Array.isArray(payload?.reasons) ? payload.reasons : [],
			computedAt: typeof payload?.computedAt === 'string' ? payload.computedAt : null,
		}
	} catch (e) {
		error.value = e instanceof Error ? e.message : t('versioniq', 'Could not load the proposed updates.')
	}
}

/**
 * @param proposal The proposal to decide on.
 * @param decision Approve or decline exactly its version.
 * @spec openspec/changes/autoupdate-proposed-updates/specs/auto-update-policies/spec.md
 */
async function decide (proposal: Proposal, decision: 'approve' | 'decline'): Promise<void> {
	busy.value = proposal.appId
	error.value = ''
	try {
		const body: Record<string, unknown> = { version: proposal.toVersion }
		if (decision === 'decline') {
			body.reason = (declineReasons.value[proposal.appId] ?? '').trim()
		}
		const { error: apiError } = await ocsWrite('POST', `${ENDPOINT}/${encodeURIComponent(proposal.appId)}/${decision}`, body)
		if (apiError) {
			error.value = apiError
			return
		}
		await load()
	} catch (e) {
		error.value = e instanceof Error ? e.message : t('versioniq', 'Could not save the decision.')
	} finally {
		busy.value = ''
	}
}

onMounted(load)
</script>

<template>
	<section :class="$style.panel" data-testid="proposed-updates-panel">
		<h4>{{ t('versioniq', 'Proposed updates') }}</h4>
		<NcNoteCard v-if="error" type="error">
			<span data-testid="proposed-updates-error">{{ error }}</span>
		</NcNoteCard>
		<template v-if="listing">
			<p :class="$style.hint" data-testid="proposed-updates-computed">
				{{ listing.computedAt
					? t('versioniq', 'Computed at the last update check, {time}.', { time: formatTime(listing.computedAt) })
					: t('versioniq', 'No update check has computed proposals yet.') }}
			</p>
			<p v-if="listing.proposals.length === 0 && listing.reasons.length === 0" :class="$style.hint">
				{{ t('versioniq', 'No automatic update is proposed.') }}
			</p>
			<ul :class="$style.list">
				<li v-for="proposal in listing.proposals"
					:key="proposal.appId"
					:class="$style.row"
					:data-testid="`proposal-${proposal.appId}`">
					<div>
						<strong>{{ labelFor(proposal.appId) }}</strong>
						{{ t('versioniq', '{from} to {to}', { from: proposal.fromVersion, to: proposal.toVersion }) }}
					</div>
					<div :class="$style.hint" data-testid="proposal-status">
						{{ statusText(proposal) }}
						<template v-if="proposal.plannedFor">
							· {{ t('versioniq', 'planned for {date}', { date: formatTime(proposal.plannedFor) }) }}
						</template>
					</div>
					<template v-if="proposal.releaseNotes">
						<NcButton variant="tertiary"
							:aria-expanded="notesOpen[proposal.appId] ? 'true' : 'false'"
							data-testid="proposal-notes-toggle"
							@click="notesOpen[proposal.appId] = !notesOpen[proposal.appId]">
							{{ notesOpen[proposal.appId] ? t('versioniq', 'Hide release notes') : t('versioniq', 'Show release notes') }}
						</NcButton>
						<pre v-if="notesOpen[proposal.appId]" :class="$style.notes" data-testid="proposal-notes">{{ proposal.releaseNotes }}</pre>
					</template>
					<div v-if="proposal.status !== 'approved' && proposal.status !== 'declined'" :class="$style.actions">
						<NcButton variant="primary"
							:disabled="busy !== ''"
							data-testid="proposal-approve"
							@click="decide(proposal, 'approve')">
							{{ t('versioniq', 'Approve {version}', { version: proposal.toVersion }) }}
						</NcButton>
						<label :class="$style.reason" :for="`proposal-reason-${proposal.appId}`">
							<span>{{ t('versioniq', 'Reason for declining (optional)') }}</span>
							<input :id="`proposal-reason-${proposal.appId}`"
								v-model="declineReasons[proposal.appId]"
								type="text"
								data-testid="proposal-decline-reason">
						</label>
						<NcButton variant="secondary"
							:disabled="busy !== ''"
							data-testid="proposal-decline"
							@click="decide(proposal, 'decline')">
							{{ t('versioniq', 'Decline {version}', { version: proposal.toVersion }) }}
						</NcButton>
					</div>
				</li>
				<li v-for="row in listing.reasons"
					:key="`reason-${row.appId}`"
					:class="$style.row"
					:data-testid="`proposal-reason-${row.appId}`">
					<strong>{{ labelFor(row.appId) }}</strong>
					<span :class="$style.hint">{{ reasonText(row.reason) }}</span>
				</li>
			</ul>
		</template>
	</section>
</template>

<style module>
.panel {
	display: flex;
	flex-direction: column;
	gap: 6px;
	margin-top: 12px;
}

.list {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin: 0;
	padding: 0;
	list-style: none;
}

.row {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	align-items: flex-end;
}

.reason {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.notes {
	white-space: pre-wrap;
	max-height: 240px;
	overflow: auto;
	padding: 8px;
	border-radius: var(--border-radius);
	background: var(--color-background-dark);
}

.hint {
	color: var(--color-text-maxcontrast);
}
</style>
