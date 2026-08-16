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
	public int    $coverage;
	public int    $showMap = 1;

	public function __construct(
		public string $id,
		public string $type,
		public string $lat,
		public string $long,
		public string $iprange,
		public string $description,
		public string $hostname,
		int $radius,
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
		$this->radius = (string) $radius;

		/* Cacti checkbox convention: 'on' when ticked, '' otherwise. */
		$this->coverage = ($coverage === 'on') ? 1 : 0;
	}
}
