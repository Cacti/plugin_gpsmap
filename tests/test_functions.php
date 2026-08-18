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

/* Never reachable over HTTP.  Cacti deploys plugins inside the web root, so
 * plugins/gpsmap/tests/ would otherwise be a public endpoint that resolves DNS
 * and writes to the filesystem. */
if (PHP_SAPI !== 'cli') {
	exit;
}

require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/../includes/polling/functions.php';

// ------------------------------------------------------------------
// parseToXML — encoding correctness
// ------------------------------------------------------------------

// Basic entity encoding.
assert_equal('parseToXML: ampersand',      '&amp;',       parseToXML('&'));
assert_equal('parseToXML: less-than',      '&lt;',        parseToXML('<'));
assert_equal('parseToXML: greater-than',   '&gt;',        parseToXML('>'));
assert_equal('parseToXML: double-quote',   '&quot;',      parseToXML('"'));
// ENT_XML1 uses &apos; for single quotes (XML 1.0 named entity, not &#039;).
assert_equal('parseToXML: single-quote',   '&apos;',      parseToXML("'"));

/* The old implementation double-encoded: '<br>' became '&amp;lt;br&amp;gt;'
 * because & was replaced AFTER < and >.  The new implementation must not
 * double-encode. */
assert_equal(
	'parseToXML: no double-encoding of ampersand-introduced-by-lt',
	'&lt;br&gt;',
	parseToXML('<br>')
);

// An already-encoded string must not be double-encoded.
assert_equal(
	'parseToXML: no double-encoding of existing entity',
	'&amp;amp;',
	parseToXML('&amp;')
);

// Plain strings pass through unchanged.
assert_equal('parseToXML: plain string',   'hello world', parseToXML('hello world'));
assert_equal('parseToXML: numeric string', '42',          parseToXML(42));
assert_equal('parseToXML: empty string',   '',            parseToXML(''));

// ------------------------------------------------------------------
// calcKm — distance calculation and backward-compat alias
// ------------------------------------------------------------------

// Same point — distance must be 0.
assert_equal('calcKm: same point is 0', 0.0, calcKm(0, 0, 0, 0));

// Known approximate: London (51.5, -0.1) to Paris (48.8, 2.3) ~ 341 km.
$dist = calcKm(51.5, -0.1, 48.8, 2.3);
assert_true('calcKm: London-Paris between 330 and 360 km', $dist >= 330 && $dist <= 360);

/* calcMeters() compat wrapper must delegate to calcKm() and return the same
 * result (the old name was wrong — it always returned km, not metres). */
assert_equal(
	'calcMeters: compat wrapper returns same value as calcKm',
	calcKm(51.5, -0.1, 48.8, 2.3),
	calcMeters(51.5, -0.1, 48.8, 2.3)
);

// ------------------------------------------------------------------
// Subnet parameter validation regex (mirrors gpsmap.php logic)
// ------------------------------------------------------------------

$valid_re   = '/^(?:all|[0-9]+(?:\.[0-9]+){0,2}|v6-(?:16-[0-9a-f]{4}|32-[0-9a-f]{8}|48-[0-9a-f]{12}))$/';

// Valid values.
assert_true('subnet regex: "all"',              preg_match($valid_re, 'all'));
assert_true('subnet regex: IPv6 token',          preg_match($valid_re, 'v6-32-20010db8'));
assert_true('subnet regex: dotted subnet',      preg_match($valid_re, '10.0.0'));
assert_false('subnet regex: arbitrary region',  preg_match($valid_re, 'region1'));

// Values that must be rejected (path traversal and other dangerous input).
assert_false('subnet regex: ".."',              preg_match($valid_re, '..'));
assert_false('subnet regex: "."',               preg_match($valid_re, '.'));
assert_false('subnet regex: "../etc/passwd"',   preg_match($valid_re, '../etc/passwd'));
assert_false('subnet regex: leading dot',       preg_match($valid_re, '.foo'));
assert_false('subnet regex: trailing dot',      preg_match($valid_re, 'foo.'));
assert_false('subnet regex: null byte',         preg_match($valid_re, "foo\x00bar"));
assert_false('subnet regex: slash',             preg_match($valid_re, 'a/b'));
assert_false('subnet regex: backslash',         preg_match($valid_re, 'a\\b'));
assert_false('subnet regex: percent-encoded',   preg_match($valid_re, '..%2fetc'));
assert_false('subnet regex: space',             preg_match($valid_re, 'a b'));

// ------------------------------------------------------------------
// parseToXML — multibyte UTF-8
// ------------------------------------------------------------------

assert_equal('parseToXML: multibyte CJK', '日本語', parseToXML('日本語'));
assert_equal('parseToXML: multibyte with entities', '&lt;日本語&gt;', parseToXML('<日本語>'));

// ------------------------------------------------------------------
// calcKm — negative coordinates (southern/western hemispheres)
// ------------------------------------------------------------------

/* Southern hemisphere: Cape Town (-33.9, 18.4) to Buenos Aires (-34.6, -58.4).
 * The flat-earth approximation in calcKm overestimates at large longitude
 * separations, so we verify it returns a finite positive value. */
$dist_neg = calcKm(-33.9, 18.4, -34.6, -58.4);
assert_true('calcKm: negative coords (CapeTown-BuenosAires) > 0', $dist_neg > 0);
assert_true('calcKm: negative coords is finite and positive', is_finite($dist_neg) && $dist_neg > 0);

// ------------------------------------------------------------------
// calcKm — symmetry: distance(A,B) === distance(B,A)
// ------------------------------------------------------------------

assert_equal(
	'calcKm: symmetry London-Paris',
	calcKm(51.5, -0.1, 48.8, 2.3),
	calcKm(48.8, 2.3, 51.5, -0.1)
);

assert_equal(
	'calcKm: symmetry CapeTown-BuenosAires',
	calcKm(-33.9, 18.4, -34.6, -58.4),
	calcKm(-34.6, -58.4, -33.9, 18.4)
);

// ------------------------------------------------------------------
// calcKm — antipodal points (max distance ~20,000 km)
// ------------------------------------------------------------------

$dist_anti = calcKm(0, 0, 0, 180);
assert_true('calcKm: antipodal not NaN', !is_nan($dist_anti));
assert_true('calcKm: antipodal > 0', $dist_anti > 0);
assert_true('calcKm: antipodal <= 21000 km', $dist_anti <= 21000);

// ------------------------------------------------------------------
// coordCheck — validation
// ------------------------------------------------------------------

assert_equal('coordCheck: valid positive',    '45.123',  coordCheck('45.123'));
assert_equal('coordCheck: valid negative',    '-90.000', coordCheck('-90.000'));
assert_equal('coordCheck: valid 3-digit',     '180.000', coordCheck('180.000'));
assert_equal('coordCheck: invalid alpha',     '0.000',   coordCheck('abc'));
assert_equal('coordCheck: invalid empty',     '0.000',   coordCheck(''));
assert_equal('coordCheck: valid zero',        '0.000',   coordCheck('0.000'));
assert_equal('coordCheck: negative longitude', '-122.4194', coordCheck('-122.4194'));

// ------------------------------------------------------------------
if (!defined('GPSMAP_TEST_SUITE')) {
	exit(gpsmap_test_summary());
}
