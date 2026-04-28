---
Generated at: 2026-04-27
---

# Architecture

`bolt_inspect` is a small read/write Drupal module: three services
behind five Drush commands. No routes, no controllers, no plugins, no
config entities, no schema.

## File layout

```
bolt_inspect/
├── bolt_inspect.info.yml          # core_version_requirement: ^11
├── bolt_inspect.install           # hook_uninstall: clear state + delete public://bolt-test/
├── bolt_inspect.services.yml      # 3 services
├── drush.services.yml             # registers BoltInspectCommands (Drush ^13)
├── composer.json                  # electriccitizen/bolt-inspect, drupal-module
└── src/
    ├── Drush/Commands/
    │   └── BoltInspectCommands.php   # 5 commands, thin glue over services
    └── Service/
        ├── SiteProfiler.php          # builds the profile JSON
        ├── ContentGenerator.php      # creates test entities
        └── TestEntityTracker.php     # state-backed cleanup ledger
```

## Services

### `bolt_inspect.site_profiler`

`SiteProfiler` is read-only. `profile()` returns the dictionary
documented in [`site-profile.md`](site-profile.md). It depends on:

- `entity_type.manager`, `entity_field.manager` — bundles + fields
- `extension.list.module` — module info, custom-module detection
- `router.route_provider` — frontend route enumeration
- `menu.link_tree` — main menu walk

`getRepresentativeUrls()` and `getSampleEntities()` issue entity
queries with `accessCheck(FALSE)` to surface content regardless of
the running user — these are used by trusted Drush commands, not by
exposed routes.

### `bolt_inspect.content_generator`

`ContentGenerator::generateAll()` iterates every `node_type`, calls
`generateNode($bundle)` once per bundle, and reports per-bundle
results. Within a node it walks `FieldConfigInterface` definitions
and dispatches to type-specific generators via a `match` expression.

Key behaviors:

- **Required-only.** Skips optional fields unless they're paragraph
  references (always populated for paragraph coverage) or referenced
  by an `auto_entitylabel` token pattern (so the auto-generated
  title isn't blank).
- **Recursive paragraphs.** `entity_reference_revisions` fields are
  populated by creating one paragraph per target bundle, recursing
  to `MAX_PARAGRAPH_DEPTH = 3`. At depth > 0, only one simple child
  bundle (`text`, `horizontal_rule`, or first available) is created.
- **Cached supporting entities.** Reused taxonomy terms, media
  entities, files, and block_content are kept in
  `$supportingEntities` so a single fixture is shared across all
  nodes.
- **Per-call instance state.** Cached entries are reset every time
  Drush instantiates the service; running `bolt-inspect:generate`
  twice in the same process is not an expected workflow.
- **Logged-not-thrown errors.** Unknown field types and paragraph
  failures are logged via `\Drupal::logger('bolt_inspect')` with a
  `warning` level. Required unknowns get a string fallback.

### `bolt_inspect.entity_tracker`

`TestEntityTracker` is the cleanup ledger. State backend:
`bolt_inspect.tracked_entities` (a list of
`{entity_type, id, label, created}` records). Three methods Bolt
cares about:

- `track($type, $id, $label)` — append.
- `getTracked()` — return the list.
- `cleanupAll()` — `array_reverse()` and delete; clear the state
  key. On `Exception` from `$entity->delete()`, retries after
  forcing `setTitle('Bolt Cleanup')` to defuse `auto_entitylabel`
  hooks that misfire on partially-populated entities.

Reverse order matters: paragraphs and supporting media must be
deleted after the nodes that reference them, otherwise referential
constraints fail.

## Drush integration

`drush.services.yml` ties `BoltInspectCommands` to the three
services and tags it `drush.command`. The class is a thin glue
layer — every command body is ~10–30 lines that calls one service
method and formats the result.

The `extra.drush.services` block in `composer.json` declares
compatibility with Drush ^13 only, which is the supported floor for
Drupal 11.

## How Bolt CLI consumes this module

(Reference: `~/projects/bolt`.)

1. `bolt init` (`bolt/src/commands/init.ts`) runs
   `composer require electriccitizen/bolt-inspect`,
   `drush en bolt_inspect -y`, then `drush cex` so the enable
   survives a future `composer install` + `drush cim` cycle.
2. `bolt update` (`bolt/src/update/apply.ts → ensureBoltInspect()`)
   re-enables the module after a composer update if a dependency
   resolution removed it.
3. `bolt test` (`bolt/src/runner.ts → fetchProfile()`) runs
   `drush bolt-inspect:profile` once per run, parses the JSON into
   `SiteProfile`, then:
   - Asks each plugin `canRun(profile)` to filter applicable tests.
   - Runs `drush bolt-inspect:generate` if any selected plugin sets
     `needsGeneratedContent`.
   - At the end, runs `drush bolt-inspect:cleanup`. A non-zero exit
     here is logged as a warning, not a failure — Bolt's principle
     is that the user can always re-run cleanup by hand.

`SiteProfile.sampleEntities` is marked optional in
`bolt/src/types.ts` so older sites running pre-0.5.0 modules still
produce a usable profile; the field-interaction plugin falls back
to label guessing when the key is absent.

## Non-invasive guarantees

The module is built to leave no traces:

| Surface          | Behavior                                                    |
| ---------------- | ----------------------------------------------------------- |
| Database schema  | None added.                                                 |
| Config entities  | None created.                                               |
| State            | Single key `bolt_inspect.tracked_entities`, deleted on uninstall. |
| Filesystem       | `public://bolt-test/` only, deleted on uninstall.           |
| Hooks            | Only `hook_uninstall`.                                      |
| Routes / blocks  | None.                                                       |
| Permissions      | None defined; commands rely on Drush's authenticated context. |

This matches Bolt's "non-invasive" principle: removing the module
and the Composer entry returns the site to its prior state.

## Testing

There are currently no automated tests in this repository. The
module is exercised end-to-end by Bolt's test suite
(`~/projects/bolt/tests/`), which spins up a DDEV-backed Drupal
fixture, installs `bolt_inspect`, and runs each Drush command in
sequence. If you change a public method on a service or a Drush
command output shape, run the Bolt suite against the change.
