=== MCP Abilities - Elementor ===
Contributors: devenia
Tags: mcp, elementor, page builder, ai, automation
Requires at least: 6.9
Tested up to: 6.9
Stable tag: 2.0.3
Requires PHP: 8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Elementor page builder integration for WordPress via MCP.

== Description ==

This add-on plugin exposes Elementor functionality through MCP (Model Context Protocol). Your AI assistant can read Elementor page structures, update widgets, and manage templates directly.

Part of the MCP Expose Abilities ecosystem.

== Installation ==

1. Install the required plugins (Abilities API, MCP Adapter, Elementor)
2. Download the latest release
3. Upload via WordPress Admin → Plugins → Add New → Upload Plugin
4. Activate the plugin

== Changelog ==

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
