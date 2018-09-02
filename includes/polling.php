<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2009-2013 Andrew Aloia                                    |
 | Copyright (C) 2014 Wixiweb                                              |
 | Copyright (C) 2017-2018 The Cacti Group                                 |
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

	//Here we are getting the available hostnames (Numbers only) and
	//Processing them to create our XML index arrays. So that it can
	//pass an partial IP as a parameter to the region() function in
	//the processregion.php file. Start with high subnet and work down.
	include_once($config['base_path'] . '/plugins/gpsmap/includes/polling/functions.php');

	$result      =  db_fetch_assoc('SELECT hostname, latitude, longitude
		FROM host AS h
		INNER JOIN gpsmap_templates AS gt
		ON h.host_template_id = gt.templateID');

	$firstArray  = array();
	$secondArray = array();
	$thirdArray  = array();
	$totals      = 0;

	if (sizeof($result)) {
		foreach($result as $row){
			if ($row['latitude'] != '0.000' && $row['longitude'] != '0.000') {
				$totals++;

				$hostname = gethostbyname($row['hostname']);

				$indexes = explode('.', $hostname);

				if (isset($indexes[0])) {
					$first = $indexes[0];
				} else {
					$first = '';
				}

				if (isset($indexes[1])) {
					$second = $indexes[1];
				} else {
					$second = '';
				}

				if (isset($indexes[2])) {
					$third = $indexes[2];
				} else {
					$third = '';
				}

				if (isset($indexes[3])) {
					$fourth = $indexes[3];
				} else {
					$fourth = '';
				}

				if (!in_array($first . '.', $firstArray)){
					$firstArray[] = $first . '.';
				}

				if (!in_array($first . '.' . $second . '.', $secondArray)){
					$secondArray[] = $first . '.' . $second . '.';
				}

				if (!in_array($first . '.' . $second . '.' . $third . '.', $thirdArray)){
					$thirdArray[] = $first . '.' . $second . '.' . $third . '.';
				}
			}
		}
	}

	callRegion('all');

	if ($totals > 0) {
		foreach($firstArray as $ip) {
			callRegion($ip);
		}

		foreach($secondArray as $ip) {
			callRegion($ip);
		}

		foreach($thirdArray as $ip) {
			callRegion($ip);
		}
	}
}

