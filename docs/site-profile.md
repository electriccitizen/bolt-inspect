---
Generated at: 2026-04-27
---

# Site profile JSON contract

`drush bolt-inspect:profile` emits a single JSON object describing the
site. Bolt CLI consumes this as its `SiteProfile` type
(`bolt/src/types.ts`). Treat the keys here as a stable contract — any
breaking change requires a module version bump and a corresponding
`MIN_MODULE_VERSION` bump in Bolt.

## Top-level shape

```json
{
  "boltInspectVersion": "0.5.0",
  "contentTypes":       [ ... ],
  "paragraphBundles":   [ ... ],
  "enabledModules":     [ "node", "user", ... ],
  "customModules":      [ ... ],
  "routes":             [ "/", "/about", ... ],
  "mediaTypes":         [ "image", "remote_video" ],
  "menus":              [ ... ],
  "representativeUrls": [ ... ],
  "sampleEntities":     { ... }
}
```

## `boltInspectVersion` *(string)*

Value of `version:` in `bolt_inspect.info.yml`. Bolt compares this
against its `MIN_MODULE_VERSION` and warns when the installed module
is older than expected (`bolt init --upgrade` to fix).

## `contentTypes` *(array)*

One entry per `node_type`:

```json
{
  "id": "article",
  "label": "Article",
  "fields": [ FieldDefinition, ... ]
}
```

`fields` lists only configurable fields (`FieldConfigInterface`) —
base fields like `nid`, `uuid`, `created` are excluded.

### `FieldDefinition`

```json
{
  "name": "field_body",
  "label": "Body",
  "type": "text_with_summary",
  "required": true,
  "cardinality": 1,
  "settings": { ... raw FieldConfig settings ... }
}
```

`settings` is passed through verbatim from
`FieldConfigInterface::getSettings()` — useful keys include
`target_type`, `handler_settings.target_bundles`, `allowed_values`,
`max_length`.

## `paragraphBundles` *(array)*

Same shape as `contentTypes` but for the `paragraph` entity. Empty
array if the `paragraphs` module is not installed.

## `enabledModules` *(string[])*

Machine names of every installed module, sourced from
`ModuleExtensionList::getAllInstalledInfo()`.

## `customModules` *(array)*

Subset of installed modules whose path contains `modules/custom/`,
with detection metadata for AI-driven analysis. `bolt_inspect`
itself is excluded.

```json
{
  "name": "my_module",
  "label": "My Module",
  "description": "...",
  "path": "modules/custom/my_module",
  "version": "1.0.0",
  "package": "Custom",
  "provides": [
    "hooks: form_alter, theme, preprocess_node",
    "services",
    "src: plugins, forms, event_subscribers",
    "config",
    "templates"
  ]
}
```

`provides` is a heuristic list assembled by inspecting the module
directory: hook names parsed from `*.module`, presence of a
`*.services.yml`, subdirectories under `src/`, presence of
`config/install` or `config/optional`, presence of `templates/`.
Hook lists are capped at 15 entries.

## `routes` *(string[])*

Frontend GET-able route paths, deduplicated. Excludes:

- admin / system / batch / devel / editor / contextual / history /
  media / user / `/node/add` / autocomplete / antibot / ckeditor
  paths
- routes whose `_admin_route` option is true
- the special placeholders `<current>`, `<front>`, `<nolink>`,
  `<none>`
- entity edit/delete forms
- routes containing `{...}` placeholders

This is a coarse list intended for "is this URL crawlable" checks,
not for exhaustive routing analysis.

## `mediaTypes` *(string[])*

Machine names of media bundles. Empty when the `media` module is not
installed.

## `menus` *(array)*

Currently always `[{ name: "main", items: [...] }]` — only the main
menu, depth 1. Each item:

```json
{ "title": "About", "url": "/about" }
```

Items whose URL fails to render (e.g. broken external link) are
silently skipped.

## `representativeUrls` *(array)*

A deduplicated list of "interesting" URLs for browser-smoke and
visual-regression plugins. Sources, in order:

1. `/` — homepage
2. Each main-menu item
3. Every Views page display whose path is static (no `%`/`{}` tokens)
   and isn't in the skiplist (`admin*`, `node`, `rss.xml`, `search`)
4. The most recently published node of each content type

```json
{
  "url": "/articles/hello-world",
  "source": "content_type",
  "label": "Article: Hello world",
  "contentType": "article"
}
```

`source` is one of `homepage | menu | content_type | view | custom`.
`viewId` and `displayId` are present when `source = view`.

## `sampleEntities` *(object, since 0.5.0)*

Sample referenceable entities used by Bolt's field-interaction
plugin to type real labels into entity-autocomplete widgets.

Keys are `${target_type}:${bundle}`, where `bundle` is `*` when the
referring field imposes no bundle restriction. Values are arrays of
up to three `{id, label}` pairs.

```json
{
  "node:article":     [ { "id": 12, "label": "Hello world" }, ... ],
  "taxonomy_term:tags": [ { "id": 3, "label": "Drupal" } ],
  "user:*":           [ { "id": 1, "label": "admin" } ]
}
```

Construction rules:

- Only `entity_reference` fields are walked
  (`entity_reference_revisions` paragraphs are excluded — those
  widgets aren't autocompleted).
- Bolt's own test content is excluded from suggestions
  (`label NOT LIKE 'Bolt %'`).
- For nodes: only published. For users: only active, never UID 0.
- Storage errors for individual target types are swallowed —
  consumers should treat missing keys as "no samples available".

Older versions of `bolt_inspect` (≤0.4.x) omit this key entirely;
Bolt's `SiteProfile.sampleEntities` is therefore optional.

## Versioning

| Module version | Schema change                                            |
| -------------- | -------------------------------------------------------- |
| 0.1.0          | Initial release.                                         |
| 0.2.0          | Added `customModules`.                                   |
| 0.3.0          | `auto_entitylabel`-aware content generation.             |
| 0.5.0          | Added `sampleEntities`.                                  |

Backwards-incompatible changes (renaming or removing a key) require
a major version bump and coordination with Bolt's
`MIN_MODULE_VERSION`.
