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
//get all icons in the icon folder and create an icon list.
//This a process to dynamically create the javascript for each icon.
//This is separate from the function in customicons.php
/* Use relative $config['url_path'] for icon URLs. This avoids injecting
 * an attacker-controlled origin if base_url were set to an external domain. */
global $config;
$url_path = $config['url_path'];

$icons = opendir('plugins/gpsmap/images/icons');

if ($icons !== false) {
	while (false !== ($icon = readdir($icons))) {
		$tail = pathinfo($icon, PATHINFO_EXTENSION);

		/* Compare case-insensitively but keep the on-disk spelling in the
		 * URL; the icon folder may live on a case-sensitive filesystem. */
		if (!in_array(strtolower($tail), GPSMAP_ICON_EXTENSIONS, true)) {
			continue;
		}

		/* $icon is emitted as an assignment target, so json_encode() on the
		 * url line does not protect it.  Skip anything that is not a bare
		 * identifier instead of emitting broken JavaScript. */
		$base = gpsmap_icon_identifier($icon);

		if ($base === null) {
			continue;
		}

		$icon_url = $url_path . 'plugins/gpsmap/images/icons/' . $icon;
		$icon     = $base;

		echo 'gpsmap.', $icon, ' = {', PHP_EOL,
			'url : ', json_encode($icon_url, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), ',', PHP_EOL,
			'size : new google.maps.Size(12, 20),', PHP_EOL,
			'anchor : new google.maps.Point(6, 20)};', PHP_EOL, PHP_EOL
		;
	}

	closedir($icons);
}
