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
/* Included from kmlCreate(); $hostArrays, $preemptive and $config come from
 * that scope. */
include_once($config['base_path'] . '/plugins/gpsmap/includes/polling/iconskml.php');

$kmldoc  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
$kmldoc .= '<kml xmlns="http://www.opengis.net/kml/2.2">' . "\n";
$kmldoc .= '<Document>';
$kmldoc .= '<name>Map Points</name>' . "\n";
$kmldoc .= iconskml();

// fallback style for any placemark whose icon is not in the icon folder
$kmldoc .= '<Style id="pushpin">'
	. '<IconStyle id="mystyle">'
	. '<Icon>'
	. '<href>http://maps.google.com/mapfiles/kml/pushpin/ylw-pushpin.png</href>'
	. '<scale>1.0</scale>'
	. '</Icon>'
	. '</IconStyle>'
	. '</Style>' . PHP_EOL;

foreach ($hostArrays as $hostArray) {
	foreach ($hostArray as $host) {
		if ($host->showMap !== 1) {
			continue;
		}

		$image = match ($host->status) {
			'down',
			'alert'      => $host->downimage,
			'recovering' => $host->recoverimage,
			default      => $host->upimage,
		};

		/* Must resolve to the same name iconskml() registered as a Style id;
		 * anything it skipped falls back to the built-in pushpin style. */
		$style = gpsmap_icon_identifier($image) ?? 'pushpin';

		/* Icons named GoogleXxx map onto the built-in Google styles, which are
		 * registered lower-cased without the prefix. */
		if (str_starts_with($style, 'Google')) {
			$style = strtolower(substr($style, 6));
		}

		$kmldoc .= '<Placemark>'
			. '<name>' . parseToXML($host->description) . '</name>'
			. '<styleUrl>' . parseToXML($style) . '</styleUrl> '
			. '<description>' . parseToXML($host->description) . PHP_EOL
				. 'Availability: ' . $host->avail . PHP_EOL
				. 'Address: ' . parseToXML($host->hostname) . '</description>'
			. '<Point>'
			. '<coordinates>' . parseToXML($host->long) . ',' . parseToXML($host->lat) . '</coordinates>'
			. '</Point>'
			. '</Placemark>'
			. PHP_EOL;
	}
}

$kmldoc .= '</Document></kml>';

gpsmap_write_file(gpsmap_xml_path($preemptive, 'kml'), $kmldoc);
