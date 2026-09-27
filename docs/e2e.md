# End-to-end tests

The Playwright suite in `tests/e2e/` drives the real admin UI against a running
Nextcloud instance with Versioniq enabled. It covers every capability the app
ships: the settings shell, version listing and release notes, safe mode and the
downgrade guard, pinning (including its audit entries), auto-update policies,
discovery, and the history / artifact-cache / tokens / trusted-source panels.

> **Never point the suite at production.** The specs create and clean up their
> own state (pins, policies, auto-update settings), but they write to the
> instance they are given.

## Bootstrapping a disposable instance

```bash
docker run -d --name av-e2e -p 8099:80 \
  -e SQLITE_DATABASE=nc.db \
  -e NEXTCLOUD_ADMIN_USER=admin -e NEXTCLOUD_ADMIN_PASSWORD=adminadmin123 \
  -e NEXTCLOUD_TRUSTED_DOMAINS="localhost 127.0.0.1" \
  nextcloud:34.0.0-apache

# wait for the install to finish
until docker exec av-e2e php occ status 2>/dev/null | grep -q 'installed: true'; do sleep 5; done
```

Build the app and copy it in (a production build — `--no-dev` keeps
`symfony/console` out of the app's own `vendor/`, since Nextcloud already
provides it):

```bash
composer install --no-dev
npm ci && npm run build

docker cp . av-e2e:/var/www/html/custom_apps/versioniq
docker exec av-e2e rm -rf /var/www/html/custom_apps/versioniq/{node_modules,.git}
docker exec av-e2e chown -R www-data:www-data /var/www/html/custom_apps/versioniq
docker exec av-e2e php occ config:system:set apps_paths 1 path --value=/var/www/html/custom_apps
docker exec av-e2e php occ config:system:set apps_paths 1 url --value=/custom_apps
docker exec av-e2e php occ config:system:set apps_paths 1 writable --value=true --type=boolean
docker exec -u www-data av-e2e php occ app:enable versioniq

# The first-run wizard is a modal that covers the page and swallows clicks.
docker exec -u www-data av-e2e php occ app:disable firstrunwizard

# The version specs need a genuine App Store app with real release history.
# Shipped apps (dashboard, files, …) are unsuitable: they follow the server
# release, so the picker reports that rather than listing versions. The auth
# setup step installs `notes` automatically (idempotent), so this line is only
# needed when provisioning by hand; the downgrade spec targets the oldest
# release the store still lists, so it survives the store pruning old versions.
docker exec -u www-data av-e2e php occ app:install notes
```

### Warm the App Store caches first

The App Store catalogue endpoint answers with the *entire* store (~30 MB) and
ignores its `filter` parameter, so the very first version listing and the first
discovery search pay a large download. Both results are cached for an hour, so
warm them once before running the suite to keep it fast and predictable:

```bash
docker exec av-e2e curl -s -o /dev/null -u admin:adminadmin123 -H 'OCS-APIRequest: true' \
  'http://localhost/ocs/v2.php/apps/versioniq/api/app/notes/versions?format=json'
docker exec av-e2e curl -s -o /dev/null -u admin:adminadmin123 -H 'OCS-APIRequest: true' \
  'http://localhost/ocs/v2.php/apps/versioniq/api/discover?q=calendar&format=json'
```

## Running

```bash
npm run test:e2e          # headless
npm run test:e2e:ui       # Playwright UI mode
npx playwright test pinning   # a single spec
```

Configuration comes from the environment, so the same suite runs against any
instance:

| Variable        | Default                  |
| --------------- | ------------------------ |
| `NC_BASE_URL`   | `http://localhost:8099`  |
| `NC_ADMIN_USER` | `admin`                  |
| `NC_ADMIN_PASS` | `adminadmin123`          |

`tests/e2e/auth.setup.ts` logs in once and stores the session in
`tests/e2e/.auth/admin.json` (git-ignored); every other spec reuses it.

## Conventions

- Specs are **serial** (`workers: 1`): they share one admin settings page and
  several mutate server-side state.
- Prefer `data-testid` over text where a control has no stable accessible name;
  add the attribute to the component rather than writing a brittle selector.
- Each spec cleans up what it creates — pins and policies are removed in
  `afterEach`, and toggles are restored to their default.
- Assertions that depend on the App Store are given generous timeouts because
  the upstream payload is large; they are never silently skipped.

## Forge fixture (fixture-backed install specs)

`tests/e2e/forge.spec.ts` drives real installs, TOFU digest enforcement,
integrity failures, rate-limiting, and offline-cache fallback against a
**fixture forge** — a Forgejo/Gitea-shaped HTTP double in
`tests/e2e/fixtures/forge/` — instead of real GitHub or Forgejo hosts. This relies on
two app config seams (both default to the public host, so production is
unaffected):

- `forge.{github,forgejo}.{api_base,web_base}` — point a forge at another
  deployment (self-hosted Forgejo / GitHub Enterprise, or the fixture).
- the `allow_local_remote_servers` system switch — the app's forge fetches
  defer to it, so a fixture on the Docker network is reachable only when it is
  enabled (off by default).

Bootstrap it before the forge specs:

```bash
tests/e2e/fixtures/forge/bootstrap.sh av-e2e
```

This builds the app tarballs, starts the fixture container on a shared network
with Nextcloud, points the self-hosted forgejo forge at it, enables local-address fetches,
allowlists `forgejo:fixtureowner/*`, and installs+binds a baseline
`fixtureapp`. The forge specs skip themselves when the fixture is unreachable.

> Forge **installs** in these specs are driven through `occ versioniq:install`,
> not the HTTP API: an install calls `opcache_reset()`, which under the test
> image's mod_php poisons the shared web opcache. `occ` runs with opcache off
> (`opcache.enable_cli=Off`). Both paths call the same `InstallerService`.
