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

$GLOBALS['gpsmap_test_pass'] = 0;
$GLOBALS['gpsmap_test_fail'] = 0;

/* Rows the db stubs hand back.  Tests overwrite these per case. */
$GLOBALS['gpsmap_stub_rows']     = array();
$GLOBALS['gpsmap_stub_settings'] = array('base_url' => 'https://cacti.example/');

function assert_equal($label, $expected, $actual) {
	if ($expected === $actual) {
		echo "PASS  $label\n";
		$GLOBALS['gpsmap_test_pass']++;
	} else {
		echo "FAIL  $label\n";
		echo '      expected: ' . var_export($expected, true) . "\n";
		echo '      actual:   ' . var_export($actual, true) . "\n";
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
	echo "\n";
	echo 'Results: ' . $GLOBALS['gpsmap_test_pass'] . ' passed, ' . $GLOBALS['gpsmap_test_fail'] . " failed\n";

	return $GLOBALS['gpsmap_test_fail'] > 0 ? 1 : 0;
}

/* ------------------------------------------------------------------ */
/* Cacti stubs                                                         */
/* ------------------------------------------------------------------ */

if (!isset($GLOBALS['config'])) {
	$GLOBALS['config'] = array(
		'base_path' => dirname(__DIR__, 3) . '/cacti-stub',
		'url_path'  => '/cacti/',
	);
}

if (!function_exists('cacti_sizeof')) {
	function cacti_sizeof($var) { return is_array($var) ? count($var) : 0; }
}

if (!function_exists('db_fetch_assoc')) {
	function db_fetch_assoc($sql) { return gpsmap_stub_query($sql); }
}

if (!function_exists('db_fetch_assoc_prepared')) {
	function db_fetch_assoc_prepared($sql, $params = array()) { return gpsmap_stub_query($sql); }
}

/* Route each query to a named fixture bucket so a test can set them
 * independently rather than guessing call order. */
function gpsmap_stub_query($sql) {
	if (str_contains($sql, 'FROM `host` AS h')) {
		return $GLOBALS['gpsmap_stub_rows']['hosts'] ?? array();
	}

	if (str_contains($sql, '`AP` = 1')) {
		return $GLOBALS['gpsmap_stub_rows']['towers'] ?? array();
	}

	if (str_contains($sql, '`host_template`')) {
		return $GLOBALS['gpsmap_stub_rows']['templates'] ?? array();
	}

	if (str_contains($sql, 'gpsmap_templates')) {
		return $GLOBALS['gpsmap_stub_rows']['icons'] ?? array();
	}

	return array();
}

if (!function_exists('read_config_option')) {
	function read_config_option($name, $force = false) {
		return $GLOBALS['gpsmap_stub_settings'][$name] ?? '';
	}
}

if (!function_exists('cacti_log')) {
	function cacti_log($message, $stdout = false, $env = '') {
		$GLOBALS['gpsmap_stub_log'][] = $message;
	}
}

if (!function_exists('is_ipaddress')) {
	function is_ipaddress($ip) { return filter_var($ip, FILTER_VALIDATE_IP) !== false; }
}

if (!function_exists('html_escape')) {
	function html_escape($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
}

if (!function_exists('__')) {
	function __($format, ...$args) {
		/* Cacti's last argument is the text domain when more than one is given. */
		if (count($args) > 1) { array_pop($args); }

		return $args === array() ? $format : vsprintf($format, $args);
	}
}

if (!function_exists('__esc')) {
	function __esc($format, ...$args) { return html_escape(__($format, ...$args)); }
}

/* Scratch Cacti root.  class/ and includes/ are symlinked back to the real
 * checkout so the code under test loads unmodified, while XML/ and the icon
 * folder are real directories the tests can write to. */
function gpsmap_test_tmpdir(): string {
	static $dir = null;

	if ($dir !== null) {
		return $dir;
	}

	$dir    = sys_get_temp_dir() . '/gpsmap-tests-' . getmypid();
	$plugin = $dir . '/plugins/gpsmap';
	$repo   = dirname(__DIR__);

	@mkdir($plugin . '/XML', 0777, true);
	@mkdir($plugin . '/images/icons', 0777, true);

	foreach (array('class', 'includes') as $link) {
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
		@unlink($dir . '/' . $entry);
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
