#!/usr/bin/env php
<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
*/

chdir(__DIR__ . '/../../');
require_once('./include/cli_check.php');
require_once($config['base_path'] . '/plugins/gpsmap/includes/dns.php');

try {
	$status = gpsmap_dns_refresh_exit_code();
} catch (Throwable $e) {
	cacti_log('ERROR: gpsmap DNS worker terminated unexpectedly: ' . $e->getMessage(), false, 'GPSMAP');
	$status = 1;
}

exit($status);
