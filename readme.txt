=== MCP Abilities - Elementor ===
Contributors: basicus
Tags: mcp, elementor, page builder, ai, automation
Requires at least: 6.9
Tested up to: 7.0
Stable tag: 2.3.38
Requires PHP: 8.0
Requires Plugins: elementor
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Elementor page builder integration for WordPress via MCP.

== Description ==

This add-on plugin exposes Elementor functionality through MCP (Model Context Protocol). Your AI assistant can read Elementor page structures, locate and update elements, manage templates and conditions, and run Elementor tools like maintenance mode, experiments, and URL replacement.

Version 2.3.37 decodes Elementor document data without an extra `wp_unslash` pass so UTF-8 characters like `ø`, `æ`, `å`, `é`, `²` and `—` survive the read-write cycle instead of being stored as literal `u00XX` sequences.

= Requirements =

* [WordPress Abilities API](https://developer.wordpress.org/apis/abilities-api/) in WordPress 6.9 or newer
* [PHP 8.0](https://www.php.net/releases/8.0/en.php) or newer
* [WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter/) installed and active
* [Elementor](https://wordpress.org/plugins/elementor/) installed and active
* [Elementor Pro](https://elementor.com/pro/) is optional and enables the Pro-specific abilities

= Abilities Included =

**Page/Post data** - Get, patch, update, delete, and clone Elementor JSON and page settings.

**Authoring primitives** - Create Elementor pages, add containers and widgets, move/remove/duplicate/reorder elements, and add common heading, text, image, and button widgets.

**Templates** - List, create, update, duplicate, delete, export, and import Elementor templates.

**Theme Builder** - Read and update Elementor theme-builder display conditions.

**Custom code and forms** - Manage Elementor Pro custom code and form submissions when Pro is active.

= Use Cases =

* Update text or layout inside Elementor pages without opening the editor
* Duplicate proven template setups across pages or sites
* Audit template conditions and maintenance-mode settings
* Export/import Elementor templates through MCP workflows
* Clear Elementor cache safely after AI-assisted changes

== Installation ==

1. Confirm WordPress 6.9 or newer and PHP 8.0 or newer
2. Install and activate WordPress MCP Adapter and Elementor
3. Download the latest release
4. Upload via WordPress Admin → Plugins → Add New → Upload Plugin
5. Activate the plugin
6. The abilities are now available via the MCP endpoint

= Links =

* [Plugin Page](https://devenia.com/plugins/mcp-abilities-elementor/)
* [Stable Download](https://downloads.devenia.com/mcp-abilities-elementor.zip)

== Changelog ==

= 2.3.38 =

* Adds a generic source-write authorization hook around registered mutating Elementor abilities so native source saves can keep the owning Workflow authority.

= 2.3.37 =
* Fixed: UTF-8 corruption in Elementor document data. `mcp_abilities_elementor_get_post_elements()` no longer runs `wp_unslash()` before `json_decode()`, so Unicode characters no longer persist as literal `u00XX` text after abilities that read and rewrite the document tree.

= 2.3.36 =
* Fixed: `get-kit-settings` and `update-kit-settings` normalize the active Kit option to an integer before guarded metadata access.

= 2.3.35 =
* Removed the site-specific campaign-detail recipe from the generic Elementor ability catalogue. Site-owned layout recipes now belong in site presentation Adapters.

= 2.3.34 =
* Fixed: native document saves initialize Elementor and Elementor Pro version metadata so Theme Builder Post Content renders newly converted posts.

= 2.3.33 =
* Fixed: legacy-style preservation compares the complete internal audit instead of mistaking older violations beyond the 25-item response limit for new styling.

= 2.3.32 =
* Added: `elementor/update-element` accepts `allow_legacy_style_preservation` for safe, targeted native widget and container replacements.
* Fixed: targeted replacements use the shared document-save boundary without normalizing unrelated Elementor subtrees.

= 2.3.31 =
* Fixed: native element deletion compares the stored document with the same document minus the target element, without normalizing unrelated legacy subtrees first.
* Safety: unchanged legacy styling can be preserved for a deletion, while new or modified local styling remains blocked.

= 2.3.30 =
* Fixed: the canonical `elementor/delete-element` ability can remove a native Elementor element while preserving all unchanged legacy style debt.

= 2.3.29 =
* Added: explicit `allow_legacy_style_preservation` support for targeted text/settings patches and element removal.
* Safety: retained legacy local colors, typography, and inline style attributes must remain unchanged; new or modified local styling is still rejected.

= 2.3.28 =
* Fixed: the global style policy now allows Elementor background mode selectors such as `classic` while still requiring actual background color values to use global Kit color tokens.

= 2.3.27 =
* Fixed: `elementor/add-post-tabs` now defaults native Nested Tabs horizontal scroll to disabled so all filter tabs stay visible and wrap above the post grid.

= 2.3.26 =
* Fixed: `elementor/add-post-tabs` now uses valid native Elementor Nested Tabs direction, alignment, scroll, and breakpoint defaults so mobile filters stay above the post grid.

= 2.3.25 =
* Added: `elementor/add-post-tabs` creates native Elementor Nested Tabs where each tab contains a native Posts widget, for tabbed blog/post sections without manual cards or custom filter markup.

= 2.3.24 =
* Fixed: Elementor write invalidation and `elementor/clear-cache` now clear Cache Enabler page cache through public Cache Enabler hooks when that plugin is active.

= 2.3.23 =
* Added: `elementor/clear-cache` can regenerate a single post's generated Elementor CSS file with `regenerate_css=true` and reports whether the CSS file exists.

= 2.3.22 =
* Fixed: translated sibling restore now re-slashes captured Elementor JSON meta before writing it back, preventing invalid `_elementor_data` after WPML/Polylang-style custom-field sync hooks.

= 2.3.21 =
* Fixed: `elementor/update-data` with `force_replace=true` can now repair a document whose existing `_elementor_data` meta is malformed.

= 2.3.20 =
* Improved: translated sibling protection now schedules a final shutdown restore after late multilingual custom-field sync hooks.

= 2.3.19 =
* Added: `elementor_translation_guard` details to targeted settings-merge responses so callers can verify which translated sibling documents were protected during Elementor writes.

= 2.3.18 =
* Fixed: Elementor writes now preserve translated sibling documents when multilingual plugins try to sync `_elementor_data` or `_elementor_page_settings` during postmeta updates.
* Added: a translation-sibling provider seam through `mcp_abilities_elementor_translation_sibling_filter_name()` so language plugins can supply sibling IDs without Elementor depending on a specific multilingual plugin.

= 2.3.17 =
* Improved: internal ability registration now passes through a shared registrar so common defaults and future policy rules live behind one interface.
* Improved: common Elementor document loading and design-audit execution now use shared helper modules.
* Added: lightweight architecture regression tests for helper interfaces; tests are excluded from release packages.

= 2.3.16 =
* Added: `elementor/find-template-for-pattern` finds saved Elementor Library templates that match reusable layout patterns before raw authoring.
* Improved: pattern guidance now requires saved-template reuse first, and template creation when a repeatable Elementor pattern is identified but no suitable template exists.
* Changed: raw container authoring now requires explicit template lookup status and blocks reusable repeated patterns until a saved template exists.

= 2.3.15 =
* Added: official pattern guidance now documents when to use Elementor Pro Slides for full-height split-panel carousel image surfaces instead of Media Carousel.
* Added: official pattern guidance now documents when native container background images are the correct Elementor model for static split-panel image surfaces.
* Added: official pattern guidance now documents dynamic related/archive card lists, static Image Box card grids, curated Gallery sections, and repeated Call to Action modules.
* Improved: `elementor/get-official-pattern-guidance` supports `topic=patterns`.

= 2.3.14 =
* Added: `elementor/get-widget-controls` returns schema-safe summaries of native Elementor widget controls from the target site.

= 2.3.13 =
* Fixed: `elementor/update-data` can now initialize an existing post/page with empty Elementor data when `force_replace=true` and the dangerous-action confirmation are both provided.

= 2.3.12 =
* Fixed: `elementor/merge-element-settings` now validates the merged target element instead of blocking on unrelated legacy style violations elsewhere in the Elementor document.

= 2.3.11 =
* Security: `elementor/update-data`, `elementor/clone-data`, and `elementor/patch-data` now require explicit per-ability confirmation before writing raw Elementor document data.

= 2.3.10 =
* Added: Elementor write guard blocks Posts widgets that set desktop image ratio without an explicit mobile image ratio, because Elementor Pro defaults mobile ratio to 0.5.
* Added: Elementor write guard rejects `calc(...)` values in ordinary Elementor control fields; use concrete native control values instead.
* Added: write responses can include `elementor_write_guard` warnings for non-blocking responsive setting gaps.

= 2.3.9 =
* Added: official-pattern guidance now distinguishes legacy Nav Menu/WordPress Menu from the newer Elementor Menu (`mega-menu`) widget, including the Nav Menu limitations around exact desktop dropdown width and line height.
* Added: Elementor write responses now include `menu_widget_guidance` warnings for legacy `nav-menu` control limits and malformed `mega-menu` child-container structures.
* Fixed: `mega-menu` is now treated as an interactive Elementor widget by the frontend runtime guard.

= 2.3.8 =
* Fixed: Social Icons widget `icon_color` is now treated as an Elementor color-mode selector instead of a local color value, so `icon_color: "custom"` can pass when concrete icon colors are bound to Elementor Kit global color tokens.
* Added: official-pattern guidance now documents Social Icons as the native widget for header/footer social profile links.

= 2.3.7 =
* Fixed: failed `elementor/create-page` initialization now deletes the newly inserted draft when the global style policy or Elementor data save rejects the payload, avoiding half-created pages.

= 2.3.6 =
* Added: Elementor write abilities now enforce global style values by rejecting local typography settings and inline style attributes before `_elementor_data` is saved.
* Added: local hex color settings are normalized to matching Elementor Kit global color token references when possible; otherwise the write is rejected with structured violations.
* Changed: `elementor/apply-text-hierarchy` now defaults to Elementor global typography references instead of local font-size/weight/line-height widget overrides.

= 2.3.5 =
* Fixed: frontend runtime repair now only runs for Elementor Canvas/headless opt-in templates instead of normal Elementor pages.
* Fixed: navigation menu widgets no longer trigger the runtime-repair path, avoiding duplicate menu initialization.

= 2.3.0 =
* Added: first abilities-only page-authoring pass inspired by the requested `elementor-mcp` comparison.
* Added: `elementor/create-page`, `elementor/add-container`, `elementor/add-widget`, `elementor/add-heading`, `elementor/add-text-editor`, `elementor/add-image`, `elementor/add-button`, `elementor/move-element`, `elementor/remove-element`, `elementor/duplicate-element`, and `elementor/reorder-elements`.
* Kept the implementation inside the existing `elementor/*` ability namespace without adding a separate MCP server or proxy layer.

= 2.2.27 =
* Added: `elementor/get-official-pattern-guidance` to expose the official Elementor.com layout/widget guidance catalog directly through the plugin
* Improved: `elementor/audit-layout-mechanism-fit`, `elementor/audit-native-widget-opportunities`, `elementor/evaluate-design`, and `elementor/suggest-design-fixes` now surface an explicit source policy so recommendations stay grounded in Elementor.com first instead of site-local guesswork

= 2.2.28 =
* Improved: `elementor/get-theme-context`, `elementor/get-style-guide`, `elementor/evaluate-design`, and `elementor/suggest-design-fixes` now expose `guidance_basis` alongside `source_policy`, explicitly separating official Elementor-doc-backed topics from plugin heuristic audits

= 2.2.38 =

* Fixed: frontend runtime repair no longer fatals on Elementor versions where `print_config()` is a protected method.
* Fixed: runtime health audit no longer uses a taxonomy query that triggers a Plugin Check slow-query warning.

= 2.2.37 =

* Normalize Elementor Pro popup display settings so `triggers` and `timing` stay frontend-safe on popup writes.
* Extend the interactive frontend runtime guard to surface broken published popup/theme-builder documents when they are the likely root cause of missing interactive JS.
* Remove the direct script-tag runtime fallback so frontend repair stays inside WordPress enqueue/print flows and passes Plugin Check.

= 2.2.35 =
* Fixed: the direct JS fallback no longer skips itself just because Elementor marked script handles as enqueued on templates that still never print those handles.

= 2.2.34 =
* Fixed: when Elementor config/CSS load but the JS runtime handles still never emit, runtime repair now prints the core Elementor JS assets directly as a last-resort frontend fallback for interactive documents.

= 2.2.33 =
* Fixed: runtime repair now explicitly enqueues Elementor JS runtime handles and re-runs at Elementor's script-registration stage, closing the gap where config and CSS loaded but `window.elementorFrontend` never booted.

= 2.2.32 =
* Fixed: runtime repair now resolves the current frontend document more reliably on static front-page and posts-page requests instead of depending only on `is_singular()`.

= 2.2.31 =
* Fixed: frontend runtime repair no longer caches a false negative before the main WordPress query is ready, so interactive Elementor pages can actually bootstrap their runtime on the frontend.

= 2.2.30 =
* Added: frontend runtime guard diagnostics on Elementor write abilities so interactive-widget documents fail loudly when the published page is missing Elementor runtime.
* Added: conditional frontend runtime repair hooks that enqueue Elementor frontend assets, print `elementorFrontendConfig`, and print queued runtime scripts early for interactive Elementor documents on canvas-like templates.

= 2.2.29 =
* Added: `elementor/get-official-widget-catalog` to fetch the full official widget catalog from `https://elementor.com/widgets`, grouped into Basic, Pro, Theme, and WooCommerce categories
* Improved: the plugin now has an official availability surface for all Elementor widgets instead of only a hand-maintained shortlist of widget docs

= 2.2.26 =
* Fixed: `elementor/audit-native-widget-opportunities` is now narrower and no longer treats editorial trios or mixed case-study sections as generic Accordion/Tabs candidates just because they contain repeated heading+copy content

= 2.2.25 =
* Added: `elementor/audit-native-widget-opportunities` to identify where hand-built container patterns are better served by native Elementor widgets such as Accordion, Nested Tabs, Call to Action, or Icon List
* Improved: `elementor/evaluate-design` and `elementor/suggest-design-fixes` now surface native-widget recommendations so Elementor is treated more like a full builder system and less like raw container JSON

= 2.2.24 =
* Added: `elementor/audit-layout-mechanism-fit` to identify equal, symmetric column groups where Elementor Grid is a better fit than Flexbox width-guessing
* Improved: `elementor/evaluate-design` and `elementor/suggest-design-fixes` now surface Grid-vs-Flex recommendations for symmetric peer-column layouts using Elementor's official guidance

= 2.2.23 =
* Added: `elementor/audit-separator-discipline` to detect when top-level section separators start flattening hierarchy instead of helping section families
* Improved: `elementor/evaluate-design` and `elementor/suggest-design-fixes` now include separator-overuse as a soft rhythm/hierarchy issue instead of forcing it as a style rule

= 2.2.22 =
* Added: `elementor/get-theme-context` to summarize the active theme, Elementor version, active kit, and viewport options before design work begins
* Added: `elementor/get-style-guide` to turn the active Elementor kit into a usable style-guide summary with tokens, layout, colors, and typography
* Added: `elementor/evaluate-design` to aggregate overlapping design audits into one coherent score, issue list, and recommendation surface
* Added: `elementor/suggest-design-fixes` to turn that aggregated evaluation into concrete next-step design fixes
* Added: `elementor/evaluate-render-context` to inspect wrapper/theme-render issues separately from Elementor content quality

= 2.2.20 =
* Added: `elementor/audit-column-patterns` to audit repeated column ratios such as repeated 50/50 and equal-third rows without assuming asymmetry is automatically better
* Added: `elementor/audit-column-dominance` to flag equal column splits that may be hiding a clearly dominant side
* Added: `elementor/audit-column-alignment-rhythm` to report when similar column ratios use inconsistent gutter rhythms
* Added: `elementor/audit-column-balance` to flag asymmetric rows that may not be earning their asymmetry
* Added: `elementor/audit-column-necessity` to flag splits that may not be earning their complexity and could read more clearly as one lane

= 2.2.19 =
* Added: `elementor/audit-generic-component-repetition` to flag overused landing-page furniture such as too many buttons and repeated card-like panel treatments without punishing simple layouts for being restrained
* Added: `elementor/audit-surface-overuse` to report repeated panel/surface signatures cautiously, with recommendations that explicitly distinguish formulaic repetition from intentional simplicity
* Added: `elementor/audit-emphasis-drift` to check whether top-level sections are all carrying roughly the same emphasis weight, while only warning when the page risks making every section land with the same force
* Added: `elementor/audit-composition-rhythm` to inspect top-level tonal runs and pacing without assuming that minimal or restrained pages are wrong

= 2.2.18 =
* Fixed: `elementor/audit-generic-layout-patterns` no longer treats simple header rows with image+button furniture as generic split-hero compositions; split-hero detection now requires a real hero-style copy side

= 2.2.17 =
* Added: `elementor/audit-generic-layout-patterns` to flag repeated split heroes, repeated 50/50 rows, equal-width grids, and repeated component rows without prescribing any visual style
* Added: `elementor/score-distinctiveness` to turn those structural repetition signals into a neutral distinctiveness score with non-style-specific recommendations
* Changed: `elementor/apply-text-hierarchy` no longer hardcodes `Jost` as the default font family; default hierarchy normalization is now style-neutral unless explicit font choices are provided

= 2.2.16 =
* Fixed: `elementor/normalize-responsive-values` now caps generated left/right tablet/mobile spacing by default so desktop padding does not collapse narrow breakpoint layouts
* Added: `tablet_horizontal_spacing_cap` and `mobile_horizontal_spacing_cap` inputs on `elementor/normalize-responsive-values` for explicit breakpoint edge-spacing control

= 2.2.15 =
* Added: `elementor/extract-design-tokens` to inspect recurring colors, typography, spacing, and dimensional rhythm from a page/subtree and the active kit
* Added: `elementor/apply-text-hierarchy` to normalize heading/body/button typography in a subtree
* Added: `elementor/normalize-section-spacing-rhythm` to snap section padding and row gaps to a consistent rhythm step
* Added: `elementor/normalize-responsive-values` to fill or normalize tablet/mobile values from desktop settings
* Added: `elementor/sync-component-variant` to copy design-relevant settings from one component subtree to another

= 2.2.14 =
* Added: `elementor/enforce-boundary-coherence` to normalize a subtree to either true full width or a coherent boxed lane with matching outer and inner left/right boundaries

= 2.2.13 =
* Added: `elementor/image-widget-to-background-container` to convert an image-widget container into a native background-image container using the same media
* Added: `elementor/fix-visible-gap-rhythm` to remove hidden leading-edge padding/margin that makes visible section gaps drift from the intended rhythm

= 2.2.12 =
* Added: `elementor/copy-row-balance` to copy row gap plus direct-child width/flex/padding settings from one row to another for more consistent visual balance

= 2.2.11 =
* Added: `elementor/merge-element-settings` for targeted settings-only updates without full element replacement payloads
* Added: `elementor/zero-container-padding-subtree` to normalize hidden container padding inside a section/subtree
* Added: `elementor/copy-lane-settings` to copy standard width/gap lane settings from one element to another
* Added: `elementor/reset-negative-margins-subtree` to clamp negative Elementor margins that cancel intended spacing

= 2.2.10 =
* Fixed: all Elementor data write paths now normalize top-level background-image subtrees so parent containers get `e-no-lazyload` automatically when needed

= 2.2.9 =
* Fixed: `elementor/update-element` now normalizes background-image container replacements so missing layout settings inherit from the original container before save

= 2.2.8 =
* Docs: expanded the WordPress-standard `readme.txt` so the published ZIP now includes fuller requirements, abilities, setup guidance, and Devenia ecosystem links

= 2.2.7 =
* Added: `elementor/clone-data` to clone native Elementor data and page settings from an existing page/template into a target page

= 2.2.6 =
* Fixed: `elementor/duplicate-template` now preserves JSON-backed Elementor meta correctly when duplicating templates
* Fixed: `elementor/get-data`, `elementor/get-template`, and `elementor/export-template` now normalize invalid or unexpected Elementor data into schema-safe arrays
* Added: duplicated templates now also carry template sub type and saved Elementor conditions

= 2.2.5 =
* Added: `elementor/delete-element` ability for targeted deletion of Elementor widgets/containers by element ID
* Added: `cache_scope` support and cache details response on `elementor/delete-element`

= 2.2.4 =
* Fixed: duplicate `clean_post_cache()` calls on write cache invalidation paths
* Added: no-op short-circuit for `elementor/update-data` and `elementor/update-element` (skips writes/cache invalidation when output is unchanged)
* Improved: `effective_scope` now reflects actual cache invalidation outcome (`site` falls back to `post`/`none` when applicable)
* Improved: centralized Elementor site cache clear logic in a shared helper to reduce duplication
* Fixed: `elementor/clear-cache` description to match behavior (post scope does not touch post timestamps)
* Changed: marked write abilities (`update-data`, `patch-data`, `update-element`) as non-idempotent in metadata

= 2.2.3 =
* Added: `cache_scope` (`none|post|site`) to `elementor/update-data`, `elementor/patch-data`, and `elementor/update-element`
* Improved: cache invalidation after Elementor writes (post cache cleanup, asset meta cleanup, optional site-wide Elementor cache clear)
* Improved: `elementor/clear-cache` responses now include cache details and supports `scope` alias (`post|site`)
* Fixed: `elementor/clear-cache` post-scope no longer relies on CSS-meta-only clearing

= 2.2.2 =
* Fixed: parse error in set-active-kit ability
* Added: output schema and metadata sync in docs for AI clients

= 2.2.1 =
* Fixed: Removed hard plugin header dependency on abilities-api to avoid slug-mismatch activation blocking


= 2.2.0 =
* Added: custom code snippet CRUD abilities (Elementor Pro)
* Added: form submissions list/get/delete abilities (Elementor Pro)
* Added: template sub type support for WooCommerce/theme builder templates

= 2.1.0 =
* Added: get-element and find-elements abilities for targeted updates
* Added: theme builder conditions get/update abilities
* Added: maintenance mode get/update abilities
* Added: experiments list/update abilities
* Added: replace-urls tool
* Changed: conditions normalization helper (conditions can be cleared)

= 2.0.6 =
* Added: success/message fields to list-templates and list-global-widgets outputs

= 2.0.5 =
* Fixed: Popups now use Elementor's native Documents Manager API for creation
* Fixed: Popups created via MCP now display correctly on frontend (proper document registration)
* Changed: create-template ability now uses \Elementor\Plugin::$instance->documents->create() instead of direct post meta

= 2.0.4 =
* Fixed: Popup conditions now stored as strings (PHP 8.4 compatibility)
* Fixed: Theme builder templates (popup, header, footer) no longer crash site on PHP 8.4
* Root cause: Elementor expects conditions as "include/general/site" strings, not arrays

= 2.0.3 =
* Fixed: delete-template now properly trashes instead of permanently deleting (wp_trash_post for custom post types)

= 2.0.2 =
* Fixed: Empty properties validation for get-kit-settings and list-global-widgets (added placeholder property)

= 2.0.1 =
* Reverted: stdClass approach broke array iteration

= 2.0.0 =
* Major release: Complete template management suite (19 abilities total)
* Added: create-template, update-template, delete-template for full CRUD
* Added: get-template for retrieving single template with all data
* Added: restore-template for recovering trashed templates
* Added: empty-trash for bulk permanent deletion
* Added: duplicate-template for copying templates
* Added: export-template / import-template for JSON interchange
* Added: list-global-widgets for global widget management
* Added: get-kit-settings / update-kit-settings for site-wide styles
* All template abilities support display conditions and popup triggers
* Fixed: Removed slow meta_query, using taxonomy queries instead
* Fixed: Removed direct database calls in clear-cache fallback

= 1.1.0 =
* Added: create-template ability for creating Elementor templates (page, section, popup, header, footer, etc.)
* Added: update-template ability for modifying existing templates
* Added: delete-template ability for removing templates (trash or permanent)
* All template abilities support display conditions and popup triggers

= 1.0.2 =
* Security: Added per-post capability checks for Elementor operations

= 1.0.1 =
* Added: update-page-settings ability

= 1.0.0 =
* Initial release
