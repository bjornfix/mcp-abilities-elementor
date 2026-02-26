# MCP Abilities - Elementor

Elementor page builder integration for WordPress via MCP.

[![GitHub release](https://img.shields.io/github/v/release/bjornfix/mcp-abilities-elementor)](https://github.com/bjornfix/mcp-abilities-elementor/releases)
[![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2-blue.svg)](https://www.gnu.org/licenses/gpl-2.0)

**Tested up to:** 6.9
**Stable tag:** 2.2.3
**License:** GPLv2 or later
**License URI:** https://www.gnu.org/licenses/gpl-2.0.html

## What It Does

This add-on plugin exposes Elementor functionality through MCP (Model Context Protocol). Your AI assistant can read Elementor page structures, locate and update elements, manage templates and conditions, control site-wide settings, and run Elementor tools like maintenance mode, experiments, and URL replacement.

**Part of the [MCP Expose Abilities](https://devenia.com/plugins/mcp-expose-abilities/) ecosystem.**

## Requirements

- WordPress 6.9+
- PHP 8.0+
- [Abilities API](https://github.com/WordPress/abilities-api) plugin
- [MCP Adapter](https://github.com/WordPress/mcp-adapter) plugin
- [Elementor](https://wordpress.org/plugins/elementor/) (Free or Pro)

## Installation

1. Install the required plugins (Abilities API, MCP Adapter, Elementor)
2. Download the latest release from [Releases](https://github.com/bjornfix/mcp-abilities-elementor/releases)
3. Upload via WordPress Admin > Plugins > Add New > Upload Plugin
4. Activate the plugin

## Abilities (38)

### Page/Post Data
| Ability | Description |
|---------|-------------|
| `elementor/get-data` | Get Elementor JSON structure for a page |
| `elementor/get-element` | Get a specific element by ID |
| `elementor/find-elements` | Find elements by type, widget, or text |
| `elementor/update-element` | Update a specific element by ID |
| `elementor/update-data` | Replace entire Elementor JSON for a page |
| `elementor/patch-data` | Find/replace text within Elementor JSON |
| `elementor/update-page-settings` | Update Elementor page settings |

### Template Management
| Ability | Description |
|---------|-------------|
| `elementor/list-templates` | List all saved Elementor templates |
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
| `elementor/clear-cache` | Clear Elementor cache (post or site scope) |
| `elementor/replace-urls` | Replace URLs inside Elementor data |
| `elementor/get-maintenance-mode` | Get maintenance mode settings |
| `elementor/update-maintenance-mode` | Enable or update maintenance mode |
| `elementor/list-experiments` | List Elementor experiments |
| `elementor/update-experiment` | Update experiment state |

## Feature Coverage & Notes

- Theme Builder templates (header/footer/single/archive/popup) and conditions require Elementor Pro.
- Custom Code snippets and Form Submissions require Elementor Pro.
- WooCommerce templates are usually Theme Builder templates with a `template_sub_type` like `product` or `product-archive`.
- Maintenance mode uses Elementor templates; you must provide a template ID to enable it.
- Experiments map to Elementor's experiments manager; reset to default clears the saved option.
- Prefer `get-element`/`find-elements` + `update-element` for targeted edits, and use `update-data` only when replacing full JSON.

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

### 2.2.3
- Added: `cache_scope` (`none` / `post` / `site`) to `elementor/update-data`, `elementor/patch-data`, and `elementor/update-element`
- Improved: stronger cache invalidation after Elementor writes (post cache cleanup, asset meta cleanup, optional site-wide Elementor cache clear)
- Improved: `elementor/clear-cache` now returns cache details and supports `scope` alias (`post` / `site`)

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

## License

GPL-2.0+

## Author

[Devenia](https://devenia.com) - We've been doing SEO and web development since 1993.

## Links

- [Plugin Page](https://devenia.com/plugins/mcp-expose-abilities/)
- [Core Plugin (MCP Expose Abilities)](https://github.com/bjornfix/mcp-expose-abilities)
- [All Add-on Plugins](https://devenia.com/plugins/mcp-expose-abilities/#add-ons)
