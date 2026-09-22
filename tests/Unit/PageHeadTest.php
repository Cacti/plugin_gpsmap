<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
*/

/*
 * Unit coverage for gpsmap_page_head() in setup.php.
 */

beforeAll(function () {
	require_once __DIR__ . '/../../setup.php';
});

beforeEach(function () {
	$GLOBALS['gpsmap_stub_settings'] = array();
});

it('omits the Google Maps API key when none is configured', function () {
	ob_start();
	gpsmap_page_head();
	$output = ob_get_clean();

	expect($output)->toContain('maps.googleapis.com/maps/api/js?libraries=geometry');
	expect($output)->not->toContain('key=');
	expect($output)->toContain('GPSMaps.js');
	expect($output)->toContain('infobubble.js');
});

it('includes the configured Google Maps API key, URL-encoded', function () {
	$GLOBALS['gpsmap_stub_settings']['gpsmap_apikey'] = 'my key/value';

	ob_start();
	gpsmap_page_head();
	$output = ob_get_clean();

	expect($output)->toContain('key=' . rawurlencode('my key/value') . '&amp;libraries=geometry');
});
