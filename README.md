# MCP Abilities - Elementor

Elementor page builder integration for WordPress via MCP.

[![GitHub release](https://img.shields.io/github/v/release/bjornfix/mcp-abilities-elementor)](https://github.com/bjornfix/mcp-abilities-elementor/releases)
[![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2-blue.svg)](https://www.gnu.org/licenses/gpl-2.0)

**Tested up to:** 6.9
**Stable tag:** 2.0.3
**License:** GPLv2 or later
**License URI:** https://www.gnu.org/licenses/gpl-2.0.html

## What It Does

This add-on plugin exposes Elementor functionality through MCP (Model Context Protocol). Your AI assistant can read Elementor page structures, update widgets, manage templates, and control site-wide settings directly.

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

## Abilities (19)

### Page/Post Data
| Ability | Description |
|---------|-------------|
| `elementor/get-data` | Get Elementor JSON structure for a page |
| `elementor/update-data` | Replace entire Elementor JSON for a page |
| `elementor/patch-data` | Find/replace text within Elementor JSON |
| `elementor/update-element` | Update a specific element by ID |
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

### Global Settings
| Ability | Description |
|---------|-------------|
| `elementor/list-global-widgets` | List all global widgets |
| `elementor/get-kit-settings` | Get site-wide Elementor settings |
| `elementor/update-kit-settings` | Update global colors, typography, etc. |
| `elementor/clear-cache` | Clear Elementor CSS cache |

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

### Clear CSS cache

```json
{
  "ability_name": "elementor/clear-cache",
  "parameters": { "all": true }
}
```

## Changelog

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
