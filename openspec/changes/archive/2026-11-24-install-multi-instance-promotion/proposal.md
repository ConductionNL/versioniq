---
kind: code
---

# Proposal: install-multi-instance-promotion

## Why

Many organisations run Nextcloud more than once: a test instance, an acceptance instance and production. Versioniq manages only the instance it runs on. An admin who wants production to run the version acceptance approved opens both admin pages, reads the version off one and installs it on the other by hand. Nothing checks that the two instances ran the same package, and nothing shows the versions of all instances side by side.

The archived `add-app-discovery-search` proposal lists federation, one Versioniq asking another, as future work. It is not a non-goal.

This change comes from the versioniq competitor parity matrix `openspec/parity/capabilities.json` (compared 2026-09-26) and covers two rows that share one connection between instances.

| Row | Rating now | What is missing |
|---|---|---|
| `inv-multi-instance` | no | Versioniq reads only its own instance. There is no view of the versions on other instances. |
| `ins-staged-promotion` | no | No test, acceptance, production chain. An admin can install the tested version by hand on each instance, but nothing ties the install to what was tested, and nothing enforces a waiting period on the earlier stage. |

### Demand

- `ins-staged-promotion`: tender, https://www.tenderned.nl/aankondigingen/overzicht/416836. SHN Cliënt- en Casusondersteuning, requirement 89061, eis: controlled rollout of changes through the OTAP chain, including rollback. The matrix note adds tender 408309, requirement 133821, eis: releases land on a test or acceptance environment two weeks ahead.
- `inv-multi-instance`: no demand row. The row is in the product's core area (inventory).

### Competitors rated yes (evidence quoted from the matrix)

- `inv-multi-instance`, Renovate rated yes: "lib/config/options/index.ts:1197 autodiscover and lib/config/options/index.ts:1258 repositories run one bot across many repositories; Mend cloud adds an org view in the Developer Portal (docs/usage/mend-hosted/overview.md:26-31)."
- `ins-staged-promotion`, no competitor rated yes. Renovate is partial: "baseBranchPatterns (lib/config/options/index.ts:1268) lets updates land on a test or development branch first, and the tested commit reaches production by an ordinary git merge, with git revert as rollback; Renovate does not track or promote the version between stages itself." Dependabot is partial: "target-branch sends version updates to a chosen branch ... and promotion to production is a normal git merge; Dependabot does not promote or roll back between stages."

## What changes

- An admin adds another Nextcloud instance that runs Versioniq as a connection: a name, a stage (test, acceptance, production or another label), its address, and an app password of an admin account on that instance. The password is stored encrypted.
- Every Versioniq gets one read-only endpoint, `GET /api/instance/manifest`. It lists each managed app with its installed version, state, bound source, the SHA-256 recorded for the installed version, and since when that version runs. It is admin-only, like every other Versioniq endpoint.
- A new Instances tab shows a table: one row per app, one column per instance, this one included. A cell holds the installed version. A row where the instances differ is marked.
- On an app's row an admin can promote the version an earlier stage runs to this instance. The install goes through the standard installer with the source the earlier stage used. For a forge package the SHA-256 the earlier stage recorded must match, or the install is refused.
- An admin can set a minimum number of days a version must run on the earlier stage before it can be promoted. A promotion inside that period needs an explicit override, and the override is audited.
- Each promotion is recorded in the audit trail with the instance it came from. Rollback uses the existing last-known-good flow, with the downgrade guard and the migration diff.
- `occ versioniq:instances` prints the table and `occ versioniq:promote` promotes one or more apps from a connection, for scripts and for runs too long for a web request.

## Scope

In scope: the connection store, the manifest endpoint, the remote reader, the Instances tab, promotion with the SHA and waiting-period checks, the audit operation, both commands, tests.

Out of scope:
- Pushing a version from one instance into another. Promotion is always pulled by an admin of the instance that changes, under that admin's password confirmation. Design D2 says why.
- Promoting many apps in one click on the page. `install-one-click-updates` specifies bulk updates; on the command line `occ versioniq:promote` takes several app ids.
- Instances that do not run Versioniq. The manifest is the only thing read; a plain Nextcloud has none.
- Pending updates across instances. `inventory-pending-updates` specifies them for one instance; a later change can add them to the table.
- Showing the connection on integriq's connections page. `adopt-connection-registry` covers the fixed outside systems; a per-admin list of instances is not in its declaration file.

## Impact

- New: `lib/Service/Instance/InstanceConnection.php`, `lib/Service/Instance/InstanceConnectionStore.php`, `lib/Service/Instance/ManifestBuilder.php`, `lib/Service/Instance/RemoteInstanceReader.php`, `lib/Service/Instance/PromotionService.php`, `lib/Controller/InstanceController.php`, `lib/Command/ListInstances.php`, `lib/Command/PromoteVersion.php`, `src/components/InstancesPanel.vue`, `src/dialogs/InstanceConnectionDialog.vue`, `src/dialogs/PromoteDialog.vue`.
- Changed: `lib/Service/InstallerService.php` (an optional expected digest on `installAppVersion()`), `lib/Service/Audit/AuditLogger.php` (operations `promote` and `instance_connection`), `appinfo/info.xml` (two commands), `src/App.vue` (the tab), `l10n/en` and `l10n/nl`.
- New capability spec `multi-instance`; ADDED requirement in `cli-commands`.

### MCP coverage

No MCP surface in this change: Versioniq publishes no `IMcpToolProvider` yet. `admin-mcp-assistant` specifies one and can read the manifest.

## Rollback

Revert the change. Connections live in app config keys `instance.{id}` and the waiting period in `promotion.min_days`. Nothing else reads them, and `occ config:app:delete versioniq <key>` removes them. Audit rows with operation `promote` stay readable as plain rows.
