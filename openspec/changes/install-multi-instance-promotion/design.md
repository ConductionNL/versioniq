# Design: install-multi-instance-promotion

Read against `development` at 02e1050 (2026-09-27).

## Context

- Every Versioniq endpoint is an OCS route on `ApiController` guarded by `isAdmin()` (`lib/Controller/ApiController.php:1386`). `GET /api/apps` (`lib/Controller/ApiController.php:101-108`) returns `InstallerService::getInstalledApps()` (`lib/Service/InstallerService.php:89`): per app the installed version, state, `boundSourceId` and the last-known-good record. So an admin account on another instance can already read that instance's app list over OCS with an app password.
- The last-known-good record (`lkg.{appId}`, written by `InstallFinalizer::finalize()`, `lib/Service/Installer/InstallFinalizer.php:169-173`) carries the version and `recordedAt` of the last install through Versioniq. It is the only local fact about since when a version runs.
- A forge binding keeps the SHA-256 of every version it installed (`SourceBinding::getRecordedSha()`, `lib/Service/Source/SourceBinding.php:150`). The external installer refuses a package whose digest differs from the recorded one unless `acceptNewSha` is passed (`lib/Service/ExternalReleaseInstallerService.php:201-204`). App Store packages are signed and carry no recorded digest.
- Secrets at rest: personal access tokens are encrypted with `ICrypto` before storage (`lib/Service/Pat/PatManager.php:65`) and decrypted only for the call that needs them (`lib/Service/Pat/PatManager.php:88`).
- Outbound calls go through `IClientService` with local addresses blocked unless the server's `allow_local_remote_servers` is on (`lib/Service/Source/ForgeReleaseSource.php:72`, used in `performFetch()` at `lib/Service/Source/ForgeReleaseSource.php:307`).
- `InstallerService::installAppVersion()` (`lib/Service/InstallerService.php:407`) is the one install path. It carries the downgrade guard (`lib/Service/InstallerService.php:476`), the pin guard (`lib/Service/InstallerService.php:498`), maintenance mode and the outcome taxonomy. The web page, the CLI and the nightly job all call it.

## Goals and non-goals

Goals: one admin sees the app versions of every connected instance in one table, and promotes the exact package an earlier stage ran, with a waiting period and a rollback.

Non-goals: pushing into another instance (D2), managing a Nextcloud that does not run Versioniq, a central server that owns the other instances. Every instance stays admin-only and keeps its own install path.

## Decisions

### D1. The connection is an admin account's app password, stored encrypted

`InstanceConnectionStore` keeps each connection as JSON under app config key `instance.{id}`: `id` (slug), `name`, `stage` (free label, default `test`, `acceptance` or `production`), `order` (its place in the chain), `baseUrl` (https only, no user, query or fragment, the same checks as `InstanceSettings::url()`), `username`, `encryptedPassword` (`ICrypto::encrypt()`), `addedBy`, `addedAt`. The local instance gets a stage and an order too, under `instance.self`. Adding, editing and removing a connection are password-confirmed and audited with a new operation `instance_connection`. The routes are `GET /api/instances`, `POST /api/instances`, `PUT /api/instances/{id}` and `DELETE /api/instances/{id}` on a new `InstanceController`, each admin-only.

On save, `RemoteInstanceReader` calls the remote manifest once. A 401 or 403 is saved as an error and shown: "This account is not an admin on that instance." So admin-only holds on both ends: the local routes check `isAdmin()`, and the remote route refuses anyone who is not an admin there.

Alternative considered: a shared secret that both Versioniq instances trust. Rejected. It needs a new trust mechanism on the remote side, outside Nextcloud's own authentication, and it would let a non-admin with the secret read the remote app list.

### D2. Pull, never push

Promotion runs on the instance that changes. An admin of production opens the Instances tab on production and pulls the version acceptance runs. The install is a local `installAppVersion()` call under the local admin's password confirmation. The remote credential is only ever used for one `GET`.

Alternative considered: acceptance pushes to production. Rejected. Acceptance would have to hold a credential that can install on production, and the production admin would not confirm the change on their own instance.

### D3. The manifest endpoint

`GET /api/instance/manifest` on a new `InstanceController`, admin-only through the same `isAdmin()` check. `ManifestBuilder` returns:

| Field | Source |
|---|---|
| `instanceName` | `instance.self.name`, or the host of `IURLGenerator::getAbsoluteURL('/')` when unset |
| `stage` | `instance.self.stage` |
| `serverVersion` | `OCP\ServerVersion::getVersionString()` |
| `versioniqVersion` | this app's installed version |
| `apps[]` | for each app `getInstalledApps()` returns: `appId`, `installedVersion`, `state`, `sourceId`, `sha256`, `runningSince` |

`sha256` is `getRecordedSha(installedVersion)` on the app's binding, or null for an App Store app. `runningSince` is the last-known-good `recordedAt` when the record's version equals the installed version, otherwise the time of the newest successful audit row whose `to_version` equals it, otherwise null. It never guesses.

The manifest carries no secrets, tokens or file paths. It reads only local state and makes no outbound call, so it answers fast on any instance size.

### D4. Reading remotes

`RemoteInstanceReader::fetch(InstanceConnection)` sends `GET {baseUrl}/ocs/v2.php/apps/versioniq/api/instance/manifest` with Basic authentication, `OCS-APIREQUEST: true`, `Accept: application/json`, a 15 s timeout and `allow_local_address` from `allow_local_remote_servers`. The decrypted password lives only inside that call. The result, with `fetchedAt`, is cached under `instance.{id}.manifest`.

The Instances tab reads the cached manifests through `GET /api/instances` and offers Refresh, which fetches every connection once (`POST /api/instances/refresh`). A remote that fails keeps its last manifest and shows the error and its age. A remote that runs a Versioniq without the endpoint answers 404; the tab says "This instance runs an older Versioniq without instance support."

Alternative considered: a background job that refreshes every hour. Rejected for now: the table is read on demand, and one call per instance is cheap. The command in D8 serves scripts that need a fresh view.

### D5. The table

`InstancesPanel.vue` shows one row per app id that appears on any instance, and one column per instance in chain order, this one included. A cell shows the version and, when known, "since {date}". A row whose versions differ is marked "Differs". A filter shows only differing rows. A cell whose app is not installed reads "Not installed".

### D6. Promotion

On a row the admin picks "Promote from {stage}". `PromotionService::plan(connectionId, appId)` reads the cached manifest and returns the version, the source id, the digest, `runningSince`, the waiting-period verdict and whether the move is an upgrade or a downgrade. `POST /api/app/{appId}/promote` (password-confirmed) then:

1. Refreshes that one remote manifest, so the admin promotes what runs now, not a cached answer.
2. Checks the waiting period (D7).
3. For a forge source, passes the remote's digest as a new optional `expectedSha` argument of `installAppVersion()`. Before the external installer runs, `InstallerService` compares it with the digest the resolved binding records for that version. A different recorded digest refuses the promotion: "The two instances installed different packages for {version}." No recorded digest means the remote's digest is added to the binding with `withRecordedSha()`, so the existing check at `lib/Service/ExternalReleaseInstallerService.php:201-204` refuses a different package. `acceptNewSha` is never passed.
4. Calls `installAppVersion()` with the remote's source id as the one-off source, and `allowDowngrade` only when the admin confirmed a downgrade in the dialog. The trusted-source allowlist, the pin guard and the downgrade guard all apply unchanged.
5. Records an audit row, operation `promote`, with `from_version`, `to_version`, `source_id` and a message naming the connection and its stage.

An App Store package is identified by app id and version: the store serves one signed archive per release.

Rollback is the existing "Roll back to last known good" action. The dialog after a promotion names the previous version and links to it, so the downgrade guard and the migration diff apply.

Alternative considered: copy the package file from the earlier stage. Rejected. It would move an archive between instances outside the signature and digest checks, and the source is still reachable in the normal case.

### D7. The waiting period

`promotion.min_days`, an integer from 0 to 90, 0 meaning off, set on the Settings tab. A promotion whose `runningSince` on the earlier stage is less than that many days ago, or unknown, is refused with a 409 naming the days left. The request may carry `overrideMinDays=1`; the override is written into the audit message.

Alternative considered: a waiting period per app. Rejected until someone asks: the tender asks for one period for releases.

### D8. The commands

`occ versioniq:instances` prints the table of D5, or JSON with `--json`, and `--refresh` fetches every connection first. `occ versioniq:promote <connection> <appId>...` runs D6 for each app in order, with `--dry-run`, `--allow-downgrade` and `--override-min-days`, and exits with the install command's exit codes (`cli-commands` "Install a specific version from the CLI") for the first failure. Two refusals happen before any install and get their own documented codes: `10` waiting period not met, `11` connection unknown or unreadable. It stops at the first failure unless `--continue` is passed. The CLI needs no password confirmation, like `versioniq:install`.

## Risks and trade-offs

- [The app password is a full admin credential on the remote] → it is encrypted at rest, used only for one `GET`, never returned by any endpoint, and the dialog advises a dedicated admin account. Removing the connection deletes it.
- [A remote runs an older Versioniq] → the 404 is shown as "older Versioniq", not as a broken connection.
- [`runningSince` is unknown for a version installed outside Versioniq] → the waiting period treats unknown as not met and needs an override. Once `audit-external-changes` records outside updates, the audit fallback in D3 finds them.
- [An App Store release is pulled after acceptance installed it] → the install fails with the store's own error; the artifact cache fallback of the existing installer still applies.
- [A slow remote holds the page] → reads come from the cache; only Refresh calls out, with a 15 s timeout per remote.

## Migration

No schema change. New app config keys only. Instances without the endpoint keep working; they just cannot be read.
