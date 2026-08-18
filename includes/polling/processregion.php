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

/* The poller renders one file set per subnet prefix.  Loading the device list
 * is the expensive part -- one query plus a DNS lookup per device -- so it
 * happens once in gpsmap_load_devices() and every render reuses the result.
 * region() keeps the old load-then-render shape for single callers. */

// ---------------------------------------------------------------
function region(string $subnet): void {
	$hostArrays = gpsmap_load_devices(gpsmap_enable_all());

	gpsmap_render_region($hostArrays, $subnet);
}

// ---------------------------------------------------------------
/* One query and no synchronous DNS. Returns array(towers, devices) in the
 * order coveragexml.php and xmlCreate() expect. */
function gpsmap_enable_all(): bool {
	/* pollinginitial.php assigns $enableAll from inside callRegion(), so the
	 * global that used to be read here was never bound and the setting was
	 * inert.  Read it where it is needed instead. */
	return read_config_option('gpsmap_enableall') === 'on';
}

// ---------------------------------------------------------------
function gpsmap_load_devices(bool $enableAll): array {
	global $config;

	include_once($config['base_path'] . '/plugins/gpsmap/class/hosts_class.php');

	$towerIds = getTowerIds();

	/* Select only the columns used by the renderers.  Avoids pulling SNMP
	 * credentials (snmp_community, snmp_auth_passphrase, etc.) into PHP
	 * memory on every poller cycle. */
	$has_dns   = db_table_exists('plugin_gpsmap_dns_cache');
	$has_thold = api_plugin_is_enabled('thold') && db_table_exists('thold_data');
	$sql       = 'SELECT h.id, h.host_template_id, h.hostname, h.description,
		h.status, h.disabled, h.availability, h.cur_time,
		h.latitude, h.longitude, h.start, h.stop, h.rdistance,
		h.groupnum, h.GPScoverage,
		gt.AP, gt.upimage, gt.downimage, gt.recoverimage'
		. ($has_dns ? ', dc.address AS cached_address' : ', NULL AS cached_address')
		. ($has_thold ? ', COALESCE(td.thold_alarm, 0) AS thold_alarm' : ', 0 AS thold_alarm') . '
		FROM `host` AS h
		INNER JOIN gpsmap_templates AS gt
		ON h.host_template_id = gt.templateID'
		. ($has_dns ? '
		LEFT JOIN plugin_gpsmap_dns_cache AS dc ON dc.hostname = h.hostname' : '')
		. ($has_thold ? '
		LEFT JOIN (
			SELECT host_id, MAX(thold_alert > 0) AS thold_alarm
			FROM thold_data
			WHERE thold_enabled = "on"
			GROUP BY host_id
		) AS td ON td.host_id = h.id' : '');

	// Cacti stores '' for enabled and 'on' for disabled.
	$sql_where  = $enableAll ? '' : ' WHERE h.disabled = ?';
	$sql_params = $enableAll ? [] : [''];

	$results = db_fetch_assoc_prepared($sql . $sql_where . ' ORDER BY h.hostname', $sql_params);

	/* A failed query and an estate with no mapped Devices both arrive here as
	 * an empty set.  Only the first is a reason to withhold publication, so the
	 * caller is told which happened.
	 *
	 * false really is reachable: db_fetch_assoc_prepared() delegates to
	 * db_execute_prepared(), which returns false on a connection failure, a
	 * failed re-connect and an exhausted retry loop, and db_fetch_assoc_return()
	 * documents itself as returning "the associated array of data, or false on
	 * failure" (Cacti 1.2.x lib/database.php). */
	$GLOBALS['gpsmap_load_failed'] = ($results === false);

	$towerArray = [];
	$hostArray  = [];

	if (!cacti_sizeof($results)) {
		return [$towerArray, $hostArray];
	}

	foreach ($results as $row) {
		if ($row['latitude'] == '0.000' || $row['longitude'] == '0.000') {
			continue;
		}

		/* Literal addresses need no DNS.  Names use the last asynchronously
		 * refreshed cache value; the poller never blocks on a resolver. */
		$hostip = is_ipaddress($row['hostname']) ? $row['hostname'] : ($row['cached_address'] ?? '');

		if (!is_ipaddress($hostip)) {
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
		} elseif ($status === 'up' && !empty($row['thold_alarm'])) {
			$status = 'alert';
		}

		$is_tower = in_array($row['host_template_id'], $towerIds, true);

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
			// Only towers carry a coverage window; devices use the full day.
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

	return [$towerArray, $hostArray];
}

// ---------------------------------------------------------------
/* Rendering mutates showMap and grows tower radii, so the shared device set
 * has to be returned to its loaded state before each subnet. */
function gpsmap_reset_devices(array $hostArrays): void {
	foreach ($hostArrays as $group) {
		foreach ($group as $host) {
			$host->showMap = 1;
			$host->radius  = '0';
		}
	}
}

// ---------------------------------------------------------------
/* Every distinct /8, /16 and /24 prefix present in the loaded device set.
 * Derived from the already-resolved addresses, so no second DNS pass. */
function gpsmap_subnet_prefixes(array $hostArrays): array {
	/* Keyed rather than searched: in_array() over a growing list is quadratic in
	 * the number of prefixes, which is material on a large estate. */
	$prefixes = [];

	foreach ($hostArrays as $group) {
		foreach ($group as $host) {
			if (filter_var($host->iprange, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
				for ($depth = 1; $depth <= 3; $depth++) {
					$token = gpsmap_ipv6_prefix_token($host->iprange, $depth * 16);

					if ($token !== null) {
						$prefixes[$token] = true;
					}
				}

				continue;
			}

			$octets = array_pad(explode('.', $host->iprange), 4, '0');

			for ($depth = 1; $depth <= 3; $depth++) {
				$prefixes[implode('.', array_slice($octets, 0, $depth)) . '.'] = true;
			}
		}
	}

	return array_keys($prefixes);
}

// ---------------------------------------------------------------
function gpsmap_render_region(array $hostArrays, string $subnet): void {
	global $config;

	gpsmap_reset_devices($hostArrays);

	$kmlDomain  = read_config_option('base_url');
	$body       = '';
	$iparray    = [];
	$ipwriteout = [];
	$subnet     = $subnet === '' ? 'all' : $subnet;
	$is_all     = $subnet === 'all';
	$is_v6      = str_starts_with($subnet, 'v6-');
	$preempt    = $is_all ? 0 : ($is_v6 ? (int) explode('-', $subnet, 3)[1] / 16 : str_word_count($subnet, 0, '.'));

	// This section deals with traversal of the subnets
	// sort by top level, and display all top IP range so they can be selected, and iterate this down through level 4
	// if subnet level is met we want to display the lower ones.
	// for 1 we want to display all top level IP
	foreach ($hostArrays as $group) {
		foreach ($group as $host) {
			$host_is_v6 = filter_var($host->iprange, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;

			if ($is_v6 || ($is_all && $host_is_v6)) {
				if (!$host_is_v6 || (!$is_all && !gpsmap_ipv6_prefix_contains($subnet, $host->iprange))) {
					$host->showMap = 0;

					continue;
				}

				$child = gpsmap_ipv6_prefix_token($host->iprange, ($preempt + 1) * 16);

				if ($preempt === 3) {
					if (!in_array($host->iprange, $iparray, true)) {
						$iparray[]    = $host->iprange;
						$ipwriteout[] = '<a href="' . $config['url_path'] . 'graph_view.php?action=preview&amp;reset=1&amp;host_id=' . $host->id . '">' . __('IP %s', html_escape($host->iprange), 'gpsmap') . '</a><br/>';
					}
				} elseif ($child !== null && !in_array($child, $iparray, true)) {
					$iparray[] = $child;
				}

				continue;
			}

			if ($host_is_v6) {
				$host->showMap = 0;

				continue;
			}

			// iprange is the address gpsmap_load_devices() already resolved.
			$octets = array_pad(explode('.', $host->iprange), 4, '0');

			/* The prefix this host would contribute at the current depth, and
			 * the prefix the requested subnet has to match for it to count. */
			$parent = implode('.', array_slice($octets, 0, $preempt)) . '.';
			$child  = implode('.', array_slice($octets, 0, $preempt + 1)) . '.';

			if ($preempt > 3 || ($preempt > 0 && strcasecmp($subnet, $parent) !== 0)) {
				// outside the requested subnet, keep it off the map
				$host->showMap = 0;

				continue;
			}

			if ($preempt == 3) {
				/* Deepest level: link straight to the device's graphs.  The
				 * guard has to test the same form that gets stored, otherwise
				 * two Devices resolving to one address each emit a link. */
				$leaf = rtrim($child, '.');

				if (!in_array($leaf, $iparray, true)) {
					$iparray[]    = $leaf;
					$ipwriteout[] = '<a href="' . $config['url_path'] . 'graph_view.php?action=preview&reset=1&host_id=' . $host->id . '">' . __('IP %s', $leaf) . '</a><br/>';
				}
			} elseif (!in_array($child, $iparray, true)) {
				$iparray[] = $child;
			}
		}
	}

	// process data collected and create interface output
	if ($preempt != 3) {
		foreach ($iparray as $ipout) {
			$token = trim($ipout, '.');
			$label = gpsmap_ipv6_prefix_label($token) ?? $token;

			$ipwriteout[] = '<a href="' . html_escape($config['url_path'] . 'plugins/gpsmap/gpsmap.php?subnet=' . rawurlencode($token)) . '">' . __('IP %s', html_escape($label), 'gpsmap') . '</a>-(<a href="' . $kmlDomain . $config['url_path'] . 'plugins/gpsmap/XML/' . $token . '.xml">X</a>-<a href="' . $kmlDomain . $config['url_path'] . 'plugins/gpsmap/XML/' . $token . '.kml">K</a>)<br/>';
		}
	}

	// print out the information we have gathered.
	$body .= '<div id="gpstopmenu" style="overflow: auto; width:100%; ">';
	$body .= '<div id="gpsnav" style="overflow:auto; float:left; position:relative;"><input type="button" value="' . __esc('Start Over', 'gpsmap') . '" onclick="window.location.reload(true);" />';
	$body .= '<input type="button" class="print" alt="" value="' . __esc('Print', 'gpsmap') . '" onclick="window.open(\'print.php\')" />';
	$body .= '</div>';

	// six links per column block
	foreach (array_chunk($ipwriteout, 6) as $chunk) {
		$body .= '<div id="iplevels" style="display:table-cell;vertical-align:middle;overflow:auto;float:left;position:relative;"> ' . implode('', $chunk) . '</div>';
	}

	$body .= '</div>';

	$name = $subnet;

	createDoc($hostArrays, $name);

	gpsmap_write_file($config['base_path'] . '/plugins/gpsmap/XML/' . gpsmap_artifact_stem($name) . '-top.html', $body);
}
