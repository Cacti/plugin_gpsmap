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

if (!register_process_start('gpsmap', 'dns-refresh', 0, 3600)) {
	exit(0);
}

try {
	gpsmap_refresh_dns_cache();
} finally {
	unregister_process('gpsmap', 'dns-refresh', 0);
}
