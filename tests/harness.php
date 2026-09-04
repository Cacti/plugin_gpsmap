<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Shared assertions and Cacti stubs for the standalone test suite.  No    |
 | Composer, no framework: the suite has to run from a plain PHP CLI on a  |
 | machine that only has the plugin checked out.                           |
 +-------------------------------------------------------------------------+
*/

/* Never reachable over HTTP.  Cacti deploys plugins inside the web root, so
 * plugins/gpsmap/tests/ would otherwise be a public endpoint that resolves DNS
 * and writes to the filesystem. */
if (PHP_SAPI !== 'cli') {
	exit;
}

$GLOBALS['gpsmap_test_pass'] = 0;
$GLOBALS['gpsmap_test_fail'] = 0;

// Rows the db stubs hand back.  Tests overwrite these per case.
$GLOBALS['gpsmap_stub_rows']     = [];
$GLOBALS['gpsmap_stub_settings'] = ['base_url' => 'https://cacti.example/'];
$GLOBALS['gpsmap_stub_plugins']  = [];

function assert_equal($label, $expected, $actual) {
	if ($expected === $actual) {
		print "PASS  $label\n";
		$GLOBALS['gpsmap_test_pass']++;
	} else {
		print "FAIL  $label\n";
		print '      expected: ' . var_export($expected, true) . "\n";
		print '      actual:   ' . var_export($actual, true) . "\n";
		$GLOBALS['gpsmap_test_fail']++;
	}
}

function assert_true($label, $value) {
	assert_equal($label, true, (bool) $value);
}

function assert_false($label, $value) {
	assert_equal($label, false, (bool) $value);
}

function assert_contains($label, string $needle, string $haystack) {
	assert_true($label, str_contains($haystack, $needle));
}

function assert_not_contains($label, string $needle, string $haystack) {
	assert_false($label, str_contains($haystack, $needle));
}

function gpsmap_test_summary(): int {
	print "\n";
	print 'Results: ' . $GLOBALS['gpsmap_test_pass'] . ' passed, ' . $GLOBALS['gpsmap_test_fail'] . " failed\n";

	return $GLOBALS['gpsmap_test_fail'] > 0 ? 1 : 0;
}

// ------------------------------------------------------------------
// Cacti stubs
// ------------------------------------------------------------------

if (!isset($GLOBALS['config'])) {
	$GLOBALS['config'] = [
		'base_path' => dirname(__DIR__, 3) . '/cacti-stub',
		'url_path'  => '/cacti/',
	];
}

if (!function_exists('cacti_sizeof')) {
	function cacti_sizeof($var) {
		return is_array($var) ? count($var) : 0;
	}
}

if (!function_exists('db_fetch_assoc')) {
	function db_fetch_assoc($sql) {
		return gpsmap_stub_query($sql);
	}
}

if (!function_exists('db_fetch_assoc_prepared')) {
	function db_fetch_assoc_prepared($sql, $params = []) {
		$GLOBALS['gpsmap_stub_last_params'] = $params;

		return gpsmap_stub_query($sql);
	}
}

if (!function_exists('db_table_exists')) {
	function db_table_exists($table, $log = true, $db_conn = false) {
		return !in_array($table, $GLOBALS['gpsmap_stub_missing_tables'] ?? [], true);
	}
}

if (!function_exists('db_column_exists')) {
	function db_column_exists($table, $column, $log = true, $db_conn = false) {
		return empty($GLOBALS['gpsmap_stub_missing_column'])
			&& !in_array($table . '.' . $column, $GLOBALS['gpsmap_stub_missing_columns'] ?? [], true);
	}
}

if (!function_exists('api_plugin_is_enabled')) {
	function api_plugin_is_enabled($plugin) {
		return in_array($plugin, $GLOBALS['gpsmap_stub_plugins'], true);
	}
}

/* Route each query to a named fixture bucket so a test can set them
 * independently rather than guessing call order. */
function gpsmap_stub_query($sql) {
	$GLOBALS['gpsmap_stub_last_sql'] = $sql;

	if (str_contains($sql, 'FROM `host` AS h')) {
		$GLOBALS['gpsmap_stub_host_sql'] = $sql;

		return $GLOBALS['gpsmap_stub_rows']['hosts'] ?? [];
	}

	if (str_contains($sql, '`AP` = 1')) {
		return $GLOBALS['gpsmap_stub_rows']['towers'] ?? [];
	}

	if (str_contains($sql, '`host_template`')) {
		return $GLOBALS['gpsmap_stub_rows']['templates'] ?? [];
	}

	if (str_contains($sql, 'gpsmap_templates')) {
		if (str_contains($sql, 'MIN(dc.attempted_at)')) {
			$rows = $GLOBALS['gpsmap_stub_rows']['dns'] ?? [];

			if (is_array($rows) && str_contains($sql, 'INET6_ATON(h.hostname) IS NULL')) {
				$rows = array_values(array_filter($rows,
					static fn (array $row): bool => filter_var($row['hostname'], FILTER_VALIDATE_IP) === false));
			}

			return $rows;
		}

		return $GLOBALS['gpsmap_stub_rows']['icons'] ?? [];
	}

	return [];
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

if (!function_exists('cacti_escapeshellarg')) {
	function cacti_escapeshellarg($arg) {
		return escapeshellarg((string) $arg);
	}
}

if (!function_exists('cacti_log')) {
	function cacti_log($message, $stdout = false, $env = '') {
		$GLOBALS['gpsmap_stub_log'][] = $message;
	}
}

if (!function_exists('exec_background')) {
	function exec_background($command, $args) {
		$GLOBALS['gpsmap_stub_background'][] = [$command, $args];
	}
}

if (!function_exists('is_ipaddress')) {
	function is_ipaddress($ip) {
		return filter_var($ip, FILTER_VALIDATE_IP) !== false;
	}
}

if (!function_exists('html_escape')) {
	function html_escape($text) {
		return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
	}
}

if (!function_exists('__')) {
	function __($format, ...$args) {
		// Cacti's last argument is the text domain when more than one is given.
		if (count($args) > 1) {
			array_pop($args);
		}

		return $args === [] ? $format : vsprintf($format, $args);
	}
}

if (!function_exists('__esc')) {
	function __esc($format, ...$args) {
		return html_escape(__($format, ...$args));
	}
}

/* Scratch Cacti root.  class/ and includes/ are symlinked back to the real
 * checkout so the code under test loads unmodified, while XML/ and the icon
 * folder are real directories the tests can write to. */
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

	foreach (['class', 'includes', 'INFO', 'setup.php', 'gpsmap_security.php'] as $link) {
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

	foreach (array_diff(scandir($path), ['.', '..']) as $entry) {
		gpsmap_test_rmtree($path . '/' . $entry);
	}

	@rmdir($path);
}

// Replaces the icon fixture folder with exactly the given file names.
function gpsmap_test_icons(array $names): string {
	$dir = gpsmap_test_tmpdir() . '/plugins/gpsmap/images/icons';

	foreach (array_diff(scandir($dir), ['.', '..']) as $entry) {
		@unlink($dir . '/' . $entry);
	}

	foreach ($names as $name) {
		file_put_contents($dir . '/' . $name, 'x');
	}

	return $dir;
}

// Points $config at the scratch root for the duration of a test file.
function gpsmap_test_use_tmp_root(): void {
	$GLOBALS['config']['base_path'] = gpsmap_test_tmpdir();
}

/* A stream that accepts fopen then reports a short write, so the truncated
 * artefact path can be exercised without /dev/full or a full filesystem. */
class GpsmapShortWriteStream {
	public $context;

	public function stream_open($path, $mode, $options, &$opened_path) {
		return true;
	}
	public function stream_write($data) {
		return max(0, strlen($data) - 1);
	}
	public function stream_close() {
		return true;
	}
	public function stream_flush() {
		return true;
	}
	public function stream_eof() {
		return true;
	}
	public function stream_stat() {
		return [];
	}
	public function url_stat($path, $flags) {
		return [];
	}
	public function unlink($path) {
		return true;
	}
	public function rename($from, $to) {
		return false;
	}
}

if (!in_array('gpsmapshort', stream_get_wrappers(), true)) {
	stream_wrapper_register('gpsmapshort', 'GpsmapShortWriteStream');
}
