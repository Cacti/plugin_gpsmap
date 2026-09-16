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

/* $old is the version being upgraded from.  It used to be read from a global
 * that nothing ever set, which made every comparison below true and re-ran the
 * whole migration history on every version change. */
/* Backoff between attempts, so a failing migration cannot re-run an ALTER on
 * Cacti's host table from every page view. */
if (!defined('GPSMAP_UPGRADE_RETRY_SECONDS')) {
	define('GPSMAP_UPGRADE_RETRY_SECONDS', 300);
}

if (!defined('GPSMAP_UPGRADE_MAX_FAILURES')) {
	define('GPSMAP_UPGRADE_MAX_FAILURES', 5);
}

function gpsmap_upgrade_database(string $old = '', bool $force = false): void {
	global $config;

	include_once($config['library_path'] . '/database.php');

	$v = plugin_gpsmap_version();

	$retry_after = (int) read_config_option('plugin_gpsmap_upgrade_retry_after', true);
	$failures    = (int) read_config_option('plugin_gpsmap_upgrade_failures', true);

	if (!$force && $failures >= GPSMAP_UPGRADE_MAX_FAILURES) {
		cacti_log('ERROR: gpsmap schema upgrade is suspended after repeated failures; use Plugin Management to retry after correcting the database error', false, 'GPSMAP');

		return;
	}

	if (!$force && $retry_after > time()) {
		cacti_log('NOTICE: gpsmap schema upgrade retry is deferred for another ' . ($retry_after - time()) . ' seconds', false, 'GPSMAP');

		return;
	}

	/* gpsmap_setup_database() adds the host columns and creates the template
	 * table, so its result belongs in the same gate as the migrations below. */
	$ok = gpsmap_setup_database();

	if (version_compare($old, '1.6', '<')) {
		$ok = db_execute('ALTER TABLE host CHANGE COLUMN latitude latitude DECIMAL(13,10) NOT NULL DEFAULT 0.0000000000;') && $ok;
		$ok = db_execute('ALTER TABLE host CHANGE COLUMN longitude longitude DECIMAL(13,10) NOT NULL DEFAULT 0.0000000000;') && $ok;
	}

	if (version_compare($old, '2.1', '<')) {
		if (!db_index_exists('gpsmap_templates', 'templateID')) {
			$ok = db_add_index('gpsmap_templates', 'unique', 'templateID', ['templateID']) && $ok;
		}
	}

	include_once($config['base_path'] . '/plugins/gpsmap/setup.php');

	/* Recorded last, and only when every migration reported success.
	 * gpsmap_check_upgrade() gates on this option, so writing it earlier would
	 * mark the plugin current even though a failed ALTER left the schema
	 * behind, with no log line and no retry. */
	if ($ok) {
		/* Both records, together and only on success.  Plugin Management reads
		 * plugin_config; gpsmap_check_upgrade() reads the settings option.
		 * Writing either early reports the plugin current while the schema is
		 * still behind. */
		$ok = db_execute_prepared('UPDATE plugin_config SET version = ? WHERE directory = ?', [$v['version'], 'gpsmap']);
	}

	if ($ok) {
		set_config_option('plugin_gpsmap_version', $v['version']);
		set_config_option('plugin_gpsmap_upgrade_retry_after', '0');
		set_config_option('plugin_gpsmap_upgrade_failures', '0');

		return;
	}

	/* Never record the version here: doing so would report a schema the upgrade
	 * knows is incomplete as current, and nothing would re-arm the migration
	 * when the transient cause clears.  Back off instead, so a lock timeout
	 * cannot turn ordinary page views into sustained contention on host. */
	$failures++;
	$retry_seconds = min(3600, GPSMAP_UPGRADE_RETRY_SECONDS * (2 ** min($failures - 1, 4)));
	set_config_option('plugin_gpsmap_upgrade_failures', (string) $failures);
	set_config_option('plugin_gpsmap_upgrade_retry_after', (string) (time() + $retry_seconds));

	cacti_log('WARNING: gpsmap schema upgrade did not complete and will be retried after ' . $retry_seconds . ' seconds.  If it keeps failing, correct the database error and retry from Plugin Management.', false, 'GPSMAP');
}

function gpsmap_setup_database(): bool {
	api_plugin_db_add_column('gpsmap', 'host', ['name' => 'latitude', 'type' => 'decimal(13,10)', 'NULL' => false, 'default' => '0', 'after' => 'availability']);
	api_plugin_db_add_column('gpsmap', 'host', ['name' => 'longitude', 'type' => 'decimal(13,10)', 'NULL' => false, 'default' => '0', 'after' => 'availability']);
	api_plugin_db_add_column('gpsmap', 'host', ['name' => 'GPScoverage', 'type' => 'varchar(3)', 'NULL' => false, 'default' => 'on', 'after' => 'availability']);
	api_plugin_db_add_column('gpsmap', 'host', ['name' => 'start', 'type' => 'int(3)', 'NULL' => false, 'default' => '0', 'after' => 'availability']);
	api_plugin_db_add_column('gpsmap', 'host', ['name' => 'stop', 'type' => 'int(3)', 'NULL' => false, 'default' => '360', 'after' => 'availability']);
	api_plugin_db_add_column('gpsmap', 'host', ['name' => 'groupnum', 'type' => 'int(3)', 'NULL' => false, 'default' => '0', 'after' => 'availability']);
	api_plugin_db_add_column('gpsmap', 'host', ['name' => 'rdistance', 'type' => 'decimal(10,6)', 'NULL' => false, 'default' => '0', 'after' => 'availability']);

	$data                  = [];
	$data['columns'][]     = ['name' => 'templateID', 'type' => 'int(11)', 'NULL' => true];
	$data['columns'][]     = ['name' => 'templateName', 'type' => 'varchar(100)', 'NULL' => true];
	$data['columns'][]     = ['name' => 'upimage', 'type' => 'varchar(255)', 'NULL' => true];
	$data['columns'][]     = ['name' => 'recoverimage', 'type' => 'varchar(255)', 'NULL' => true];
	$data['columns'][]     = ['name' => 'downimage', 'type' => 'varchar(255)', 'NULL' => true];
	$data['columns'][]     = ['name' => 'AP', 'type' => 'int(1)', 'NULL' => true];
	$data['type']          = 'InnoDB';
	$data['unique_keys'][] = ['name' => 'templateID', 'columns' => 'templateID', 'unique' => true];
	$data['comment']       = 'Map icon template';
	api_plugin_db_table_create('gpsmap', 'gpsmap_templates', $data);

	$data                  = [];
	/* The fixed-width hash supports the full DNS hostname length without
	 * exceeding the 767-byte key limit on older InnoDB installations. */
	$data['columns'][]     = ['name' => 'hostname_hash', 'type' => 'binary(32)', 'NULL' => false];
	$data['columns'][]     = ['name' => 'hostname', 'type' => 'varchar(255)', 'NULL' => false, 'default' => ''];
	$data['columns'][]     = ['name' => 'address', 'type' => 'varchar(45)', 'NULL' => false, 'default' => ''];
	/* Nullable DATETIME columns behave consistently even when older MySQL or
	 * MariaDB servers run with explicit_defaults_for_timestamp disabled. */
	$data['columns'][]     = ['name' => 'refreshed_at', 'type' => 'datetime', 'NULL' => true, 'default' => null];
	$data['columns'][]     = ['name' => 'attempted_at', 'type' => 'datetime', 'NULL' => true, 'default' => null];
	$data['columns'][]     = ['name' => 'failure_count', 'type' => 'int(5) unsigned', 'NULL' => false, 'default' => '0'];
	$data['primary']       = 'hostname_hash';
	$data['keys'][]        = ['name' => 'refreshed_at', 'columns' => 'refreshed_at'];
	$data['keys'][]        = ['name' => 'attempted_at', 'columns' => 'attempted_at'];
	$data['type']          = 'InnoDB';
	$data['comment']       = 'Asynchronously refreshed gpsmap hostname addresses';
	api_plugin_db_table_create('gpsmap', 'plugin_gpsmap_dns_cache', $data);
	api_plugin_db_add_column('gpsmap', 'plugin_gpsmap_dns_cache', ['name' => 'attempted_at', 'type' => 'datetime', 'NULL' => true, 'default' => null, 'after' => 'refreshed_at']);
	api_plugin_db_add_column('gpsmap', 'plugin_gpsmap_dns_cache', ['name' => 'failure_count', 'type' => 'int(5) unsigned', 'NULL' => false, 'default' => '0', 'after' => 'attempted_at']);

	/* The Cacti helpers do not report failure consistently, so confirm the
	 * schema directly rather than trusting their return values. */
	foreach (['latitude', 'longitude', 'GPScoverage', 'start', 'stop', 'groupnum', 'rdistance'] as $column) {
		if (!db_column_exists('host', $column)) {
			return false;
		}
	}

	return db_table_exists('gpsmap_templates')
		&& db_table_exists('plugin_gpsmap_dns_cache')
		&& db_column_exists('plugin_gpsmap_dns_cache', 'hostname_hash')
		&& db_column_exists('plugin_gpsmap_dns_cache', 'attempted_at')
		&& db_column_exists('plugin_gpsmap_dns_cache', 'failure_count');
}
