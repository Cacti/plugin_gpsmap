<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Regression coverage for the CSP fix in gpsmap_render_region(): the region
 * navigation's "Start Over" and "Print" controls must render as delegated
 * jQuery targets (CSS classes) rather than inline onclick handlers that
 * Cacti's Content-Security-Policy script-src-attr directive blocks.
 *
 * Uses expect() (a real PHPUnit assertion) so the test is not flagged risky
 * and its line coverage of processregion.php is recorded by the gate.
 */

require_once __DIR__ . '/../../setup.php';
require_once __DIR__ . '/../../class/hosts_class.php';
require_once __DIR__ . '/../../includes/polling/functions.php';
require_once __DIR__ . '/../../includes/polling/processregion.php';
require_once __DIR__ . '/../../includes/dns.php';

it('renders the region navigation controls without inline onclick handlers', function () {
	gpsmap_test_use_tmp_root();
	$root = gpsmap_test_tmpdir();

	// An empty estate still publishes the navigation menu, which is enough to
	// exercise the Start Over / Print button markup.
	expect(gpsmap_render_region([[], []], '198.51.100'))->toBeTrue();

	$menu = file_get_contents($root . '/plugins/gpsmap/XML/198.51.100-top.html');

	expect($menu)->toContain('class="gpsmapStartOver"');
	expect($menu)->toContain('class="print gpsmapPrint"');
	expect($menu)->not->toContain('onclick');
});
