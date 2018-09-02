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

$kmldoc = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
$kmldoc .= "<kml xmlns=\"http://www.opengis.net/kml/2.2\">\n";
$kmldoc .= "<Document>";
$kmldoc .= "<name>GPSMaps Points</name>\n";

//default google point info for KML

include_once($config['base_path'] . '/plugins/gpsmap/includes/polling/iconskml.php');

$kmldoc .= iconskml();

//default
$kmldoc .= '<Style id="pushpin">';
$kmldoc .= '<IconStyle id="mystyle">';
$kmldoc .= '<Icon>';
$kmldoc .= '<href>http://maps.google.com/mapfiles/kml/pushpin/ylw-pushpin.png</href>';
$kmldoc .= '<scale>1.0</scale>';
$kmldoc .= '</Icon>';
$kmldoc .= '</IconStyle>';
$kmldoc .= '</Style>'. PHP_EOL;

if (sizeof($hostArrays)) {
	foreach ($hostArrays as $hostArray) {
		foreach ($hostArray as $host) {
			$icon = array();

			//Check if google icon is used first
			//else a custom icon is used and we need to parse.
			if ($host->status == "up") {
				$icon = explode('.',$host->upimage);
			} elseif ($host->status == "down") {
				$icon = explode('.',$host->downimage);
			} elseif ($host->status == "recovering") {
				$icon = explode('.',$host->recoverimage);
			} else {
				$icon = explode('.',$host->upimage);
			}

			if (!strncmp('Google',$icon[0],6)) {
				$icon[0] = strtolower(substr($icon[0],6));
			}

			$kmldoc .= '<Placemark>';
			$kmldoc .= '<name>' . parseToXML($host->description) . '</name>';
			$kmldoc .= '<styleUrl>' . parseToXml($icon[0]) . '</styleUrl> ';
			$kmldoc .= '<description>'. parseToXML($host->description) . PHP_EOL . 'Availability: ' . $host->avail . PHP_EOL . 'Address: ' . parseToXML($host->hostname) . '</description>';
			$kmldoc .= '<Point>';
			$kmldoc .= '<coordinates>'. parseToXML($host->long) .',' . parseToXML($host->lat) . '</coordinates>';
			$kmldoc .= '</Point>';
			$kmldoc .= '</Placemark>';
			$kmldoc .= PHP_EOL;
		}
	}
}

$kmldoc .= '</Document></kml>';

$filename = './plugins/gpsmap/XML/' . trim($preemptive, '.') . '.kml';
$f = @fopen($filename, 'w');

if (is_resource($f)) {
	fwrite($f, $kmldoc);
	fclose($f);
} else {
	cacti_log('Unable to write to: ' . $filename . '.  Please verify that the Data Collector has write access to this location.', false, 'POLLER');
}

