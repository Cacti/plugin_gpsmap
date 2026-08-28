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

/* Never reachable over HTTP.  Cacti deploys plugins inside the web root, so
 * plugins/gpsmap/tests/ would otherwise be a public endpoint that resolves DNS
 * and writes to the filesystem. */
if (PHP_SAPI !== 'cli') {
	exit;
}

define('GPSMAP_TEST_SUITE', true);

require_once __DIR__ . '/harness.php';

foreach (['test_functions.php', 'test_host.php', 'test_security.php', 'test_icons.php', 'test_poller_isolation.php', 'test_upgrade.php', 'test_polling.php'] as $file) {
	print "\n--- $file ---\n";

	require __DIR__ . '/' . $file;
}

exit(gpsmap_test_summary());
