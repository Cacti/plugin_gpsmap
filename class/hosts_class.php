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

class host {
	public string $radius;
	public string $configuredRadius;
	public int    $coverage;
	public int    $showMap = 1;

	/**
 * Represents a single device plotted on a GPS map, holding its location,
 * coverage radius, availability/status, and up/down/recovering icon
 * assignment. Instantiated throughout this plugin's polling code
 * (includes/polling/*) while building the set of hosts to render on a
 * map, and read back when generating KML/XML map artifacts.
 *
 * @param string            $id               The host's identifier.
 * @param string            $type             The host's map template
 *                                            type.
 * @param string            $lat              The host's latitude.
 * @param string            $long             The host's longitude.
 * @param string            $iprange          The host's resolved IP
 *                                            address, used for subnet
 *                                            grouping and graph links.
 * @param string            $description      The host's description.
 * @param string            $hostname         The host's hostname.
 * @param int|float|string  $radius           The host's coverage radius,
 *                                            coerced to a string and
 *                                            stored in $radius/
 *                                            $configuredRadius.
 * @param string            $avail            The host's availability
 *                                            method.
 * @param string            $status           The host's current status.
 * @param string            $latency          The host's last measured
 *                                            latency.
 * @param string            $coverage         The submitted 'on'/'' Cacti
 *                                            checkbox value, converted to
 *                                            the $coverage int property
 *                                            (1 when 'on', 0 otherwise).
 * @param string            $upimage          The icon filename to use
 *                                            when the host is up.
 * @param string            $downimage        The icon filename to use
 *                                            when the host is down.
 * @param string            $recoverimage     The icon filename to use
 *                                            when the host is recovering.
 * @param string            $start            The host's monitoring
 *                                            window start time.
 * @param string            $stop             The host's monitoring
 *                                            window stop time.
 * @param string            $group            The host's group
 *                                            assignment.
 *
 * @return void
 */
public function __construct(
		public string $id,
		public string $type,
		public string $lat,
		public string $long,
		public string $iprange,
		public string $description,
		public string $hostname,
		int|float|string $radius,
		public string $avail,
		public string $status,
		public string $latency,
		string $coverage,
		public string $upimage,
		public string $downimage,
		public string $recoverimage,
		public string $start,
		public string $stop,
		public string $group,
	) {
		$this->radius           = (string) $radius;
		$this->configuredRadius = $this->radius;

		// Cacti checkbox convention: 'on' when ticked, '' otherwise.
		$this->coverage = ($coverage === 'on') ? 1 : 0;
	}
}
