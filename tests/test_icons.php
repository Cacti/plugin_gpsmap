<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Tests for icon name handling.  An icon's base name is emitted as a      |
 | JavaScript assignment target and as a KML Style id, so a name that is   |
 | not a bare identifier has to be dropped rather than printed.            |
 +-------------------------------------------------------------------------+
*/

require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/../setup.php';
require_once __DIR__ . '/../includes/polling/iconskml.php';

gpsmap_test_use_tmp_root();

/* ------------------------------------------------------------------ */
/* gpsmap_icon_identifier                                              */
/* ------------------------------------------------------------------ */

assert_equal('identifier: simple name',      'Green',   gpsmap_icon_identifier('Green.png'));
assert_equal('identifier: underscore start', '_x',      gpsmap_icon_identifier('_x.png'));
assert_equal('identifier: digits allowed',   'Node2',   gpsmap_icon_identifier('Node2.gif'));
assert_equal('identifier: no extension',     'Plain',   gpsmap_icon_identifier('Plain'));
assert_equal('identifier: dotted name',      null,      gpsmap_icon_identifier('ap.v2.png'));
assert_equal('identifier: hyphenated name',  null,      gpsmap_icon_identifier('my-icon.png'));
assert_equal('identifier: leading digit',    null,      gpsmap_icon_identifier('2fast.png'));
assert_equal('identifier: empty',            null,      gpsmap_icon_identifier(''));
assert_equal('identifier: quote injection',  null,      gpsmap_icon_identifier("x';alert(1);//.png"));
assert_equal('identifier: space',            null,      gpsmap_icon_identifier('two words.png'));

/* ------------------------------------------------------------------ */
/* includes/icons.php - JavaScript emitted into an inline <script>      */
/* ------------------------------------------------------------------ */

gpsmap_test_icons(array('Green.png', 'GoogleBlue.PNG', 'ap.v2.png', 'my-icon.png', 'notes.txt', 'noext'));

$cwd = getcwd();
chdir(gpsmap_test_tmpdir());
ob_start();
include __DIR__ . '/../includes/icons.php';
$js = ob_get_clean();
chdir($cwd);

assert_contains('icons.php: emits a safe name',        'gpsmap.Green = {',      $js);
assert_contains('icons.php: keeps on-disk casing',     'GoogleBlue.PNG',        $js);
assert_not_contains('icons.php: drops dotted name',    'gpsmap.ap.v2',          $js);
assert_not_contains('icons.php: drops hyphenated name', 'my-icon',              $js);
assert_not_contains('icons.php: ignores non-images',   'notes',                 $js);
assert_not_contains('icons.php: ignores extensionless', 'gpsmap.noext',         $js);
assert_contains('icons.php: url is json encoded',      '"/cacti/plugins/gpsmap/images/icons/Green.png"', $js);

/* Every emitted assignment target must be a bare identifier. */
preg_match_all('/gpsmap\.([^ ]+) = \{/', $js, $m);
foreach ($m[1] as $name) {
	assert_true('icons.php: identifier is bare - ' . $name, (bool) preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name));
}

/* An unreadable icon directory must not emit anything. */
$cwd = getcwd();
chdir(sys_get_temp_dir());
ob_start();
@include __DIR__ . '/../includes/icons.php';
$empty = ob_get_clean();
chdir($cwd);
assert_not_contains('icons.php: missing directory emits no icons', 'gpsmap.', $empty);

/* ------------------------------------------------------------------ */
/* iconskml() - KML Style ids                                          */
/* ------------------------------------------------------------------ */

gpsmap_test_icons(array('Green.png', 'Red.jpg', 'Amber.jpeg', 'Node.gif', 'ap.v2.png', 'notes.txt'));

$kml = iconskml();

assert_contains('iconskml: png style',      '<Style id="Green">',  $kml);
assert_contains('iconskml: jpg style',      '<Style id="Red">',    $kml);
assert_contains('iconskml: jpeg style',     '<Style id="Amber">',  $kml);
assert_contains('iconskml: gif style',      '<Style id="Node">',   $kml);
assert_not_contains('iconskml: drops dotted name', 'ap.v2',        $kml);
assert_not_contains('iconskml: ignores non-images', 'notes',       $kml);
assert_contains('iconskml: absolute href',  'https://cacti.example//cacti/plugins/gpsmap/images/icons/Green.png', $kml);

/* Missing directory is logged, not fatal. */
$GLOBALS['gpsmap_stub_log']      = array();
$saved                           = $GLOBALS['config']['base_path'];
$GLOBALS['config']['base_path']  = sys_get_temp_dir() . '/gpsmap-no-such-root';
assert_equal('iconskml: missing directory returns empty', '', @iconskml());
assert_true('iconskml: logs the missing directory', str_contains($GLOBALS['gpsmap_stub_log'][0] ?? '', 'could not open icon directory'));
$GLOBALS['config']['base_path'] = $saved;

/* ------------------------------------------------------------------ */
/* customicons.php - property-access position, degrades to undefined   */
/* ------------------------------------------------------------------ */

$GLOBALS['gpsmap_stub_rows']['icons'] = array(
	array('templateID' => '10', 'upimage' => 'Green.png', 'downimage' => 'Red.png',    'recoverimage' => 'Yellow.png'),
	array('templateID' => '11', 'upimage' => 'ap.v2.png', 'downimage' => 'my-icon.png', 'recoverimage' => ''),
);

ob_start();
include __DIR__ . '/../includes/customicons.php';
$custom = ob_get_clean();

assert_contains('customicons: maps a good icon',   '["10up"] = gpsmap.Green;',    $custom);
assert_contains('customicons: unsafe degrades',    '["11up"] = gpsmap.undefined;', $custom);
assert_contains('customicons: keeps builtin ups',  "gpsmap.customIcons['up'] = gpsmap.Green;", $custom);
assert_contains('customicons: keeps disabled',     "gpsmap.customIcons['disabled'] = gpsmap.Black;", $custom);
assert_equal('gpsmap_safe_icon_base: good name',   'Green',     gpsmap_safe_icon_base('Green.png'));
assert_equal('gpsmap_safe_icon_base: bad name',    'undefined', gpsmap_safe_icon_base('ap.v2.png'));

if (!defined('GPSMAP_TEST_SUITE')) {
	exit(gpsmap_test_summary());
}
