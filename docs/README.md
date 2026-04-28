---
Generated at: 2026-04-27
---

# Bolt Inspect — Documentation

Drupal 11+ module that exposes a site's structure and manages disposable
test content for the [Bolt CLI](https://github.com/electriccitizen/bolt)
test runner. This module is the in-Drupal half of Bolt; the Node.js CLI
shells out to it via Drush.

| Item            | Value                                                  |
| --------------- | ------------------------------------------------------ |
| Package         | `electriccitizen/bolt-inspect`                         |
| Module name     | `bolt_inspect`                                         |
| Version         | 0.5.0                                                  |
| Drupal core     | `^11`                                                  |
| PHP             | `>=8.1`                                                |
| Drush services  | `^13`                                                  |
| Required ext    | `ext-gd` (placeholder image generation)                |
| License         | GPL-2.0-or-later                                       |

## What it does

1. **Profile the site** (`drush bolt-inspect:profile`) — emits a single
   JSON document describing content types, paragraph bundles, fields,
   menus, media types, enabled and custom modules, accessible frontend
   routes, representative URLs, and sample referenceable entities. This
   is the contract Bolt's plugins read to decide what to test.
2. **Generate test content** (`drush bolt-inspect:generate`) — creates
   one unpublished node per content type, populating required fields
   (and any field referenced by an `auto_entitylabel` token pattern).
   Supporting entities (terms, media, files, paragraphs) are created
   on demand and tracked.
3. **Render-check** (`drush bolt-inspect:render-check`) — renders each
   generated test node in isolation and reports per-bundle status.
4. **Cleanup** (`drush bolt-inspect:cleanup`) — deletes everything that
   was tracked, in reverse creation order. Uninstalling the module
   also wipes `public://bolt-test/` and the tracking state.

The module is designed to leave **no traces**: tracked-only deletion,
state cleared on uninstall, no config entities, no schema changes.

## Documents in this directory

- [`commands.md`](commands.md) — Drush command reference and example output.
- [`site-profile.md`](site-profile.md) — JSON contract emitted by
  `bolt-inspect:profile`, field-by-field.
- [`architecture.md`](architecture.md) — services, classes, file layout,
  and how Bolt CLI consumes the module.

## Install (standalone)

```bash
composer require electriccitizen/bolt-inspect
drush en bolt_inspect -y
drush bolt-inspect:profile | jq .
```

In normal use you never install this module directly — `bolt init` does
it from the host project. See the Bolt CLI README for the host-side flow.

## Uninstall

```bash
drush pmu bolt_inspect -y
composer remove electriccitizen/bolt-inspect
```

`hook_uninstall()` deletes the `bolt_inspect.tracked_entities` state
key and recursively removes `public://bolt-test/`. Run
`drush bolt-inspect:cleanup` first if tracked entities still exist —
once the module is uninstalled, the tracker is gone and stragglers
must be deleted by hand.

## Revisions

| Date       | Change                                       |
| ---------- | -------------------------------------------- |
| 2026-04-27 | Initial documentation set for v0.5.0.        |
