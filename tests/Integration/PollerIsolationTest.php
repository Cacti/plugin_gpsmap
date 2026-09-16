<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti includes a plugin's setup.php only on install, uninstall and       |
 | config check.  Hooks load the file they were registered with.  So        |
 | anything the poller path touches has to resolve without setup.php, or    |
 | every poll cycle fatals on an undefined symbol.                          |
 +-------------------------------------------------------------------------+
*/

it('resolves the poller path without setup.php and produces KML styles', function () {
	// Load the poller entry exactly as Cacti's hook would: the registered file
	// alone, in a child process with setup.php absent from the include set.
	$probe = <<<'PROBE'
	<?php
	$root = %s;
	require $root . '/tests/bootstrap-unit.php';
	require $root . '/includes/polling/functions.php';
	require $root . '/includes/polling/processregion.php';

	if (in_array($root . '/setup.php', get_included_files(), true)) {
		fwrite(STDERR, "setup.php was pulled in\n");
		exit(2);
	}

	/* The two symbols the poller path needs. */
	if (!defined('GPSMAP_ICON_EXTENSIONS')) { fwrite(STDERR, "GPSMAP_ICON_EXTENSIONS undefined\n"); exit(3); }
	if (!function_exists('gpsmap_icon_identifier')) { fwrite(STDERR, "gpsmap_icon_identifier undefined\n"); exit(4); }

	/* And it has to actually run: iconskml() reads the icon folder and both
	 * symbols are used inside it. */
	gpsmap_test_use_tmp_root();
	gpsmap_test_icons(array('Green.png', 'ap.v2.png'));
	require $root . '/includes/polling/iconskml.php';
	$kml = iconskml();

	if (strpos($kml, '<Style id="Green">') === false) { fwrite(STDERR, "iconskml produced nothing\n"); exit(5); }

	echo "OK\n";
	PROBE;

	$file = gpsmap_test_tmpdir() . '/poller_probe.php';
	file_put_contents($file, sprintf($probe, var_export(dirname(__DIR__, 2), true)));

	$output = array();
	$status = 0;
	exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file) . ' 2>&1', $output, $status);

	expect($status)->toBe(0, 'probe said: ' . implode(' | ', $output));
	expect(in_array('OK', $output, true))->toBeTrue();
});
