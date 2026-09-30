<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2009-2013 Andrew Aloia                                    |
 | Copyright (C) 2014 Wixiweb                                              |
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

/**
 * Return the CSP nonce attribute for inline <script> tags, safely across
 * Cacti versions. Newer Cacti releases enforce a Content-Security-Policy that
 * requires a per-request nonce on parser-inserted scripts; older releases lack
 * the CactiSecureHeaders class, so this returns an empty string there.
 *
 * @return string The `nonce="..."` attribute when supported, otherwise ''.
 */
function plugin_gpsmap_csp_nonce(): string {
	if (class_exists('CactiSecureHeaders')) {
		return CactiSecureHeaders::getNonceAttribute();
	}

	return '';
}

/**
 * Installs the GPS Map plugin: registers its Cacti hooks (top_header_tabs,
 * top_graph_header_tabs, config_arrays, config_settings,
 * draw_navigation_text, api_device_save, config_form, poller_bottom,
 * page_head), registers its two realms (Configure Maps, View Maps), and
 * creates/verifies its database schema. Invoked by Cacti's plugin
 * architecture when an administrator installs this plugin from Console >
 * Plugin Management.
 *
 * @return void
 *
 * @global array $config Cacti global configuration array; used to load
 *                        includes/setup/database.php.
 */
function plugin_gpsmap_install() {
	global $config;

	api_plugin_register_hook('gpsmap', 'top_header_tabs',       'gpsmap_show_tab',             'includes/setup/tabs.php');
	api_plugin_register_hook('gpsmap', 'top_graph_header_tabs', 'gpsmap_show_tab',             'includes/setup/tabs.php');
	api_plugin_register_hook('gpsmap', 'config_arrays',         'gpsmap_config_arrays',        'includes/setup/settings.php');
	api_plugin_register_hook('gpsmap', 'config_settings',       'gpsmap_config_settings',      'includes/setup/settings.php');
	api_plugin_register_hook('gpsmap', 'draw_navigation_text',  'gpsmap_draw_navigation_text', 'includes/setup/settings.php');
	api_plugin_register_hook('gpsmap', 'api_device_save',       'gpsmap_api_device_save',      'includes/setup/settings.php');
	api_plugin_register_hook('gpsmap', 'config_form',           'gpsmap_config_form',          'setup.php');
	api_plugin_register_hook('gpsmap', 'poller_bottom',         'gpsmap_poller_bottom',        'includes/polling.php');
	api_plugin_register_hook('gpsmap', 'page_head',             'gpsmap_page_head',            'setup.php');

	api_plugin_register_realm('gpsmap', 'gpstemplates.php,gpstemplates_add.php',__('Configure Maps', 'gpsmap'), 1);
	api_plugin_register_realm('gpsmap', 'gpsmap.php', __('View Maps', 'gpsmap'), 1);

	require_once($config['base_path'] . '/plugins/gpsmap/includes/setup/database.php');

	if (gpsmap_setup_database()) {
		$info = plugin_gpsmap_version();

		if (empty($info['version'])) {
			cacti_log('ERROR: gpsmap plugin INFO file is missing required fields, skipping version registration', false, 'GPSMAP');

			return;
		}

		$version = $info['version'];

		if (db_execute_prepared('UPDATE plugin_config SET version = ? WHERE directory = ?', [$version, 'gpsmap'])) {
			set_config_option('plugin_gpsmap_version', $version);
		}
	} else {
		cacti_log('ERROR: gpsmap installation could not create or verify the required database schema; correct the database error and retry from Plugin Management', false, 'GPSMAP');
	}
}

/**
 * Uninstalls the GPS Map plugin; currently a no-op placeholder. Invoked
 * by Cacti's plugin architecture when an administrator uninstalls this
 * plugin from Console > Plugin Management.
 *
 * Tables and settings created on install are intentionally left in place
 * on uninstall to prevent data loss on accidental removal. A future
 * release should call gpsmap_remove_database() here with explicit
 * confirmation from the administrator.
 *
 * @return void
 */
function plugin_gpsmap_uninstall() {
}

/**
 * Verifies the plugin's configuration by triggering its upgrade check.
 * Invoked by Cacti's plugin architecture on relevant page loads.
 *
 * @return bool Always returns true.
 */
function plugin_gpsmap_check_config() {
	gpsmap_check_upgrade();

	return true;
}

/**
 * Performs any schema/data migrations needed when upgrading to a newer
 * version of this plugin, by force-running the upgrade check regardless
 * of the current page. Invoked by Cacti's plugin architecture when an
 * installed plugin's version increases.
 *
 * @return bool Always returns false.
 */
function plugin_gpsmap_upgrade() {
	gpsmap_check_upgrade(true);

	return false;
}

/**
 * Reads this plugin's INFO file and returns its [info] section, via
 * gpsmap_version(). Used by Cacti's plugin architecture via the
 * api_plugin_version hook.
 *
 * @return array The parsed [info] section of the plugin's INFO file.
 */
function plugin_gpsmap_version() {
	return gpsmap_version();
}

/**
 * Detects whether the installed plugin version differs from this
 * plugin's INFO file version and, if so, runs the database schema
 * upgrade; also self-heals a legacy misspelled 'gpsmap_latutude' setting
 * key by migrating it to 'gpsmap_latitude'. Only runs on gpsmap.php/
 * gpstemplates.php/plugins.php/poller.php unless $force is set. Called
 * from plugin_gpsmap_check_config()/plugin_gpsmap_upgrade() and on
 * relevant page loads.
 *
 * Migrate the misspelled 'gpsmap_latutude' key to 'gpsmap_latitude'. Runs
 * on every version transition so it self-heals on first upgrade.
 * read_config_option() returns '' for missing keys in most Cacti
 * versions, but some older versions return false or null. The triple-
 * check guards against all known return values so the DELETE only fires
 * when the old key actually exists with a non-empty value.
 *
 * @param bool $force Whether to run the upgrade check regardless of the
 *                    current page; defaults to false.
 *
 * @return void
 *
 * @global array $config Cacti global configuration array; used to load
 *                        includes/setup/database.php.
 */
function gpsmap_check_upgrade(bool $force = false) {
	global $config;

	$files = ['gpsmap.php', 'gpstemplates.php', 'plugins.php', 'poller.php'];

	if (!$force && !in_array(get_current_page(), $files, true)) {
		return;
	}

	$info = plugin_gpsmap_version();

	if (empty($info['version'])) {
		cacti_log('ERROR: gpsmap plugin INFO file is missing required fields, skipping upgrade check', false, 'GPSMAP');

		return;
	}

	$current = $info['version'];
	$old     = read_config_option('plugin_gpsmap_version', true);

	if ($current != $old) {
		require_once($config['base_path'] . '/plugins/gpsmap/includes/setup/database.php');
		gpsmap_upgrade_database((string) $old, $force);
		plugin_gpsmap_prune_files();
	}

	/* Migrate the misspelled 'gpsmap_latutude' key to 'gpsmap_latitude'.
	 * Runs on every version transition so it self-heals on first upgrade.
	 * read_config_option() returns '' for missing keys in most Cacti versions,
	 * but some older versions return false or null. The triple-check guards
	 * against all known return values so the DELETE only fires when the old
	 * key actually exists with a non-empty value. */
	$old_lat = read_config_option('gpsmap_latutude');

	if ($old_lat !== false && $old_lat !== null && $old_lat !== '') {
		$current_lat = read_config_option('gpsmap_latitude');

		if ($current_lat === false || $current_lat === null || $current_lat === '') {
			set_config_option('gpsmap_latitude', $old_lat);
		}
		db_execute_prepared('DELETE FROM settings WHERE name = ?', ['gpsmap_latutude']);
	}
}

/**
 * Hook implementation for Cacti's 'page_head' filter. Includes the
 * Google Maps JavaScript API (with the configured API key, when set) and
 * this plugin's GPSMaps.js/infobubble.js scripts on every page. Called by
 * Cacti core via api_plugin_hook('page_head', ...) while rendering the
 * page <head> section.
 *
 * @return void Outputs HTML directly.
 *
 * @global array $config Cacti global configuration array; used to build
 *                        this plugin's script URLs.
 */
function gpsmap_page_head() {
	global $config;

	$apiKey = read_config_option('gpsmap_apikey');

	print "<script type='text/javascript' " . plugin_gpsmap_csp_nonce() . " src='https://maps.googleapis.com/maps/api/js?" . (empty($apiKey) === false ? 'key=' . rawurlencode($apiKey) . '&amp;' : '') . "libraries=geometry'></script>" . PHP_EOL;
	print get_md5_include_js('plugins/gpsmap/js/GPSMaps.js');
	print get_md5_include_js('plugins/gpsmap/js/infobubble.js');
}

/**
 * Reads this plugin's INFO file and returns its [info] section. Called
 * from plugin_gpsmap_version()/gpsmap_check_upgrade() to detect/report
 * the plugin's version.
 *
 * @return array The parsed [info] section of the plugin's INFO file (keys
 *               such as name, version, author).
 *
 * @global array $config Cacti global configuration array; used to locate
 *                        the plugin's base path.
 */
function gpsmap_version() {
	global $config;
	$info = parse_ini_file($config['base_path'] . '/plugins/gpsmap/INFO', true);

	return isset($info['info']) && is_array($info['info']) ? $info['info'] : [];
}

/**
 * Hook implementation for Cacti's 'config_form' filter. Adds this
 * plugin's Map Settings section (coverage-overlay inclusion, latitude/
 * longitude, and, for Access Point host templates, directional coverage
 * start/stop degrees and radius) to the Device edit form, immediately
 * after the 'disabled' field. Called by Cacti core via
 * api_plugin_hook('config_form', ...) while building the Device edit
 * form.
 *
 * Defines latitude and longitude for devices.
 *
 * @return void
 *
 * @global array  $fields_host_edit The Device edit form's field
 *                                  definitions, rebuilt here with this
 *                                  plugin's fields inserted after
 *                                  'disabled'.
 * @global string $url_path        Cacti's configured URL path; used to
 *                                  detect whether the current page is
 *                                  host.php before querying Access Point
 *                                  fields.
 */
function gpsmap_config_form() {
	global $fields_host_edit, $url_path;

	$fields_host_edit2 = $fields_host_edit;
	$fields_host_edit3 = [];

	foreach ($fields_host_edit2 as $f => $a) {
		$fields_host_edit3[$f] = $a;

		if ($f == 'disabled') {
			$fields_host_edit3['gpsSpacer'] = [
				'friendly_name' => __('Map Settings', 'gpsmap'),
				'method'        => 'spacer',
			];

			$fields_host_edit3['GPScoverage'] = [
				'friendly_name' => __('Overlay Inclusion', 'gpsmap'),
				'description'   => __('Disable to plot host only, not included in coverage overlay.', 'gpsmap'),
				'method'        => 'checkbox',
				'value'         => '|arg1:GPScoverage|',
				'default'       => 'on',
			];

			$fields_host_edit3['latitude'] = [
				'friendly_name' => __('Latitude', 'gpsmap'),
				'description'   => __('The devices latitude coordinates', 'gpsmap'),
				'method'        => 'textbox',
				'max_length'    => 13,
				'value'         => '|arg1:latitude|',
				'default'       => '',
			];

			$fields_host_edit3['longitude'] = [
				'friendly_name' => __('Longitude', 'gpsmap'),
				'description'   => __('The devices longitude coordinates', 'gpsmap'),
				'method'        => 'textbox',
				'max_length'    => 13,
				'value'         => '|arg1:longitude|',
				'default'       => '',
			];

			if (isset_request_var('id') && get_current_page() == $url_path . 'host.php') {
				$did = get_filter_request_var('id');

				$row = db_fetch_row_prepared('SELECT AP
					FROM `host`
					RIGHT JOIN gpsmap_templates
					ON host.host_template_id = gpsmap_templates.templateID
					WHERE id = ?',
					[$did]);

				if (is_array($row) && cacti_sizeof($row) && $row['AP'] == 1) {
					$fields_host_edit3['start'] = [
						'friendly_name' => __('Starting Degree', 'gpsmap'),
						'description'   => __('Starting degree for directional area between 0-360', 'gpsmap'),
						'method'        => 'textbox',
						'max_length'    => 4,
						'value'         => '|arg1:start|',
						'default'       => '0',
					];

					$fields_host_edit3['stop'] = [
						'friendly_name' => __('Stopping Degree', 'gpsmap'),
						'description'   => __('Stopping degree for directional area, must be greater than the Starting Degree', 'gpsmap'),
						'method'        => 'textbox',
						'max_length'    => 4,
						'value'         => '|arg1:stop|',
						'default'       => '360',
					];

					$fields_host_edit3['rdistance'] = [
						'friendly_name' => __('Specify Radius', 'gpsmap'),
						'description'   => __('Manually specify radius for Access Point. Set to 0 to determine radius based on grouped devices', 'gpsmap'),
						'method'        => 'textbox',
						'max_length'    => 10,
						'value'         => '|arg1:rdistance|',
						'default'       => '0',
					];
				}
			}

			$fields_host_edit3['groupnum'] = [
				'friendly_name' => __('Group ID', 'gpsmap'),
				'description'   => __('Groups define what devices are included in the coverage overlay. Will be checked against AP device group number. 0 to disable', 'gpsmap'),
				'method'        => 'textbox',
				'max_length'    => 3,
				'value'         => '|arg1:groupnum|',
				'default'       => '0',
			];
		}
	}

	$fields_host_edit = $fields_host_edit3;
}

/**
 * Removes files and directories that a previous version of this plugin
 * shipped but that have since moved or been deleted, using the tombstone
 * and whitelist lists in manifest.json. Whitelisted (user-data) paths and
 * any VCS metadata (.git*) are never touched; the dev-only tests/ tree is
 * removed. Any path that resolves outside the plugin directory (a tampered
 * manifest.json) is refused, and any file/directory that cannot be removed
 * (e.g. read-only) is reported to the Cacti log. Any top-level entry that is
 * neither expected nor a tombstone nor whitelisted is logged to the Cacti
 * log and left in place. Called on a plugin version change.
 *
 * @return void
 *
 * @global array $config Cacti global configuration array; used to resolve
 *                       the plugin directory.
 */
function plugin_gpsmap_prune_files(): void {
	global $config;

	$plugin_dir    = $config['base_path'] . '/plugins/gpsmap';
	$manifest_path = $plugin_dir . '/manifest.json';

	if (!is_readable($manifest_path)) {
		return;
	}

	$manifest = json_decode((string) file_get_contents($manifest_path), true);

	if (!is_array($manifest)) {
		cacti_log('WARNING: gpsmap manifest.json could not be parsed; skipping file prune', false, 'GPSMAP');

		return;
	}

	$tombstones = isset($manifest['tombstones']) && is_array($manifest['tombstones']) ? $manifest['tombstones'] : [];
	$expected   = isset($manifest['expected'])   && is_array($manifest['expected'])   ? $manifest['expected']   : [];
	$whitelist  = isset($manifest['whitelist'])  && is_array($manifest['whitelist'])  ? $manifest['whitelist']  : [];

	$protected = function (string $rel) use ($whitelist): bool {
		if (strncmp($rel, '.git', 4) === 0 || strncmp($rel, '.md', 3) === 0) {
			return true;
		}

		foreach ($whitelist as $entry) {
			$entry = trim((string) $entry, '/');

			if ($entry !== '' && ($rel === $entry || strncmp($rel, $entry . '/', strlen($entry) + 1) === 0)) {
				return true;
			}
		}

		return false;
	};

	// Security: resolve the plugin directory so a tampered manifest.json
	// cannot steer the prune outside of it.
	$plugin_real = realpath($plugin_dir);

	// Remove tombstoned (moved/deleted) paths plus the dev-only tests/
	// tree and the phpunit.xml test configuration.
	$remove   = $tombstones;
	$remove[] = 'tests/';
	$remove[] = 'phpunit.xml';

	foreach ($remove as $rel) {
		$rel = trim((string) $rel, '/');

		if ($rel === '' || $protected($rel)) {
			continue;
		}

		$path = $plugin_dir . '/' . $rel;

		if (!is_link($path) && !file_exists($path)) {
			continue;
		}

		// Refuse any path that, after resolving symlinks and ../ segments,
		// escapes the plugin directory (protects user data from a tampered
		// manifest.json).
		$anchor = is_link($path) ? dirname($path) : $path;
		$real   = realpath($anchor);

		if ($real === false || ($real !== $plugin_real && strncmp($real, $plugin_real . DIRECTORY_SEPARATOR, strlen((string) $plugin_real) + 1) !== 0)) {
			cacti_log(sprintf('WARNING: gpsmap prune refused to remove %s: path resolves outside the plugin directory (tampered manifest.json?)', $rel), false, 'GPSMAP');

			continue;
		}

		if (is_dir($path) && !is_link($path)) {
			$removed = plugin_gpsmap_rmtree($path);
		} else {
			$removed = @unlink($path);
		}

		if (!$removed) {
			cacti_log(sprintf('WARNING: gpsmap upgrade could not remove %s (check file/directory permissions)', $rel), false, 'GPSMAP');
		}
	}

	// Surface any top-level entry the manifest does not account for.
	$known = [];

	foreach (array_merge($expected, $tombstones) as $entry) {
		$top = explode('/', trim((string) $entry, '/'))[0];

		if ($top !== '') {
			$known[$top] = true;
		}
	}

	$entries = scandir($plugin_dir);

	foreach (($entries !== false ? $entries : []) as $entry) {
		if ($entry === '.' || $entry === '..' || $entry === 'tests' || $entry === 'phpunit.xml' || $protected($entry) || isset($known[$entry])) {
			continue;
		}

		cacti_log(sprintf('WARNING: gpsmap upgrade found a file/directory not described in manifest.json: %s (left in place)', $entry), false, 'GPSMAP');
	}
}

/**
 * Recursively deletes a directory and its contents. Symlinks are removed
 * without being followed. Helper for plugin_gpsmap_prune_files().
 *
 * @param string $dir Absolute path to the directory to remove.
 *
 * @return bool True if the directory and everything under it was removed;
 *              false if any entry could not be deleted.
 */
function plugin_gpsmap_rmtree(string $dir): bool {
	$entries = scandir($dir);
	$ok      = true;

	foreach (($entries !== false ? $entries : []) as $entry) {
		if ($entry === '.' || $entry === '..') {
			continue;
		}

		$path = $dir . '/' . $entry;

		if (is_dir($path) && !is_link($path)) {
			if (!plugin_gpsmap_rmtree($path)) {
				$ok = false;
			}
		} elseif (!@unlink($path)) {
			$ok = false;
		}
	}

	if (!@rmdir($dir)) {
		$ok = false;
	}

	return $ok;
}
