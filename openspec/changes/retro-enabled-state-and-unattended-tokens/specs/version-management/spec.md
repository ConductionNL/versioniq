# version-management Specification Delta

**Status**: proposed
**Scope**: versioniq
**OpenSpec changes**: [retro-enabled-state-and-unattended-tokens](../../)

## MODIFIED Requirements

### Requirement: Install Specific Version [MVP]

The system MUST allow an admin to install any available version of an app, replacing the currently installed version. This operation MUST require password confirmation for security. Every install failure MUST return a structured payload — independent of the debug toggle — carrying `stage` (the last installer stage reached, e.g. `backup`, `download`, `checksum`, `archive-extracted`, `info-validated`, `finalize`), `category` (one of `preflight_permission | download | checksum_mismatch | extract | appid_mismatch | version_mismatch | incompatible | finalize | unknown`), and a `hint` (a human, actionable, translatable remediation string). The HTTP status MUST reflect the category and MUST NOT be a blanket 500 for classified failures; 500 is reserved for `unknown`/unexpected errors. A version change MUST keep the app's enabled or disabled state: an app that was enabled, for all users or for some groups, MUST be enabled the same way afterwards, and an app that was disabled MUST stay disabled. A first install of an app that was not installed MUST leave it disabled.

#### Scenario: Install an older version (rollback)

@e2e tests/e2e/version-management.spec.ts

- GIVEN OpenRegister is currently at version 2.5.0
- WHEN the admin selects version 2.3.0 and confirms their password
- THEN the system MUST download version 2.3.0 from the app store
- AND replace the current app files with the downloaded version
- AND show a success message with the new version number
- AND the app MUST remain enabled after the version change
- AND the payload `installStatus` MUST be `installed`

#### Scenario: Install a newer version (upgrade)

@e2e tests/e2e/version-management.spec.ts

- GIVEN OpenRegister is currently at version 2.3.0 and version 2.5.0 is available
- WHEN the admin selects version 2.5.0 and confirms
- THEN the system MUST download and install version 2.5.0
- AND any database migrations for the new version MUST be triggered

#### Scenario: Installation fails

@e2e tests/e2e/forge.spec.ts

- GIVEN a download or extraction error occurs during installation
- WHEN the admin attempts to install a version
- THEN the system MUST show a clear error message
- AND the previous version MUST remain intact (no partial installs)
- AND the response payload MUST include `stage`, `category`, and `hint` fields even when debug mode is OFF
- AND the `hint` MUST be an actionable, translatable remediation string

#### Scenario: Failure category drives HTTP status

@e2e tests/e2e/version-management.spec.ts

- GIVEN an install fails with category `preflight_permission`
- WHEN the response is returned
- THEN the HTTP status MUST NOT be 500
- AND the status MUST be a category-appropriate code (e.g. 409 for `preflight_permission`, 422 for `incompatible`/`version_mismatch`/`appid_mismatch`/`checksum_mismatch`)
- AND a genuinely unexpected error MUST map to category `unknown` with HTTP 500

#### Scenario: Frontend surfaces the structured message

@e2e exclude a real UI-triggered install resets opcache and 503s the mod_php test image; the result-panel rendering is covered by App.vue vitest.

- GIVEN the backend returns a failure payload with `message` and `hint`
- WHEN the frontend renders the install result
- THEN the frontend MUST display the backend `message`/`hint` rather than the generic OCS meta message
- AND the result card MUST render the `stage`, `category`, and `hint`

#### Scenario: Password confirmation required

@e2e exclude the e2e admin session is always password-confirmed; the un-confirmed rejection cannot be produced in a browser. Enforced by the PasswordConfirmationRequired attribute (route-auth gate).

- GIVEN an admin clicks "Install" for a specific version
- WHEN the install action is triggered
- THEN the system MUST require password re-confirmation before proceeding
- AND the install MUST NOT proceed without valid password confirmation

#### Scenario: A disabled app stays disabled across a version change

@e2e exclude a real UI-triggered install resets opcache and 503s the mod_php test image; the carried flag is covered by the installer unit tests.

- GIVEN OpenRegister is installed at version 2.5.0 and disabled
- WHEN the admin installs version 2.3.0
- THEN OpenRegister MUST be at version 2.3.0
- AND OpenRegister MUST still be disabled

#### Scenario: An app enabled for some groups keeps its groups

@e2e exclude a real UI-triggered install resets opcache and 503s the mod_php test image; the carried flag is covered by the installer unit tests.

- GIVEN an app is enabled only for the group `finance`
- WHEN the admin installs another version of it
- THEN the app MUST be enabled only for the group `finance` afterwards
