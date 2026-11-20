# Architecture decision records

Decisions this app has already made, written down in a short MADR-style format (status, context, decision, consequences). Each record cites the OpenSpec design file it comes from, so the full reasoning stays one click away. Smaller decisions that only matter inside one change stay in that change's `design.md` under `openspec/changes/`.

| ADR | Title | Status |
| --- | --- | --- |
| [0001](0001-admin-settings-section-is-the-only-entry-point.md) | The admin settings section is the only entry point to the UI | Accepted |
| [0002](0002-separate-installer-for-external-sources.md) | External release sources get their own installer | Accepted |
| [0003](0003-trust-on-first-use-checksums-for-external-releases.md) | External release checksums are pinned on first use | Accepted |
| [0004](0004-personal-access-tokens-are-encrypted-at-rest.md) | Access tokens are encrypted at rest and decrypted only inside a callback | Accepted |
| [0005](0005-pins-are-self-enforced-and-monitored.md) | Version pins are enforced by Versioniq and monitored elsewhere | Accepted |
| [0006](0006-audit-trail-in-its-own-table.md) | The audit trail is its own table, written best effort and never edited | Accepted |
| [0007](0007-slow-lookups-are-background-sweeps.md) | Lookups across all apps run as background sweeps that store a snapshot | Accepted |

## Adding a record

Copy the shape of an existing record, take the next number, and add it to the table above. Record only a decision the code already follows, and cite where it was made.
