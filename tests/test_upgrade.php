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
$GLOBALS['gpsmap_stub_ddl'] = [];

if (!function_exists('db_execute')) {
	function db_execute($sql, $log = true, $db_conn = false) {
		$GLOBALS['gpsmap_stub_ddl'][] = $sql;

		return empty($GLOBALS['gpsmap_stub_fail_ddl']);
	}
}

if (!function_exists('db_execute_prepared')) {
	function db_execute_prepared($sql, $params = [], $log = true, $db_conn = false) {
		$GLOBALS['gpsmap_stub_ddl'][] = $sql;

		if (isset($GLOBALS['gpsmap_stub_execute'])) {
			$GLOBALS['gpsmap_stub_execute'][] = [$sql, $params];
		}

		if (!empty($GLOBALS['gpsmap_stub_reject_double_quoted_literals']) && str_contains($sql, '"gpsmap"')) {
			return false;
		}

		return $GLOBALS['gpsmap_stub_execute_result'] ?? true;
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
	function db_table_exists($table, $log = true, $db_conn = false) {
		return true;
	}
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
	function api_plugin_db_add_column($plugin, $table, $column) {
		return true;
	}
}

if (!function_exists('api_plugin_db_table_create')) {
	function api_plugin_db_table_create($plugin, $table, $data) {
		$GLOBALS['gpsmap_stub_tables'][$table] = $data;
		$sql                                   = 'CREATE TABLE `' . $table . '` (';
		$definitions                           = [];

		/* Mirror Cacti's column renderer so schema tests assert the SQL emitted by
		 * the supported helper contract, not only the plugin's input array. */
		foreach ($data['columns'] as $column) {
			$definition = '`' . $column['name'] . '` ' . $column['type'];

			if (isset($column['NULL']) && $column['NULL'] == false) {
				$definition .= ' NOT NULL';
			}

			if (isset($column['NULL']) && $column['NULL'] == true && !isset($column['default'])) {
				$definition .= ' default NULL';
			}

			if (isset($column['default'])) {
				$definition .= ' default ' . (is_numeric($column['default'])
					? $column['default']
					: "'" . $column['default'] . "'");
			}

			$definitions[] = $definition;
		}

		$GLOBALS['gpsmap_stub_ddl'][] = $sql . implode(', ', $definitions) . ') ENGINE = ' . $data['type'];

		return true;
	}
}

if (!function_exists('api_plugin_register_hook')) {
	function api_plugin_register_hook(...$args) {
	}
}

if (!function_exists('api_plugin_register_realm')) {
	function api_plugin_register_realm(...$args) {
	}
}

if (!function_exists('get_current_page')) {
	function get_current_page() {
		return $GLOBALS['gpsmap_stub_current_page'] ?? 'gpsmap.php';
	}
}

gpsmap_test_use_tmp_root();
$GLOBALS['config']['library_path'] = sys_get_temp_dir();
file_put_contents(sys_get_temp_dir() . '/database.php', "<?php\n");

require_once __DIR__ . '/../setup.php';
require_once __DIR__ . '/../includes/setup/database.php';

$info = plugin_gpsmap_version();

// A successful fresh install records its schema version before any page check.
$GLOBALS['gpsmap_stub_settings']['plugin_gpsmap_version'] = '';
$GLOBALS['gpsmap_stub_ddl']                               = [];
$GLOBALS['gpsmap_stub_execute']                           = [];
plugin_gpsmap_install();
assert_equal('install: a successful schema install records the current version', $info['version'],
	read_config_option('plugin_gpsmap_version', true));
assert_equal('install: plugin_config version update uses two placeholders',
	'UPDATE plugin_config SET version = ? WHERE directory = ?', $GLOBALS['gpsmap_stub_execute'][0][0]);
assert_equal('install: plugin_config directory is bound safely under ANSI_QUOTES',
	[$info['version'], 'gpsmap'], $GLOBALS['gpsmap_stub_execute'][0][1]);

$GLOBALS['gpsmap_stub_settings']['plugin_gpsmap_version']  = '';
$GLOBALS['gpsmap_stub_missing_column']                     = true;
$GLOBALS['gpsmap_stub_log']                                = [];
$GLOBALS['gpsmap_stub_execute']                            = [];
plugin_gpsmap_install();
assert_equal('install: a failed schema install writes no version', '',
	read_config_option('plugin_gpsmap_version', true));
assert_equal('install: a failed schema install skips the plugin_config version write', [],
	$GLOBALS['gpsmap_stub_execute']);
assert_true('install: a failed schema install logs the database failure',
	(bool) preg_grep('/ERROR: gpsmap installation could not create or verify/', $GLOBALS['gpsmap_stub_log']));
unset($GLOBALS['gpsmap_stub_missing_column']);
$GLOBALS['gpsmap_stub_settings']['plugin_gpsmap_version'] = $info['version'];

$GLOBALS['gpsmap_stub_current_page'] = 'plugins.php';
$GLOBALS['gpsmap_stub_ddl']          = [];
gpsmap_check_upgrade();
assert_equal('install: the first plugins.php check issues no ALTER TABLE', [],
	preg_grep('/ALTER TABLE/', $GLOBALS['gpsmap_stub_ddl']));
$GLOBALS['gpsmap_stub_current_page']                      = 'gpsmap.php';
$GLOBALS['gpsmap_stub_settings']['plugin_gpsmap_version'] = '';
unset($GLOBALS['gpsmap_stub_execute']);

// First run from an unknown previous version: the migrations fire.
$GLOBALS['gpsmap_stub_ddl']                           = [];
$GLOBALS['gpsmap_stub_reject_double_quoted_literals'] = true;
gpsmap_upgrade_database('');
$first = $GLOBALS['gpsmap_stub_ddl'];
unset($GLOBALS['gpsmap_stub_reject_double_quoted_literals']);

assert_true('upgrade: a fresh install runs the column migration', (bool) preg_grep('/ALTER TABLE host CHANGE COLUMN latitude/', $first));
assert_equal('upgrade: no backticked literal default reaches MySQL', [], preg_grep('/SET DEFAULT `/', $first));
assert_equal('upgrade: plugin_config update remains valid under ANSI_QUOTES', $info['version'],
	read_config_option('plugin_gpsmap_version', true));
assert_equal('upgrade: DNS cache key fits legacy InnoDB limits', 'binary(32)',
	$GLOBALS['gpsmap_stub_tables']['plugin_gpsmap_dns_cache']['columns'][0]['type']);
assert_equal('upgrade: DNS cache retains the full configured hostname', 'varchar(255)',
	$GLOBALS['gpsmap_stub_tables']['plugin_gpsmap_dns_cache']['columns'][1]['type']);
assert_equal('upgrade: DNS cache freshness avoids implicit TIMESTAMP defaults', 'datetime',
	$GLOBALS['gpsmap_stub_tables']['plugin_gpsmap_dns_cache']['columns'][3]['type']);
assert_equal('upgrade: DNS cache attempts avoid implicit TIMESTAMP defaults', 'datetime',
	$GLOBALS['gpsmap_stub_tables']['plugin_gpsmap_dns_cache']['columns'][4]['type']);
$dnsCacheDdl = implode("\n", preg_grep('/CREATE TABLE `plugin_gpsmap_dns_cache`/', $first));
assert_contains('upgrade: rendered refresh time is nullable DATETIME', '`refreshed_at` datetime default NULL', $dnsCacheDdl);
assert_contains('upgrade: rendered attempt time is nullable DATETIME', '`attempted_at` datetime default NULL', $dnsCacheDdl);
assert_not_contains('upgrade: rendered DNS cache DDL has no TIMESTAMP dependency', 'timestamp', strtolower($dnsCacheDdl));

// The version has to be persisted where gpsmap_check_upgrade() reads it.
assert_equal(
	'upgrade: version is persisted to the option the guard reads',
	$info['version'],
	read_config_option('plugin_gpsmap_version', true)
);

// Second run, now that the version matches: no DDL against host at all.
$GLOBALS['gpsmap_stub_ddl'] = [];
gpsmap_upgrade_database((string) $info['version']);

assert_equal(
	'upgrade: a current install issues no ALTER TABLE',
	[],
	preg_grep('/ALTER TABLE/', $GLOBALS['gpsmap_stub_ddl'])
);

// Pre-2.1 installs without the unique index get it added exactly once.
$GLOBALS['gpsmap_stub_index_exists'] = false;
$GLOBALS['gpsmap_stub_ddl']          = [];
gpsmap_upgrade_database('2.0');
assert_true('upgrade: missing unique index is added', (bool) preg_grep('/ADD INDEX templateID/', $GLOBALS['gpsmap_stub_ddl']));

$GLOBALS['gpsmap_stub_index_exists'] = true;
$GLOBALS['gpsmap_stub_ddl']          = [];
gpsmap_upgrade_database('2.0');
assert_equal('upgrade: existing index is not re-added', [], preg_grep('/ADD INDEX/', $GLOBALS['gpsmap_stub_ddl']));

// And the guard itself now goes quiet.
assert_true(
	'upgrade: the guard is satisfied after one run',
	$info['version'] == read_config_option('plugin_gpsmap_version', true)
);

/* A failed migration must not mark the plugin as current, or the upgrade never
 * runs again and the schema stays behind silently. */
$GLOBALS['gpsmap_stub_settings']['plugin_gpsmap_version'] = '';
$GLOBALS['gpsmap_stub_ddl']                               = [];
$GLOBALS['gpsmap_stub_fail_ddl']                          = true;

gpsmap_upgrade_database('');

assert_equal('upgrade: a failed migration leaves the version unrecorded', '',
	(string) read_config_option('plugin_gpsmap_version', true));
assert_true('upgrade: the failure is logged',
	str_contains(implode(' ', $GLOBALS['gpsmap_stub_log'] ?? []), 'did not complete'));

$GLOBALS['gpsmap_stub_fail_ddl']                                      = false;
$GLOBALS['gpsmap_stub_settings']['plugin_gpsmap_upgrade_retry_after'] = '0';
gpsmap_upgrade_database('');
assert_equal('upgrade: a successful migration records the version',
	$info['version'], read_config_option('plugin_gpsmap_version', true));

/* A failed migration must back off rather than retry from every page view, and
 * must never record the version while the schema is behind. */
$GLOBALS['gpsmap_stub_settings']['plugin_gpsmap_version']             = '';
$GLOBALS['gpsmap_stub_settings']['plugin_gpsmap_upgrade_retry_after'] = '0';
$GLOBALS['gpsmap_stub_fail_ddl']                                      = true;
$GLOBALS['gpsmap_stub_log']                                           = [];

gpsmap_upgrade_database('');

assert_equal('upgrade: a failure never records the version', '',
	(string) read_config_option('plugin_gpsmap_version', true));
assert_true('upgrade: a backoff is recorded',
	(int) read_config_option('plugin_gpsmap_upgrade_retry_after', true) > time());

// While the backoff is live, no further DDL is attempted.
$GLOBALS['gpsmap_stub_ddl'] = [];
$GLOBALS['gpsmap_stub_log'] = [];
gpsmap_upgrade_database('');
assert_equal('upgrade: the backoff suppresses the retry', [], $GLOBALS['gpsmap_stub_ddl']);
assert_true('upgrade: the deferred retry is logged',
	(bool) preg_grep('/retry is deferred/', $GLOBALS['gpsmap_stub_log']));

foreach ([[1, 600], [4, 3600]] as [$priorFailures, $expectedDelay]) {
	$GLOBALS['gpsmap_stub_settings']['plugin_gpsmap_upgrade_failures']    = (string) $priorFailures;
	$GLOBALS['gpsmap_stub_settings']['plugin_gpsmap_upgrade_retry_after'] = '0';
	$beforeRetry                                                          = time();
	gpsmap_upgrade_database('');
	$retryAfter = (int) read_config_option('plugin_gpsmap_upgrade_retry_after', true);
	assert_true('upgrade: failure backoff grows and caps at ' . $expectedDelay . ' seconds',
		$retryAfter >= $beforeRetry + $expectedDelay && $retryAfter <= time() + $expectedDelay);
}

// An explicit operator upgrade bypasses the automatic retry backoff.
$GLOBALS['gpsmap_stub_fail_ddl'] = false;
$GLOBALS['gpsmap_stub_ddl']      = [];
gpsmap_upgrade_database('', true);
assert_true('upgrade: an operator retry bypasses the backoff',
	(bool) preg_grep('/ALTER TABLE/', $GLOBALS['gpsmap_stub_ddl']));
assert_equal('upgrade: an operator retry records the version', $info['version'],
	read_config_option('plugin_gpsmap_version', true));

/* Automatic requests stop issuing schema DDL after a bounded number of
 * failures. An explicit Plugin Management action remains the recovery path. */
$GLOBALS['gpsmap_stub_settings']['plugin_gpsmap_version']             = '';
$GLOBALS['gpsmap_stub_settings']['plugin_gpsmap_upgrade_retry_after'] = '0';
$GLOBALS['gpsmap_stub_settings']['plugin_gpsmap_upgrade_failures']    = (string) GPSMAP_UPGRADE_MAX_FAILURES;
$GLOBALS['gpsmap_stub_ddl']                                           = [];
$GLOBALS['gpsmap_stub_log']                                           = [];
gpsmap_upgrade_database('');
assert_equal('upgrade: repeated failures suspend automatic DDL', [], $GLOBALS['gpsmap_stub_ddl']);
assert_true('upgrade: suspended automatic DDL is logged',
	(bool) preg_grep('/suspended after repeated failures/', $GLOBALS['gpsmap_stub_log']));

$GLOBALS['gpsmap_stub_ddl'] = [];
gpsmap_upgrade_database('', true);
assert_true('upgrade: an explicit retry bypasses the failure limit',
	(bool) preg_grep('/ALTER TABLE/', $GLOBALS['gpsmap_stub_ddl']));
assert_equal('upgrade: a successful explicit retry clears the failure count', '0',
	(string) read_config_option('plugin_gpsmap_upgrade_failures', true));

/* Plugin Management invokes the explicit upgrade hook from plugins.php. That
 * operator action must bypass both the page filter and the retry backoff. */
$GLOBALS['gpsmap_stub_current_page']                                  = 'plugins.php';
$GLOBALS['gpsmap_stub_settings']['plugin_gpsmap_version']             = '';
$GLOBALS['gpsmap_stub_settings']['plugin_gpsmap_upgrade_retry_after'] = (string) (time() + 300);
$GLOBALS['gpsmap_stub_ddl']                                           = [];
gpsmap_check_upgrade(true);
assert_true('upgrade: Plugin Management bypasses the page filter',
	(bool) preg_grep('/ALTER TABLE/', $GLOBALS['gpsmap_stub_ddl']));
assert_equal('upgrade: Plugin Management records the version', $info['version'],
	read_config_option('plugin_gpsmap_version', true));
$GLOBALS['gpsmap_stub_current_page'] = 'gpsmap.php';

// The normal plugins.php configuration check also performs a pending upgrade.
$GLOBALS['gpsmap_stub_current_page']                                  = 'plugins.php';
$GLOBALS['gpsmap_stub_settings']['plugin_gpsmap_version']             = '';
$GLOBALS['gpsmap_stub_settings']['plugin_gpsmap_upgrade_retry_after'] = '0';
$GLOBALS['gpsmap_stub_ddl']                                           = [];
gpsmap_check_upgrade();
assert_true('upgrade: plugins.php automatically runs a pending migration',
	(bool) preg_grep('/ALTER TABLE/', $GLOBALS['gpsmap_stub_ddl']));
assert_equal('upgrade: plugins.php records the completed version', $info['version'],
	read_config_option('plugin_gpsmap_version', true));
$GLOBALS['gpsmap_stub_current_page'] = 'gpsmap.php';

// Once it expires and the cause clears, the upgrade completes and re-arms.
$GLOBALS['gpsmap_stub_settings']['plugin_gpsmap_version']             = '';
$GLOBALS['gpsmap_stub_settings']['plugin_gpsmap_upgrade_retry_after'] = '0';
$GLOBALS['gpsmap_stub_fail_ddl']                                      = false;
gpsmap_upgrade_database('');
assert_equal('upgrade: recovery records the version', $info['version'],
	read_config_option('plugin_gpsmap_version', true));
assert_equal('upgrade: recovery clears the backoff', '0',
	(string) read_config_option('plugin_gpsmap_upgrade_retry_after', true));

// A missing host column means the schema work failed, whatever the helpers say.
$GLOBALS['gpsmap_stub_settings']['plugin_gpsmap_version']             = '';
$GLOBALS['gpsmap_stub_settings']['plugin_gpsmap_upgrade_retry_after'] = '0';
$GLOBALS['gpsmap_stub_missing_column']                                = true;
gpsmap_upgrade_database('');
assert_equal('upgrade: a missing column blocks the version write', '',
	(string) read_config_option('plugin_gpsmap_version', true));
$GLOBALS['gpsmap_stub_missing_column'] = false;

if (!defined('GPSMAP_TEST_SUITE')) {
	exit(gpsmap_test_summary());
}
