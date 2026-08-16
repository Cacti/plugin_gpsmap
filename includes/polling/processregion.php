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

//this function is called to process the nodes and get them ready for analysis
//---------------------------------------------------------------
function region(string $subnet): void {
	global $config, $enableAll;

	/* Cacti checkbox convention: '' = unchecked, 'on' = checked. (bool)
	 * correctly maps '' -> false and 'on' -> true for this contract. */
	$enableAll = (bool) $enableAll;
	$towerIds  = getTowerIds();

	include_once($config['base_path'] . '/plugins/gpsmap/class/hosts_class.php');

	/* Per-call output.  This used to be a global that region() had to blank on
	 * the way out; the poller calls region() once per subnet, so a missed reset
	 * concatenated every earlier subnet's navigation into the next file. */
	$body       = '';
	$kmlDomain  = read_config_option('base_url');
	$iparray    = array();
	$ipwriteout = array();
	$hostArray  = array();
	$towerArray = array();

	/* Select only the columns used by region()/createDoc().  Avoids pulling
	 * SNMP credentials (snmp_community, snmp_auth_passphrase, etc.) into PHP
	 * memory on every poller cycle. */
	$sql = 'SELECT h.id, h.host_template_id, h.hostname, h.description,
		h.status, h.disabled, h.availability, h.cur_time,
		h.latitude, h.longitude, h.start, h.stop, h.rdistance,
		h.groupnum, h.GPScoverage,
		gt.AP, gt.upimage, gt.downimage, gt.recoverimage
		FROM `host` AS h
		INNER JOIN gpsmap_templates AS gt
		ON h.host_template_id = gt.templateID';

	/* Cacti stores '' for enabled and 'on' for disabled. */
	$sql_where  = $enableAll ? '' : ' WHERE h.disabled = ?';
	$sql_params = $enableAll ? array() : array('');

	$results = db_fetch_assoc_prepared($sql . $sql_where . ' ORDER BY h.hostname', $sql_params);

	/* Cache hostname -> IP resolutions so each hostname is resolved at most
	 * once per region() call rather than twice (here and in the subnet loop).
	 * Intentionally per-invocation with no TTL: the poller is batch-oriented
	 * and stale entries within a single cycle are acceptable. If poller cycles
	 * exceed 5 minutes, consider adding a TTL-based expiry. */
	$dns_cache = array();

	foreach ($results as $row) {
		if ($row['latitude'] == '0.000' || $row['longitude'] == '0.000') {
			continue;
		}

		$dns_cache[$row['hostname']] ??= gethostbyname($row['hostname']);
		$hostip = $dns_cache[$row['hostname']];

		if (!is_ipaddress($hostip) || substr_count($hostip, '.') != 3) {
			continue;
		}

		$status = match ((int) $row['status']) {
			3       => 'up',
			2       => 'recovering',
			1       => 'down',
			default => 'undefined',
		};

		if ($row['disabled'] == 'on') {
			$status = 'disabled';
		}

		$is_tower = in_array($row['host_template_id'], $towerIds);

		$host = new host(
			$row['id'],
			$row['host_template_id'],
			coordCheck($row['latitude']),
			coordCheck($row['longitude']),
			$hostip,
			$row['description'],
			$row['hostname'],
			0,
			$row['availability'],
			$status,
			$row['cur_time'],
			$row['GPScoverage'],
			$row['upimage'] ?: 'Green',
			$row['downimage'] ?: 'Red',
			$row['recoverimage'] ?: 'Yellow',
			/* Only towers carry a coverage window; devices use the full day. */
			$is_tower ? $row['start'] : '0',
			$is_tower ? $row['stop'] : '360',
			$row['groupnum'],
		);

		if ($is_tower) {
			$towerArray[] = $host;
		} else {
			$hostArray[] = $host;
		}
	}

	$hostArrays = array($towerArray, $hostArray);
	$preempt    = ($subnet == 'all') ? 0 : str_word_count($subnet, 0, '.');

	//This section deals with traversal of the subnets
	//sort by top level, and display all top IP range so they can be selected, and iterate this down through level 4
	//if subnet level is met we want to display the lower ones.
	//for 1 we want to display all top level IP
	foreach ($hostArrays as $group) {
		foreach ($group as $host) {
			$dns_cache[$host->hostname] ??= gethostbyname($host->hostname);

			/* pad to 4 elements so destructuring is safe when the name did not
			 * resolve to a dotted-quad. */
			[$first, $second, $third, $fourth] = array_pad(explode('.', $dns_cache[$host->hostname]), 4, '0');

			/* The prefix this host would contribute at the current depth, and
			 * the prefix the requested subnet has to match for it to count. */
			$octets = array($first, $second, $third, $fourth);
			$parent = implode('.', array_slice($octets, 0, $preempt)) . '.';
			$child  = implode('.', array_slice($octets, 0, $preempt + 1)) . '.';

			if ($preempt > 3 || ($preempt > 0 && strcasecmp($subnet, $parent) !== 0)) {
				//outside the requested subnet, keep it off the map
				$host->showMap = 0;

				continue;
			}

			if ($preempt == 3) {
				/* Deepest level: link straight to the device's graphs.  The
				 * guard tests the dotted form but stores the undotted one,
				 * which is how this has always behaved. */
				if (!in_array($child, $iparray)) {
					$iparray[]    = rtrim($child, '.');
					$ipwriteout[] = '<a href="' . $config['url_path'] . 'graph_view.php?action=preview&reset=1&host_id=' . $host->id . '">' . __('IP %s', rtrim($child, '.')) . '</a><br/>';
				}
			} elseif (!in_array($child, $iparray)) {
				$iparray[] = $child;
			}
		}
	}

	//process data collected and create interface output
	if ($preempt != 3) {
		foreach ($iparray as $ipout) {
			$ipout = trim($ipout, '.');

			$ipwriteout[] = '<a href="' . html_escape($config['url_path'] . 'plugins/gpsmap/gpsmap.php?subnet=' . $ipout) . '">' . __('IP %s', $ipout, 'gpsmap') . '</a>-(<a href="' . $kmlDomain . $config['url_path'] . 'plugins/gpsmap/XML/' . $ipout . '.xml">X</a>-<a href="' . $kmlDomain . $config['url_path'] . 'plugins/gpsmap/XML/' . $ipout . '.kml">K</a>)<br/>';
		}
	}

	//print out the information we have gathered.
	$body .= '<div id="gpstopmenu" style="overflow: auto; width:100%; ">';
	$body .= '<div id="gpsnav" style="overflow:auto; float:left; position:relative;"><input type="button" value="' . __esc('Start Over', 'gpsmap') . '" onclick="window.location.reload(true);" />';
	$body .= '<input type="button" class="print" alt="" value="' . __esc('Print', 'gpsmap') . '" onclick="window.open(\'print.php\')" />';
	$body .= '</div>';

	//six links per column block
	foreach (array_chunk($ipwriteout, 6) as $chunk) {
		$body .= '<div id="iplevels" style="display:table-cell;vertical-align:middle;overflow:auto;float:left;position:relative;"> ' . implode('', $chunk) . '</div>';
	}

	$body .= '</div>';

	createDoc($hostArrays, $subnet === '' ? 'all' : $subnet);

	$top = $config['base_path'] . '/plugins/gpsmap/XML/' . trim($subnet === '' ? 'all' : $subnet, '.') . '-top.html';

	gpsmap_write_file($top, $body);
}
