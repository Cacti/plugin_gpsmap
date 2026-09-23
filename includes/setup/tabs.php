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
 * Hook implementation for Cacti's 'top_header_tabs'/'top_graph_header_tabs'
 * filters (registered via setup.php). Prints a clickable tab icon
 * linking to gpsmap.php, using a different icon when gpsmap.php is the
 * currently displayed page. Called by Cacti core via
 * api_plugin_hook('top_header_tabs'/'top_graph_header_tabs', ...) while
 * rendering the page header tabs, for users with access to gpsmap.php.
 *
 * @return void Outputs HTML directly.
 *
 * @global array $config Cacti global configuration array; used to build
 *                        the tab's image/link URLs.
 */
function gpsmap_show_tab () {
	global $config;

	if (api_user_realm_auth('gpsmap.php')) {
		$cp = false;

		if (basename(get_current_page()) == 'gpsmap.php'){
			$cp = true;
		}

		print '<a href="' . $config['url_path'] . 'plugins/gpsmap/gpsmap.php"><img src="' . $config['url_path'] . 'plugins/gpsmap/images/tab_gpsmap' . ($cp ? '_down': '') . '.gif" alt="' . __('Maps', 'gpsmap') . '"></a>';
	}
}

