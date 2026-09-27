# interface-language Specification Delta

**Status**: proposed
**Scope**: versioniq
**OpenSpec changes**: [admin-translate-apps-tab](../../)

## Purpose

An admin uses Versioniq in their own language, Dutch included, on every tab.

## ADDED Requirements

### Requirement: Every string on the admin page is translatable and has a Dutch translation

Every user-facing string on the Versioniq admin page MUST pass through `t()` or `n()` with an English source key, and every such key MUST exist in `l10n/en.json` and `l10n/nl.json`. The lint run MUST fail when a key is missing from either catalogue, or when a template renders English text outside `t()`.

#### Scenario: A Dutch admin reads the Apps tab in Dutch

- **GIVEN** an admin whose Nextcloud language is Dutch
- **WHEN** they open the Apps tab, pick an app and run a dry run
- **THEN** the dry run switch, the app filter, the version filter and the result grid MUST read in Dutch

#### Scenario: A new English string cannot slip in

- **GIVEN** a developer adds a button with bare English text to `src/App.vue`
- **WHEN** `npm run lint` runs
- **THEN** it MUST fail and name the file and line
