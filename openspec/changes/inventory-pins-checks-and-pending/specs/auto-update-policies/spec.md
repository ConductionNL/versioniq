# auto-update-policies Specification Delta

**Status**: proposed
**Scope**: versioniq
**OpenSpec changes**: [inventory-pins-checks-and-pending](../../)

## ADDED Requirements

### Requirement: The overview lists the updates that are pending now

The automatic updates overview MUST list, for every app with a policy, the newer version that is available now according to the last availability sweep, and its state: scheduled for the next window, waiting for approval, held by a pin or by the minimum age, or failed earlier. It MUST NOT query every source when the page opens; it MUST read the stored sweep and say when that sweep ran.

#### Scenario: An admin sees what tonight's run will do

- **GIVEN** Dossiq has policy "patches only" and 0.4.50 is available, and Decidiq is pinned on 0.9.3 with 0.10.1 available
- **WHEN** the admin opens the automatic updates tab
- **THEN** Dossiq 0.4.50 MUST be listed as scheduled for the next window
- **AND** Decidiq 0.10.1 MUST be listed as held by the pin
