# audit-trail Specification Delta

**Status**: proposed
**Scope**: versioniq
**OpenSpec changes**: [sources-trust-audit-and-offline-feed](../../)

## ADDED Requirements

### Requirement: Trusted-source changes and cleared bindings are recorded

Adding a trusted-source pattern MUST write an audit entry with operation `trust_source` naming the pattern and the admin. Removing one MUST write `untrust_source`. Clearing an app's source binding MUST write `unbind_source` naming the app and the source it was bound to. The History tab MUST show these entries with the others, for example "Trusted source added: codeberg.org/zuiddrecht".

#### Scenario: An admin adds a trusted source

- **WHEN** an admin adds the pattern `codeberg.org/zuiddrecht/*` and confirms their password
- **THEN** the audit trail MUST hold a `trust_source` entry with that pattern and the admin's user id
- **AND** the History tab MUST show "Trusted source added: codeberg.org/zuiddrecht"

#### Scenario: An admin clears a binding

- **GIVEN** Dossiq is bound to GitHub `ConductionNL/dossiq`
- **WHEN** an admin clears the binding
- **THEN** the audit trail MUST hold an `unbind_source` entry naming Dossiq and `github:ConductionNL/dossiq`
