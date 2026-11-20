// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// Runs a rollback plan in the browser (install-bulk-rollback design D4): one
// standard install per step, in plan order, from the snapshot's source, so
// every install is attributed to the admin who started it. A downgrade step
// sends allowDowngrade (the admin acknowledged the plan), a ticked pinned step
// sends overridePin=repin. The run stops at the first failure; the caller may
// continue with the rest from the step after it.

import { apiUrl, ocsHeaders, withOcsJson } from '../ocs.ts'
import { compareVersions } from './versionCompare.ts'

export type RollbackStepKind = 'unchanged' | 'downgrade' | 'upgrade' | 'pinned' | 'removed' | 'added'

export type RollbackStep = {
	appId: string
	kind: RollbackStepKind
	from: string | null
	to: string | null
	sourceId: string | null
	pinnedTo: string | null
}

export type StepOutcome = {
	appId: string
	outcome: 'succeeded' | 'failed' | 'checked'
	message: string
	orphanedMigrations: string[]
}

export type InstallStep = (step: RollbackStep, dryRun: boolean) => Promise<StepOutcome>

/**
 * Whether a step moves the app back to its snapshot version.
 *
 * @param step A plan step.
 * @param movePins Whether the admin chose to move pinned apps.
 * @spec openspec/changes/install-bulk-rollback/specs/migration-safety/spec.md
 */
export function isRunnable (step: RollbackStep, movePins: boolean): boolean {
	return step.kind === 'downgrade' || step.kind === 'upgrade' || (movePins && step.kind === 'pinned')
}

/**
 * Whether a step installs an older version than the one running now.
 *
 * @param step A plan step.
 * @spec openspec/changes/install-bulk-rollback/specs/migration-safety/spec.md
 */
export function isDowngrade (step: RollbackStep): boolean {
	return step.from !== null && step.to !== null && compareVersions(step.to, step.from) < 0
}

/**
 * The install query of one step.
 *
 * @param step A runnable plan step.
 * @param dryRun Whether to check the step without installing it.
 * @spec openspec/changes/install-bulk-rollback/specs/migration-safety/spec.md
 */
export function stepQuery (step: RollbackStep, dryRun: boolean): Record<string, string> {
	const query: Record<string, string> = { debug: '0', dryRun: dryRun ? '1' : '0', targetVersion: step.to ?? '' }
	if (step.sourceId) {
		query.source = step.sourceId
	}
	if (isDowngrade(step)) {
		query.allowDowngrade = '1'
	}
	if (step.kind === 'pinned') {
		query.overridePin = 'repin'
	}
	return query
}

type OcsInstallResponse = {
	ocs?: { meta?: { status?: string, statuscode?: number, message?: string }, data?: { message?: string, hint?: string, orphanedMigrations?: string[] | null } }
}

/**
 * Posts the standard install of one step and reads its outcome.
 *
 * @param step A runnable plan step.
 * @param dryRun Whether to check the step without installing it.
 * @spec openspec/changes/install-bulk-rollback/specs/migration-safety/spec.md
 */
export async function installStep (step: RollbackStep, dryRun: boolean): Promise<StepOutcome> {
	const version = step.to ?? ''
	const endpoint = withOcsJson(
		`/ocs/v2.php/apps/versioniq/api/app/${encodeURIComponent(step.appId)}/versions/${encodeURIComponent(version)}/install`,
		stepQuery(step, dryRun),
	)
	try {
		const response = await fetch(apiUrl(endpoint), {
			method: 'POST',
			headers: { ...ocsHeaders, Accept: 'application/json', 'Content-Type': 'application/json' },
			body: JSON.stringify({ version }),
		})
		const raw = (await response.json()) as OcsInstallResponse
		const meta = raw.ocs?.meta
		const data = raw.ocs?.data ?? {}
		const failed = !response.ok || meta?.status === 'failure' || (typeof meta?.statuscode === 'number' && meta.statuscode >= 400)
		return {
			appId: step.appId,
			outcome: failed ? 'failed' : (dryRun ? 'checked' : 'succeeded'),
			message: data.message || meta?.message || '',
			orphanedMigrations: Array.isArray(data.orphanedMigrations) ? data.orphanedMigrations : [],
		}
	} catch (error) {
		return { appId: step.appId, outcome: 'failed', message: error instanceof Error ? error.message : String(error), orphanedMigrations: [] }
	}
}

export type RunOptions = {
	movePins: boolean
	dryRun: boolean
	/** The index in `steps` to start at; "Continue with the rest" passes the step after the failure. */
	startAt?: number
	/** Asked before each step; true stops the run after the current install. */
	shouldStop?: () => boolean
	/** Called after each step. */
	onStep?: (index: number, outcome: StepOutcome) => void
	/** The install of one step; injectable for tests. Defaults to installStep. */
	install?: InstallStep
}

export type RunResult = {
	outcomes: Map<number, StepOutcome>
	/** The index of the failed step the run stopped at, or null. */
	failedAt: number | null
	/** Whether the run stopped because shouldStop() said so. */
	interrupted: boolean
}

/**
 * Runs (or, with dryRun, checks) the steps of a plan in order. A check covers
 * only the downgrade steps; a run stops at the first failure.
 *
 * @param steps The plan.
 * @param options What to run and how, and the install of one step.
 * @spec openspec/changes/install-bulk-rollback/specs/migration-safety/spec.md
 */
export async function runRollback (steps: RollbackStep[], options: RunOptions): Promise<RunResult> {
	const install = options.install ?? installStep
	const outcomes = new Map<number, StepOutcome>()
	for (let index = options.startAt ?? 0; index < steps.length; index++) {
		const step = steps[index]
		if (!isRunnable(step, options.movePins) || (options.dryRun && !isDowngrade(step))) {
			continue
		}
		if (options.shouldStop?.()) {
			return { outcomes, failedAt: null, interrupted: true }
		}
		const outcome = await install(step, options.dryRun)
		outcomes.set(index, outcome)
		options.onStep?.(index, outcome)
		if (outcome.outcome === 'failed' && !options.dryRun) {
			return { outcomes, failedAt: index, interrupted: false }
		}
	}
	return { outcomes, failedAt: null, interrupted: false }
}

/**
 * The counts for the run log: succeeded and failed from the outcomes, and
 * every other step that would have moved as skipped.
 *
 * @param steps The plan.
 * @param run The outcome of each step that ran, by index, and whether pinned steps were included.
 * @param run.outcomes The outcome of each step that ran, by index.
 * @param run.movePins Whether pinned steps were included.
 * @spec openspec/changes/install-bulk-rollback/specs/migration-safety/spec.md
 */
export function runCounts (steps: RollbackStep[], { outcomes, movePins }: { outcomes: Map<number, StepOutcome>, movePins: boolean }): { succeeded: number, failed: number, skipped: number } {
	const counts = { succeeded: 0, failed: 0, skipped: 0 }
	steps.forEach((step, index) => {
		const outcome = outcomes.get(index)
		if (outcome?.outcome === 'succeeded') {
			counts.succeeded++
		} else if (outcome?.outcome === 'failed') {
			counts.failed++
		} else if (isRunnable(step, movePins) || step.kind === 'pinned') {
			counts.skipped++
		}
	})
	return counts
}
