# MCP Abilities - Elementor

**Elementor page builder integration for WordPress via MCP.** Get, update, and patch Elementor page data. Manage templates and cache.

[![GitHub release](https://img.shields.io/github/v/release/bjornfix/mcp-abilities-elementor)](https://github.com/bjornfix/mcp-abilities-elementor/releases)
[![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2-blue.svg)](https://www.gnu.org/licenses/gpl-2.0)

## What It Does

This add-on plugin exposes Elementor functionality through MCP (Model Context Protocol). Your AI assistant can read Elementor page structures, update widgets, and manage templates directly.

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
3. Upload via WordPress Admin → Plugins → Add New → Upload Plugin
4. Activate the plugin

## Abilities (6)

| Ability | Description |
|---------|-------------|
| `elementor/get-data` | Get Elementor JSON structure for a page |
| `elementor/update-data` | Replace entire Elementor JSON for a page |
| `elementor/patch-data` | Find/replace text within Elementor JSON |
| `elementor/update-element` | Update a specific element by ID |
| `elementor/list-templates` | List all saved Elementor templates |
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

### Update specific element

```json
{
  "ability_name": "elementor/update-element",
  "parameters": {
    "id": 123,
    "element_id": "abc12345",
    "settings": {
      "title": "New Heading Text"
    }
  }
}
```

### Find/replace in page

```json
{
  "ability_name": "elementor/patch-data",
  "parameters": {
    "id": 123,
    "find": "Old Company Name",
    "replace": "New Company Name"
  }
}
```

### Clear CSS cache

```json
{
  "ability_name": "elementor/clear-cache",
  "parameters": {}
}
```

## License

GPL-2.0+

## Author

[Devenia](https://devenia.com) - We've been doing SEO and web development since 1993.

## Links

- [Plugin Page](https://devenia.com/plugins/mcp-expose-abilities/)
- [Core Plugin (MCP Expose Abilities)](https://github.com/bjornfix/mcp-expose-abilities)
- [All Add-on Plugins](https://devenia.com/plugins/mcp-expose-abilities/#add-ons)
