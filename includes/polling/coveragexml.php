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

// Included from coverageXML(); $doc and $hostArrays come from that scope.
$typeArray  = createTypeArray();
$towerArray = $hostArrays[0];
$hostArray  = $hostArrays[1];

// Grow each tower's radius to reach the furthest device sharing its group.
foreach ($hostArray as $host) {
	if ($host->showMap != 1 || $host->coverage != 1) {
		continue;
	}

	foreach ($towerArray as $tower) {
		/* A non-zero Specify Radius value is operator policy, not a seed for
		 * automatic growth. Zero retains the geometry-derived behaviour. */
		if ((float) $tower->configuredRadius > 0.0 || $host->group != $tower->group) {
			continue;
		}

		$distance = calcKm((float) $tower->lat, (float) $tower->long, (float) $host->lat, (float) $host->long);

		if ($distance > (float) $tower->radius) {
			$tower->radius = (string) $distance;
		}
	}
}

foreach ($towerArray as $tower) {
	if ($tower->showMap == 1) {
		$doc .= gpsmap_marker($tower, $typeArray, $tower->radius, true);
	}
}
