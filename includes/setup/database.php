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

function gpsmap_upgrade_database(string $old = ''): void {
	global $config;

	include_once($config['library_path'] . '/database.php');

	$v = plugin_gpsmap_version();

	$retry_after = (int) read_config_option('plugin_gpsmap_upgrade_retry_after', true);

	if ($retry_after > time()) {
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
			$ok = db_add_index('gpsmap_templates', 'unique', 'templateID', array('templateID')) && $ok;
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
		db_execute_prepared('UPDATE plugin_config SET version = ? WHERE directory = "gpsmap"', array($v['version']));
		set_config_option('plugin_gpsmap_version', $v['version']);
		set_config_option('plugin_gpsmap_upgrade_retry_after', '0');

		return;
	}

	/* Never record the version here: doing so would report a schema the upgrade
	 * knows is incomplete as current, and nothing would re-arm the migration
	 * when the transient cause clears.  Back off instead, so a lock timeout
	 * cannot turn ordinary page views into sustained contention on host. */
	set_config_option('plugin_gpsmap_upgrade_retry_after', (string) (time() + GPSMAP_UPGRADE_RETRY_SECONDS));

	cacti_log('WARNING: gpsmap schema upgrade did not complete and will be retried after ' . GPSMAP_UPGRADE_RETRY_SECONDS . ' seconds.  If it keeps failing, run the ALTER TABLE statements in plugins/gpsmap/includes/setup/database.php by hand; the plugin will then record itself current on the next attempt.', false, 'GPSMAP');
}

function gpsmap_setup_database(): bool {
	$v = plugin_gpsmap_version();

	api_plugin_db_add_column('gpsmap', 'host', array('name' => 'latitude', 'type' => 'decimal(13,10)', 'NULL' => false, 'default' => '0', 'after' => 'availability'));
	api_plugin_db_add_column('gpsmap', 'host', array('name' => 'longitude', 'type' => 'decimal(13,10)', 'NULL' => false, 'default' => '0', 'after' => 'availability'));
	api_plugin_db_add_column('gpsmap', 'host', array('name' => 'GPScoverage', 'type' => 'varchar(3)', 'NULL' => false, 'default' => 'on', 'after' => 'availability'));
	api_plugin_db_add_column('gpsmap', 'host', array('name' => 'start', 'type' => 'int(3)', 'NULL' => false, 'default' => '0', 'after' => 'availability'));
	api_plugin_db_add_column('gpsmap', 'host', array('name' => 'stop', 'type' => 'int(3)', 'NULL' => false, 'default' => '360', 'after' => 'availability'));
	api_plugin_db_add_column('gpsmap', 'host', array('name' => 'groupnum', 'type' => 'int(3)', 'NULL' => false, 'default' => '0', 'after' => 'availability'));
	api_plugin_db_add_column('gpsmap', 'host', array('name' => 'rdistance', 'type' => 'decimal(10,6)', 'NULL' => false, 'default' => '0', 'after' => 'availability'));

	$data = array();
	$data['columns'][] = array('name' => 'templateID', 'type' => 'int(11)', 'NULL' => true);
	$data['columns'][] = array('name' => 'templateName', 'type' => 'varchar(100)', 'NULL' => true);
	$data['columns'][] = array('name' => 'upimage', 'type' => 'varchar(255)', 'NULL' => true);
	$data['columns'][] = array('name' => 'recoverimage', 'type' => 'varchar(255)', 'NULL' => true);
	$data['columns'][] = array('name' => 'downimage', 'type' => 'varchar(255)', 'NULL' => true);
	$data['columns'][] = array('name' => 'AP', 'type' => 'int(1)', 'NULL' => true);
	$data['type'] = 'InnoDB';
	$data['unique_keys'][] = array('name' => 'templateID' , 'columns' => 'templateID', 'unique' => true);
	$data['comment'] = 'Map icon template';
	api_plugin_db_table_create('gpsmap', 'gpsmap_templates', $data);

	/* The Cacti helpers do not report failure consistently, so confirm the
	 * schema directly rather than trusting their return values. */
	foreach (array('latitude', 'longitude', 'GPScoverage', 'start', 'stop', 'groupnum', 'rdistance') as $column) {
		if (!db_column_exists('host', $column)) {
			return false;
		}
	}

	return db_table_exists('gpsmap_templates');


}

