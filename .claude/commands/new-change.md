---
description: Start a new OpenSpec change in openspec/changes/
---

Start an OpenSpec change named $ARGUMENTS (kebab-case, prefixed with the area like the existing changes, for example `install-...`, `releases-...`, `autoupdate-...`).

1. Read `openspec/config.yaml` for the project context and the rules for proposals, specs, design and tasks.
2. Look at one or two existing changes in `openspec/changes/` (for example `openspec/changes/admin-mcp-assistant/`) and copy their shape. Create `openspec/changes/<name>/` with:
   - `proposal.md`: front matter with `kind: code`, then `# Proposal: <name>` and a `## Why` section.
   - `design.md`: `# Design: <name>`, `## Context`, `## Goals and non-goals`, `## Decisions` (numbered `### D1. ...`), `## Risks and trade-offs`, `## Migration`.
   - `tasks.md`: numbered groups of `- [ ]` tasks, each with how it is verified.
   - `screens.md`: the screens the change touches, or a line saying it has none.
   - `specs/<capability>/spec.md`: the delta spec, with `## ADDED Requirements` or `## MODIFIED Requirements`, `### Requirement: ...` statements using MUST, and `#### Scenario:` blocks with GIVEN, WHEN and THEN.
3. Check `openspec/specs/<capability>/spec.md` for the current requirements of the capability you change.

Do not write code until the proposal is agreed. When the change is done it moves to `openspec/changes/archive/<YYYY-MM-DD>-<name>/` and its deltas are merged into `openspec/specs/`.
