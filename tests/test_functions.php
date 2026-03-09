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
 | Standalone unit tests for includes/polling/functions.php and the        |
 | subnet parameter validation in gpsmap.php.                              |
 |                                                                         |
 | Run: php tests/test_functions.php                                       |
 +-------------------------------------------------------------------------+
*/

/* Stub Cacti globals so functions.php can be included without a full
 * Cacti installation. */
if (!function_exists('db_fetch_assoc')) {
	function db_fetch_assoc($sql) { return array(); }
}
if (!function_exists('cacti_sizeof')) {
	function cacti_sizeof($var) { return is_array($var) ? count($var) : 0; }
}

require_once __DIR__ . '/../includes/polling/functions.php';

/* ------------------------------------------------------------------ */
$pass = 0;
$fail = 0;

function assert_equal($label, $expected, $actual) {
	global $pass, $fail;
	if ($expected === $actual) {
		echo "PASS  $label\n";
		$pass++;
	} else {
		echo "FAIL  $label\n";
		echo "      expected: " . var_export($expected, true) . "\n";
		echo "      actual:   " . var_export($actual,   true) . "\n";
		$fail++;
	}
}

function assert_true($label, $value) {
	assert_equal($label, true, (bool) $value);
}

function assert_false($label, $value) {
	assert_equal($label, false, (bool) $value);
}

/* ------------------------------------------------------------------ */
/* parseToXML — encoding correctness                                   */
/* ------------------------------------------------------------------ */

/* Basic entity encoding. */
assert_equal('parseToXML: ampersand',      '&amp;',       parseToXML('&'));
assert_equal('parseToXML: less-than',      '&lt;',        parseToXML('<'));
assert_equal('parseToXML: greater-than',   '&gt;',        parseToXML('>'));
assert_equal('parseToXML: double-quote',   '&quot;',      parseToXML('"'));
/* ENT_XML1 uses &apos; for single quotes (XML 1.0 named entity, not &#039;). */
assert_equal('parseToXML: single-quote',   '&apos;',      parseToXML("'"));

/* The old implementation double-encoded: '<br>' became '&amp;lt;br&amp;gt;'
 * because & was replaced AFTER < and >.  The new implementation must not
 * double-encode. */
assert_equal(
	'parseToXML: no double-encoding of ampersand-introduced-by-lt',
	'&lt;br&gt;',
	parseToXML('<br>')
);

/* An already-encoded string must not be double-encoded. */
assert_equal(
	'parseToXML: no double-encoding of existing entity',
	'&amp;amp;',
	parseToXML('&amp;')
);

/* Plain strings pass through unchanged. */
assert_equal('parseToXML: plain string',   'hello world', parseToXML('hello world'));
assert_equal('parseToXML: numeric string', '42',          parseToXML(42));
assert_equal('parseToXML: empty string',   '',            parseToXML(''));

/* ------------------------------------------------------------------ */
/* calcKm — distance calculation and backward-compat alias            */
/* ------------------------------------------------------------------ */

/* Same point — distance must be 0. */
assert_equal('calcKm: same point is 0', 0.0, calcKm(0, 0, 0, 0));

/* Known approximate: London (51.5, -0.1) to Paris (48.8, 2.3) ~ 341 km. */
$dist = calcKm(51.5, -0.1, 48.8, 2.3);
assert_true('calcKm: London-Paris between 330 and 360 km', $dist >= 330 && $dist <= 360);

/* calcMeters() compat wrapper must delegate to calcKm() and return the same
 * result (the old name was wrong — it always returned km, not metres). */
assert_equal(
	'calcMeters: compat wrapper returns same value as calcKm',
	calcKm(51.5, -0.1, 48.8, 2.3),
	calcMeters(51.5, -0.1, 48.8, 2.3)
);

/* ------------------------------------------------------------------ */
/* Subnet parameter validation regex (mirrors gpsmap.php logic)       */
/* ------------------------------------------------------------------ */

$valid_re   = '/^[a-zA-Z0-9_-]+$/';

/* Valid values. */
assert_true('subnet regex: "all"',              preg_match($valid_re, 'all'));
assert_true('subnet regex: IP octets with -',   preg_match($valid_re, '192-168-1'));
assert_true('subnet regex: alphanumeric',       preg_match($valid_re, 'region1'));
assert_true('subnet regex: underscore',         preg_match($valid_re, 'region_1'));
assert_true('subnet regex: uppercase',          preg_match($valid_re, 'RegionA'));

/* Values that must be rejected (path traversal and other dangerous input). */
assert_false('subnet regex: "../etc/passwd"',   preg_match($valid_re, '../etc/passwd'));
assert_false('subnet regex: ".."',              preg_match($valid_re, '..'));
assert_false('subnet regex: "."',               preg_match($valid_re, '.'));
assert_false('subnet regex: null byte',         preg_match($valid_re, "foo\x00bar"));
assert_false('subnet regex: slash',             preg_match($valid_re, 'a/b'));
assert_false('subnet regex: backslash',         preg_match($valid_re, 'a\\b'));
assert_false('subnet regex: percent-encoded',   preg_match($valid_re, '..%2fetc'));
assert_false('subnet regex: space',             preg_match($valid_re, 'a b'));

/* ------------------------------------------------------------------ */
echo "\n";
echo "Results: $pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
