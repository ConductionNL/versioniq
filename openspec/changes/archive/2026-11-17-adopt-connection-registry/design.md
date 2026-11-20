# Design: adopt-connection-registry

The contract is hydra `openspec/changes/connection-registry/design.md` (hydra#667, amended in hydra#673 and hydra#674). This file records how Versioniq meets it and where it fits loosely.

## D1. Which connections are declared

Each candidate was checked against the code on `main` on 2026-09-14.

| Key | Code | Declared as | Why |
|---|---|---|---|
| `appstore` | `lib/Service/Source/AppStoreSource.php` | `reportedOnly: true`, no `settingsUrl` | `appstore.api_base` is an optional override. Empty means the public store answers. No admin screen sets it, only `occ`. |
| `github` | `lib/Service/Source/ForgeRegistry.php`, `ForgeReleaseSource.php` | `reportedOnly: true`, `settingsUrl` `#section-sources` | `forge.github.api_base` and `forge.github.web_base` are optional overrides. Tokens are stored per user, encrypted. |
| `advisories` | `lib/Service/Advisory/NextcloudAdvisoryFeed.php` | `reportedOnly: true`, `settingsUrl` `#section-advisories` | `advisory.feed_base` is an optional override. The check interval sits in the Security advisory checks block. |

**Why every row is reported only.** Rule 5 reads "every required key is filled" as configured. For all three, an empty key is the working default, so no `requiredConfig` list could say anything true. Rule 3 needs an adapter that can be a mock, and none of the three has one. What an admin wants to know is whether the source answers, and only a request can tell.

**Why no `codeberg` row.** `ForgeRegistry` still carries a Codeberg forge, and `SourcesPanel` still offers it. Conduction retired Codeberg, and hydra runs `retire-codeberg-references`. Declaring a row would advertise a host the organisation no longer uses. The forge code stays; this change neither adds to it nor removes it.

**Anchors.** The admin section is `versioniq` (`Admin::getSection()`), so each link is `/settings/admin/versioniq#section-…`. The page is one Vue app with tabs, and a hidden tab's anchor scrolls nowhere. `App.vue` therefore reads the hash on mount and on `hashchange`, selects the tab that holds the anchor and scrolls to it.

| Anchor | Element | Tab |
|---|---|---|
| `section-sources` | the App sources heading in `SourcesPanel.vue` | Sources |
| `section-advisories` | the Security advisory checks heading in `App.vue` | Apps |
| `section-integrations` | the Integrations heading in `IntegrationsPanel.vue` | Integrations |

## D2. What Versioniq reports, and when

`lib/Service/Connection/ConnectionReportService.php` sends both events. It names the classes by string behind `class_exists` (ADR-041) and never throws.

**GitHub, after a release or advisory request** (`ForgeReleaseSource::performFetch`). A request to any other forge sends nothing.

| GitHub answers | Status | Message |
|---|---|---|
| 200 with a readable body | `configured` | "api.github.com answered the last request." |
| 200 with a body Versioniq cannot read | `error` | "api.github.com answered with a body Versioniq could not read." |
| 401 | `limited` | "api.github.com refused a saved token. Public repositories still answer without one." |
| 403 or 429 | `limited` | "api.github.com refused the last request with HTTP 403, usually a rate limit. A token raises the limit." |
| 404 | nothing | a missing or private repository says nothing about the connection |
| any other status | `error` | "api.github.com answered HTTP 502." |
| no answer | `error` | "Versioniq could not reach api.github.com." |

The host comes from the forge's API base, so a GitHub Enterprise override names its own host. A message never carries the repository, the endpoint or the exception text, because every admin reads the row.

**GitHub, after a token save or removal** (`POST` and `DELETE /api/pats`). A saved GitHub token sends the refresh first, then `configured`: the token check reached GitHub and GitHub accepted it. A rejected token saves nothing and sends nothing. A removed token sends the refresh alone. Codeberg tokens send nothing.

**App Store, after a catalogue fetch** (`AppStoreSource::fetchAppPayloadUncached`). A cached payload makes no request, so it sends nothing.

| The store | Status |
|---|---|
| answered any page with a readable catalogue | `configured` |
| answered only with errors, empty bodies or nothing | `error`, with the last failure cut at 160 characters |

**Advisories, after an advisory check** (`NextcloudAdvisoryFeed::fetchAll`, run by `AdvisoryRefreshJob`).

| The feed | Status |
|---|---|
| read in full | `configured`, with the number of advisories read |
| failed after some advisories | `limited`, with the number read and the failure |
| failed before any advisory | `error`, with the failure |

**Why this is cheap.** A version list is a page request, so a report per request would break ADR-076. `ConnectionReportService` keeps the last status sent per key in app config (`connection_report.<key>`, `status|unix time`). It sends when the status changes, or when the last report is an hour old. A refresh clears that record, so the first outcome after a token save is always reported. The App Store fetch is itself behind a one-hour cache, and the advisory check runs every one to 24 hours.

**Wiring.** The three sources and `ApiController` are autowired. The sources take the report service as an optional last argument, so existing tests and callers keep working.

## D3. The page

- `src/components/IntegrationsPanel.vue` is a tab in the admin settings page, next to Sources and Tokens. It reads `GET /apps/openregister/api/objects/integriq/app_connection?app=versioniq&_limit=50`. `app` is a bare key: the objects endpoint reads `filter[app]` as a filter on nothing.
- It sorts the rows by `order`, and drops any row whose `app` is not `versioniq`, so a dropped filter cannot show another app's rows as ours.
- The columns are connection, status, status message, last checked and settings.
- `Admin::getForm()` provides the initial state `integriq-installed`. `App.vue` adds the tab only when it is true, so without integriq nothing asks the `integriq` register.
- `src/utils/connectionRegistry.ts` holds the two formatters, the Add integration URL and the row filter.

**Formatters.** Versioniq has no `@conduction/nextcloud-vue`, so it carries a local copy with all six labels, `limited` included. The names are the contract's.

## D4. Contract misfits

- **No manifest app.** D8 describes an `index` page in a manifest, with a menu entry carrying `query`, `requiresApp` and `visibleIf.appInstalled`. Versioniq is a Vue 3 settings page with tabs, and has no `CnAppRoot`, manifest or router. The tab is the equivalent: the filter is the query, the initial state is `visibleIf.appInstalled`. There is no folder sidebar by status; three rows do not need one.
- **Configured means reachable, per user.** A token belongs to one user, and `PatResolver` picks it per request. So `configured` says a request reached GitHub, not that every admin's token works. A 401 reads `limited`, not `error`, because public repositories still answer.
- **An optional override has no required config.** All three keys are empty on a working instance. `requiredConfig` counts filled keys, so it could only say "configured" for an override nobody needs. `reportedOnly` is the honest fit.
- **No message for "not used".** An instance with no app bound to GitHub never makes a GitHub request, so the row stays "Not checked yet". The contract has no status for "declared, not in use here".
- **Gate 116's vendored schema.** The installed `conduction/hydra-gates` has no gate 116 yet. The file validates against integriq's own `connections.schema.json` on `development`.

## Risks

- **Same-second ordering.** A token save sends the refresh before the report. If integriq stamps `refreshedAt` later than the report's `at` in the same second, the report is retired. Hydra#674 compares with "not older than", so an equal stamp counts.
- **A row can lag a failure by up to an hour.** Only when the status stays the same; a change is sent at once.
- **App config writes.** One small write per key at most once an hour, plus one per status change.
