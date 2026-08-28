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

function gpsmap_schedule_dns_refresh(): void {
	global $config;

	if (!db_table_exists('plugin_gpsmap_dns_cache')) {
		cacti_log('WARNING: gpsmap DNS cache table is unavailable; run the plugin upgrade to restore asynchronous hostname resolution', false, 'GPSMAP');

		return;
	}

	$php = trim((string) read_config_option('path_php_binary'));

	if ($php === '') {
		cacti_log('WARNING: gpsmap DNS refresh was not scheduled because the PHP binary path is not configured', false, 'GPSMAP');

		return;
	}

	exec_background($php, cacti_escapeshellarg($config['base_path'] . '/plugins/gpsmap/gpsmap_dns.php'));
}

function gpsmap_poller_bottom() {
	global $config;

	include_once($config['base_path'] . '/plugins/gpsmap/includes/polling/functions.php');
	include_once($config['base_path'] . '/plugins/gpsmap/includes/polling/processregion.php');

	$cycle_started_at = time();
	$start            = microtime(true);
	$state            = new GpsmapPollState();

	/* Load once.  Every subnet below is rendered from this same set, so the
	 * device query and the DNS lookups happen a single time per poller cycle
	 * rather than once per subnet. */
	$hostArrays       = gpsmap_load_devices(gpsmap_enable_all(), $state);
	$mapped           = cacti_sizeof($hostArrays[0]) + cacti_sizeof($hostArrays[1]);
	$poller_interval  = max(60, (int) read_config_option('poller_interval'));
	$last_dns_success = (int) read_config_option('plugin_gpsmap_dns_last_success');

	if (db_table_exists('plugin_gpsmap_dns_cache') && $last_dns_success <= 0) {
		cacti_log('WARNING: gpsmap DNS refresh has never reported successful completion; named Device markers will be preserved while the resolver is unavailable', false, 'GPSMAP');
	} elseif (db_table_exists('plugin_gpsmap_dns_cache')
		&& gpsmap_dns_worker_is_stale($last_dns_success, $poller_interval)) {
		cacti_log('WARNING: gpsmap DNS refresh has not completed successfully within its runtime and scheduling window; cached hostname addresses may be stale', false, 'GPSMAP');
	}

	/* Start the resolver even when this cycle cannot safely publish. A cold
	 * cache therefore heals in the background for the next poller cycle. */
	gpsmap_schedule_dns_refresh();

	/* Withhold publication only when the Device query itself failed.  An estate
	 * with no mapped Devices is a real answer and has to be published, or a new
	 * install never gets an all.xml at all and the map page fetches a 404. */
	if ($state->loadFailed) {
		cacti_log('WARNING: gpsmap could not read the Device list this cycle; the existing map has been left in place', false, 'GPSMAP');

		return;
	}

	if ($state->unresolvedHostnames !== []) {
		$unresolved_ids = implode(', ', array_slice($state->unresolvedDeviceIds, 0, 10));

		cacti_log(sprintf(
			'WARNING: gpsmap has %d hostname(s) without a cached address (Device IDs: %s); last-known markers are retained and artifact pruning is deferred while the background resolver runs',
			cacti_sizeof($state->unresolvedHostnames),
			$unresolved_ids
		), false, 'GPSMAP');
	}

	if ($state->expiredHostnames !== []) {
		$expired_ids = implode(', ', array_slice($state->expiredDeviceIds, 0, 10));

		cacti_log(sprintf(
			'WARNING: gpsmap omitted %d hostname(s) after repeated DNS failures or an expired cached address (Device IDs: %s); successful Devices and artifact pruning will continue',
			cacti_sizeof($state->expiredHostnames),
			$expired_ids
		), false, 'GPSMAP');
	}

	if ($state->staleHostnames !== []) {
		$stale_ids = implode(', ', array_slice($state->staleDeviceIds, 0, 10));

		cacti_log(sprintf(
			'WARNING: gpsmap rendered %d hostname(s) from explicitly stale last-known-good DNS addresses (Device IDs: %s); verify the DNS worker configuration',
			cacti_sizeof($state->staleHostnames),
			$stale_ids
		), false, 'GPSMAP');
	}

	if ($mapped === 0) {
		cacti_log('NOTICE: gpsmap has no Devices to map.  Check that a Device Template is listed under Templates -> Map and that Devices have coordinates.', false, 'GPSMAP');
	}

	$prefixes = array_values(array_unique(array_merge(
		gpsmap_subnet_prefixes($hostArrays),
		gpsmap_preserved_subnet_prefixes($state)
	)));

	$published = gpsmap_render_region($hostArrays, 'all', $state);

	foreach ($prefixes as $prefix) {
		$rendered  = gpsmap_render_region($hostArrays, $prefix, $state);
		$published = $rendered && $published;
	}

	/* Only a successful cycle may age out old snapshots. Three poller intervals
	 * make absence persistent evidence instead of a transient partial result. */
	$pruned = 0;

	if ($published && $state->preservedArtifacts === []) {
		$pruned = gpsmap_prune_artifacts(
			gpsmap_artifact_prune_threshold($cycle_started_at, $poller_interval)
		);
	} elseif (!$published) {
		cacti_log('WARNING: gpsmap did not prune artifacts because at least one map snapshot could not be published', false, 'GPSMAP');
	}

	cacti_log(sprintf(
		'GPSMAP STATS: Mapped:%d Towers:%d Subnets:%d Pruned:%d Time:%0.2f',
		$mapped,
		cacti_sizeof($hostArrays[0]),
		cacti_sizeof($prefixes),
		$pruned,
		microtime(true) - $start
	), false, 'GPSMAP');
}
