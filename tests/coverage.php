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
 | Line coverage for the plugin's library and poller code.  Uses Xdebug    |
 | directly so the suite keeps its no-Composer contract.                   |
 |                                                                         |
 | Run: XDEBUG_MODE=coverage php tests/coverage.php                        |
 +-------------------------------------------------------------------------+
*/

/* Never reachable over HTTP.  Cacti deploys plugins inside the web root, so
 * plugins/gpsmap/tests/ would otherwise be a public endpoint that resolves DNS
 * and writes to the filesystem. */
if (PHP_SAPI !== 'cli') {
	exit;
}

if (!function_exists('xdebug_start_code_coverage')) {
	fwrite(STDERR, "Xdebug with XDEBUG_MODE=coverage is required.\n");
	exit(2);
}

/* Files that hold logic.  The web entry points (gpsmap.php, gpstemplates.php,
 * print.php) chdir() to the Cacti root and include
 * include/auth.php, so they cannot execute outside a real installation and are
 * deliberately out of scope here. */
$targets = [
	'class/hosts_class.php',
	'gpsmap_security.php',
	'includes/dns.php',
	'includes/setup/database.php',
	'includes/polling.php',
	'includes/polling/functions.php',
	'includes/polling/processregion.php',
	'includes/polling/coveragexml.php',
	'includes/polling/kmlcreation.php',
	'includes/polling/iconskml.php',
	'includes/icons.php',
	'includes/customicons.php',
];

/* run.php ends in exit(), so the report runs from a shutdown
 * handler and preserves the suite's own exit status when it fails. */
register_shutdown_function(function () use ($targets) {
	gpsmap_coverage_report($targets);
});

xdebug_start_code_coverage(XDEBUG_CC_UNUSED | XDEBUG_CC_DEAD_CODE);

require __DIR__ . '/run.php';

function gpsmap_coverage_report(array $targets): void {
	$status = 0;

	$coverage = xdebug_get_code_coverage();
	xdebug_stop_code_coverage();

	$root       = dirname(__DIR__);
	$totalLines = 0;
	$missing    = [];
	$uncovered  = [];
	$hitLines   = 0;
	$rows       = [];

	foreach ($targets as $rel) {
		$abs = $root . '/' . $rel;

		if (!isset($coverage[$abs])) {
			/* A target that never executed would otherwise score 0/0 and drop
			 * out of the total, letting the gate pass on a file the suite
			 * never loaded. */
			$rows[]    = [$rel, 0, 0, 0.0];
			$missing[] = $rel;

			continue;
		}

		$executable = 0;
		$hit        = 0;

		foreach ($coverage[$abs] as $line => $state) {
			// 1 = executed, -1 = executable but not executed, -2 = dead code.
			if ($state === -2) {
				continue;
			}

			$executable++;

			if ($state === 1) {
				$hit++;
			} else {
				$uncovered[$rel][] = $line;
			}
		}

		$totalLines += $executable;
		$hitLines   += $hit;
		$rows[]      = [$rel, $hit, $executable, $executable ? $hit / $executable * 100 : 0.0];
	}

	print "\nLine coverage\n";
	print str_repeat('-', 62) . "\n";

	foreach ($rows as $r) {
		printf("%-42s %4d/%-4d %6.1f%%\n", $r[0], $r[1], $r[2], $r[3]);
	}

	print str_repeat('-', 62) . "\n";
	$pct = $totalLines ? $hitLines / $totalLines * 100 : 0.0;
	printf("%-42s %4d/%-4d %6.1f%%\n\n", 'TOTAL', $hitLines, $totalLines, $pct);

	if ($missing !== []) {
		printf("FAIL: never executed, so not counted: %s\n", implode(', ', $missing));
		exit(1);
	}

	foreach ($uncovered as $rel => $lines) {
		printf("MISS: %s:%s\n", $rel, implode(',', $lines));
	}

	$threshold = (float) (getenv('GPSMAP_COVERAGE_MIN') ?: 100);

	if ($pct + 0.001 < $threshold) {
		printf("FAIL: coverage %.1f%% is below the %.1f%% threshold\n", $pct, $threshold);
		$status = 1;
	}

	if ($status !== 0) {
		exit($status);
	}
}
