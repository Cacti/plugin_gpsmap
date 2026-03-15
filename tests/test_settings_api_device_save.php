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
 | Standalone regression tests for includes/setup/settings.php             |
 |                                                                         |
 | Run: php tests/test_settings_api_device_save.php                        |
 +-------------------------------------------------------------------------+
 */

$gpsmap_test_request = [];
$gpsmap_validate_calls = [];

if (!function_exists('isset_request_var')) {
	function isset_request_var($name) {
		global $gpsmap_test_request;

		return array_key_exists($name, $gpsmap_test_request);
	}
}

if (!function_exists('get_nfilter_request_var')) {
	function get_nfilter_request_var($name) {
		global $gpsmap_test_request;

		return $gpsmap_test_request[$name] ?? '';
	}
}

if (!function_exists('form_input_validate')) {
	function form_input_validate($value, $field, $default = '', $allow_empty = true, $data_type = 3) {
		global $gpsmap_validate_calls;

		$gpsmap_validate_calls[] = [
			'value'       => $value,
			'field'       => $field,
			'default'     => $default,
			'allow_empty' => $allow_empty,
			'data_type'   => $data_type
		];

		return sprintf('validated:%s:%s', $field, $value);
	}
}

require_once __DIR__ . '/../includes/setup/settings.php';

$pass = 0;
$fail = 0;

function assert_equal($label, $expected, $actual) {
	global $pass, $fail;

	if ($expected === $actual) {
		echo "PASS  $label\n";
		$pass++;
	} else {
		echo "FAIL  $label\n";
		echo '      expected: ' . var_export($expected, true) . "\n";
		echo '      actual:   ' . var_export($actual, true) . "\n";
		$fail++;
	}
}

/* ------------------------------------------------------------------ */
/* All fields present                                                   */
/* ------------------------------------------------------------------ */
global $gpsmap_test_request, $gpsmap_validate_calls;
$gpsmap_test_request = [
	'GPScoverage' => 'on',
	'latitude'    => '45.1',
	'longitude'   => '-73.2',
	'start'       => '1',
	'stop'        => '10',
	'rdistance'   => '123.45',
	'groupnum'    => '3'
];
$gpsmap_validate_calls = [];

$save = gpsmap_api_device_save([]);

assert_equal('GPScoverage is enabled when request flag is set', 'on', $save['GPScoverage']);
assert_equal('latitude validation result', 'validated:latitude:45.1', $save['latitude']);
assert_equal('longitude validation result', 'validated:longitude:-73.2', $save['longitude']);
assert_equal('start validation result', 'validated:start:1', $save['start']);
assert_equal('stop validation result', 'validated:stop:10', $save['stop']);
assert_equal('rdistance validation result', 'validated:distance:123.45', $save['rdistance']);
assert_equal('groupnum validation result', 'validated:groupnum:3', $save['groupnum']);
assert_equal('validation call count with all fields set', 6, count($gpsmap_validate_calls));
assert_equal('rdistance uses distance validation key', 'distance', $gpsmap_validate_calls[4]['field']);

/* ------------------------------------------------------------------ */
/* Missing optional request values                                      */
/* ------------------------------------------------------------------ */
$gpsmap_test_request = [];
$gpsmap_validate_calls = [];

$save = gpsmap_api_device_save([]);

assert_equal('GPScoverage is disabled when request flag is missing', 'off', $save['GPScoverage']);
assert_equal('latitude empty validation result', 'validated:latitude:', $save['latitude']);
assert_equal('longitude empty validation result', 'validated:longitude:', $save['longitude']);
assert_equal('start empty validation result', 'validated:start:', $save['start']);
assert_equal('stop empty validation result', 'validated:stop:', $save['stop']);
assert_equal('rdistance empty validation result', 'validated:rdistance:', $save['rdistance']);
assert_equal('groupnum empty validation result', 'validated:groupnum:', $save['groupnum']);
assert_equal('validation call count with no fields set', 6, count($gpsmap_validate_calls));
assert_equal('latitude missing request validates empty string', '', $gpsmap_validate_calls[0]['value']);
assert_equal('rdistance missing request uses legacy rdistance validation key', 'rdistance', $gpsmap_validate_calls[4]['field']);

echo "\n";
echo "Results: $pass passed, $fail failed\n";

exit($fail > 0 ? 1 : 0);
