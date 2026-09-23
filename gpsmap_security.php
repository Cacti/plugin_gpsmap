<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2009-2013 Andrew Aloia                                    |
 | Copyright (C) 2014 Wixiweb                                              |
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/* Image types accepted as map icons.  Declared here rather than in setup.php: Cacti only
 * includes setup.php on install, uninstall and config check, so the poller
 * would not have them. */
if (!defined('GPSMAP_ICON_EXTENSIONS')) {
	define('GPSMAP_ICON_EXTENSIONS', ['png', 'jpg', 'jpeg', 'gif']);
}

if (!defined('GPSMAP_ARTIFACT_STEM_PATTERN')) {
	define('GPSMAP_ARTIFACT_STEM_PATTERN', '/^(?:all|[0-9]+(?:\.[0-9]+){0,2}|v6-(?:16-[0-9a-f]{4}|32-[0-9a-f]{8}|48-[0-9a-f]{12}))$/D');
}

if (!defined('GPSMAP_ARTIFACT_SUFFIXES')) {
	define('GPSMAP_ARTIFACT_SUFFIXES', ['.xml', '.kml', '-top.html']);
}

if (!defined('GPSMAP_DNS_REFRESH_TIME_BUDGET')) {
	define('GPSMAP_DNS_REFRESH_TIME_BUDGET', 300.0);
}

/**
 * Validates that a value is safe to use as the version/region "stem"
 * portion of a generated map artifact filename (e.g. 'all', a dotted
 * version number, or an IPv6-derived hex stem), preventing path
 * traversal or injection via a crafted parameter. Called from
 * gpsmap_artifact_filename_is_valid() and
 * gpsmap_artifact_temporary_filename_is_valid() before trusting a
 * filename-derived value.
 *
 * @param string $value The candidate stem value to validate.
 *
 * @return bool True when $value matches the expected stem pattern.
 */
function gpsmap_artifact_parameter_is_valid(string $value): bool {
	return preg_match(GPSMAP_ARTIFACT_STEM_PATTERN, $value) === 1;
}

/**
 * Validates that a filename matches one of this plugin's known generated
 * map artifact patterns (a valid stem followed by a recognized suffix
 * such as '.xml', '.kml', or '-top.html'). Called wherever this plugin
 * serves or deletes a generated map artifact file by name, to guard
 * against path traversal/arbitrary file access.
 *
 * @param string $filename The candidate artifact filename to validate.
 *
 * @return bool True when $filename matches a known artifact pattern.
 */
function gpsmap_artifact_filename_is_valid(string $filename): bool {
	foreach (GPSMAP_ARTIFACT_SUFFIXES as $suffix) {
		if (str_ends_with($filename, $suffix)) {
			return gpsmap_artifact_parameter_is_valid(substr($filename, 0, -strlen($suffix)));
		}
	}

	return false;
}

/**
 * Validates that a filename matches the temporary-file naming convention
 * used while a map artifact is being generated (a valid artifact
 * filename suffixed with a numeric '.N.tmp' PID/uniqueness marker).
 * Called wherever this plugin cleans up or otherwise handles in-progress
 * temporary artifact files, to guard against path traversal/arbitrary
 * file access.
 *
 * @param string $filename The candidate temporary filename to validate.
 *
 * @return bool True when $filename matches the expected temporary-file
 *              pattern.
 */
function gpsmap_artifact_temporary_filename_is_valid(string $filename): bool {
	if (preg_match('/^(.+)\.[1-9][0-9]*\.tmp$/D', $filename, $matches) !== 1) {
		return false;
	}

	return gpsmap_artifact_filename_is_valid($matches[1]);
}

/**
 * Extracts the set of Map Template ids selected via 'chk_<id>' checkbox
 * fields in a submitted bulk-action form, validating that each suffix is
 * a positive integer before including it. Called from gpstemplates.php's
 * bulk-actions handler before applying an action to the selected
 * templates.
 *
 * @param array $request The submitted request array (e.g. $_POST) to
 *                        scan for 'chk_<id>' keys.
 *
 * @return list<int> The validated list of selected template ids.
 */
function gpsmap_template_ids_from_request(array $request): array {
	$ids = [];

	foreach (array_keys($request) as $key) {
		if (!is_string($key) || !str_starts_with($key, 'chk_')) {
			continue;
		}

		$suffix = substr($key, 4);

		if ($suffix === '' || !ctype_digit($suffix) || (int) $suffix < 1) {
			continue;
		}

		$ids[(int) $suffix] = true;
	}

	return array_keys($ids);
}

/**
 * Derives a safe JavaScript-identifier/KML-Style-id base name from an
 * icon's filename, since an icon's base name is emitted as a JavaScript
 * assignment target (gpsmap.Green = {...}) and as a KML Style id, so it
 * has to be a bare identifier. Returns null for anything else; callers
 * skip those files rather than emitting a name that would break the
 * surrounding script. 'ap.v2.png' and 'my-icon.png' are the common
 * cases. Called from getIcons() while building the list of usable map
 * icons, and from the KML/icon generation code that emits each icon's
 * JavaScript/Style definition.
 *
 * @param string $filename The icon's filename.
 *
 * @return string|null The safe bare identifier (filename without
 *                      extension), or null when the base name isn't a
 *                      valid identifier.
 */
function gpsmap_icon_identifier(string $filename): ?string {
	$base = pathinfo($filename, PATHINFO_FILENAME);

	return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $base) ? $base : null;
}

/**
 * Normalizes a submitted icon filename to a known-safe value, falling
 * back to a default icon when the value is missing, empty, or not
 * present in the list of currently available icons. Called wherever a
 * host/template's icon selection is saved or rendered, to guard against
 * an attacker-supplied icon name that doesn't correspond to a real file.
 *
 * @param mixed  $value      The submitted/stored icon filename to
 *                           normalize.
 * @param array  $icon_array The map of currently available icon
 *                           filenames (as returned by getIcons()).
 * @param string $default    The icon filename to fall back to; defaults
 *                           to 'Green.png'.
 *
 * @return string The normalized icon filename.
 */
function gpsmap_normalize_icon_name($value, $icon_array, $default = 'Green.png') {
	if (!is_string($value) || $value === '') {
		return $default;
	}

	if (!isset($icon_array[$value])) {
		return $default;
	}

	return $value;
}

/**
 * Scans this plugin's icons directory and returns the map icons that can
 * actually be rendered (recognized image extension, and a filename that
 * yields a valid JavaScript/KML identifier via gpsmap_icon_identifier()).
 * Called from gpstemplates.php and the host/template edit forms to
 * populate icon-selection dropdowns.
 *
 * @return array Map of icon filename to itself, for every usable icon
 *               file found.
 *
 * @global array $config Cacti global configuration array; used to
 *                        resolve the icons directory, built from
 *                        base_path so poller and CLI callers resolve it
 *                        too (only web entry points chdir() to the Cacti
 *                        root).
 */
function getIcons() {
	$iconArray = [];
	global $config;

	/* Built from base_path so poller and CLI callers resolve it too; only web
	 * entry points chdir() to the Cacti root. */
	$dir = $config['base_path'] . '/plugins/gpsmap/images/icons';

	/* Suppressed rather than warned: this runs on the Map Templates page, and a
	 * missing icon folder should not print a PHP warning into the form. */
	$icons = @opendir($dir);

	if ($icons === false) {
		cacti_log('WARNING: gpsmap could not open icon directory ' . $dir, false, 'GPSMAP');

		return $iconArray;
	}

	while (false !== ($icon = readdir($icons))) {
		if (!is_file($dir . '/' . $icon)) {
			continue;
		}

		/* Offer only names the map can actually render.  icons.php emits each
		 * base name as a JavaScript identifier, so a file this rule rejects
		 * would appear in the dropdown, save cleanly, and then silently fail
		 * to draw. */
		if (!in_array(strtolower(pathinfo($icon, PATHINFO_EXTENSION)), GPSMAP_ICON_EXTENSIONS, true)) {
			continue;
		}

		if (gpsmap_icon_identifier($icon) === null) {
			continue;
		}

		$iconArray[$icon] = $icon;
	}

	closedir($icons);

	return $iconArray;
}
