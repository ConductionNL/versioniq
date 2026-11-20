# auto-update-policies Specification Delta

**Status**: proposed
**Scope**: versioniq
**OpenSpec changes**: [autoupdate-default-and-config-file](../../)

## MODIFIED Requirements

### Requirement: Per-app update policy [MVP]

Admins MUST be able to set, read, and clear a per-app policy `level` ∈ `none|patch|minor|all` via `GET /api/policies`, `PUT /api/app/{appId}/policy`, `DELETE /api/app/{appId}/policy`; writes MUST require password confirmation and MUST record `setBy`/`setAt`. Policy MUST be persisted as `policy.{appId}` app config JSON. Absent policy means the instance default level, which is `none` unless an admin set another. `GET /api/policies` MUST return, per app, whether its level is its own or the default. Non-admins MUST receive 403.

#### Scenario: Set a patch policy

@e2e tests/e2e/auto-update.spec.ts

- WHEN admin `alice` calls `PUT /api/app/openregister/policy` with `{level: "patch"}` and confirms her password
- THEN `policy.openregister` MUST record level patch, setBy alice, setAt ISO-8601
- AND `GET /api/policies` MUST list it

#### Scenario: Invalid level rejected

@e2e tests/e2e/install-effects.spec.ts

- WHEN `PUT .../policy` is called with `{level: "yolo"}`
- THEN the response MUST be 400 and no policy MUST be written

#### Scenario: An app without a policy follows the default

- GIVEN the instance default level is `patch` and `calendar` has no stored policy
- WHEN the admin opens the Apps tab
- THEN the `calendar` card MUST show level patch marked as the default
- AND the nightly job MUST treat `calendar` as a patch policy

## ADDED Requirements

### Requirement: Policies can be kept in a file

`occ versioniq:policies:export` MUST write the kill switch, the window, the default level and every per-app policy to a JSON file with `schemaVersion` 1. `occ versioniq:policies:import <file>` MUST validate the file, report every problem with its JSON path, and apply it through the same stores as the API with one audit row per changed policy; `--dry-run` MUST only print the difference, and `--prune` MUST remove stored per-app policies the file does not name. When `config.php` names a policy file, the nightly job MUST read its policies from that file at every run, MUST do nothing when the file is invalid, the page MUST show the policies read-only with the file's path, and policy writes through the API MUST answer 409.

#### Scenario: An admin reviews a policy change before applying it

- GIVEN an exported policy file in which the admin changed `openregister` from `patch` to `minor`
- WHEN the admin runs `occ versioniq:policies:import policies.json --dry-run`
- THEN the command MUST print that `openregister` goes from patch to minor
- AND no policy MUST change until the admin runs it without `--dry-run`

#### Scenario: A broken file stops automatic updates instead of guessing

- GIVEN `config.php` names a policy file that contains the level `weekly`
- WHEN the nightly job runs inside the window
- THEN it MUST install nothing and log the file error with its JSON path
- AND the Automatic updates section MUST show the error
