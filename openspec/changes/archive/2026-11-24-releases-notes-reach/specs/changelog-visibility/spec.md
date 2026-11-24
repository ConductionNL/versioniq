# changelog-visibility Specification Delta

**Status**: proposed
**Scope**: versioniq
**OpenSpec changes**: [releases-notes-reach](../../)

## ADDED Requirements

### Requirement: Users hear what changed after an update through Versioniq

After a successful real install that changes an app's version, the system MUST dispatch the platform's `AppUpdateEvent` for that app, after the app's pin state is settled, so the platform notifies the app's users as it does for its own updates. A dry run, a reinstall of the same version and a failed install MUST NOT dispatch it. An admin MUST be able to switch this off on the Settings tab; it MUST be on by default.

#### Scenario: A user is told about an update the admin made in Versioniq

- **GIVEN** `calendar` is enabled for user `bob`, and an admin updates `calendar` from 5.0.0 to 5.1.0 through Versioniq
- **WHEN** the platform processes the update event
- **THEN** `bob` MUST receive Nextcloud's app updated notification for `calendar`

#### Scenario: A re-pin does not read as drift

- **GIVEN** `openregister` is pinned to 2.3.0 and an admin installs 2.4.0 with override re-pin
- **WHEN** the install completes and the event is dispatched
- **THEN** the pin MUST read 2.4.0 with no drift, and no drift notification MUST be sent

### Requirement: An admin translates release notes into their language

Each version entry MUST say which language its release notes are in, or that the language is unknown. When the notes are not in the admin's language and the instance has a translation provider, the release notes disclosure MUST offer "Translate to {language}". The translated text MUST be labelled as a machine translation, MUST be stored so the same notes are translated once, and the original MUST stay reachable. Without a provider the button MUST NOT show.

#### Scenario: A Dutch admin reads English notes in Dutch

- **GIVEN** the admin's language is Dutch, the instance has a translation provider, and `openregister` 2.4.1 has English release notes only
- **WHEN** the admin opens the 2.4.1 release notes and clicks "Translate to Dutch"
- **THEN** the notes MUST show in Dutch, marked "Machine translation"
- **AND** opening them again MUST NOT call the translation provider again
