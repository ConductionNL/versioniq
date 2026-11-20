# 7. Lookups across all apps run as background sweeps that store a snapshot

- Status: Accepted
- Date: 2026-09-29

## Context

Answering a question for every installed app (which advisories apply, how far behind each app is) costs at least one external call per app. Issue 160 showed the cost: the live advisory endpoint did not answer within 120 seconds on an instance with 88 apps and held the session lock, so other requests waited. The fix there was a background sweep writing a stored result that the endpoint reads.

## Decision

- Give whole-instance lookups the same shape: a `TimedJob` runs the sweep within a time budget and saves the result to a store, and the API endpoint only reads that stored snapshot and never calls a source.
- For pending updates, `AvailabilityRefreshJob` (every 6 hours) calls `AvailabilityService::sweep()` and saves through `AvailabilityResultStore`, following `AdvisoryResultStore`. The snapshot carries the time it was taken, a snapshot that fails to encode keeps the previous one, and reading before the first sweep returns no time.
- Keep separate sweeps in separate jobs rather than extending one job, so one failing does not hide the other.

## Consequences

- Pages and the CLI answer quickly regardless of the number of apps or the speed of external sources.
- Results can be up to one sweep interval old, so they are shown with the time they were checked.
- Values derived from settings (such as the lag limit) are computed on read, so changing a setting does not need a new sweep.

## Source

`openspec/changes/archive/2026-09-29-inventory-pending-updates/design.md` ("Context", "D1. A sweep and a snapshot, not a live list", "D3. The lag limit"); implemented in `lib/BackgroundJob/AvailabilityRefreshJob.php`, `lib/Service/Availability/AvailabilityResultStore.php` and `lib/Service/Advisory/AdvisoryResultStore.php`.
