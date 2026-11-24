# cli-commands Specification Delta

**Status**: proposed
**Scope**: versioniq
**OpenSpec changes**: [install-multi-instance-promotion](../../)

## ADDED Requirements

### Requirement: Compare instances and promote versions from the CLI

`occ versioniq:instances` MUST print, per app, the installed version on this instance and on every connected instance, from the cached manifests, or read every connection first with `--refresh`. `--json` MUST print the same data with each manifest's read time. `occ versioniq:promote <connection> <appId>...` MUST promote each named app from that connection in order, with the same checks as the page: fresh manifest, waiting period, source, recorded digest and the standard installer. It MUST accept `--dry-run`, `--allow-downgrade` and `--override-min-days`, MUST stop at the first failure unless `--continue` is given, and MUST exit with the exit code of `versioniq:install` for an install failure, `10` when the waiting period is not met, and `11` when the connection is unknown or cannot be read.

#### Scenario: A deployment script promotes two apps

- **GIVEN** a connection `acceptance` that runs `openregister` 2.4.1 and `opencatalogi` 1.9.0 for longer than the waiting period
- **WHEN** an admin runs `occ versioniq:promote acceptance openregister opencatalogi`
- **THEN** both versions MUST be installed through the standard installer and the exit code MUST be 0

#### Scenario: The waiting period stops a script

- **GIVEN** acceptance runs `openregister` 2.4.1 for fewer days than the waiting period
- **WHEN** an admin runs `occ versioniq:promote acceptance openregister`
- **THEN** nothing MUST be installed, stderr MUST name the days left, and the exit code MUST be 10
