<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | The upgrade path used to re-run every migration on each request because  |
 | the version it gates on was never persisted.  On a large host table that |
 | is a full rebuild plus a metadata lock, per page load.                   |
 +-------------------------------------------------------------------------+
*/

if (PHP_SAPI !== 'cli') {
	exit;
}

require_once __DIR__ . '/harness.php';

/* Record the DDL the migration issues, and let set_config_option write to the
 * same store read_config_option reads. */
$GLOBALS['gpsmap_stub_ddl'] = array();

if (!function_exists('db_execute')) {
	function db_execute($sql, $log = true, $db_conn = false) {
		$GLOBALS['gpsmap_stub_ddl'][] = $sql;

		return empty($GLOBALS['gpsmap_stub_fail_ddl']);
	}
}

if (!function_exists('db_execute_prepared')) {
	function db_execute_prepared($sql, $params = array(), $log = true, $db_conn = false) {
		$GLOBALS['gpsmap_stub_ddl'][] = $sql;

		return true;
	}
}

if (!function_exists('set_config_option')) {
	function set_config_option($name, $value) {
		$GLOBALS['gpsmap_stub_settings'][$name] = (string) $value;
	}
}

if (!function_exists('db_column_exists')) {
	function db_column_exists($table, $column, $log = true, $db_conn = false) {
		return empty($GLOBALS['gpsmap_stub_missing_column']);
	}
}

if (!function_exists('db_table_exists')) {
	function db_table_exists($table, $log = true, $db_conn = false) { return true; }
}

if (!function_exists('db_index_exists')) {
	function db_index_exists($table, $index, $log = true, $db_conn = false) {
		return $GLOBALS['gpsmap_stub_index_exists'] ?? true;
	}
}

if (!function_exists('db_add_index')) {
	function db_add_index($table, $type, $name, $columns, $log = true, $db_conn = false) {
		$GLOBALS['gpsmap_stub_ddl'][] = 'ADD INDEX ' . $name;

		return true;
	}
}

if (!function_exists('api_plugin_db_add_column')) {
	function api_plugin_db_add_column($plugin, $table, $column) { return true; }
}

if (!function_exists('api_plugin_db_table_create')) {
	function api_plugin_db_table_create($plugin, $table, $data) { return true; }
}

gpsmap_test_use_tmp_root();
$GLOBALS['config']['library_path'] = sys_get_temp_dir();
file_put_contents(sys_get_temp_dir() . '/database.php', "<?php\n");

require_once __DIR__ . '/../setup.php';
require_once __DIR__ . '/../includes/setup/database.php';

$info = plugin_gpsmap_version();

/* First run from an unknown previous version: the migrations fire. */
$GLOBALS['gpsmap_stub_ddl'] = array();
gpsmap_upgrade_database('');
$first = $GLOBALS['gpsmap_stub_ddl'];

assert_true('upgrade: a fresh install runs the column migration', (bool) preg_grep('/ALTER TABLE host CHANGE COLUMN latitude/', $first));
assert_equal('upgrade: no backticked literal default reaches MySQL', array(), preg_grep('/SET DEFAULT `/', $first));

/* The version has to be persisted where gpsmap_check_upgrade() reads it. */
assert_equal(
	'upgrade: version is persisted to the option the guard reads',
	$info['version'],
	read_config_option('plugin_gpsmap_version', true)
);

/* Second run, now that the version matches: no DDL against host at all. */
$GLOBALS['gpsmap_stub_ddl'] = array();
gpsmap_upgrade_database((string) $info['version']);

assert_equal(
	'upgrade: a current install issues no ALTER TABLE',
	array(),
	preg_grep('/ALTER TABLE/', $GLOBALS['gpsmap_stub_ddl'])
);

/* Pre-2.1 installs without the unique index get it added exactly once. */
$GLOBALS['gpsmap_stub_index_exists'] = false;
$GLOBALS['gpsmap_stub_ddl']          = array();
gpsmap_upgrade_database('2.0');
assert_true('upgrade: missing unique index is added', (bool) preg_grep('/ADD INDEX templateID/', $GLOBALS['gpsmap_stub_ddl']));

$GLOBALS['gpsmap_stub_index_exists'] = true;
$GLOBALS['gpsmap_stub_ddl']          = array();
gpsmap_upgrade_database('2.0');
assert_equal('upgrade: existing index is not re-added', array(), preg_grep('/ADD INDEX/', $GLOBALS['gpsmap_stub_ddl']));

/* And the guard itself now goes quiet. */
assert_true(
	'upgrade: the guard is satisfied after one run',
	$info['version'] == read_config_option('plugin_gpsmap_version', true)
);

/* A failed migration must not mark the plugin as current, or the upgrade never
 * runs again and the schema stays behind silently. */
$GLOBALS['gpsmap_stub_settings']['plugin_gpsmap_version'] = '';
$GLOBALS['gpsmap_stub_ddl'] = array();
$GLOBALS['gpsmap_stub_fail_ddl'] = true;

gpsmap_upgrade_database('');

assert_equal('upgrade: a failed migration leaves the version unrecorded', '',
	(string) read_config_option('plugin_gpsmap_version', true));
assert_true('upgrade: the failure is logged',
	str_contains(implode(' ', $GLOBALS['gpsmap_stub_log'] ?? array()), 'did not complete'));

$GLOBALS['gpsmap_stub_fail_ddl'] = false;
$GLOBALS['gpsmap_stub_settings']['plugin_gpsmap_upgrade_retry_after'] = '0';
gpsmap_upgrade_database('');
assert_equal('upgrade: a successful migration records the version',
	$info['version'], read_config_option('plugin_gpsmap_version', true));

/* A failed migration must back off rather than retry from every page view, and
 * must never record the version while the schema is behind. */
$GLOBALS['gpsmap_stub_settings']['plugin_gpsmap_version']            = '';
$GLOBALS['gpsmap_stub_settings']['plugin_gpsmap_upgrade_retry_after'] = '0';
$GLOBALS['gpsmap_stub_fail_ddl'] = true;
$GLOBALS['gpsmap_stub_log']      = array();

gpsmap_upgrade_database('');

assert_equal('upgrade: a failure never records the version', '',
	(string) read_config_option('plugin_gpsmap_version', true));
assert_true('upgrade: a backoff is recorded',
	(int) read_config_option('plugin_gpsmap_upgrade_retry_after', true) > time());

/* While the backoff is live, no further DDL is attempted. */
$GLOBALS['gpsmap_stub_ddl'] = array();
gpsmap_upgrade_database('');
assert_equal('upgrade: the backoff suppresses the retry', array(), $GLOBALS['gpsmap_stub_ddl']);

/* Once it expires and the cause clears, the upgrade completes and re-arms. */
$GLOBALS['gpsmap_stub_settings']['plugin_gpsmap_upgrade_retry_after'] = '0';
$GLOBALS['gpsmap_stub_fail_ddl'] = false;
gpsmap_upgrade_database('');
assert_equal('upgrade: recovery records the version', $info['version'],
	read_config_option('plugin_gpsmap_version', true));
assert_equal('upgrade: recovery clears the backoff', '0',
	(string) read_config_option('plugin_gpsmap_upgrade_retry_after', true));

/* A missing host column means the schema work failed, whatever the helpers say. */
$GLOBALS['gpsmap_stub_settings']['plugin_gpsmap_version']             = '';
$GLOBALS['gpsmap_stub_settings']['plugin_gpsmap_upgrade_retry_after'] = '0';
$GLOBALS['gpsmap_stub_missing_column'] = true;
gpsmap_upgrade_database('');
assert_equal('upgrade: a missing column blocks the version write', '',
	(string) read_config_option('plugin_gpsmap_version', true));
$GLOBALS['gpsmap_stub_missing_column'] = false;

if (!defined('GPSMAP_TEST_SUITE')) {
	exit(gpsmap_test_summary());
}
