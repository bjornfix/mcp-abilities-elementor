# MCP Abilities - Elementor

Inspect an Elementor page, find the widget or shared setting behind a problem, and make a targeted change through an authenticated MCP client. Keep the page editable in Elementor and check the rendered result after saving.

[![Release 2.3.56](https://img.shields.io/badge/release-2.3.56-blue.svg)](https://downloads.devenia.com/mcp-abilities-elementor.zip)
[![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
[![WordPress](https://img.shields.io/badge/WordPress-6.9%2B-blue.svg)](https://wordpress.org)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-purple.svg)](https://www.php.net/)

**Tested up to:** 7.1 (validated with WordPress 7.1-RC3)

**Stable tag:** 2.3.56

**License:** GPLv2 or later

**Tags:** mcp, elementor, page builder, ai, automation

## What It Does

The add-on registers 89 Elementor abilities. They cover document inspection and editing, individual widgets and containers, shared templates and Kit settings, design observations, cache control and selected site tools. Elementor Pro supplies the optional Theme Builder, custom-code and form-submission features.

The frontend integration supplies missing JavaScript messages and Swiper arrow labels from native site translations. Existing translations, customized defaults and individual carousel settings remain authoritative.

For example, a course page may have the wrong booking link and cramped mobile text. Inspect the button for the link problem and its container for the spacing problem. Changing a shared Kit or linked template can affect other pages, so choose that scope deliberately.

## The Real Workflow

1. Identify the exact page and read its current Elementor data.
2. Inspect the relevant element, available widget controls and active Kit styles.
3. Decide whether the change belongs to one widget, a container, a shared template or the Kit.
4. Preview a supported operation with `dry_run:true` before saving. Read the ability schema: dry runs are not available on every operation and must not be assumed to be the default.
5. Save the selected change, read it back and inspect the page on desktop and mobile. Follow its important links and test its intended actions.

## Why This Feels Different

The client can inspect real element IDs and native control names before acting. Targeted settings merges keep the change within Elementor data. Shared style references are checked against the active Kit; a copied template and a native linked template remain different choices.

The plugin's structural audits help locate questions worth investigating. Their scores do not prove that a page is clear, attractive, accessible or finished.

## Before vs After

| Before | After |
|---|---|
| Guess which setting causes narrow mobile text | Inspect the container and its responsive controls before choosing a change |
| Rebuild a whole page to change one destination | Find the button and update its link settings |
| Copy a section and assume future edits will propagate | Choose a real native template link when consumers should share later updates |
| Treat a saved JSON response as final approval | Verify the saved page and its visible actions |

## Who It Is For

WordPress operators, developers and editorial teams maintaining Elementor sites through an MCP client. It is useful for targeted repairs, repeated layout work and inspection of the site's native design settings. It does not convert another builder's pages into Elementor or supply an Elementor Pro licence.

## Requirements

- WordPress 6.9 or later with the native Abilities API, and PHP 8.0 or later.
- Elementor installed and active on the site being edited.
- WordPress MCP Adapter and an ability exposure configuration. MCP Expose Abilities provides discovery for this add-on in the Devenia stack.
- An authenticated account with the permissions required by the selected operation and document.
- Elementor Pro for its Theme Builder conditions, custom-code and form-submission features.

## Documentation

- [Product page](https://devenia.com/plugins/mcp-abilities-elementor/)
- [WordPress Abilities API](https://developer.wordpress.org/apis/abilities-api/)
- [WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter/)
- [MCP Expose Abilities](https://devenia.com/plugins/mcp-expose-abilities/)
- [Elementor](https://wordpress.org/plugins/elementor/)
- [Elementor Pro](https://elementor.com/pro/)

## Start Here

Activate the required stack and this add-on. Confirm that `elementor/get-data` appears in your MCP client's discovery. Start with a read-only request for a page you are authorised to edit. Describe the visible problem and desired result before selecting a write operation.

## Abilities (89)

### Page/Post Data
| Ability | Description |
|---------|-------------|
| `elementor/get-data` | Get Elementor JSON structure for a page |
| `elementor/clone-data` | Copy complete Elementor data and page settings between documents with explicit confirmation |
| `elementor/get-element` | Get a specific element by ID |
| `elementor/get-widget-controls` | Inspect the native control keys and metadata for an Elementor widget type |
| `elementor/find-elements` | Find elements by type, widget, or text |
| `elementor/update-element` | Update a specific element by ID |
| `elementor/merge-element-settings` | Deep-merge settings into one element |
| `elementor/zero-container-padding-subtree` | Zero container padding in a subtree |
| `elementor/copy-lane-settings` | Copy width/gap lane settings between elements |
| `elementor/copy-row-balance` | Copy row rhythm and child column balance between rows |
| `elementor/image-widget-to-background-container` | Convert an image-widget container into a native background-image container |
| `elementor/fix-visible-gap-rhythm` | Remove hidden leading-edge spacing that breaks visible gap rhythm |
| `elementor/enforce-boundary-coherence` | Normalize a subtree to true full-width or coherent boxed left/right boundaries |
| `elementor/audit-generic-layout-patterns` | Audit a page/subtree for repeated generic landing-page composition patterns |
| `elementor/score-distinctiveness` | Score compositional distinctiveness without prescribing any house style |
| `elementor/audit-generic-component-repetition` | Flag repeated landing-page furniture such as excessive buttons and repeated card-like panel treatments |
| `elementor/audit-surface-overuse` | Report repeated surface treatments cautiously without treating simplicity as failure |
| `elementor/audit-emphasis-drift` | Check whether top-level sections are landing with overly similar emphasis weight |
| `elementor/audit-section-rivalry` | Catch pages where too many sections are acting like simultaneous local climaxes |
| `elementor/audit-composition-rhythm` | Inspect top-level tonal runs and pacing without punishing restrained design |
| `elementor/audit-separator-discipline` | Warn when separators start flattening major-section hierarchy instead of helping section families |
| `elementor/get-theme-context` | Summarize active theme, Elementor version, active kit, and viewport settings |
| `elementor/get-official-widget-catalog` | Fetch the full official Elementor widget catalog from Elementor.com/widgets |
| `elementor/get-official-pattern-guidance` | Return the official Elementor.com guidance catalog used for widget and layout recommendations |
| `elementor/get-style-guide` | Build a style-guide summary from the active Elementor kit and token set |
| `elementor/evaluate-design` | Aggregate the main design audits into one score, issue list, and recommendations |
| `elementor/suggest-design-fixes` | Turn the aggregated evaluation into concrete design-fix suggestions |
| `elementor/evaluate-render-context` | Inspect frontend wrapper/render context separately from Elementor content quality |
| `elementor/audit-column-patterns` | Audit repeated column ratios such as repeated 50/50 and equal-third rows |
| `elementor/audit-layout-mechanism-fit` | Recommend Grid instead of Flexbox for equal, symmetric column groups when Elementor’s own layout guidance points that way |
| `elementor/audit-native-widget-opportunities` | Suggest native Elementor widgets and Pro widgets when a hand-built container pattern is recreating them |
| `elementor/audit-column-dominance` | Flag equal column splits that may be hiding a clearly dominant side |
| `elementor/audit-column-alignment-rhythm` | Report when similar column ratios use inconsistent gutter rhythms |
| `elementor/audit-column-balance` | Flag asymmetric rows that may not be earning their asymmetry |
| `elementor/audit-column-necessity` | Flag splits that may not be earning their complexity and could read more clearly as one lane |
| `elementor/reset-negative-margins-subtree` | Clamp negative margins in a subtree |
| `elementor/extract-design-tokens` | Extract recurring colors, type, spacing, and dimensional tokens from a page/subtree |
| `elementor/apply-text-hierarchy` | Normalize heading/body/button typography in a subtree |
| `elementor/normalize-section-spacing-rhythm` | Snap section spacing and row gaps to a consistent rhythm |
| `elementor/normalize-responsive-values` | Fill or normalize tablet/mobile values from desktop settings with capped inherited side spacing |
| `elementor/sync-component-variant` | Copy design-relevant settings from one component subtree to another |
| `elementor/delete-element` | Delete a specific element by ID |
| `elementor/update-data` | Replace entire Elementor JSON for a page |
| `elementor/patch-data` | Find/replace text within Elementor JSON |
| `elementor/update-page-settings` | Update Elementor page settings |

### Authoring Primitives
| Ability | Description |
|---------|-------------|
| `elementor/create-page` | Create a new page/post with Elementor builder mode enabled |
| `elementor/add-container` | Add a top-level or nested Elementor container |
| `elementor/add-widget` | Add any Elementor widget type with raw settings |
| `elementor/add-post-tabs` | Add native Nested Tabs where each tab contains a native Posts widget |
| `elementor/add-heading` | Add a heading widget |
| `elementor/add-text-editor` | Add a text editor widget |
| `elementor/add-image` | Add an image widget from an attachment ID or URL |
| `elementor/add-button` | Add a button widget |
| `elementor/move-element` | Move an element to a new parent/position |
| `elementor/remove-element` | Remove an element with safety guards |
| `elementor/duplicate-element` | Duplicate an element subtree with fresh IDs |
| `elementor/reorder-elements` | Reorder direct children under a parent or at the top level |

### Template Management
| Ability | Description |
|---------|-------------|
| `elementor/list-templates` | List all saved Elementor templates |
| `elementor/find-template-for-pattern` | Find saved templates that match reusable layout patterns before raw authoring |
| `elementor/get-template` | Get single template with all data |
| `elementor/create-template` | Create page, section, popup, header, footer templates |
| `elementor/update-template` | Modify existing template |
| `elementor/delete-template` | Move to trash or permanently delete |
| `elementor/restore-template` | Restore trashed template |
| `elementor/empty-trash` | Permanently delete all trashed templates |
| `elementor/duplicate-template` | Copy a template |
| `elementor/export-template` | Export as JSON |
| `elementor/import-template` | Import from JSON |

### Theme Builder
| Ability | Description |
|---------|-------------|
| `elementor/get-theme-builder-conditions` | Get display conditions for a template or type |
| `elementor/update-theme-builder-conditions` | Update display conditions for a template |

### Custom Code (Pro)
| Ability | Description |
|---------|-------------|
| `elementor/list-custom-code` | List custom code snippets |
| `elementor/get-custom-code` | Get a custom code snippet |
| `elementor/create-custom-code` | Create a custom code snippet |
| `elementor/update-custom-code` | Update a custom code snippet |
| `elementor/delete-custom-code` | Delete a custom code snippet |

Create a native Elementor Pro snippet with `elementor/create-custom-code`:

```json
{
  "title": "Example script",
  "code": "<script>document.documentElement.dataset.example = 'ready';</script>",
  "status": "draft",
  "location": "elementor_body_end",
  "priority": 1,
  "conditions": ["include/general"]
}
```

Read it back with `elementor/get-custom-code`, then use `elementor/update-custom-code` with its `id` and `status: "publish"` to enable it. Use `status: "draft"` to disable it. An update preserves omitted fields; `code: ""` clears its code. List results include placement, priority and conditions; request `include_code: true` for the code.

Locations are `elementor_head`, `elementor_body_start` and `elementor_body_end`. Priority ranges from 1 to 10 and orders snippets within the same insertion location. Conditions use Elementor strings such as `include/singular/page/123` and `exclude/singular/page/456`. An empty conditions list removes restrictions: published Custom Code then applies site-wide, as in native Elementor.

Code uses Elementor's native `_elementor_code` metadata. Existing native keys in `meta` remain supported and receive the same validation. Conflicting explicit fields and metadata are rejected; the older `code: ""` plus `meta._elementor_code` form is retained. Creating or updating code respects native `unfiltered_html` restrictions. Custom Code supports HTML, CSS and JavaScript; it does not execute PHP.

### Form Submissions (Pro)
| Ability | Description |
|---------|-------------|
| `elementor/list-form-submissions` | List form submissions |
| `elementor/get-form-submission` | Get a form submission |
| `elementor/delete-form-submission` | Delete a form submission |

### Site Tools & Settings
| Ability | Description |
|---------|-------------|
| `elementor/list-global-widgets` | List all global widgets |
| `elementor/list-kits` | List available Elementor kits |
| `elementor/get-kit-settings` | Get site-wide Elementor settings |
| `elementor/update-kit-settings` | Update global colors, typography, etc. |
| `elementor/set-active-kit` | Set the active Elementor kit |
| `elementor/clear-cache` | Clear Elementor cache (post or site scope, optional post CSS regeneration) |
| `elementor/replace-urls` | Replace URLs inside Elementor data |
| `elementor/get-maintenance-mode` | Get maintenance mode settings |
| `elementor/update-maintenance-mode` | Enable or update maintenance mode |
| `elementor/list-experiments` | List Elementor experiments |
| `elementor/update-experiment` | Update experiment state |

## Usage Examples

These examples use illustrative IDs. Inspect the actual target and its current schema first.

Read a page and locate the booking button:

```json
{"ability_name":"elementor/get-data","parameters":{"id":123,"format":"array"}}
{"ability_name":"elementor/find-elements","parameters":{"id":123,"widget_type":"button","include_path":true}}
```

Inspect the button's native controls, then preview a focused settings merge:

```json
{"ability_name":"elementor/get-widget-controls","parameters":{"widget_type":"button","search":"link"}}
{"ability_name":"elementor/merge-element-settings","parameters":{"id":123,"element_id":"abc1234","settings":{"link":{"url":"https://example.com/courses/drawing/"}},"dry_run":true}}
```

Use the real element ID from inspection. Review the preview before submitting the intended save. Existing style violations can still block the document; `allow_legacy_style_preservation:true` permits unchanged legacy style violations, not newly introduced ones.

Inspect shared styles and available templates before authoring repeated content:

```json
{"ability_name":"elementor/get-style-guide","parameters":{}}
{"ability_name":"elementor/find-template-for-pattern","parameters":{"pattern":"custom","search":"course"}}
```

For a saved page, regenerate its Elementor CSS when needed:

```json
{"ability_name":"elementor/clear-cache","parameters":{"id":123,"scope":"post","regenerate_css":true}}
```

## Safety and Ownership Boundaries

- Page and element operations check editing permissions. Switching the active Kit requires `manage_options`, permission to edit the selected Kit, a genuine non-trashed Kit document and successful option readback.
- Full or raw document replacement has operation-specific confirmation and destructive-change guards. Inspect each schema; there is no universal confirmation or dry-run contract across all 89 abilities.
- Shared document preparation applies style-reference and structural checks to supported saves and dry runs. It is not a browser rendering test.
- A native template reference can share future changes. Copying template data produces independent content. Confirm where Theme Builder conditions apply before changing them.
- Translation sibling protection depends on a registered language-provider filter. The add-on cannot discover every multilingual system by itself. Verify the other languages after a relevant change.
- Custom code and form submissions use administrative permissions. Form results can contain personal data when values are requested. Permanent deletion operations cannot be undone through a general plugin rollback command.
- Cache invalidation is scoped to the selected operation. Inspect actual frontend output after saving; clearing generated CSS alone does not prove every external cache has refreshed.

## Installation


For update notifications in WordPress, install [Devenia MCP Updater](https://downloads.devenia.com/devenia-mcp-updater.zip). The updater is optional. You choose which plugins update automatically through WordPress.

1. Confirm the WordPress, PHP, Elementor and MCP requirements above.
2. Download the [stable ZIP](https://downloads.devenia.com/mcp-abilities-elementor.zip).
3. In WordPress, open Plugins → Add New → Upload Plugin, select the ZIP and activate it.
4. Connect your authenticated MCP client and verify ability discovery.
5. Read one existing Elementor page before making changes.

## Recent Changes

### 2.3.56

Read and write the code rendered by native Elementor Pro snippets. Expose insertion location, priority and display conditions through the existing abilities, refresh native assignments, preserve code escaping and report metadata save failures.

### 2.3.54

- Supply missing Swiper arrow accessibility labels from native translations without replacing custom defaults or individual carousel settings.

### 2.3.53

- Supply missing JavaScript catalog messages from native frontend translations while preserving existing catalog entries and English defaults.

### 2.3.52

- Include Elementor data and document settings when WordPress checks whether a new revision is needed. Native preview snapshots now retain metadata-only edits without changing older snapshots.

### 2.3.51

- Keep Theme Builder display conditions aligned with each template's native location, including Single Post templates, while preserving other templates' assignments.
- Read conditions for one template or complete location maps. Empty conditions leave a template unassigned.

### 2.3.50

- Preserve both language versions during synchronous metadata synchronization and report successful Elementor saves correctly.

### 2.3.48

- Coordinate translated document protection with compatible Core Content writes. Preserve later explicit body, data and native settings edits without accepting incidental language copies.


### 2.3.47

Preserve native array values when restoring translated Elementor page settings.

### 2.3.46

Preserve translated post content when multilingual page builders regenerate it during Elementor saves.

### 2.3.45

Preserve translated Elementor data and page settings across repeated saves, including intentional edits to another language in the same request.

### 2.3.44

Add one dismissible Plugins-screen reminder when Devenia MCP Updater is missing or inactive, with persistent install or activate links. Automatic updates remain your choice in WordPress.

### 2.3.43

- Require site-settings authority and document edit permission before switching the active Kit.
- Reject ordinary templates and trashed Kits; verify the saved active Kit before reporting success.
- Preserve backslashes and escaped quotes when creating or updating Elementor Pro custom code.
- Keep project documentation outside the installable package.

### 2.3.42

- Apply shared validation and save handling to document, element and template writes, including supported dry runs.
- Validate global color and typography references against the active Kit.
- Expire native document HTML caches after writes and verify native linked-template reuse.

## Contributing

Keep changes focused on the public WordPress abilities. Include a reproducible example and a targeted behavior check for a defect. Do not submit credentials or private page or form data.

## License

[GPLv2 or later](https://www.gnu.org/licenses/gpl-2.0.html).

## Author

[basicus](https://profiles.wordpress.org/basicus/)

## Links

- [Product page](https://devenia.com/plugins/mcp-abilities-elementor/)
- [Stable download](https://downloads.devenia.com/mcp-abilities-elementor.zip)
