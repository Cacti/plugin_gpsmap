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

//---------------------------------------------------------------
function callRegion(string $subnet): void {
	global $config;

	include_once($config['base_path'] . '/plugins/gpsmap/includes/polling/pollinginitial.php');
	include_once($config['base_path'] . '/plugins/gpsmap/includes/polling/processregion.php');

	region($subnet);
}

//---------------------------------------------------------------
function getTowerIds(): array {
	$results = db_fetch_assoc('SELECT `templateID`
		FROM `gpsmap_templates`
		WHERE `AP` = 1');

	/* 9999 is a sentinel that matches no host_template_id, so an empty
	 * result set still produces a usable in_array() haystack. */
	return cacti_sizeof($results) ? array_column($results, 'templateID') : array(9999);
}

//---------------------------------------------------------------
/* Returns the great-circle distance in kilometres (not metres, the constant
 * 6378.7 is Earth's mean radius in km).  Renamed from calcMeters to reflect
 * the actual unit; callers treating the result as metres will be off by 1000x.
 * The gpsmap_templates.radius column is also stored in km. */
function calcKm(float $Lat1, float $Lon1, float $Lat2, float $Lon2): float {
	return 6378.7 * 3.1415926 * sqrt(
		($Lat2 - $Lat1) * ($Lat2 - $Lat1)
		+ cos($Lat2 / 57.29578) * cos($Lat1 / 57.29578) * ($Lon2 - $Lon1) * ($Lon2 - $Lon1)
	) / 180;
}

/** @deprecated Use calcKm() instead. */
function calcMeters(float $Lat1, float $Lon1, float $Lat2, float $Lon2): float {
	return calcKm($Lat1, $Lon1, $Lat2, $Lon2);
}

//---------------------------------------------------------------
function coordCheck(string $coords): string {
	$coords = trim($coords);

	//return 0.000 for anything unparseable, the user can correct the device
	return preg_match('#^-?\d{1,3}\.\d+$#', $coords) ? $coords : '0.000';
}

//---------------------------------------------------------------
/* Single writer for every generated artefact (XML, KML, top HTML) so the
 * failure path and its log message stay identical across all three. */
function gpsmap_write_file(string $filename, string $contents): bool {
	$f = @fopen($filename, 'w');

	if ($f === false) {
		cacti_log('Unable to write to: ' . $filename . '.  Please verify that the Data Collector has write access to this location.', false, 'POLLER');

		return false;
	}

	fwrite($f, $contents);
	fclose($f);

	return true;
}

//---------------------------------------------------------------
function gpsmap_xml_path(string $preemptive, string $extension): string {
	global $config;

	return $config['base_path'] . '/plugins/gpsmap/XML/' . trim($preemptive, '.') . '.' . $extension;
}

//---------------------------------------------------------------
function createDoc(array $hostArrays, string $preemptive): void {
	xmlCreate($hostArrays, $preemptive);
	kmlCreate($hostArrays, $preemptive);
}

//---------------------------------------------------------------
/* The previous implementation processed '&' last, which re-encoded the '&'
 * already introduced by the earlier substitutions (e.g. '<' -> '&lt;' -> '&amp;lt;').
 * htmlspecialchars() with ENT_XML1 handles the correct order atomically. */
function parseToXML($htmlStr): string {
	return htmlspecialchars((string) $htmlStr, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

//---------------------------------------------------------------
function xmlCreate(array $hostArrays, string $preemptive): void {
	$doc = '<markers>'
		. createXMLNodes($hostArrays[1])
		. coverageXML($hostArrays)
		. '</markers>';

	gpsmap_write_file(gpsmap_xml_path($preemptive, 'xml'), $doc);
}

//---------------------------------------------------------------
function coverageXML(array $hostArrays): string {
	global $config;

	$doc = '';
	require($config['base_path'] . '/plugins/gpsmap/includes/polling/coveragexml.php');

	return $doc;
}

//---------------------------------------------------------------
function kmlCreate(array $hostArrays, string $preemptive): void {
	global $config;

	require($config['base_path'] . '/plugins/gpsmap/includes/polling/kmlcreation.php');
}

//---------------------------------------------------------------
function createTypeArray(): array {
	return array_column(db_fetch_assoc('SELECT `id`, `name` FROM `host_template`'), 'name', 'id');
}

//---------------------------------------------------------------
/* Renders one <marker/>.  Device markers carry no radius and no schedule;
 * tower markers carry the computed coverage radius and their active window. */
function gpsmap_marker(host $host, array $typeArray, string $radius, bool $withSchedule): string {
	$doc = '<marker '
		. 'id="' . parseToXML($host->id) . '" '
		. 'name="' . parseToXML($host->description) . '" '
		. 'address="' . parseToXML($host->hostname) . '" '
		. 'lat="' . parseToXML($host->lat) . '" '
		. 'lng="' . parseToXML($host->long) . '" '
		. 'type="' . parseToXML($typeArray[$host->type] ?? 'Unknown') . '" '
		. 'templateId="' . parseToXML($host->type) . '" '
		. 'availability="' . $host->avail . '" '
		. 'radius="' . $radius . '" '
		. 'status="' . parseToXML($host->status) . '" '
		. 'latency="' . parseToXML($host->latency) . '" ';

	if ($withSchedule) {
		$doc .= 'start="' . parseToXML($host->start) . '" '
			. 'stop="' . parseToXML($host->stop) . '" ';
	}

	return $doc . 'group="' . parseToXML($host->group) . '" />' . "\n";
}

//---------------------------------------------------------------
function createXMLNodes(array $hostArray): string {
	$typeArray = createTypeArray();
	$doc       = '';

	foreach ($hostArray as $host) {
		if ($host->showMap == 1) {
			$doc .= gpsmap_marker($host, $typeArray, '0', false);
		}
	}

	return $doc;
}
