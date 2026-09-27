# update-checks Specification Delta

**Status**: proposed
**Scope**: versioniq
**OpenSpec changes**: [safety-update-checks](../../)

## Purpose

An admin, or the operator of the server, attaches checks that must pass before an update, and a dry run tells the truth about whether the install will pass.

## ADDED Requirements

### Requirement: A dry run validates the package like a real install

A dry run MUST validate the unpacked package's `appinfo/info.xml` with the same checks a real install runs, for App Store and forge packages alike: the app id, the version, compatibility with the running server, and the declared dependencies including the PHP version and extensions. A dry run that fails one of them MUST report the failure with its category and hint, and MUST change nothing.

#### Scenario: A dry run catches a missing PHP extension

- **GIVEN** the App Store package of `openregister` 2.4.0 requires a PHP extension the server lacks
- **WHEN** an admin runs a dry run of 2.4.0 from the picker
- **THEN** the result MUST report the failed dependency check
- **AND** no file, config value or maintenance mode MUST change

### Requirement: Admins and operators attach checks that every dry run runs

An admin MUST be able to add, edit and remove up to 10 verdict checks on the Settings tab, each with a name, an https address template that may contain `{appId}`, `{version}` and `{fromVersion}`, an optional bearer token and an optional list of apps. A verdict check MUST pass only when the address answers 2xx within 15 s. Writes MUST require password confirmation and MUST be recorded in the audit trail, and the token MUST be stored encrypted and never returned. The server operator MUST be able to define command checks in `config.php` under `versioniq.update_checks`, each a program with arguments, an optional list of apps and a timeout; they MUST NOT be settable from the web page, MUST run without a shell, and MUST pass only on exit code 0 within the timeout. Every dry run MUST run the checks that match the app and MUST report each one's name, result and detail. In a web request a command check with a timeout above 60 s MUST be skipped and reported as running from the command line and the nightly job only.

#### Scenario: A CI verdict blocks an untested version

- **GIVEN** a verdict check "Staging tests" with address `https://ci.example.org/verdict/{appId}/{version}`, and the CI server answers 404 for `openregister` 2.4.0
- **WHEN** admin `alice` runs a dry run of 2.4.0
- **THEN** the result MUST list "Staging tests" as failed with status 404

#### Scenario: An operator's smoke test runs on the unpacked package

- **GIVEN** `config.php` defines a command check "Lint" running `/opt/checks/lint.sh {path}` with a timeout of 30 s
- **WHEN** an admin runs `occ versioniq:install openregister 2.4.0 --dry-run`
- **THEN** the script MUST run with the path of the unpacked 2.4.0 package, and its result MUST be printed
- **AND** a non-zero exit of the script MUST make the command exit 12

#### Scenario: A command check cannot be added from the page

- **GIVEN** an admin on the Settings tab
- **WHEN** they look at the checks section
- **THEN** command checks MUST be listed read-only with a note that they are set in `config.php`
