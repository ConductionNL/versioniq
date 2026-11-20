# app-discovery Specification Delta

**Status**: proposed
**Scope**: versioniq
**OpenSpec changes**: [sources-trust-audit-and-offline-feed](../../)

## ADDED Requirements

### Requirement: Public GitHub search is switched on and off on the page

The Discover tab MUST say whether search in public GitHub repositories is on and MUST offer a switch for it. The switch MUST write the existing `discovery.github_search_enabled` key through the instance settings, MUST require password confirmation, and MUST be recorded in the audit trail as a settings change. Private repositories MUST stay searched with the admin's visible tokens whatever the switch says.

#### Scenario: An admin switches public GitHub search on

- **GIVEN** public GitHub search is off
- **WHEN** an admin turns the switch on and confirms their password
- **THEN** the next search MUST include public GitHub hits
- **AND** the tab MUST say search in public GitHub repositories is on
