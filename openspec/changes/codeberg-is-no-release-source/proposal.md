# Codeberg is no release source

## Why

ConductionNL uses GitHub only, a standing rule since Codeberg was retired on 23 Jul 2026. The code already follows it: `lib/Service/Source/ForgeRegistry.php` marks `codeberg` as retired and leaves it out of `selectableIds()`, the forges an admin may pick for a new binding, token or trusted pattern. The specs still describe Codeberg as a release source: `external-sources` "Codeberg releases as a source [MVP]", its binding UI and allowlist scenarios, and the Forge abstraction in the open change `sources-gitlab-forge`. Ruben decided on 12 Nov 2026 (decision 392) that the specs drop it.

## What changes

- `external-sources`: "Codeberg releases as a source [MVP]" is removed.
- "Trusted-allowlist management API" refuses a new pattern for a retired forge. Its add example is GitHub.
- "Source binding UI surfacing" no longer offers Codeberg for a new binding.
- New requirement "A retired forge keeps what was stored under it": bindings, tokens and trusted patterns already stored under `codeberg` keep working and stay removable.
- `sources-gitlab-forge` (open change): the Forge abstraction's known-forges scenario no longer names Codeberg.

Existing bindings keep working. Nothing an admin stored under `codeberg` breaks.

Left as it is: the default allowlist in `TrustedSourceList::DEFAULT_PATTERNS` still holds `codeberg:Conduction/*`, and "Trusted-source allowlist [MVP]" still says so. Dropping that default is a separate code change, named in task 2.2.

## Matrix rows (`openspec/parity/capabilities.json`)

| row | change |
|---|---|
| `src-codeberg` | reworded to the self-hosted Forgejo or Gitea forge it already records |
