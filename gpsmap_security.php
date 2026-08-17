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
	define('GPSMAP_ICON_EXTENSIONS', array('png', 'jpg', 'jpeg', 'gif'));
}

/* An icon's base name is emitted as a JavaScript assignment target
 * (gpsmap.Green = {...}) and as a KML Style id, so it has to be a bare
 * identifier.  Returns null for anything else; callers skip those files
 * rather than emitting a name that would break the surrounding script.
 * 'ap.v2.png' and 'my-icon.png' are the common cases. */
function gpsmap_icon_identifier(string $filename): ?string {
	$base = pathinfo($filename, PATHINFO_FILENAME);

	return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $base) ? $base : null;
}

function gpsmap_normalize_icon_name($value, $icon_array, $default = 'Green.png') {
	if (!is_string($value) || $value === '') {
		return $default;
	}

	if (!isset($icon_array[$value])) {
		return $default;
	}

	return $value;
}

//---------------------------------------------------------------
function getIcons() {
	$iconArray = array();
	global $config;

	/* Built from base_path so poller and CLI callers resolve it too; only web
	 * entry points chdir() to the Cacti root. */
	$dir = $config['base_path'] . '/plugins/gpsmap/images/icons';

	/* Suppressed rather than warned: this runs on the Map Templates page, and a
	 * missing icon folder should not print a PHP warning into the form. */
	$icons = @opendir($dir);

	if ($icons === false) {
		return $iconArray;
	}

	while (false !== ($icon = readdir($icons))) {
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
