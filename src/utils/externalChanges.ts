// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Changes made outside Versioniq (audit-external-changes): the readable
// names of the history operations, the trigger line, and the notice on the
// card of an app an update left disabled.
//
// @spec openspec/changes/audit-external-changes

import { t } from '@nextcloud/l10n'

/** What `GET /api/apps` returns per app an update left disabled. */
export type LeftDisabled = {
	toVersion: string
	compatible: boolean
	compatibleVersion: string | null
}

/** Operations whose message is what triggered them. */
const TRIGGERED_OPERATIONS = new Set(['external_update', 'external_disable'])

/**
 * The readable name of a history operation; an unknown one shows as stored.
 *
 * @param operation The stored operation name.
 * @spec openspec/changes/audit-external-changes
 */
export function operationLabel (operation: string): string {
	const labels: Record<string, string> = {
		install: t('versioniq', 'Install'),
		bind_source: t('versioniq', 'Source bound'),
		pin: t('versioniq', 'Pinned'),
		unpin: t('versioniq', 'Unpinned'),
		pin_drift: t('versioniq', 'Pin drift'),
		settings: t('versioniq', 'Settings changed'),
		external_update: t('versioniq', 'Changed outside Versioniq'),
		external_disable: t('versioniq', 'Disabled outside Versioniq'),
		reactivate: t('versioniq', 'Enabled again after an update'),
	}

	return labels[operation] ?? operation
}

/**
 * The message line of a history row: for a change made outside Versioniq it
 * names what triggered it.
 *
 * @param operation The stored operation name.
 * @param message The stored message, if any.
 * @spec openspec/changes/audit-external-changes
 */
export function historyMessage (operation: string, message: string | null): string {
	if (message === null || message === '') {
		return ''
	}

	return TRIGGERED_OPERATIONS.has(operation) ? t('versioniq', 'Trigger: {trigger}', { trigger: message }) : message
}

/**
 * The notice on the card of an app an update left disabled.
 *
 * @param mark What the server remembered about it.
 * @spec openspec/changes/audit-external-changes
 */
export function leftDisabledNotice (mark: LeftDisabled): string {
	const notice = t('versioniq', 'Disabled by the update to {version}', { version: mark.toVersion })
	if (mark.compatible) {
		return notice
	}
	if (mark.compatibleVersion) {
		return `${notice}. ${t('versioniq', 'It does not run on this server; version {version} does.', { version: mark.compatibleVersion })}`
	}

	return `${notice}. ${t('versioniq', 'It does not run on this server.')}`
}

/**
 * The left-disabled marks from the apps payload; an empty map arrives as a list.
 *
 * @param raw The `disabledByUpdate` field as received.
 * @spec openspec/changes/audit-external-changes
 */
export function leftDisabledMarks (raw: unknown): Record<string, LeftDisabled> {
	return raw !== null && typeof raw === 'object' && !Array.isArray(raw) ? raw as Record<string, LeftDisabled> : {}
}
