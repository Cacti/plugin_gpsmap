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

//This file takes care of the individual assignments for each custom icon set for the map templates
//This pulls the template from MySQL and attaches the Up/Down/Recover icon reference.
//Basically this makes a mapping between template and icon.

$customiconlist = "gpsmap.customIcons = {};\n";

/* Restrict icon property names to safe JS identifiers to prevent injection
 * when the value is concatenated directly into a property-access expression. */
function gpsmap_safe_icon_base(string $filename): string {
	$base = pathinfo($filename, PATHINFO_FILENAME);
	return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $base) ? $base : 'undefined';
}

$results = db_fetch_assoc('SELECT * FROM gpsmap_templates ORDER BY templateID');
if (cacti_sizeof($results)) {
	foreach ($results as $row) {
		$tid          = (int) $row['templateID'];
		$up_base      = gpsmap_safe_icon_base($row['upimage']);
		$down_base    = gpsmap_safe_icon_base($row['downimage']);
		$recover_base = gpsmap_safe_icon_base($row['recoverimage']);

		$customiconlist .= 'gpsmap.customIcons[' . json_encode($tid . 'up')        . '] = gpsmap.' . $up_base      . ";\n";
		$customiconlist .= 'gpsmap.customIcons[' . json_encode($tid . 'down')       . '] = gpsmap.' . $down_base    . ";\n";
		$customiconlist .= 'gpsmap.customIcons[' . json_encode($tid . 'recovering') . '] = gpsmap.' . $recover_base . ";\n";
	}
}

//DO NOT REMOVE THESE
$customiconlist .= "gpsmap.customIcons['up'] = gpsmap.Green;\n";
$customiconlist .= "gpsmap.customIcons['recovering'] = gpsmap.Orange;\n";
$customiconlist .= "gpsmap.customIcons['down'] = gpsmap.Red;\n";
$customiconlist .= "gpsmap.customIcons['disabled'] = gpsmap.Black;\n";
$customiconlist .= "gpsmap.customIcons['undefined'] = gpsmap.Black;\n";

echo $customiconlist;

