<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Exercise the deployed CLI entry point, including its process exit code. |
 +-------------------------------------------------------------------------+
*/

if (PHP_SAPI !== 'cli') {
	exit;
}

require_once __DIR__ . '/harness.php';

$root   = gpsmap_test_tmpdir();
$plugin = $root . '/plugins/gpsmap';
@mkdir($root . '/include', 0700, true);
copy(dirname(__DIR__) . '/gpsmap_dns.php', $plugin . '/gpsmap_dns.php');

$bootstrap = <<<'PHP'
<?php
$config = ['base_path' => %s];
$GLOBALS['gpsmap_test_log'] = %s;

function register_process_start($process, $task, $id = 0, $timeout = 0) {
	return ($GLOBALS['argv'][1] ?? '') !== 'fail';
}

function unregister_process($process, $task, $id = 0) {
}

function db_fetch_assoc_prepared($sql, $params = []) {
	if (($GLOBALS['argv'][1] ?? '') === 'throw') {
		throw new RuntimeException('simulated worker failure');
	}

	return [];
}

function db_execute_prepared($sql, $params = []) {
	return true;
}

function set_config_option($name, $value) {
}

function cacti_log($message, $stdout = false, $env = '') {
	file_put_contents($GLOBALS['gpsmap_test_log'], $message . PHP_EOL, FILE_APPEND);
}
PHP;

file_put_contents($root . '/include/cli_check.php', sprintf(
	$bootstrap,
	var_export($root, true),
	var_export($root . '/gpsmap-worker.log', true)
));

foreach (['success' => 0, 'fail' => 1, 'throw' => 1] as $mode => $expected) {
	$output = [];
	$status = 0;
	exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($plugin . '/gpsmap_dns.php') . ' ' . escapeshellarg($mode) . ' 2>&1', $output, $status);
	assert_equal('dns CLI: ' . $mode . ' worker exit status', $expected, $status);
}

assert_contains('dns CLI: an unexpected worker failure is logged', 'simulated worker failure',
	file_get_contents($root . '/gpsmap-worker.log'));

if (!defined('GPSMAP_TEST_SUITE')) {
	exit(gpsmap_test_summary());
}
