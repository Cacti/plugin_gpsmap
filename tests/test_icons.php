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

/* Never reachable over HTTP.  Cacti deploys plugins inside the web root, so
 * plugins/gpsmap/tests/ would otherwise be a public endpoint that resolves DNS
 * and writes to the filesystem. */
if (PHP_SAPI !== 'cli') {
	exit;
}

require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/../setup.php';
require_once __DIR__ . '/../gpsmap_security.php';
require_once __DIR__ . '/../includes/polling/iconskml.php';

gpsmap_test_use_tmp_root();

// ------------------------------------------------------------------
// gpsmap_icon_identifier
// ------------------------------------------------------------------

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

// ------------------------------------------------------------------
// includes/icons.php - JavaScript emitted into an inline <script>
// ------------------------------------------------------------------

gpsmap_test_icons(['Green.png', 'GoogleBlue.PNG', 'ap.v2.png', 'my-icon.png', 'notes.txt', 'noext']);

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

// Every emitted assignment target must be a bare identifier.
preg_match_all('/gpsmap\.([^ ]+) = \{/', $js, $m);

foreach ($m[1] as $name) {
	assert_true('icons.php: identifier is bare - ' . $name, (bool) preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name));
}

// An unreadable icon directory must not emit anything.
$cwd = getcwd();
chdir(sys_get_temp_dir());
ob_start();
@include __DIR__ . '/../includes/icons.php';
$empty = ob_get_clean();
chdir($cwd);
assert_not_contains('icons.php: missing directory emits no icons', 'gpsmap.', $empty);

// ------------------------------------------------------------------
// iconskml() - KML Style ids
// ------------------------------------------------------------------

gpsmap_test_icons(['Green.png', 'Red.jpg', 'Amber.jpeg', 'Node.gif', 'ap.v2.png', 'notes.txt']);

$kml = iconskml();

assert_contains('iconskml: png style',      '<Style id="Green">',  $kml);
assert_contains('iconskml: jpg style',      '<Style id="Red">',    $kml);
assert_contains('iconskml: jpeg style',     '<Style id="Amber">',  $kml);
assert_contains('iconskml: gif style',      '<Style id="Node">',   $kml);
assert_not_contains('iconskml: drops dotted name', 'ap.v2',        $kml);
assert_not_contains('iconskml: ignores non-images', 'notes',       $kml);
assert_contains('iconskml: absolute href',  'https://cacti.example//cacti/plugins/gpsmap/images/icons/Green.png', $kml);

// Missing directory is logged, not fatal.
$GLOBALS['gpsmap_stub_log']      = [];
$saved                           = $GLOBALS['config']['base_path'];
$GLOBALS['config']['base_path']  = sys_get_temp_dir() . '/gpsmap-no-such-root';
assert_equal('iconskml: missing directory returns empty', '', @iconskml());
assert_true('iconskml: logs the missing directory', str_contains($GLOBALS['gpsmap_stub_log'][0] ?? '', 'could not open icon directory'));
$GLOBALS['config']['base_path'] = $saved;

// ------------------------------------------------------------------
// customicons.php - property-access position, degrades to undefined
// ------------------------------------------------------------------

$GLOBALS['gpsmap_stub_rows']['icons'] = [
	['templateID' => '10', 'upimage' => 'Green.png', 'downimage' => 'Red.png',    'recoverimage' => 'Yellow.png'],
	['templateID' => '11', 'upimage' => 'ap.v2.png', 'downimage' => 'my-icon.png', 'recoverimage' => ''],
];

ob_start();
include __DIR__ . '/../includes/customicons.php';
$custom = ob_get_clean();

assert_contains('customicons: maps a good icon',   '["10up"] = gpsmap.Green;',    $custom);
assert_contains('customicons: alert uses down icon', '["10alert"] = gpsmap.Red;', $custom);
assert_contains('customicons: unsafe degrades',    '["11up"] = gpsmap.undefined;', $custom);
assert_contains('customicons: keeps builtin ups',  "gpsmap.customIcons['up'] = gpsmap.Green;", $custom);
assert_contains('customicons: keeps disabled',     "gpsmap.customIcons['disabled'] = gpsmap.Black;", $custom);
assert_equal('gpsmap_safe_icon_base: good name',   'Green',     gpsmap_safe_icon_base('Green.png'));
assert_equal('gpsmap_safe_icon_base: bad name',    'undefined', gpsmap_safe_icon_base('ap.v2.png'));

// ------------------------------------------------------------------
// getIcons() must offer exactly what the renderers can draw
// ------------------------------------------------------------------

// getIcons() reads a path relative to the Cacti root.
gpsmap_test_icons(['Green.png', 'Node2.gif', 'my-icon.png', 'ap.v2.png', 'notes.txt', 'noext']);

$cwd = getcwd();
chdir(gpsmap_test_tmpdir());
$offered = getIcons();
chdir($cwd);

assert_true('getIcons: offers a renderable icon',      isset($offered['Green.png']));
assert_true('getIcons: offers a digit-bearing name',   isset($offered['Node2.gif']));
assert_false('getIcons: hides hyphenated name',        isset($offered['my-icon.png']));
assert_false('getIcons: hides dotted name',            isset($offered['ap.v2.png']));
assert_false('getIcons: hides non-images',             isset($offered['notes.txt']));
assert_false('getIcons: hides extensionless files',    isset($offered['noext']));

/* The dropdown and the JavaScript emitter must never disagree: anything
 * offered here has to survive gpsmap_icon_identifier(). */
foreach (array_keys($offered) as $name) {
	assert_true('getIcons: offered name is renderable - ' . $name, gpsmap_icon_identifier($name) !== null);
}

/* The path comes from base_path, not the working directory, so poller and CLI
 * callers see the same list as the web pages. */
$savedRoot = $GLOBALS['config']['base_path'];
$cwd       = getcwd();
chdir(sys_get_temp_dir());
assert_true('getIcons: resolves regardless of the working directory', isset(getIcons()['Green.png']));
chdir($cwd);

$GLOBALS['config']['base_path'] = sys_get_temp_dir() . '/gpsmap-no-such-root';
assert_equal('getIcons: missing directory yields no icons', [], @getIcons());
$GLOBALS['config']['base_path'] = $savedRoot;

// Saving still falls back when a name is not on the list.
assert_equal('save: hyphenated name rejected on save', 'Green.png', gpsmap_normalize_icon_name('my-icon.png', $offered));
assert_equal('save: offered name accepted on save',    'Green.png', gpsmap_normalize_icon_name('Green.png', $offered));

if (!defined('GPSMAP_TEST_SUITE')) {
	exit(gpsmap_test_summary());
}
