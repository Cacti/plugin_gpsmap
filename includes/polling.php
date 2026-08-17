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

function gpsmap_poller_bottom() {
	global $config;

	include_once($config['base_path'] . '/plugins/gpsmap/includes/polling/functions.php');
	include_once($config['base_path'] . '/plugins/gpsmap/includes/polling/pollinginitial.php');
	include_once($config['base_path'] . '/plugins/gpsmap/includes/polling/processregion.php');

	$start = microtime(true);

	/* Load once.  Every subnet below is rendered from this same set, so the
	 * device query and the DNS lookups happen a single time per poller cycle
	 * rather than once per subnet. */
	$hostArrays = gpsmap_load_devices(gpsmap_enable_all());
	$mapped     = cacti_sizeof($hostArrays[0]) + cacti_sizeof($hostArrays[1]);

	/* Withhold publication only when the Device query itself failed.  An estate
	 * with no mapped Devices is a real answer and has to be published, or a new
	 * install never gets an all.xml at all and the map page fetches a 404. */
	if (!empty($GLOBALS['gpsmap_load_failed'])) {
		cacti_log('WARNING: gpsmap could not read the Device list this cycle; the existing map has been left in place', false, 'GPSMAP');

		return;
	}

	if ($mapped === 0) {
		cacti_log('NOTICE: gpsmap has no Devices to map.  Check that a Device Template is listed under Templates -> Map and that Devices have coordinates.', false, 'GPSMAP');
	}

	$prefixes = gpsmap_subnet_prefixes($hostArrays);

	gpsmap_render_region($hostArrays, 'all');

	foreach ($prefixes as $prefix) {
		gpsmap_render_region($hostArrays, $prefix);
	}

	cacti_log(sprintf(
		'GPSMAP STATS: Mapped:%d Towers:%d Subnets:%d Time:%0.2f',
		$mapped,
		cacti_sizeof($hostArrays[0]),
		cacti_sizeof($prefixes),
		microtime(true) - $start
	), false, 'GPSMAP');
}
