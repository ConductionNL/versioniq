# Design: inventory-app-details-card

Read against `development` at a2ddbec (2026-10-23).

## Context

- `InstallerService::getInstalledApps()` (`lib/Service/InstallerService.php:98-200`) builds `$appList` from `OC_App::listAllApps()` (line 114) and maps each installed app to `id`, `label`, `description`, `summary`, `preview`, `isCore`, `isShipped`, `boundSourceId`, state and version. The `listAllApps()` entries already hold the parsed `info.xml`, including `author`, `licence`, `website`, `bugs` and `repository`; the mapper drops them.
- `src/App.vue` renders the app card and the version view; `appDescription()` (line 1252) falls back to "No description available."
- The board VqAppVersies draws the card as "Over Dossiq": Uitgever, Licentie, Geïnstalleerd, Links (Website, Broncode, Problemen melden), then the description.

## Decisions

### D1. Read metadata from the installed app, not the store

The installed `info.xml` describes the version that runs. It is local, so the card works offline and for apps from a forge.

### D2. Normalise author to one display string

`info.xml` allows several `author` elements, each with optional `mail` and `homepage` attributes, which `listAllApps()` returns as a string, a list or an array with `@value`. A private helper `InstallerService::authorName()` joins the names with ", " and drops mail addresses, so no e-mail reaches the page.

### D3. Links are an object with nullable members

`links: {website, repository, bugs}`; each value is an `http` or `https` URL or `null`. Other schemes are dropped, so the card never renders a `javascript:` link.

### D4. One component

`AppAboutCard.vue` takes the app object and renders the card; `App.vue` places it beside the version list. The publisher row leaves room for the verified mark that `inventory-verified-publisher` adds.
