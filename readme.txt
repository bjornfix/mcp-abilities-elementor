=== MCP Abilities - Elementor ===
Contributors: devenia
Tags: mcp, elementor, page builder, ai, automation
Requires at least: 6.9
Tested up to: 6.9
Stable tag: 2.2.13
Requires PHP: 8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Elementor page builder integration for WordPress via MCP.

== Description ==

This add-on plugin exposes Elementor functionality through MCP (Model Context Protocol). Your AI assistant can read Elementor page structures, locate and update elements, manage templates and conditions, and run Elementor tools like maintenance mode, experiments, and URL replacement.

Part of the [MCP Expose Abilities](https://devenia.com/plugins/mcp-expose-abilities/) ecosystem.

= Requirements =

* [MCP Expose Abilities](https://github.com/bjornfix/mcp-expose-abilities) (core plugin)
* [Elementor](https://wordpress.org/plugins/elementor/) plugin
* Elementor Pro is optional for the Pro-specific abilities

= Abilities Included =

**Page/Post data** - Get, patch, update, delete, and clone Elementor JSON and page settings.

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

1. Install the required plugins (Abilities API, MCP Adapter, MCP Expose Abilities, Elementor)
2. Download the latest release
3. Upload via WordPress Admin → Plugins → Add New → Upload Plugin
4. Activate the plugin
5. The abilities are now available via the MCP endpoint

= Links =

* [Plugin Page](https://devenia.com/plugins/mcp-expose-abilities/)
* [Core Plugin (MCP Expose Abilities)](https://github.com/bjornfix/mcp-expose-abilities)
* [All Add-on Plugins](https://devenia.com/plugins/mcp-expose-abilities/#add-ons)

== Changelog ==

= 2.2.13 =
* Added: `elementor/normalize-campaign-detail-page` to apply the repeated campaign-detail lane/gutter/rhythm recipe in one call
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
