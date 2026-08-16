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

require_once(__DIR__ . '/../../gpsmap_security.php');
function iconskml(): string {
	global $config;

	//register one KML style per icon in the icon folder
	$kmlDomain = read_config_option('base_url');
	$icon_dir  = $config['base_path'] . '/plugins/gpsmap/images/icons';
	$iconlist  = '';

	$dh = opendir($icon_dir);

	if ($dh === false) {
		cacti_log('WARNING: iconskml() could not open icon directory: ' . $icon_dir, false, 'GPSMAP');

		return $iconlist;
	}

	while (false !== ($file = readdir($dh))) {
		$tail = pathinfo($file, PATHINFO_EXTENSION);

		/* Compare case-insensitively but keep the on-disk spelling in the
		 * href; the icon folder may live on a case-sensitive filesystem. */
		if (!in_array(strtolower($tail), GPSMAP_ICON_EXTENSIONS, true)) {
			continue;
		}

		/* The Style id is dereferenced by <styleUrl> in kmlcreation.php, so
		 * both sides have to agree on the same normalised name. */
		$icon = gpsmap_icon_identifier($file);

		if ($icon === null) {
			continue;
		}

		$iconlist .= '<Style id="' . $icon . '">'
			. '<IconStyle id="my' . $icon . '">'
			. '<Icon>'
			. '<href>' . $kmlDomain . $config['url_path'] . 'plugins/gpsmap/images/icons/' . $icon . '.' . $tail . '</href>'
			. '<scale>1.0</scale>'
			. '</Icon>'
			. '</IconStyle>'
			. '</Style>' . PHP_EOL;
	}

	closedir($dh);

	return $iconlist;
}
