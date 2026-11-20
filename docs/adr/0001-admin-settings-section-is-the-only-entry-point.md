# 1. The admin settings section is the only entry point to the UI

- Status: Accepted
- Date: 2026-06-14

## Context

The app used to register a top-level navigation entry and serve its Vue page through a `PageController` route marked `#[NoAdminRequired]`, so any signed-in user could load the page shell while each API endpoint checked for an administrator. Nextcloud places admin utilities under Settings, Administration, not in the global app menu.

## Decision

- Register the UI as an admin settings section declared in `appinfo/info.xml` under `<settings>`, with a separate section class (`lib/Sections/AdminSection.php`, an `IIconSection`) and settings class (`lib/Settings/Admin.php`, an `ISettings`), instead of registering through `IRegistrationContext`.
- Remove the navigation entry, the `PageController` and its `GET /` route, so the admin settings section is the only way to load the UI.
- Keep the administrator check in the API layer as defence in depth.

## Consequences

- Non-admins have no page shell to load; the framework only shows the section to administrators.
- Old bookmarks to the app's own URL stopped resolving. This was accepted because the app is admin-only and had no external dependants on that URL.
- The existing template and Vue bundle were reused unchanged.

## Source

`openspec/changes/archive/2026-06-14-move-to-admin-settings/design.md` (Decisions 1 to 4); implemented in `appinfo/info.xml`, `lib/Sections/AdminSection.php` and `lib/Settings/Admin.php`.
