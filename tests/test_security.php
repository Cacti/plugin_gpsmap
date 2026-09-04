<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Tests for gpsmap_normalize_icon_name(), which gates the icon names the  |
 | template admin form is allowed to store.                                |
 +-------------------------------------------------------------------------+
*/

if (PHP_SAPI !== 'cli') {
	exit;
}

require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/../gpsmap_security.php';

// getIcons() returns a name-keyed map, so membership is an isset() check.
$icons = ['Green.png' => 'Green.png', 'Red.png' => 'Red.png', 'Orange.png' => 'Orange.png'];

assert_equal('normalize: known name passes through', 'Red.png',   gpsmap_normalize_icon_name('Red.png', $icons));
assert_equal('normalize: unknown name falls back',   'Green.png', gpsmap_normalize_icon_name('Evil.png', $icons));
assert_equal('normalize: empty string falls back',   'Green.png', gpsmap_normalize_icon_name('', $icons));
assert_equal('normalize: null falls back',           'Green.png', gpsmap_normalize_icon_name(null, $icons));
assert_equal('normalize: array falls back',          'Green.png', gpsmap_normalize_icon_name(['Red.png'], $icons));
assert_equal('normalize: integer falls back',        'Green.png', gpsmap_normalize_icon_name(1, $icons));
assert_equal('normalize: empty icon set falls back', 'Green.png', gpsmap_normalize_icon_name('Red.png', []));

// Traversal and injection attempts are rejected by the same membership test.
assert_equal('normalize: traversal rejected', 'Green.png', gpsmap_normalize_icon_name('../../../etc/passwd', $icons));
assert_equal('normalize: null byte rejected', 'Green.png', gpsmap_normalize_icon_name("Red.png\0.txt", $icons));

// Each call site in gpstemplates.php supplies its own default.
assert_equal('normalize: recover default', 'Orange.png', gpsmap_normalize_icon_name('nope', $icons, 'Orange.png'));
assert_equal('normalize: down default',    'Red.png',    gpsmap_normalize_icon_name('nope', $icons, 'Red.png'));

assert_equal('template delete: accepts only positive numeric checkbox ids', [7, 42],
	gpsmap_template_ids_from_request([
		'chk_7'   => 'on',
		'chk_42'  => 'on',
		'chk_007' => 'duplicate numeric id',
		'chk_0'   => 'invalid zero',
		'chk_abc' => 'invalid text',
		'chk_'    => 'missing id',
		'action'  => 'delete',
		3         => 'integer key',
	]));

// ------------------------------------------------------------------
// coordCheck boundaries against the anchored pattern
// ------------------------------------------------------------------

require_once __DIR__ . '/../includes/polling/functions.php';

assert_equal('coordCheck: four integer digits rejected', '0.000',  coordCheck('1234.5'));
assert_equal('coordCheck: no decimal point rejected',    '0.000',  coordCheck('51'));
assert_equal('coordCheck: trailing point rejected',      '0.000',  coordCheck('51.'));
assert_equal('coordCheck: surrounding space trimmed',    '51.5',   coordCheck(' 51.5 '));
assert_equal('coordCheck: double negative rejected',     '0.000',  coordCheck('--5.0'));
assert_equal('coordCheck: exponent rejected',            '0.000',  coordCheck('1.0e2'));
assert_equal('coordCheck: three integer digits allowed', '180.0',  coordCheck('180.0'));

// ------------------------------------------------------------------
// calcKm degenerate and extreme inputs
// ------------------------------------------------------------------

assert_equal('calcKm: identical coordinates are zero', 0.0, calcKm(51.5, -0.12, 51.5, -0.12));
assert_true('calcKm: antimeridian pair is finite', is_finite(calcKm(0.0, 179.9, 0.0, -179.9)));

if (!defined('GPSMAP_TEST_SUITE')) {
	exit(gpsmap_test_summary());
}
