# Codeberg is no release source

## Why

ConductionNL uses GitHub only, a standing rule since Codeberg was retired on 23 Jul 2026. The code already follows it: `lib/Service/Source/ForgeRegistry.php` marks `codeberg` as retired and leaves it out of `selectableIds()`, the forges an admin may pick for a new binding, token or trusted pattern. The specs still describe Codeberg as a release source: `external-sources` "Codeberg releases as a source [MVP]", its binding UI and allowlist scenarios, and the Forge abstraction in the open change `sources-gitlab-forge`. Ruben decided on 12 Nov 2026 (decision 392) that the specs drop it.

## What changes

- `external-sources`: "Codeberg releases as a source [MVP]" is removed.
- "Trusted-source allowlist [MVP]": the default allowlist is `["github:ConductionNL/*"]`. `TrustedSourceList::DEFAULT_PATTERNS` no longer holds `codeberg:Conduction/*`; a Codeberg pattern an admin stored stays trusted.
- "Trusted-allowlist management API" refuses a new pattern for a retired forge. Its add example is GitHub.
- "Source binding UI surfacing" no longer offers Codeberg for a new binding.
- New requirement "A retired forge keeps what was stored under it": bindings, tokens and trusted patterns already stored under `codeberg` keep working and stay removable.
- `sources-gitlab-forge` (open change): the Forge abstraction's known-forges scenario no longer names Codeberg.

Existing bindings keep working. Nothing an admin stored under `codeberg` breaks. One case does change: an install that never set its allowlist and bound an app to Codeberg relied on the default, so that binding is no longer trusted. A new `codeberg:` pattern is refused, so the admin binds the app to GitHub, or to codeberg.org as the self-hosted Forgejo host with a `forgejo:` pattern.

## Matrix rows (`openspec/parity/capabilities.json`)

| row | change |
|---|---|
| `src-codeberg` | reworded to the self-hosted Forgejo or Gitea forge it already records |
