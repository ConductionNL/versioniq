// SPDX-License-Identifier: EUPL-1.2
// The forges the admin pages offer (issue #437). Codeberg is retired as a
// forge of its own: it is never offered for a new binding, token or trusted
// pattern, but anything still stored under it keeps working and is labelled
// retired. Another Forgejo or Gitea host, Codeberg included, is the
// self-hosted `forgejo` forge, whose host is set on the Sources tab.

// @spec openspec/specs/external-sources/spec.md
import { t } from '@nextcloud/l10n'

export type ForgeOption = { id: string, label: string }

export const FORGE_GITHUB = 'github'
export const FORGE_FORGEJO = 'forgejo'

const RETIRED_FORGES = ['codeberg']

/**
 * The forges an admin may pick for something new.
 */
export function forgeOptions (): ForgeOption[] {
	return [
		{ id: FORGE_GITHUB, label: 'GitHub' },
		{ id: FORGE_FORGEJO, label: t('versioniq', 'Self-hosted Forgejo or Gitea') },
	]
}

/**
 * Whether a forge is kept only for stored data.
 *
 * @param forge a forge id, such as github or codeberg
 */
export function isRetiredForge (forge: string): boolean {
	return RETIRED_FORGES.includes(forge)
}

/**
 * The forge part of a forge-qualified source id or pattern, such as
 * `codeberg` for `codeberg:Conduction/*`; empty when there is none.
 *
 * @param qualified a source id or trusted pattern
 */
export function forgeOf (qualified: string): string {
	const separator = qualified.indexOf(':')
	return separator > 0 ? qualified.slice(0, separator) : ''
}

/**
 * The display name of a forge.
 *
 * @param forge a forge id
 */
export function forgeLabel (forge: string): string {
	return forgeOptions().find((option) => option.id === forge)?.label ?? forge
}
