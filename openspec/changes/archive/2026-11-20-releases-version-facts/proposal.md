---
kind: code
---

# Proposal: releases-version-facts

## Why

When an admin picks a version, the picker shows its number, whether it runs on this server, whether a copy is cached, and its release notes. It does not say when the version came out, whether anyone else runs it, whether its publisher calls it breaking, or which problems were found after release. An admin who rolls back to "the version from before the summer" has to look up dates on GitHub. The version-management spec already asks for a release date per version (scenario "View versions for an app" in `openspec/specs/version-management/spec.md`), and neither source reads one.

This change comes from the versioniq competitor parity matrix `openspec/parity/capabilities.json` (compared 2026-09-26) and covers four rows that each add one fact to a version entry.

| Row | Rating now | What is missing |
|---|---|---|
| `rel-release-date` | no | Release dates are not read from either source and not shown. |
| `rel-adoption` | no | No adoption or success-rate data. |
| `rel-breaking-flag` | partial, built | The missing half: the page counts the major and minor steps a move crosses, but no breaking flag is read from the release itself. |
| `rel-known-issues` | no | Release notes are shown, but not the known problems and workarounds found afterwards. |

### Demand

- `rel-known-issues`: tender, https://www.tenderned.nl/aankondigingen/overzicht/411765. Landelijk, Identity Governance and Administration (IGA), requirement 80142, eis: release notes describe changes against previous versions, known problems with their workarounds, and installation instructions.
- `rel-release-date`, `rel-adoption` and `rel-breaking-flag`: no demand row. All three are in the product's core area (releases), and `rel-breaking-flag` has two competitors rated yes.

### Competitors rated yes (evidence quoted from the matrix)

- `rel-release-date`, Renovate rated yes: "Datasources return releaseTimestamp (nextcloud/index.ts:66); it drives minimumReleaseAge and the Age badge in PRs (docs/usage/merge-confidence.md:17)."
- `rel-adoption`, Renovate rated yes: "Merge Confidence badges show Adoption and Passing percentages from Mend app users (docs/usage/merge-confidence.md:17-20) ... not for the nextcloud datasource." Dependabot rated yes: "Closed service: compatibility scores give the percentage of CI runs that passed in other public repositories for the same update (https://docs.github.com/en/code-security/concepts/supply-chain-security/dependabot-security-updates)."
- `rel-breaking-flag`, Renovate rated yes: "lib/config/options/index.ts:1824 matchIsBreaking and major update types (lib/config/options/index.ts:1999, separateMajorMinor lib/config/options/index.ts:1887); Merge Confidence flags undeclared breaking releases (docs/usage/merge-confidence.md:8)." Dependabot rated yes: "Core: pull requests are labelled major, minor or patch from the version precision (common/lib/dependabot/pull_request_creator/labeler.rb:140); the fetch-metadata action exposes update-type for workflows."
- `rel-known-issues`, no competitor rated yes; all five are rated no.

## What changes

- Every version entry carries `releasedAt`: the App Store release's creation time, or the forge release's publication time. The picker shows "Released {date}" and `occ versioniq:versions` prints it.
- A forge-bound version carries the download count of its release asset, the one signal of adoption a forge publishes. The App Store publishes no per-release count, so App Store versions show none.
- Every version shows how installs of it went through Versioniq: on this instance, from the audit trail, and on connected instances when `install-multi-instance-promotion` has connected any.
- A version whose release notes carry a breaking-change marker, such as a "BREAKING CHANGES" heading, gets a "Breaking" badge. The range summary names every breaking release between the installed and the chosen version, next to the major and minor steps it already counts.
- A "Known issues" section in a version's release notes is shown on its own, above the rest of the notes. Forge release notes can be edited after release, so problems a publisher adds later show up.
- An admin can record a known issue and its workaround on a version. It shows on that version in the picker, and on the app card while that version is installed.

## Scope

In scope: `releasedAt` from both sources, the forge download count, the install outcome counts and their endpoint, the breaking marker, the known-issues section and the admin note, the picker badges, the CLI column, tests.

Out of scope:
- Holding back a version until it is some days old. `releases-minimum-age` specifies that and reads `releasedAt` from this change.
- Skipping breaking releases in the nightly job. No row asks for it; the policy levels already stop at the major boundary.
- Security advisories as known issues. They are already shown per app (`security-advisory-correlation`).
- Sharing an admin's known-issue note with connected instances. A later change can add it to the manifest of `install-multi-instance-promotion`.

## Impact

- New: `lib/Service/Release/ReleaseNotesReader.php` (breaking marker and known-issues section), `lib/Service/Release/KnownIssueStore.php`, `lib/Service/Release/InstallOutcomes.php`, `src/components/VersionFacts.vue`, `src/dialogs/KnownIssueDialog.vue`.
- Changed: `lib/Service/Source/AppStoreSource.php` (`normalizeVersions` reads `created`), `lib/Service/Source/ForgeReleaseSource.php` (`listVersions` reads `published_at` and the matching asset's `download_count`), `lib/Service/InstallerService.php` (`getAppVersions` stamps the facts before truncation), `lib/Db/AuditEntryMapper.php` (outcome counts per version), `lib/Controller/ApiController.php` (known-issue and outcome routes), `lib/Command/ListVersions.php` (column), `src/App.vue` (the picker row and range summary), `l10n/en` and `l10n/nl`.
- ADDED requirements in `version-management` and `changelog-visibility`.

### MCP coverage

No MCP surface in this change: Versioniq publishes no `IMcpToolProvider` yet. `admin-mcp-assistant` specifies one; the version facts are read-only fields it can return.

## Rollback

Revert the change. Admin notes live in app config keys `known_issues.{appId}`; nothing else reads them and `occ config:app:delete` removes them. Every other fact is computed on read.
