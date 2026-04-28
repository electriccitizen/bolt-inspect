---
Generated at: 2026-04-27
---

# Drush command reference

All commands are defined in
`src/Drush/Commands/BoltInspectCommands.php` and registered via
`drush.services.yml` (Drush ^13). Each command operates on the active
Drupal site — there are no command-line options.

## `bolt-inspect:profile`

Emit the full site profile as JSON to stdout.

```bash
drush bolt-inspect:profile
drush bolt-inspect:profile | jq .
drush bolt-inspect:profile > /tmp/profile.json
```

- **Output:** pretty-printed JSON, unescaped slashes.
- **Exit code:** 0 on success; non-zero only on PHP/Drush failure.
- **Side effects:** none (read-only).
- **Schema:** see [`site-profile.md`](site-profile.md).

Bolt's runner calls this exactly once at the start of every test run
and refuses to proceed if the JSON fails to parse.

## `bolt-inspect:generate`

Create one unpublished test node (`status = 0`, `uid = 1`, title
`"Bolt Test: {bundle}"`) per content type, populating required fields
and any field referenced by an `auto_entitylabel` token pattern.
Supporting taxonomy terms, media entities, files, and paragraph
revisions are created on demand and tracked alongside the nodes.

```bash
drush bolt-inspect:generate
```

- **Idempotency:** if the tracker already holds entries, the command
  prints the existing list and exits without creating duplicates. Run
  `bolt-inspect:cleanup` first to regenerate.
- **Output:** human-readable table — `Content Type | Status | NID | Detail`.
- **Errors:** per-bundle errors are reported as `ERROR` rows. The
  command never aborts mid-run; partial state is recorded by the
  tracker so cleanup can still find it.
- **Tracking:** every created entity (node, paragraph, taxonomy_term,
  media, file, block_content) is recorded in the
  `bolt_inspect.tracked_entities` state key.

### Field coverage

| Type                                  | Strategy                                                   |
| ------------------------------------- | ---------------------------------------------------------- |
| string, string_long                   | `"Bolt test value"` truncated to `max_length`              |
| text, text_long, text_with_summary    | `<p>Bolt test content paragraph.</p>` + best text format   |
| boolean / integer / decimal / float   | `1` / `1.0`                                                |
| email / telephone / link / datetime   | static placeholders                                        |
| daterange                             | now → +1 hour                                              |
| smartdate                             | now → +1 hour, duration 60                                 |
| list_string / _integer / _float       | first allowed value                                        |
| entity_reference → taxonomy_term      | reuse existing term in vocabulary; else create one         |
| entity_reference → media (image)      | reuse existing; else generate 200×200 PNG via GD           |
| entity_reference → media (remote_video) | placeholder YouTube URL                                  |
| entity_reference → node               | first existing node matching target bundles                |
| entity_reference → block_content      | reuse existing; else create a basic block                  |
| entity_reference_revisions (paragraphs) | create one per target bundle, recursive to depth 3       |
| address                               | hardcoded Missoula, MT address                             |
| image (direct field)                  | generate 200×200 PNG via GD                                |
| file                                  | skipped                                                    |
| comment                               | open                                                       |
| webform                               | skipped                                                    |
| unknown type                          | log warning; if required, fall back to `['value' => 'Bolt test']` |

Skipped fields (always): `moderation_state`, `metatag`,
`layout_builder__layout`, `scheduler_publish_on`,
`scheduler_unpublish_on`.

## `bolt-inspect:render-check`

Load each generated test node, render it in `full` view mode using
`Renderer::renderInIsolation()`, and emit a JSON array with one entry
per content type.

```bash
drush bolt-inspect:render-check | jq .
```

Each entry has shape:

```json
{
  "bundle": "page",
  "label": "Basic page",
  "status": "ok",          // ok | error | missing
  "nid": 42,
  "title": "Bolt Test: page",
  "html_length": 12345,
  "error": "..."           // only when status = error
}
```

Used by Bolt to confirm that a freshly-generated node renders without
PHP errors before any browser-driven plugin runs against it.

## `bolt-inspect:list`

Print the contents of the tracker as a table.

```bash
drush bolt-inspect:list
```

Columns: `Entity Type | ID | Label | Created`. Useful for verifying
that a previous generate or cleanup left no orphans.

## `bolt-inspect:cleanup`

Delete every entity in the tracker, in reverse creation order, then
clear the tracker.

```bash
drush bolt-inspect:cleanup
```

- **Output:** table of `Entity Type | Deleted` counts, then a success
  line with the total.
- **Error recovery:** if `$entity->delete()` throws — typically when
  hooks like `auto_entitylabel` choke on missing tokens — the tracker
  retries after forcing a valid title via `setTitle('Bolt Cleanup')`.
- **Side effects:** deletes nodes, paragraphs, taxonomy terms, media,
  files, and block_content created by `bolt-inspect:generate`. Does
  **not** touch entities the tracker doesn't know about.
- **State:** clears `bolt_inspect.tracked_entities` unconditionally.

## Exit codes

The Drush commands here return Drush's default exit codes — they don't
override `$this->logger()->error()` to fail the process. Bolt CLI
inspects command stdout/stderr and the parsed payloads, not the exit
code, so a partial generate is reported as per-bundle `ERROR` rows
rather than a non-zero exit.

If you script against these commands directly and need failure
signaling, parse the JSON or table output rather than relying on
`$?`.
