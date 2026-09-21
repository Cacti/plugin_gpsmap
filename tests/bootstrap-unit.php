<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

/*
 * Test bootstrap.
 *
 * gpsmap's sources expect to be included by Cacti, which has already
 * defined the db_*, request-variable, and logging helpers as plain global
 * functions. Nothing here talks to a database or a network: each Cacti
 * function is declared as a stub that records the call in
 * $GLOBALS['__test_db_calls'] and hands back a safe default. db_fetch_assoc()
 * and read_config_option() instead route through the fixture tables below so
 * individual tests can control what a query or setting returns.
 *
 * The CI workflow checks out a pinned Cacti release next to this plugin so
 * Pest runs against Cacti's own Composer-managed vendor tree (Pest/PHPUnit)
 * instead of a vendor tree local to this plugin. The version check below
 * makes sure that checkout actually matches what tests/.cacti-version
 * expects before any plugin source is loaded.
 *
 * Guarding every declaration with function_exists() keeps this file usable
 * if a future integration suite loads real Cacti first.
 */

$cacti_root = dirname(__DIR__, 3);
$autoload   = $cacti_root . '/include/vendor/autoload.php';
$version    = $cacti_root . '/include/cacti_version';
$expected   = __DIR__ . '/.cacti-version';

if (!is_readable($autoload)) {
	throw new RuntimeException("Cacti Composer autoloader is not readable: $autoload");
}

if (!is_readable($version)) {
	throw new RuntimeException("Cacti version file is not readable: $version");
}

if (!is_readable($expected)) {
	throw new RuntimeException("Expected Cacti version file is not readable: $expected");
}

$cacti_version     = trim((string) file_get_contents($version));
$expected_version  = trim((string) file_get_contents($expected));

if ($cacti_version === '') {
	throw new RuntimeException("Cacti version file is empty: $version");
}

if ($expected_version === '') {
	throw new RuntimeException("Expected Cacti version file is empty: $expected");
}

// The CI workflow tracks a moving branch (1.2.x or develop) rather than a pinned release, so any actual version is accepted.
if (!in_array($expected_version, array('1.2.x', 'develop'), true) && $cacti_version !== $expected_version) {
	throw new RuntimeException("Expected Cacti $expected_version, found $cacti_version in $version");
}

require_once $autoload;

/*
 * base_path has to point at the Cacti root two levels above this plugin:
 * gpsmap's source files build include paths from it at runtime.
 */
$GLOBALS['config'] = array(
	'base_path'       => $cacti_root,
	'url_path'        => '/cacti/',
	'cacti_version'   => $cacti_version,
	'cacti_server_os' => 'unix',
);

$GLOBALS['__test_db_calls'] = array();

/* Rows the db stubs hand back and settings read_config_option() serves.
 * Tests overwrite these per case. */
$GLOBALS['gpsmap_stub_rows']     = array();
$GLOBALS['gpsmap_stub_settings'] = array('base_url' => 'https://cacti.example/');
$GLOBALS['gpsmap_stub_log']      = array();
$GLOBALS['gpsmap_stub_plugins']  = array();

/*
 * Local, throwing assertions for tests ported as a single procedural it()
 * block (large integration-style scenarios where a per-assertion expect()
 * conversion would risk transcription errors). Unlike the old standalone
 * harness these throw on the first failure, so Pest sees a real failure.
 */
if (!function_exists('assert_equal')) {
	function assert_equal($label, $expected, $actual) {
		if ($expected !== $actual) {
			throw new RuntimeException($label . "\n  expected: " . var_export($expected, true) . "\n  actual:   " . var_export($actual, true));
		}
	}
}

if (!function_exists('assert_true')) {
	function assert_true($label, $value) {
		assert_equal($label, true, (bool) $value);
	}
}

if (!function_exists('assert_false')) {
	function assert_false($label, $value) {
		assert_equal($label, false, (bool) $value);
	}
}

if (!function_exists('assert_contains')) {
	function assert_contains($label, $needle, $haystack) {
		assert_true($label, str_contains((string) $haystack, $needle));
	}
}

if (!function_exists('assert_not_contains')) {
	function assert_not_contains($label, $needle, $haystack) {
		assert_false($label, str_contains((string) $haystack, $needle));
	}
}

if (!function_exists('db_execute')) {
	function db_execute($sql) {
		$GLOBALS['__test_db_calls'][] = array('fn' => 'db_execute', 'sql' => $sql, 'params' => array());
		return true;
	}
}

if (!function_exists('db_execute_prepared')) {
	function db_execute_prepared($sql, $params = array(), $log = true, $db_conn = false) {
		$GLOBALS['__test_db_calls'][]     = array('fn' => 'db_execute_prepared', 'sql' => $sql, 'params' => $params);
		$GLOBALS['gpsmap_stub_execute'][] = array($sql, $params);

		return $GLOBALS['gpsmap_stub_execute_result'] ?? true;
	}
}

if (!function_exists('register_process_start')) {
	function register_process_start($process, $task, $id = 0, $timeout = 0) {
		$GLOBALS['gpsmap_stub_process_timeout'] = $timeout;

		return $GLOBALS['gpsmap_stub_process_registration'] ?? true;
	}
}

if (!function_exists('unregister_process')) {
	function unregister_process($process, $task, $id = 0) {
		$GLOBALS['gpsmap_stub_process_unregisters'] = ($GLOBALS['gpsmap_stub_process_unregisters'] ?? 0) + 1;
	}
}

/* Route each query to a named fixture bucket so a test can set them
 * independently rather than guessing call order. */
function gpsmap_stub_query($sql) {
	$GLOBALS['gpsmap_stub_last_sql'] = $sql;

	if (str_contains($sql, 'FROM `host` AS h')) {
		$GLOBALS['gpsmap_stub_host_sql'] = $sql;

		return $GLOBALS['gpsmap_stub_rows']['hosts'] ?? array();
	}

	if (str_contains($sql, '`AP` = 1')) {
		return $GLOBALS['gpsmap_stub_rows']['towers'] ?? array();
	}

	if (str_contains($sql, '`host_template`')) {
		return $GLOBALS['gpsmap_stub_rows']['templates'] ?? array();
	}

	if (str_contains($sql, 'gpsmap_templates')) {
		/* The DNS refresh work queue joins gpsmap_templates against the DNS
		 * cache; route it to its own fixture bucket rather than the icon one. */
		if (str_contains($sql, 'MIN(dc.attempted_at)')) {
			$rows = $GLOBALS['gpsmap_stub_rows']['dns'] ?? array();

			if (is_array($rows) && str_contains($sql, 'INET6_ATON(h.hostname) IS NULL')) {
				$rows = array_values(array_filter($rows,
					static function (array $row): bool {
						return filter_var($row['hostname'], FILTER_VALIDATE_IP) === false;
					}));
			}

			return $rows;
		}

		return $GLOBALS['gpsmap_stub_rows']['icons'] ?? array();
	}

	return array();
}

if (!function_exists('db_fetch_assoc')) {
	function db_fetch_assoc($sql) {
		return gpsmap_stub_query($sql);
	}
}

if (!function_exists('db_fetch_assoc_prepared')) {
	function db_fetch_assoc_prepared($sql, $params = array()) {
		$GLOBALS['gpsmap_stub_last_params'] = $params;

		return gpsmap_stub_query($sql);
	}
}

if (!function_exists('db_table_exists')) {
	function db_table_exists($table, $log = true, $db_conn = false) {
		return !in_array($table, $GLOBALS['gpsmap_stub_missing_tables'] ?? array(), true);
	}
}

if (!function_exists('api_plugin_is_enabled')) {
	function api_plugin_is_enabled($plugin) {
		return in_array($plugin, $GLOBALS['gpsmap_stub_plugins'], true);
	}
}

if (!function_exists('cacti_escapeshellarg')) {
	function cacti_escapeshellarg($arg) {
		return escapeshellarg((string) $arg);
	}
}

if (!function_exists('exec_background')) {
	function exec_background($command, $args) {
		$GLOBALS['gpsmap_stub_background'][] = array($command, $args);
	}
}

if (!function_exists('db_fetch_row')) {
	function db_fetch_row($sql) {
		return array();
	}
}

if (!function_exists('db_fetch_row_prepared')) {
	function db_fetch_row_prepared($sql, $params = array()) {
		return array();
	}
}

if (!function_exists('db_fetch_cell')) {
	function db_fetch_cell($sql) {
		return '';
	}
}

if (!function_exists('db_fetch_cell_prepared')) {
	function db_fetch_cell_prepared($sql, $params = array()) {
		return '';
	}
}

if (!function_exists('db_index_exists')) {
	function db_index_exists($table, $index) {
		return false;
	}
}

if (!function_exists('db_column_exists')) {
	function db_column_exists($table, $column) {
		return empty($GLOBALS['gpsmap_stub_missing_column'])
			&& !in_array($table . '.' . $column, $GLOBALS['gpsmap_stub_missing_columns'] ?? array(), true);
	}
}

if (!function_exists('api_plugin_db_add_column')) {
	function api_plugin_db_add_column($plugin, $table, $data) {
		return true;
	}
}

if (!function_exists('api_plugin_db_table_create')) {
	function api_plugin_db_table_create($plugin, $table, $data) {
		return true;
	}
}

if (!function_exists('read_config_option')) {
	function read_config_option($name, $force = false) {
		return $GLOBALS['gpsmap_stub_settings'][$name] ?? '';
	}
}

if (!function_exists('set_config_option')) {
	function set_config_option($name, $value) {
		$GLOBALS['gpsmap_stub_settings'][$name] = (string) $value;
	}
}

if (!function_exists('html_escape')) {
	function html_escape($string) {
		return htmlspecialchars((string) $string, ENT_QUOTES | ENT_HTML5, 'UTF-8');
	}
}

if (!function_exists('__')) {
	function __($format, ...$args) {
		/* Cacti's last argument is the text domain when more than one is given. */
		if (count($args) > 1) {
			array_pop($args);
		}

		return $args === array() ? $format : vsprintf($format, $args);
	}
}

if (!function_exists('__esc')) {
	function __esc($format, ...$args) {
		return html_escape(__($format, ...$args));
	}
}

if (!function_exists('cacti_log')) {
	function cacti_log($message, $also_print = false, $log_type = '', $level = 0) {
		$GLOBALS['gpsmap_stub_log'][] = $message;
	}
}

if (!function_exists('cacti_sizeof')) {
	function cacti_sizeof($array) {
		return is_array($array) ? count($array) : 0;
	}
}

if (!function_exists('is_realm_allowed')) {
	function is_realm_allowed($realm) {
		return true;
	}
}

if (!function_exists('is_ipaddress')) {
	function is_ipaddress($ip) {
		return filter_var($ip, FILTER_VALIDATE_IP) !== false;
	}
}

/*
 * PHPUnit's failOnWarning does not honor "@" suppression on the expected
 * failure paths this suite exercises (an unwritable directory, a missing
 * icon folder, etc.), so those calls install their own silent handler for
 * their duration instead of relying on "@".
 */
if (!function_exists('gpsmap_test_silence')) {
	function gpsmap_test_silence(callable $fn) {
		set_error_handler(static function () {
			return true;
		});

		try {
			return $fn();
		} finally {
			restore_error_handler();
		}
	}
}

if (!function_exists('raise_message')) {
	function raise_message($id, $text = '', $level = 0) {
	}
}

if (!function_exists('get_request_var')) {
	function get_request_var($name) {
		return '';
	}
}

if (!function_exists('get_nfilter_request_var')) {
	function get_nfilter_request_var($name) {
		return '';
	}
}

if (!function_exists('get_filter_request_var')) {
	function get_filter_request_var($name) {
		return '';
	}
}

if (!function_exists('form_input_validate')) {
	function form_input_validate($value, $name, $regex, $optional, $error) {
		return $value;
	}
}

if (!function_exists('is_error_message')) {
	function is_error_message() {
		return false;
	}
}

if (!function_exists('sql_save')) {
	function sql_save($array, $table, $key = 'id') {
		return isset($array['id']) ? $array['id'] : 1;
	}
}

if (!defined('CACTI_PATH_BASE')) {
	define('CACTI_PATH_BASE', $GLOBALS['config']['base_path']);
}

if (!defined('POLLER_VERBOSITY_LOW')) {
	define('POLLER_VERBOSITY_LOW', 2);
}

if (!defined('POLLER_VERBOSITY_MEDIUM')) {
	define('POLLER_VERBOSITY_MEDIUM', 3);
}

if (!defined('POLLER_VERBOSITY_DEBUG')) {
	define('POLLER_VERBOSITY_DEBUG', 5);
}

if (!defined('POLLER_VERBOSITY_NONE')) {
	define('POLLER_VERBOSITY_NONE', 6);
}

if (!defined('MESSAGE_LEVEL_ERROR')) {
	define('MESSAGE_LEVEL_ERROR', 1);
}

if (!function_exists('plugin_test_read_source')) {
	function plugin_test_read_source($relative_file) {
		$path = realpath(__DIR__ . '/../' . $relative_file);
		if ($path === false) {
			throw new RuntimeException("Unable to resolve required file: {$relative_file}");
		}

		$contents = file_get_contents($path);
		if ($contents === false) {
			throw new RuntimeException("Unable to read required file: {$relative_file}");
		}

		return $contents;
	}
}

/**
 * Load a plugin source file at global scope.
 *
 * Some plugin files define data as file-scope variables that the rest of
 * the plugin reads as globals, and they read $config while doing so.
 * Requiring them from inside a method would make both halves of that
 * method-local, so the require happens here and any variable the file
 * introduced is published to $GLOBALS.
 *
 * @param string $path Absolute path to the file.
 *
 * @return void
 */
function gpsmap_test_load($path) {
	global $config;

	$__before = get_defined_vars();

	require_once $path;

	foreach (get_defined_vars() as $__name => $__value) {
		if (!array_key_exists($__name, $__before) && strncmp($__name, '__', 2) !== 0) {
			$GLOBALS[$__name] = $__value;
		}
	}
}

/*
 * Scratch Cacti root used by the Integration suite. class/ and includes/ are
 * symlinked back to the real checkout so the code under test loads
 * unmodified, while XML/ and the icon folder are real directories the tests
 * can write to.
 */
function gpsmap_test_tmpdir(): string {
	static $dir = null;

	if ($dir !== null) {
		return $dir;
	}

	$dir    = sys_get_temp_dir() . '/gpsmap-tests-' . bin2hex(random_bytes(8));
	$plugin = $dir . '/plugins/gpsmap';
	$repo   = dirname(__DIR__);

	@mkdir($plugin . '/XML', 0700, true);
	@mkdir($plugin . '/images/icons', 0700, true);

	foreach (array('class', 'includes', 'INFO', 'setup.php', 'gpsmap_security.php') as $link) {
		if (!file_exists($plugin . '/' . $link)) {
			@symlink($repo . '/' . $link, $plugin . '/' . $link);
		}
	}

	register_shutdown_function(function () use ($dir) {
		gpsmap_test_rmtree($dir);
	});

	return $dir;
}

function gpsmap_test_rmtree(string $path): void {
	if (is_link($path) || is_file($path)) {
		@unlink($path);

		return;
	}

	if (!is_dir($path)) {
		return;
	}

	foreach (array_diff(scandir($path), array('.', '..')) as $entry) {
		gpsmap_test_rmtree($path . '/' . $entry);
	}

	@rmdir($path);
}

/* Replaces the icon fixture folder with exactly the given file names. */
function gpsmap_test_icons(array $names): string {
	$dir = gpsmap_test_tmpdir() . '/plugins/gpsmap/images/icons';

	foreach (array_diff(scandir($dir), array('.', '..')) as $entry) {
		// A prior test may have left a directory entry (e.g. to exercise
		// "hides directories with image-like names"); unlink() alone cannot
		// remove that, and PHPUnit's failOnWarning does not tolerate the
		// resulting warning even when suppressed with "@".
		gpsmap_test_rmtree($dir . '/' . $entry);
	}

	foreach ($names as $name) {
		file_put_contents($dir . '/' . $name, 'x');
	}

	return $dir;
}

/* Points $config at the scratch root for the duration of a test file. */
function gpsmap_test_use_tmp_root(): void {
	$GLOBALS['config']['base_path'] = gpsmap_test_tmpdir();
}

/* A stream that accepts fopen then reports a short write, so the truncated
 * artefact path can be exercised without /dev/full or a full filesystem. */
class GpsmapShortWriteStream {
	public $context;

	public function stream_open($path, $mode, $options, &$opened_path) { return true; }
	public function stream_write($data) { return max(0, strlen($data) - 1); }
	public function stream_close() { return true; }
	public function stream_flush() { return true; }
	public function stream_eof() { return true; }
	public function stream_stat() { return array(); }
	public function url_stat($path, $flags) { return array(); }
	public function unlink($path) { return true; }
	public function rename($from, $to) { return false; }
}

if (!in_array('gpsmapshort', stream_get_wrappers(), true)) {
	stream_wrapper_register('gpsmapshort', 'GpsmapShortWriteStream');
}
