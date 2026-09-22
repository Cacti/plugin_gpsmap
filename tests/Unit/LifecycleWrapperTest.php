<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
*/

/*
 * Unit coverage for the plugin lifecycle contract functions in setup.php
 * that are not already exercised by the isolated-process
 * tests/Integration/UpgradeTest.php probe: plugin_gpsmap_uninstall(),
 * plugin_gpsmap_check_config(), and plugin_gpsmap_upgrade().
 *
 * Both check_config()/upgrade() delegate to gpsmap_check_upgrade(), so
 * the stored version is set to match the current plugin version here to
 * skip its (already thoroughly tested elsewhere) migration branch and
 * keep these tests focused on the wrapper functions' own contract.
 */

beforeAll(function () {
	require_once __DIR__ . '/../../setup.php';
});

beforeEach(function () {
	$GLOBALS['gpsmap_stub_settings'] = array(
		'plugin_gpsmap_version' => plugin_gpsmap_version()['version'],
	);
	$GLOBALS['__test_current_page'] = 'gpsmap.php';
});

it('performs no work on uninstall without raising an error', function () {
	expect(plugin_gpsmap_uninstall())->toBeNull();
});

it('reports the config as always valid', function () {
	expect(plugin_gpsmap_check_config())->toBeTrue();
});

it('reports that no upgrade is pending', function () {
	expect(plugin_gpsmap_upgrade())->toBeFalse();
});
