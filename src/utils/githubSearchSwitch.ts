// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// The public GitHub search switch on the Discover tab
// (sources-trust-audit-and-offline-feed 2.2). Its state is the GitHub search
// provider's `enabled` in the search answer; the switch writes
// `githubSearchEnabled` through the instance settings, which ask for the
// password and audit the change. Private repositories are searched with the
// admin's tokens whatever it says.
// @spec openspec/changes/sources-trust-audit-and-offline-feed/specs/app-discovery/spec.md
import { t } from '@nextcloud/l10n'
import { ocsWrite } from '../ocs.ts'
import { errorMessage } from './errorMessage.ts'

export const GITHUB_SEARCH_PROVIDER = 'github-search'

const SETTINGS = '/ocs/v2.php/apps/versioniq/api/instance-settings'

type Provider = { id: string, label: string, enabled: boolean }

/**
 * Whether public GitHub search is on, from the providers of the last search
 * answer; null while no answer named the provider.
 *
 * @param providers the providers of the last search answer
 */
export function githubSearchState (providers: Provider[]): boolean | null {
	const provider = providers.find((entry) => entry.id === GITHUB_SEARCH_PROVIDER)
	return provider ? provider.enabled === true : null
}

/**
 * The providers with the GitHub search provider set to $enabled.
 *
 * @param providers the providers of the last search answer
 * @param enabled the new state
 */
export function withGithubSearch (providers: Provider[], enabled: boolean): Provider[] {
	return providers.map((entry) => entry.id === GITHUB_SEARCH_PROVIDER ? { ...entry, enabled } : entry)
}

/**
 * The line that says whether public GitHub search is on.
 *
 * @param enabled the state
 */
export function githubSearchStateText (enabled: boolean): string {
	return enabled
		? t('versioniq', 'Search in public GitHub repositories is on.')
		: t('versioniq', 'Search in public GitHub repositories is off. Private repositories are still searched with your tokens.')
}

/**
 * Stores the switch; answers the stored state, or the reason it was not stored.
 *
 * @param enabled the requested state
 */
export async function saveGithubSearch (enabled: boolean): Promise<{ enabled: boolean } | { error: string }> {
	try {
		const { payload, error } = await ocsWrite<{ githubSearchEnabled?: boolean }>('PUT', SETTINGS, { githubSearchEnabled: enabled ? 'true' : 'false' })
		if (error) {
			return { error }
		}
		return { enabled: payload.githubSearchEnabled === true }
	} catch (caught) {
		return { error: errorMessage(caught, t('versioniq', 'Could not save the settings.')) }
	}
}
