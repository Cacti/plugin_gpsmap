<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Tests for the poller path: region(), the XML/KML writers and the        |
 | coverage overlay.                                                       |
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
require_once __DIR__ . '/../class/hosts_class.php';
require_once __DIR__ . '/../includes/polling/functions.php';
require_once __DIR__ . '/../includes/polling/processregion.php';

gpsmap_test_use_tmp_root();
gpsmap_test_icons(array('Green.png', 'Red.png', 'Yellow.png', 'GoogleBlue.png', 'ap.v2.png', 'readme.txt'));

function gpsmap_test_row(array $over = array()): array {
	return $over + array(
		'id'               => '1',
		'host_template_id' => '10',
		'hostname'         => '10.1.2.3',
		'description'      => 'Device <one>',
		'status'           => '3',
		'disabled'         => '',
		'availability'     => '100',
		'cur_time'         => '1.5',
		'latitude'         => '51.5074',
		'longitude'        => '-0.1278',
		'start'            => '0',
		'stop'             => '360',
		'rdistance'        => '0',
		'groupnum'         => '1',
		'GPScoverage'      => 'on',
		'AP'               => '0',
		'upimage'          => 'Green.png',
		'downimage'        => 'Red.png',
		'recoverimage'     => 'Yellow.png',
	);
}

/* ------------------------------------------------------------------ */
/* gpsmap_xml_path / gpsmap_write_file                                 */
/* ------------------------------------------------------------------ */

$root = gpsmap_test_tmpdir();

assert_equal(
	'gpsmap_xml_path: strips surrounding dots',
	$root . '/plugins/gpsmap/XML/10.1.2.xml',
	gpsmap_xml_path('.10.1.2.', 'xml')
);
assert_equal(
	'gpsmap_xml_path: kml extension',
	$root . '/plugins/gpsmap/XML/all.kml',
	gpsmap_xml_path('all', 'kml')
);

assert_true('gpsmap_write_file: writes', gpsmap_write_file(gpsmap_xml_path('probe', 'xml'), 'body'));
assert_equal('gpsmap_write_file: contents', 'body', file_get_contents(gpsmap_xml_path('probe', 'xml')));

$GLOBALS['gpsmap_stub_log'] = array();
assert_false('gpsmap_write_file: unwritable path returns false', gpsmap_write_file($root . '/no/such/dir/x.xml', 'body'));
assert_true('gpsmap_write_file: logs the failure', str_contains($GLOBALS['gpsmap_stub_log'][0] ?? '', 'Unable to write to'));

/* ------------------------------------------------------------------ */
/* getTowerIds / createTypeArray                                       */
/* ------------------------------------------------------------------ */

$GLOBALS['gpsmap_stub_rows']['towers'] = array();
assert_equal('getTowerIds: sentinel when no towers', array(9999), getTowerIds());

$GLOBALS['gpsmap_stub_rows']['towers'] = array(array('templateID' => '10'), array('templateID' => '11'));
assert_equal('getTowerIds: template ids', array('10', '11'), getTowerIds());

$GLOBALS['gpsmap_stub_rows']['templates'] = array(
	array('id' => '10', 'name' => 'Tower'),
	array('id' => '20', 'name' => 'Switch'),
);
assert_equal('createTypeArray: id keyed names', array('10' => 'Tower', '20' => 'Switch'), createTypeArray());

/* ------------------------------------------------------------------ */
/* gpsmap_marker                                                       */
/* ------------------------------------------------------------------ */

$typeArray = array('10' => 'Tower');
$marker    = gpsmap_marker(gpsmap_test_marker_host(), $typeArray, '0', false);

assert_contains('gpsmap_marker: id',        'id="5"',            $marker);
assert_contains('gpsmap_marker: escapes',   'name="A &amp; B"',  $marker);
assert_contains('gpsmap_marker: type',      'type="Tower"',      $marker);
assert_contains('gpsmap_marker: radius',    'radius="0"',        $marker);
assert_not_contains('gpsmap_marker: device has no schedule', 'start=', $marker);

$towerMarker = gpsmap_marker(gpsmap_test_marker_host(), $typeArray, '42.5', true);
assert_contains('gpsmap_marker: tower radius',   'radius="42.5"', $towerMarker);
assert_contains('gpsmap_marker: tower start',    'start="0"',     $towerMarker);
assert_contains('gpsmap_marker: tower stop',     'stop="360"',    $towerMarker);

$unknown = gpsmap_marker(gpsmap_test_marker_host('99'), $typeArray, '0', false);
assert_contains('gpsmap_marker: unknown template falls back', 'type="Unknown"', $unknown);

function gpsmap_test_marker_host(string $type = '10'): host {
	return new host('5', $type, '1.0', '2.0', '10.0.0.5', 'A & B', 'h.example',
		0, '99', 'up', '1', 'on', 'Green.png', 'Red.png', 'Yellow.png', '0', '360', '1');
}

/* createXMLNodes skips anything the traversal switched off. */
$visible = gpsmap_test_marker_host();
$hidden  = gpsmap_test_marker_host();
$hidden->showMap = 0;

$nodes = createXMLNodes(array($visible, $hidden));
assert_equal('createXMLNodes: hidden hosts are skipped', 1, substr_count($nodes, '<marker '));
assert_equal('createXMLNodes: empty input', '', createXMLNodes(array()));

/* ------------------------------------------------------------------ */
/* region(): full poller pass                                          */
/* ------------------------------------------------------------------ */

$GLOBALS['gpsmap_stub_rows']['hosts'] = array(
	gpsmap_test_row(array('id' => '1', 'hostname' => '10.1.2.3', 'host_template_id' => '10', 'groupnum' => '1')),
	gpsmap_test_row(array('id' => '2', 'hostname' => '10.1.2.4', 'host_template_id' => '20', 'groupnum' => '1', 'status' => '1',
		/* same group as the tower but 250km away, so the coverage radius has to grow */
		'latitude' => '53.4808', 'longitude' => '-2.2426')),
	gpsmap_test_row(array('id' => '3', 'hostname' => '10.9.9.9', 'host_template_id' => '20', 'groupnum' => '2', 'status' => '2')),
	/* filtered: no coordinates */
	gpsmap_test_row(array('id' => '4', 'latitude' => '0.000', 'longitude' => '0.000')),
	gpsmap_test_row(array('id' => '5', 'longitude' => '0.000')),
	/* filtered: name does not resolve to a dotted quad */
	gpsmap_test_row(array('id' => '6', 'hostname' => 'not-an-ip')),
	/* disabled device keeps its own status label */
	gpsmap_test_row(array('id' => '7', 'hostname' => '10.1.2.7', 'disabled' => 'on', 'status' => '3')),
	/* status outside 1/2/3 falls through to 'undefined' */
	gpsmap_test_row(array('id' => '8', 'hostname' => '10.1.2.8', 'status' => '9')),
	/* GoogleXxx icons map onto Google's own KML styles */
	gpsmap_test_row(array('id' => '9', 'hostname' => '10.1.2.9', 'upimage' => 'GoogleBlue.png')),
);
$GLOBALS['gpsmap_stub_rows']['towers'] = array(array('templateID' => '10'));

$enableAll = 'on';
region('all');

$xml = file_get_contents(gpsmap_xml_path('all', 'xml'));
$kml = file_get_contents(gpsmap_xml_path('all', 'kml'));
$top = file_get_contents($root . '/plugins/gpsmap/XML/all-top.html');

assert_contains('region: writes markers',        '<markers>',   $xml);
assert_contains('region: up device',             'status="up"', $xml);
assert_contains('region: down device',           'status="down"', $xml);
assert_contains('region: recovering device',     'status="recovering"', $xml);
assert_contains('region: disabled device',       'status="disabled"', $xml);
assert_contains('region: undefined status',      'status="undefined"', $xml);
assert_not_contains('region: skips 0.000 coords', 'id="4"',     $xml);
assert_not_contains('region: skips unresolvable', 'id="6"',     $xml);
assert_contains('region: kml document',          '<kml',        $kml);
assert_contains('region: kml placemark',         '<Placemark>', $kml);
assert_contains('region: kml style for a plugin icon', '<styleUrl>Green</styleUrl>', $kml);
assert_contains('region: GoogleXxx maps to the builtin style', '<styleUrl>blue</styleUrl>', $kml);
assert_contains('region: top html nav',          'gpstopmenu',  $top);
assert_contains('region: top html subnet link',  'subnet=10',   $top);

/* Tower markers carry the coverage radius grown to the furthest group member. */
assert_true('region: coverage radius grows beyond zero', (bool) preg_match('/radius="[1-9][0-9]*(\.[0-9]+)?"/', $xml));

/* enableAll off takes the WHERE-clause branch. */
$enableAll = '';
region('10.1.2.');
$deep = file_get_contents(gpsmap_xml_path('10.1.2.', 'xml'));
assert_contains('region: subnet pass writes its own file', '<markers>', $deep);

/* Deepest level emits per-device graph links. */
$enableAll = 'on';
region('10.1.2.');
assert_contains('region: preempt 3 links to graphs', 'graph_view.php', file_get_contents($root . '/plugins/gpsmap/XML/10.1.2-top.html'));

/* Beyond the deepest level every host is switched off. */
region('10.1.2.3.4.');
assert_not_contains('region: past deepest level draws no markers', '<marker ', file_get_contents(gpsmap_xml_path('10.1.2.3.4.', 'xml')));

/* Empty subnet is treated as 'all'. */
region('');
assert_true('region: empty subnet writes the all- files', file_exists($root . '/plugins/gpsmap/XML/all-top.html'));

/* callRegion wires the includes together. */
callRegion('all');
assert_contains('callRegion: writes the navigation file', 'gpstopmenu', file_get_contents($root . '/plugins/gpsmap/XML/all-top.html'));

/* Regression: the poller calls region() many times in one process.  Each
 * -top.html must contain only its own navigation.  This is what a global
 * $body silently broke. */
region('10.1.2.');
$first = file_get_contents($root . '/plugins/gpsmap/XML/10.1.2-top.html');
region('10.9.9.');
$second = file_get_contents($root . '/plugins/gpsmap/XML/10.9.9-top.html');

assert_equal('region: navigation does not accumulate across calls', 1, substr_count($second, 'gpstopmenu'));
assert_not_contains('region: second file excludes the first subnet links', 'host_id=1"', $second);
region('10.1.2.');
assert_equal('region: rerunning a subnet does not grow its file', $first, file_get_contents($root . '/plugins/gpsmap/XML/10.1.2-top.html'));

/* Device markers are emitted before tower markers and always carry
 * radius="0"; only tower markers have start=/stop=. */
function gpsmap_test_tower_radius(string $xml): ?float {
	if (preg_match('/<marker [^>]*radius="([0-9.]+)"[^>]*start=/', $xml, $m)) {
		return (float) $m[1];
	}

	return null;
}

/* Coverage radius: only devices sharing the tower's group widen it, and it
 * lands on the furthest member rather than the last one seen. */
$GLOBALS['gpsmap_stub_rows']['towers'] = array(array('templateID' => '10'));
$GLOBALS['gpsmap_stub_rows']['hosts']  = array(
	gpsmap_test_row(array('id' => '1', 'hostname' => '10.5.0.1', 'host_template_id' => '10', 'groupnum' => '1')),
	/* different group, must not widen the tower */
	gpsmap_test_row(array('id' => '2', 'hostname' => '10.5.0.2', 'host_template_id' => '20', 'groupnum' => '7',
		'latitude' => '-33.8688', 'longitude' => '151.2093')),
);
region('all');
assert_equal('coverageXML: other groups do not widen the radius', 0.0, gpsmap_test_tower_radius(file_get_contents(gpsmap_xml_path('all', 'xml'))));

$GLOBALS['gpsmap_stub_rows']['hosts'] = array(
	gpsmap_test_row(array('id' => '1', 'hostname' => '10.5.0.1', 'host_template_id' => '10', 'groupnum' => '1')),
	/* furthest member listed first, nearest last: the radius must keep the max */
	gpsmap_test_row(array('id' => '2', 'hostname' => '10.5.0.2', 'host_template_id' => '20', 'groupnum' => '1',
		'latitude' => '-33.8688', 'longitude' => '151.2093')),
	gpsmap_test_row(array('id' => '3', 'hostname' => '10.5.0.3', 'host_template_id' => '20', 'groupnum' => '1',
		'latitude' => '51.5080', 'longitude' => '-0.1280')),
);
region('all');
assert_true('coverageXML: radius keeps the furthest member, not the last', gpsmap_test_tower_radius(file_get_contents(gpsmap_xml_path('all', 'xml'))) > 1000.0);

/* A device with coverage switched off is ignored by the overlay. */
$GLOBALS['gpsmap_stub_rows']['hosts'] = array(
	gpsmap_test_row(array('id' => '1', 'hostname' => '10.5.0.1', 'host_template_id' => '10', 'groupnum' => '1')),
	gpsmap_test_row(array('id' => '2', 'hostname' => '10.5.0.2', 'host_template_id' => '20', 'groupnum' => '1',
		'GPScoverage' => '', 'latitude' => '-33.8688', 'longitude' => '151.2093')),
);
region('all');
assert_equal('coverageXML: coverage-off devices are ignored', 0.0, gpsmap_test_tower_radius(file_get_contents(gpsmap_xml_path('all', 'xml'))));

/* Two Devices resolving to one address must yield one graph link, not two.
 * The de-duplication guard used to test a different string than it stored. */
$GLOBALS['gpsmap_stub_rows']['towers'] = array(array('templateID' => '10'));
$GLOBALS['gpsmap_stub_rows']['hosts']  = array(
	gpsmap_test_row(array('id' => '11', 'hostname' => '10.4.4.4', 'host_template_id' => '20')),
	gpsmap_test_row(array('id' => '12', 'hostname' => '10.4.4.4', 'host_template_id' => '20')),
);
region('10.4.4.');
$deepest = file_get_contents($root . '/plugins/gpsmap/XML/10.4.4-top.html');
assert_equal('region: one link per address at the deepest level', 1, substr_count($deepest, 'graph_view.php'));

/* calcMeters is the retained deprecated alias. */
assert_equal('calcMeters: delegates to calcKm', calcKm(1.0, 2.0, 3.0, 4.0), calcMeters(1.0, 2.0, 3.0, 4.0));

if (!defined('GPSMAP_TEST_SUITE')) {
	exit(gpsmap_test_summary());
}
