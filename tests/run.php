<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Runs every test file in one process and reports a combined result.      |
 |                                                                         |
 | Run: php tests/run.php                                                  |
 +-------------------------------------------------------------------------+
*/

define('GPSMAP_TEST_SUITE', true);

require_once __DIR__ . '/harness.php';

foreach (array('test_functions.php', 'test_host.php', 'test_icons.php', 'test_polling.php') as $file) {
	echo "\n--- $file ---\n";

	require __DIR__ . '/' . $file;
}

exit(gpsmap_test_summary());
