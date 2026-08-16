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
	public string $id           = '0';
	public string $type         = '0';
	public string $lat          = '0';
	public string $long         = '0';
	public string $iprange      = '';
	public string $description  = '';
	public string $hostname     = '';
	public string $radius       = '0';
	public string $avail        = '';
	public string $status       = '';
	public int    $showMap      = 1;
	public string $latency      = '0';
	public int    $coverage     = 1;
	public string $upimage      = '';
	public string $downimage    = '';
	public string $recoverimage = '';
	public string $start        = '0';
	public string $stop         = '360';
	public string $group        = '0';

	public function __construct(
		string $id,
		string $type,
		string $lat,
		string $long,
		string $iprange,
		string $description,
		string $hostname,
		int    $radius,
		string $avail,
		string $status,
		string $latency,
		string $coverage,
		string $upimage,
		string $downimage,
		string $recoverimage,
		string $start,
		string $stop,
		string $group
	) {
		$this->id           = $id;
		$this->type         = $type;
		$this->lat          = $lat;
		$this->long         = $long;
		$this->iprange      = $iprange;
		$this->description  = $description;
		$this->hostname     = $hostname;
		$this->radius       = (string) $radius;
		$this->avail        = $avail;
		$this->status       = $status;
		$this->latency      = $latency;
		$this->coverage     = ($coverage === 'on') ? 1 : 0;
		$this->upimage      = $upimage;
		$this->downimage    = $downimage;
		$this->recoverimage = $recoverimage;
		$this->start        = $start;
		$this->stop         = $stop;
		$this->group        = $group;
	}
}
