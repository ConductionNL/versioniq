---
sidebar_position: 3
---

# CLI Commands

Versioniq exposes its version listing and version-specific install
through `occ`, so provisioning scripts, CI pipelines, and Docker image
builds can reproduce an exact app version — a capability core's
`occ app:install` lacks (requested upstream in
[nextcloud/server#36940](https://github.com/nextcloud/server/issues/36940);
the implementing PR was never merged). Both commands are thin adapters
over the same `InstallerService` the HTTP API uses — identical source
resolution, allowlist enforcement, integrity verification, backup/restore,
maintenance-mode handling, and finalize behavior.

CLI execution runs as the server user with full config access — the trust
context is equivalent to `occ app:install`, so neither command prompts for
password confirmation.

## `occ versioniq:versions`

```
occ versioniq:versions <appId> [--source=<sourceId>] [--json]
```

Lists the versions available for an already-installed app from its bound
source (or a one-off `--source` override), including the installed
version, where each version stands relative to the installed one
(`installed` / `newer` / `older`), whether it runs on this server
(`yes` / `no` / `unknown`), and the source id.

Whether a version runs on this server comes from the App Store release's
platform range. A forge release does not state one, so it shows `unknown`;
the install still checks the app's own range before it finalises.

```
$ occ versioniq:versions openregister
App: openregister
Source: appstore
Installed version: 2.4.0
+---------+-----------------------+---------------------+------------------+
| Version | Relative to installed | Runs on this server | Recorded SHA-256 |
+---------+-----------------------+---------------------+------------------+
| 2.5.0   | newer                 | no                  |                  |
| 2.4.0   | installed             | yes                 |                  |
| 2.3.0   | older                 | yes                 |                  |
+---------+-----------------------+---------------------+------------------+
```

Pass `--json` for a machine-readable envelope:

```
$ occ versioniq:versions openregister --json
{"installedVersion":"2.4.0","availableVersions":[...],"versions":[...],"source":"appstore","sourceId":"appstore"}
```

An unknown app, or a source that cannot be reached, exits non-zero with
the problem named on stderr.

## `occ versioniq:install`

```
occ versioniq:install <appId> <version> [--source=<sourceId>] [--dry-run] [--allow-downgrade] [--json]
```

Installs `<version>` of `<appId>` through the normal install path:

- `--source=<sourceId>` — one-off source override (e.g.
  `github:ConductionNL/openregister`), instead of the app's bound source.
- `--dry-run` — evaluates the install (integrity checks, downgrade
  detection) without swapping any files.
- `--allow-downgrade` — acknowledges and proceeds with a downgrade
  (target version older than installed). Without it, a downgrade is
  refused before any download.
- `--json` — emits the structured outcome as JSON instead of
  human-readable text.

The command refuses to run against Versioniq itself or a core /
always-enabled app.

### Exit codes

| Code | Meaning |
| ---- | ------- |
| `0` | Success (including a successful dry run) |
| `1` | Unknown / unclassified failure |
| `2` | Unknown app or bad arguments (includes the self/core-app guard) |
| `3` | Downgrade refused — rerun with `--allow-downgrade` |
| `4` | Preflight permission failure (app folder not writable) |
| `5` | Download failure |
| `6` | Integrity failure (checksum, app id, or version mismatch) |
| `7` | Incompatible with the current Nextcloud version |
| `8` | Finalize failure — check the printed `installStatus` (`reverted` vs `installed-but-broken`) |
| `9` | Untrusted source (not on the trusted-source allowlist) |

### Reproducible provisioning example

Pin an exact app version during a Docker image build, so every build of
the image installs the same release regardless of what has shipped to
the App Store since:

```dockerfile
FROM nextcloud:34-apache

# ... install versioniq itself first (occ app:enable versioniq) ...

RUN occ versioniq:install openregister 2.4.0 --json \
    && occ versioniq:install openconnector 1.2.0 --json
```

A CI pipeline can use `--dry-run --json` first to confirm the target
version resolves and passes integrity checks before an actual rollout
step runs the same command without `--dry-run`.
