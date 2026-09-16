# GitHub Copilot Instructions

## Priority Guidelines

When generating code for this repository:

1. **Version Compatibility**: This is a Cacti plugin (`gpsmap`, version 2.1) targeting Cacti 1.2.15+
2. **Context Files**: Prioritize patterns and standards defined in this file (`.github/copilot-instructions.md`)
3. **Codebase Patterns**: When context files don't provide specific guidance, scan the codebase for established patterns
4. **Architectural Consistency**: Maintain plugin-based architecture extending Cacti core
5. **Code Quality**: Prioritize security, maintainability, and compatibility in all generated code

## Technology Stack

### Core Technologies
- **PHP**: Compatible with Cacti 1.2.x supported versions
- **Platform**: Cacti Plugin Architecture (Cacti 1.2.15+)
- **Database**: MySQL/MariaDB with InnoDB engine
- **Mapping**: Google Maps JavaScript API (`js/GPSMaps.js`, `js/infobubble.js`)

### Key Dependencies
- Cacti core framework (`api_plugin_*`, `db_*`, `read_config_option()`)
- Poller writes static XML/KML/HTML artifacts to `XML/` for the map UI to read
- Optional: `gettext` for internationalization

## Project Structure

```
gpsmap/                # Repository root (install to plugins/gpsmap/ in Cacti)
├── class/             # Supporting PHP classes
├── includes/
│   ├── setup/
│   │   ├── tabs.php        # top_header_tabs / top_graph_header_tabs callback
│   │   ├── settings.php    # config_arrays/config_settings/draw_navigation_text/api_device_save
│   │   └── database.php    # gpsmap_setup_database() / gpsmap_upgrade_database()
│   └── polling.php    # poller_bottom callback, writes map XML/KML/HTML
├── images/             # Marker icons (Green/Orange/Red)
├── js/                 # GPSMaps.js, infobubble.js
├── locales/            # Internationalization files
├── tests/              # Test suite
├── XML/                # Poller-generated map artifacts (all.xml, all.kml, all-top.html)
├── gpsmap.php          # Main map view page
├── gpsmap_security.php # Access/permission helpers
├── gpstemplates.php    # Map template administration
├── print.php           # Print-friendly map view
├── INFO                # Plugin metadata (name, version, compat)
├── README.md
└── setup.php           # Plugin install/uninstall/upgrade hooks
```

## Naming Conventions

### Function Names
- **Plugin lifecycle/hook-registration functions** MUST be prefixed `plugin_gpsmap_`: `plugin_gpsmap_install()`, `plugin_gpsmap_upgrade()`, `plugin_gpsmap_version()`.
- **All other functions** MUST be prefixed `gpsmap_`: `gpsmap_check_upgrade()`, `gpsmap_page_head()`, `gpsmap_version()`, `gpsmap_save_template()`.
- Match the existing prefix used by the function you are editing; do not introduce a third naming scheme.

### Database Tables / Settings
- Plugin-owned settings use the `gpsmap_` prefix (e.g. `gpsmap_apikey`, `gpsmap_latitude`). When migrating a misspelled setting key, preserve the existing value rather than overwriting it (see `gpsmap_check_upgrade()`).

### Variables and Constants
- Use snake_case for variables: `$apiKey` is a rare camelCase exception already in use; prefer snake_case for anything new.

## Code Style

### Indentation and Formatting
- **Tabs**: Use tabs (not spaces) for indentation throughout all PHP files.
- **Braces**: Opening brace on the same line for functions and control structures.
- **Spacing**: Space after control structure keywords (`if`, `foreach`, `while`).

### File Headers
ALL PHP files MUST include the standard GPL v2 license header used throughout this repository:

```php
<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/
```

## Security Standards

### SQL Query Security
**ALWAYS use prepared statements** for database operations - never concatenate user input into SQL:

```php
// CORRECT
db_execute_prepared('DELETE FROM settings WHERE name = ?', array('gpsmap_latutude'));

// WRONG - never do this
db_execute("DELETE FROM settings WHERE name = '$name'");
```

### Input Validation
Use Cacti's built-in input validation for anything derived from request data, and check `api_user_realm_auth()` before rendering map/template pages or actions.

`get_filter_request_var()` (and its `gfrv()` shorthand, where available) called with only the
`$name` argument (no regex/filter as the 2nd/3rd argument) already validates the value as numeric
and returns it as a **string** -- it does not return an int, and it halts execution if the request
value is not numeric. Because of this, do NOT cast its output to `(int)` when the result is only
used for string output (e.g. `print`/`echo`, string concatenation, embedding in HTML/JS); the cast
is redundant. Only cast when the value is genuinely used in an integer/numeric context (e.g.
arithmetic, strict `===` comparisons).

### Google Maps API Key
The API key (`gpsmap_apikey`) is emitted directly into a `<script src=...>` URL in `gpsmap_page_head()`. Always `rawurlencode()` it before interpolating, and never log or echo it elsewhere.

## Database Operations

### Table Creation / Upgrade
Schema is created in `includes/setup/database.php` (`gpsmap_setup_database()`) and upgraded in `gpsmap_upgrade_database()`, version-gated in `gpsmap_check_upgrade()` (`setup.php`) which compares `plugin_gpsmap_version` against the stored `read_config_option()` value.

### Upgrade Handling
```php
function gpsmap_check_upgrade() {
	global $config;

	$info    = plugin_gpsmap_version();
	$current = $info['version'];
	$old     = read_config_option('plugin_gpsmap_version', TRUE);

	if ($current != $old) {
		include_once($config['base_path'] . '/plugins/gpsmap/includes/setup/database.php');
		gpsmap_upgrade_database();
	}
}
```

## Internationalization

ALL user-facing strings MUST use the `__()`/`__esc()` function with the `'gpsmap'` text domain:

```php
print __esc('Configure Maps', 'gpsmap');
```

## Plugin Architecture

### Plugin Hooks
Register all plugin hooks in `plugin_gpsmap_install()` (`setup.php`):

```php
api_plugin_register_hook('gpsmap', 'top_header_tabs',       'gpsmap_show_tab',             'includes/setup/tabs.php');
api_plugin_register_hook('gpsmap', 'top_graph_header_tabs', 'gpsmap_show_tab',             'includes/setup/tabs.php');
api_plugin_register_hook('gpsmap', 'config_arrays',         'gpsmap_config_arrays',        'includes/setup/settings.php');
api_plugin_register_hook('gpsmap', 'config_settings',       'gpsmap_config_settings',      'includes/setup/settings.php');
api_plugin_register_hook('gpsmap', 'draw_navigation_text',  'gpsmap_draw_navigation_text', 'includes/setup/settings.php');
api_plugin_register_hook('gpsmap', 'api_device_save',       'gpsmap_api_device_save',      'includes/setup/settings.php');
api_plugin_register_hook('gpsmap', 'config_form',            'gpsmap_config_form',          'setup.php');
api_plugin_register_hook('gpsmap', 'poller_bottom',          'gpsmap_poller_bottom',        'includes/polling.php');
api_plugin_register_hook('gpsmap', 'page_head',              'gpsmap_page_head',            'setup.php');

api_plugin_register_realm('gpsmap', 'gpstemplates.php,gpstemplates_add.php', __('Configure Maps', 'gpsmap'), 1);
api_plugin_register_realm('gpsmap', 'gpsmap.php', __('View Maps', 'gpsmap'), 1);
```

### Poller Integration
`gpsmap_poller_bottom()` (`includes/polling.php`) generates `XML/all.xml`, `XML/all.kml`, and `XML/all-top.html` from device latitude/longitude and status; the map page reads these static files instead of querying the database directly.

### Uninstall Policy
`plugin_gpsmap_uninstall()` intentionally leaves tables/settings in place to avoid accidental data loss; do not add destructive `DROP TABLE` calls there without an explicit confirmation step.

## Best Practices

1. **Consistency Over Innovation** - match existing code patterns exactly.
2. **Security First** - prepared statements, realm checks, escaped/encoded API keys.
3. **Cacti Integration** - use Cacti's API functions rather than custom equivalents.
4. **Internationalization** - wrap all user-facing strings with `__()`/`__esc()` and the `gpsmap` domain.
5. **Non-destructive Upgrades** - never drop plugin data on uninstall/upgrade without explicit user confirmation.

## Common Pitfalls to Avoid

```php
// WRONG - concatenated SQL
db_execute("DELETE FROM settings WHERE name = '$name'");

// CORRECT
db_execute_prepared('DELETE FROM settings WHERE name = ?', array($name));

// WRONG - unescaped API key in a URL
print "<script src='...&key=$apiKey'></script>";

// CORRECT
print "<script src='...&key=" . rawurlencode($apiKey) . "'></script>";
```

## Version Control

### Changelog Maintenance
Document all changes in `CHANGELOG.md`.

### Commit Messages
Use descriptive commit messages; reference issue/PR numbers when applicable.

## References

- [Cacti main repo](https://github.com/Cacti/cacti/tree/1.2.x)
- [Cacti Documentation](https://www.github.com/Cacti/documentation)
- `README.md` for feature descriptions
- `CHANGELOG.md` for version history
