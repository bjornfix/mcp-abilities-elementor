# MCP Abilities - Elementor

Elementor abilities for MCP. Get, update, and patch Elementor page data. Manage templates and cache.

[![GitHub release](https://img.shields.io/github/v/release/bjornfix/mcp-abilities-elementor)](https://github.com/bjornfix/mcp-abilities-elementor/releases)
[![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2-blue.svg)](https://www.gnu.org/licenses/gpl-2.0)
[![WordPress](https://img.shields.io/badge/WordPress-6.9%2B-blue.svg)](https://wordpress.org)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-purple.svg)](https://php.net)

**Tested up to:** 7.0
**Stable tag:** 2.3.31
**License:** GPLv2 or later
**License URI:** https://www.gnu.org/licenses/gpl-2.0.html

## What It Does

Version 2.3.31 keeps native element deletion local so unrelated legacy Elementor subtrees are not normalized during the style-preservation comparison.

Elementor abilities for MCP. Get, update, and patch Elementor page data. Manage templates and cache.

This plugin is part of the Devenia MCP abilities ecosystem. It gives an MCP-capable agent a focused, authenticated way to work with Elementor work inside WordPress through MCP.

**Example:** "Handle this WordPress maintenance task directly." - The agent can inspect the site, call the relevant ability, and return the result without making the human click through wp-admin for every step.

## The Real Workflow

In practice, the human should not have to memorize every ability name.

The normal pattern is:

1. install the base MCP stack
2. install only the add-ons the site actually needs
3. let the agent discover the available abilities
4. give the agent a clear task with boundaries
5. verify the result in WordPress

The human's job is mostly to describe the goal.
The agent's job is to figure out the mechanics.

## Why This Feels Different

Most WordPress automation still leaves the repetitive part to the human.

This plugin is different because the agent can act inside the site through a narrow, authenticated ability surface:

- inspect current site state before changing anything
- run the specific action needed for the task
- return structured results that are easy to verify
- keep the workflow inside WordPress instead of a separate checklist

That changes the experience from:

- `Here is what you should do in wp-admin`

to:

- `Tell the agent what needs doing, and let it carry out the work`

## Before vs After

### Before

- ask the AI what to do
- copy the answer into WordPress by hand
- click through wp-admin for the repetitive bits
- postpone maintenance because the task is tedious

### After

- tell the agent what needs doing
- let it inspect the relevant WordPress state
- let it run the targeted ability
- verify the result and move on

## Who It Is For

This is a good fit for:

- agencies managing WordPress sites with AI-assisted maintenance
- operators who want agents to do real WordPress work instead of producing instructions
- teams already using MCP Expose Abilities
- sites where this WordPress area is updated often enough to deserve automation

It is especially useful when the manual version is repetitive enough that important maintenance gets delayed.

## Documentation

Start with the main plugin page and base stack documentation:

- [MCP Expose Abilities](https://devenia.com/plugins/mcp-expose-abilities/)
- [Plugin Page](https://devenia.com/plugins/mcp-expose-abilities/#add-ons)
- [Getting Started](https://github.com/bjornfix/mcp-expose-abilities/wiki/Getting-Started)
- [Install Order and Dependencies](https://github.com/bjornfix/mcp-expose-abilities/wiki/Install-Order-and-Dependencies)

If you are using an AI agent, the simplest instruction is often just:

- `Read https://github.com/bjornfix/mcp-expose-abilities and figure out the stack before making changes.`

## Start Here

If you are new to the stack, use this order:

1. Install **Abilities API**.
2. Install **MCP Adapter**.
3. Install **MCP Expose Abilities**.
4. Install **MCP Abilities - Elementor**.
5. Confirm the new abilities appear in discovery.
6. Give the agent a clear task that uses this add-on.

If you skip base-stack verification and start with add-ons immediately, troubleshooting gets harder than it needs to be.

## Abilities (76)

### Page/Post Data
| Ability | Description |
|---------|-------------|
| `elementor/get-data` | Get Elementor JSON structure for a page |
| `elementor/get-element` | Get a specific element by ID |
| `elementor/find-elements` | Find elements by type, widget, or text |
| `elementor/update-element` | Update a specific element by ID |
| `elementor/merge-element-settings` | Deep-merge settings into one element |
| `elementor/zero-container-padding-subtree` | Zero container padding in a subtree |
| `elementor/copy-lane-settings` | Copy width/gap lane settings between elements |
| `elementor/copy-row-balance` | Copy row rhythm and child column balance between rows |
| `elementor/normalize-campaign-detail-page` | Apply the standard campaign-detail lane/gutter/rhythm recipe |
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

### Get page structure

```json
{
  "ability_name": "elementor/get-data",
  "parameters": {
    "id": 123,
    "format": "array"
  }
}
```

### Find heading widgets containing text

```json
{
  "ability_name": "elementor/find-elements",
  "parameters": {
    "id": 123,
    "widget_type": "heading",
    "contains": "Telenor",
    "include_path": true
  }
}
```

### Create a custom code snippet (Pro)

```json
{
  "ability_name": "elementor/create-custom-code",
  "parameters": {
    "title": "Header tracking",
    "code": "<script>console.log('track');</script>",
    "status": "publish"
  }
}
```

### Create a popup template

```json
{
  "ability_name": "elementor/create-template",
  "parameters": {
    "title": "Welcome Popup",
    "type": "popup",
    "status": "publish"
  }
}
```

### Enable coming soon mode

```json
{
  "ability_name": "elementor/update-maintenance-mode",
  "parameters": {
    "enabled": true,
    "mode": "coming_soon",
    "template_id": 456,
    "exclude_mode": "logged_in"
  }
}
```

### List form submissions (Pro)

```json
{
  "ability_name": "elementor/list-form-submissions",
  "parameters": {
    "form_id": "contact_form",
    "limit": 25,
    "include_values": true
  }
}
```

### Export and import templates

```json
{
  "ability_name": "elementor/export-template",
  "parameters": { "id": 456 }
}

{
  "ability_name": "elementor/import-template",
  "parameters": {
    "data": { "...exported data..." },
    "title": "Imported Template"
  }
}
```

### Clear Elementor cache

```json
{
  "ability_name": "elementor/clear-cache",
  "parameters": { "all": true }
}
```

## Changelog

### 2.3.30

- Let the canonical `elementor/delete-element` ability remove a native Elementor element while preserving all unchanged legacy style debt.

### 2.3.29

- Add explicit `allow_legacy_style_preservation` support for targeted text/settings patches and element removal.
- Require every retained legacy local color, typography value, and inline style attribute to remain unchanged; new or modified local styling is still rejected.

### 2.3.12

- Fixed: `elementor/merge-element-settings` now validates the merged target element instead of blocking on unrelated legacy style violations elsewhere in the Elementor document.

### 2.3.11

- Security: `elementor/update-data`, `elementor/clone-data`, and `elementor/patch-data` now require explicit per-ability confirmation before writing raw Elementor document data.

### 2.3.10

- Added: Elementor write guard blocks Posts widgets that set desktop image ratio without an explicit mobile image ratio, because Elementor Pro defaults mobile ratio to 0.5.
- Added: Elementor write guard rejects `calc(...)` values in ordinary Elementor control fields; use concrete native control values instead.
- Added: write responses can include `elementor_write_guard` warnings for non-blocking responsive setting gaps.

### 2.3.9

- Added: official-pattern guidance now distinguishes legacy Nav Menu/WordPress Menu from the newer Elementor Menu (`mega-menu`) widget, including the Nav Menu limitations around exact desktop dropdown width and line height.
- Added: Elementor write responses now include `menu_widget_guidance` warnings for legacy `nav-menu` control limits and malformed `mega-menu` child-container structures.
- Fixed: `mega-menu` is now treated as an interactive Elementor widget by the frontend runtime guard.

### 2.3.8

- Fixed: Social Icons widget `icon_color` is now treated as an Elementor color-mode selector instead of a local color value, so `icon_color: "custom"` can pass when concrete icon colors are bound to Elementor Kit global color tokens.
- Added: official-pattern guidance now documents Social Icons as the native widget for header/footer social profile links, including its alignment, size, padding, spacing, and centered flex rendering behavior.

### 2.3.7

- Fixed: failed `elementor/create-page` initialization now deletes the newly inserted draft when the global style policy or Elementor data save rejects the payload, avoiding half-created pages.

### 2.3.6

- Added: Elementor write abilities now enforce global style values by rejecting local typography settings and inline style attributes before `_elementor_data` is saved.
- Added: local hex color settings are normalized to matching Elementor Kit global color token references when possible; otherwise the write is rejected with structured violations.
- Changed: `elementor/apply-text-hierarchy` now defaults to Elementor global typography references instead of local font-size/weight/line-height widget overrides.

### 2.3.5

- Fixed: frontend runtime repair now only runs for Elementor Canvas/headless opt-in templates instead of normal Elementor pages.
- Fixed: navigation menu widgets no longer trigger the runtime-repair path, avoiding duplicate menu initialization.

### 2.3.0

- Added: first abilities-only page-authoring pass inspired by the requested `elementor-mcp` comparison.
- Added: `elementor/create-page`, `elementor/add-container`, `elementor/add-widget`, `elementor/add-heading`, `elementor/add-text-editor`, `elementor/add-image`, `elementor/add-button`, `elementor/move-element`, `elementor/remove-element`, `elementor/duplicate-element`, and `elementor/reorder-elements`.
- Kept the implementation inside the existing `elementor/*` ability namespace without adding a separate MCP server or proxy layer.

### 2.2.38

- Fixed: frontend runtime repair no longer fatals on Elementor versions where `print_config()` is a protected method.
- Fixed: runtime health audit no longer uses a taxonomy query that triggers a Plugin Check slow-query warning.

### 2.2.37

- Normalize Elementor Pro popup display settings so `triggers` and `timing` stay frontend-safe on popup writes.
- Extend the interactive frontend runtime guard to surface broken published popup/theme-builder documents when they are the likely root cause of missing interactive JS.
- Remove the direct script-tag runtime fallback so frontend repair stays inside WordPress enqueue/print flows and passes Plugin Check.

### 2.2.35
- Fixed: the direct JS fallback no longer skips itself just because Elementor marked script handles as enqueued on templates that still never print those handles.

### 2.2.34
- Fixed: when Elementor config/CSS load but the JS runtime handles still never emit, runtime repair now prints the core Elementor JS assets directly as a last-resort frontend fallback for interactive documents.

### 2.2.33
- Fixed: runtime repair now explicitly enqueues Elementor JS runtime handles and re-runs at Elementor's script-registration stage, closing the gap where config and CSS loaded but `window.elementorFrontend` never booted.

### 2.2.32
- Fixed: runtime repair now resolves the current frontend document more reliably on static front-page and posts-page requests instead of depending only on `is_singular()`.

### 2.2.31
- Fixed: frontend runtime repair no longer caches a false negative before the main WordPress query is ready, so interactive Elementor pages can actually bootstrap their runtime on the frontend.

### 2.2.30
- Added: frontend runtime guard diagnostics on Elementor write abilities so interactive-widget documents fail loudly when the published page is missing Elementor runtime.
- Added: conditional frontend runtime repair hooks that enqueue Elementor frontend assets, print `elementorFrontendConfig`, and print queued runtime scripts early for interactive Elementor documents on canvas-like templates.

### 2.2.29
- Added: `elementor/get-official-widget-catalog` to fetch the full official widget catalog from `https://elementor.com/widgets`, grouped into Basic, Pro, Theme, and WooCommerce categories
- Improved: the plugin now has an official availability surface for all Elementor widgets instead of only a hand-maintained shortlist of widget docs

### 2.2.27
- Added: `elementor/get-official-pattern-guidance` to expose the official Elementor.com layout/widget guidance catalog directly through the plugin
- Improved: `elementor/audit-layout-mechanism-fit`, `elementor/audit-native-widget-opportunities`, `elementor/evaluate-design`, and `elementor/suggest-design-fixes` now surface an explicit source policy so recommendations stay grounded in Elementor.com first instead of site-local guesswork

### 2.2.28
- Improved: `elementor/get-theme-context`, `elementor/get-style-guide`, `elementor/evaluate-design`, and `elementor/suggest-design-fixes` now expose `guidance_basis` alongside `source_policy`, explicitly separating official Elementor-doc-backed topics from plugin heuristic audits

### 2.2.26
- Fixed: `elementor/audit-native-widget-opportunities` is now narrower and no longer treats editorial trios or mixed case-study sections as generic Accordion/Tabs candidates just because they contain repeated heading+copy content

### 2.2.25
- Added: `elementor/audit-native-widget-opportunities` to identify where hand-built container patterns are better served by native Elementor widgets such as Accordion, Nested Tabs, Call to Action, or Icon List
- Improved: `elementor/evaluate-design` and `elementor/suggest-design-fixes` now surface native-widget recommendations so Elementor is treated more like a full builder system and less like raw container JSON

### 2.2.24
- Added: `elementor/audit-layout-mechanism-fit` to identify equal, symmetric column groups where Elementor Grid is the better mechanism than Flexbox width guessing
- Improved: `elementor/evaluate-design` and `elementor/suggest-design-fixes` now surface Grid-vs-Flex recommendations for symmetric peer-column layouts using Elementor's official guidance

### 2.2.20
- Added: `elementor/audit-column-patterns` to audit repeated column ratios such as repeated 50/50 and equal-third rows without assuming asymmetry is automatically better
- Added: `elementor/audit-column-dominance` to flag equal column splits that may be hiding a clearly dominant side
- Added: `elementor/audit-column-alignment-rhythm` to report when similar column ratios use inconsistent gutter rhythms
- Added: `elementor/audit-column-balance` to flag asymmetric rows that may not be earning their asymmetry
- Added: `elementor/audit-column-necessity` to flag splits that may not be earning their complexity and could read more clearly as one lane

### 2.2.19
- Added: `elementor/audit-generic-component-repetition` to flag overused landing-page furniture such as too many buttons and repeated card-like panel treatments without punishing simple layouts for being restrained
- Added: `elementor/audit-surface-overuse` to report repeated panel/surface signatures cautiously, with recommendations that distinguish formulaic repetition from intentional simplicity
- Added: `elementor/audit-emphasis-drift` to check whether top-level sections are all carrying roughly the same emphasis weight, while only warning when the page risks making every section land with the same force
- Added: `elementor/audit-composition-rhythm` to inspect top-level tonal runs and pacing without assuming that minimal or restrained pages are wrong

### 2.2.18
- Fixed: `elementor/audit-generic-layout-patterns` no longer treats simple header rows with image+button furniture as generic split-hero compositions; split-hero detection now requires a real hero-style copy side

### 2.2.17
- Added: `elementor/audit-generic-layout-patterns` to flag repeated split heroes, repeated 50/50 rows, equal-width grids, and repeated component rows without prescribing any visual style
- Added: `elementor/score-distinctiveness` to turn those structural repetition signals into a neutral distinctiveness score with non-style-specific recommendations
- Changed: `elementor/apply-text-hierarchy` no longer hardcodes `Jost` as the default font family; default hierarchy normalization is now style-neutral unless explicit font choices are provided

### 2.2.16
- Fixed: `elementor/normalize-responsive-values` now caps generated tablet/mobile left-right spacing by default so inherited desktop padding does not crush narrow breakpoint layouts
- Added: `tablet_horizontal_spacing_cap` and `mobile_horizontal_spacing_cap` inputs on `elementor/normalize-responsive-values` for explicit breakpoint edge-spacing control

### 2.2.15
- Added: `elementor/extract-design-tokens` to inspect recurring colors, typography, spacing, and dimensional rhythm from a page/subtree and the active kit
- Added: `elementor/apply-text-hierarchy` to normalize heading/body/button typography in a subtree
- Added: `elementor/normalize-section-spacing-rhythm` to snap section padding and row gaps to a consistent rhythm step
- Added: `elementor/normalize-responsive-values` to fill or normalize tablet/mobile values from desktop settings
- Added: `elementor/sync-component-variant` to copy design-relevant settings from one component subtree to another

### 2.2.14
- Added: `elementor/enforce-boundary-coherence` to normalize a subtree to true edge-to-edge full width or a consistent boxed lane with matching outer and inner left/right boundaries

### 2.2.13
- Added: `elementor/normalize-campaign-detail-page` to apply the repeated `1140px` lane / zero-gutter / `18px` rhythm / widened-about-block recipe in one call
- Added: `elementor/image-widget-to-background-container` to convert an image-widget column into a native background-image container with the same local media
- Added: `elementor/fix-visible-gap-rhythm` to remove hidden top padding/margin that makes visible section gaps look larger than the intended rhythm

### 2.2.12
- Added: `elementor/copy-row-balance` to copy row gap plus direct-child width/flex/padding settings from one row to another for consistent visual balance

### 2.2.11
- Added: `elementor/merge-element-settings` for targeted settings-only updates without full element replacement payloads
- Added: `elementor/zero-container-padding-subtree` to normalize hidden container padding inside a section/subtree
- Added: `elementor/copy-lane-settings` to copy standard width/gap lane settings from one element to another
- Added: `elementor/reset-negative-margins-subtree` to clamp negative Elementor margins that cancel intended spacing

### 2.2.10
- Fixed: all Elementor data write paths now normalize top-level background-image subtrees so parent containers get `e-no-lazyload` automatically when needed

### 2.2.9
- Added: guardrails to `elementor/update-element` so incomplete replacement payloads do not silently wipe populated Elementor containers/widgets unless `force_replace=true`
- Added: guardrails to `elementor/update-data` so destructive full-document replacements require `force_replace=true`
- Added: guardrails to `elementor/delete-element` so top-level/populated element deletions require `force_delete=true`
- Fixed: `elementor/update-element` now normalizes background-image container replacements so missing layout settings inherit from the original container before save

### 2.2.8
- Docs: expanded the WordPress-standard `readme.txt` so the published ZIP now includes fuller requirements, abilities, setup guidance, and Devenia ecosystem links

### 2.2.7
- Added: `elementor/clone-data` to clone native Elementor data and page settings from an existing page/template into a target page

### 2.2.6
- Fixed: `elementor/duplicate-template` now preserves JSON-backed Elementor meta correctly when duplicating templates
- Fixed: `elementor/get-data`, `elementor/get-template`, and `elementor/export-template` now normalize invalid or unexpected Elementor data into schema-safe arrays
- Added: duplicated templates now also carry template sub type and saved Elementor conditions

### 2.2.5
- Added: `elementor/delete-element` for targeted deletion of widgets/containers by element ID
- Added: `cache_scope` support and cache details in `elementor/delete-element` responses

### 2.2.4
- Fixed: duplicate `clean_post_cache()` calls on write cache invalidation paths
- Added: no-op short-circuit for `elementor/update-data` and `elementor/update-element` (skips writes/cache invalidation when no effective change is produced)
- Improved: `effective_scope` now reflects actual cache invalidation outcome
- Improved: centralized Elementor site cache clear logic in a shared helper
- Fixed: `elementor/clear-cache` description to match behavior (post scope does not touch post timestamps)
- Changed: write abilities (`update-data`, `patch-data`, `update-element`) are now marked non-idempotent in metadata

### 2.2.3
- Added: `cache_scope` (`none` / `post` / `site`) to `elementor/update-data`, `elementor/patch-data`, and `elementor/update-element`
- Improved: stronger cache invalidation after Elementor writes (post cache cleanup, asset meta cleanup, optional site-wide Elementor cache clear)
- Improved: `elementor/clear-cache` now returns cache details and supports `scope` alias (`post` / `site`)

### 2.3.28

- Fixed: the global style policy now allows Elementor background mode selectors such as `classic` while still requiring actual background color values to use global Kit color tokens.

### 2.3.27

- Fixed: `elementor/add-post-tabs` now defaults native Nested Tabs horizontal scroll to disabled so all filter tabs stay visible and wrap above the post grid.

### 2.3.26

- Fixed: `elementor/add-post-tabs` now uses valid native Elementor Nested Tabs direction, alignment, scroll, and breakpoint defaults so mobile filters stay above the post grid.

### 2.3.25

- Added: `elementor/add-post-tabs` creates native Elementor Nested Tabs where each tab contains a native Posts widget, for tabbed blog/post sections without manual cards or custom filter markup.

### 2.3.24

- Fixed: Elementor write invalidation and `elementor/clear-cache` now clear Cache Enabler page cache through public Cache Enabler hooks when that plugin is active.

### 2.3.23

- Added: `elementor/clear-cache` can regenerate a single post's generated Elementor CSS file with `regenerate_css=true` and reports whether the CSS file exists.

### 2.3.22

- Fixed translated sibling restore so captured Elementor JSON meta is re-slashed before being written back. This prevents `_elementor_data` corruption when multilingual custom-field sync hooks briefly copy source-language data to translated siblings during a guarded write.

### 2.3.21

- Fixed `elementor/update-data` recovery so `force_replace=true` can repair a
  document whose existing `_elementor_data` meta is malformed.

### 2.3.20

- Improved translated sibling protection by scheduling a final shutdown restore
  after late multilingual custom-field sync hooks.

### 2.3.19

- Added `elementor_translation_guard` details to targeted settings-merge
  responses so callers can verify which translated sibling documents were
  protected during Elementor writes.

### 2.3.18
- Fixed translated Elementor document safety by guarding `_elementor_data` and `_elementor_page_settings` writes against multilingual custom-field sync.
- Added a translation-sibling provider seam through `mcp_abilities_elementor_translation_sibling_filter_name()` so language plugins can supply sibling IDs without Elementor depending on a specific multilingual plugin.

### 2.3.17
- Improved internal architecture with a shared ability registrar, Elementor document repository, and design audit runner.
- Added lightweight architecture tests for helper interfaces while keeping tests out of release ZIPs.
- Preserved the existing public ability surface while centralizing registration and common document/audit handling.

### 2.3.16
- Added `elementor/find-template-for-pattern` so agents can search the current site's Elementor Library for reusable pattern templates before manually authoring containers/widgets.
- Improved pattern guidance with a template-first rule: reuse saved templates for repeated patterns, and create a template when a repeatable pattern is identified but no suitable template exists.
- Changed raw container authoring: `elementor/add-container` now requires explicit template lookup status, and blocks reusable repeated patterns until a saved template exists.

### 2.3.15
- Added official pattern guidance for full-height split-panel carousel surfaces: use Elementor Pro Slides as the native background-cover slide surface instead of Media Carousel.
- Added official pattern guidance for static split-panel image surfaces: use native container background images with cover/min-height controls when the image is a design surface rather than inline content.
- Added official pattern guidance for dynamic related/archive card lists, static Image Box card grids, curated Gallery sections, and repeated Call to Action modules.
- Improved `elementor/get-official-pattern-guidance` with `topic=patterns`.

### 2.3.14
- Added `elementor/get-widget-controls` to inspect native Elementor widget control keys and metadata on the target site before writing widget settings.

### 2.2.2
- Fixed: parse error in `elementor/set-active-kit`
- Added: README sync for current stable tag and full ability list

### 2.2.0
- Added: custom code snippet CRUD abilities (Elementor Pro)
- Added: form submissions list/get/delete abilities (Elementor Pro)
- Added: template sub type support for WooCommerce/theme builder templates

### 2.1.0
- Added: element-level lookup (get-element, find-elements)
- Added: theme builder conditions get/update abilities
- Added: maintenance mode get/update abilities
- Added: experiments list/update abilities
- Added: replace-urls tool
- Changed: conditions normalization helper (conditions can be cleared)

### 2.0.6
- Added: success/message fields to list-templates and list-global-widgets outputs

### 2.0.5
- Fixed: Popups now use Elementor's native Documents Manager API for creation

### 2.0.4
- Fixed: Popup conditions now stored as strings (PHP 8.4 compatibility)

### 2.0.3
- Fixed: delete-template now properly trashes instead of permanently deleting

### 2.0.2
- Fixed: Empty properties validation for get-kit-settings and list-global-widgets

### 2.0.0
- Major release: Complete template management suite (19 abilities total)
- Added: Full CRUD for templates (create, update, delete, get, restore, empty-trash)
- Added: duplicate-template, export-template, import-template
- Added: list-global-widgets, get-kit-settings, update-kit-settings
- All template abilities support display conditions and popup triggers

### 1.0.2
- Security: Added per-post capability checks for Elementor operations

### 1.0.1
- Added: `elementor/update-page-settings` ability

### 1.0.0
- Initial release

## Contributing

PRs welcome. Keep changes focused on the plugin's WordPress ability surface and preserve authenticated, explicit workflows.

## License

GPL-2.0+

## Author

[Devenia](https://devenia.com) - We've been doing SEO and web development since 1993.

## Links

- [Plugin Page](https://devenia.com/plugins/mcp-expose-abilities/#add-ons)
- [MCP Expose Abilities](https://devenia.com/plugins/mcp-expose-abilities/)
- [GitHub Releases](https://github.com/bjornfix/mcp-abilities-elementor/releases)

## Star and Share

If this plugin saves you time or makes WordPress maintenance easier to verify, please:

- star the repo
- share it with people running WordPress sites
- point them to the main plugin page so they can see what the ecosystem can actually do

Why do it?

Because agent-friendly open WordPress tooling helps more of the boring but important work get done.
