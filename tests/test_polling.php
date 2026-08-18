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
require_once __DIR__ . '/../includes/dns.php';

if (!function_exists('db_execute_prepared')) {
	function db_execute_prepared($sql, $params = [], $log = true, $db_conn = false) {
		$GLOBALS['gpsmap_stub_execute'][] = [$sql, $params];

		return true;
	}
}

gpsmap_test_use_tmp_root();
gpsmap_test_icons(['Green.png', 'Red.png', 'Yellow.png', 'GoogleBlue.png', 'ap.v2.png', 'readme.txt']);

function gpsmap_test_row(array $over = []): array {
	return $over + [
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
		'cached_address'   => null,
		'thold_alarm'      => '0',
	];
}

// ------------------------------------------------------------------
// gpsmap_xml_path / gpsmap_write_file
// ------------------------------------------------------------------

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

$GLOBALS['gpsmap_stub_log'] = [];
assert_false('gpsmap_write_file: unwritable path returns false', gpsmap_write_file($root . '/no/such/dir/x.xml', 'body'));
assert_true('gpsmap_write_file: logs the failure', str_contains($GLOBALS['gpsmap_stub_log'][0] ?? '', 'Unable to write to'));

// ------------------------------------------------------------------
// getTowerIds / createTypeArray
// ------------------------------------------------------------------

$GLOBALS['gpsmap_stub_rows']['towers'] = [];
assert_equal('getTowerIds: sentinel when no towers', [9999], getTowerIds());

$GLOBALS['gpsmap_stub_rows']['towers'] = [['templateID' => '10'], ['templateID' => '11']];
assert_equal('getTowerIds: template ids', ['10', '11'], getTowerIds());

$GLOBALS['gpsmap_stub_rows']['templates'] = [
	['id' => '10', 'name' => 'Tower'],
	['id' => '20', 'name' => 'Switch'],
];
assert_equal('createTypeArray: id keyed names', ['10' => 'Tower', '20' => 'Switch'], createTypeArray());

// ------------------------------------------------------------------
// gpsmap_marker
// ------------------------------------------------------------------

$typeArray = ['10' => 'Tower'];
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

// createXMLNodes skips anything the traversal switched off.
$visible         = gpsmap_test_marker_host();
$hidden          = gpsmap_test_marker_host();
$hidden->showMap = 0;

$nodes = createXMLNodes([$visible, $hidden]);
assert_equal('createXMLNodes: hidden hosts are skipped', 1, substr_count($nodes, '<marker '));
assert_equal('createXMLNodes: empty input', '', createXMLNodes([]));

// ------------------------------------------------------------------
// region(): full poller pass
// ------------------------------------------------------------------

$GLOBALS['gpsmap_stub_rows']['hosts'] = [
	gpsmap_test_row(['id' => '1', 'hostname' => '10.1.2.3', 'host_template_id' => '10', 'groupnum' => '1']),
	gpsmap_test_row(['id' => '2', 'hostname' => '10.1.2.4', 'host_template_id' => '20', 'groupnum' => '1', 'status' => '1',
		// same group as the tower but 250km away, so the coverage radius has to grow
		'latitude' => '53.4808', 'longitude' => '-2.2426']),
	gpsmap_test_row(['id' => '3', 'hostname' => '10.9.9.9', 'host_template_id' => '20', 'groupnum' => '2', 'status' => '2']),
	// filtered: no coordinates
	gpsmap_test_row(['id' => '4', 'latitude' => '0.000', 'longitude' => '0.000']),
	gpsmap_test_row(['id' => '5', 'longitude' => '0.000']),
	// filtered: name does not resolve to a dotted quad
	gpsmap_test_row(['id' => '6', 'hostname' => 'not-an-ip']),
	// disabled device keeps its own status label
	gpsmap_test_row(['id' => '7', 'hostname' => '10.1.2.7', 'disabled' => 'on', 'status' => '3']),
	// status outside 1/2/3 falls through to 'undefined'
	gpsmap_test_row(['id' => '8', 'hostname' => '10.1.2.8', 'status' => '9']),
	// GoogleXxx icons map onto Google's own KML styles
	gpsmap_test_row(['id' => '9', 'hostname' => '10.1.2.9', 'upimage' => 'GoogleBlue.png']),
];
$GLOBALS['gpsmap_stub_rows']['towers'] = [['templateID' => '10']];

$GLOBALS['gpsmap_stub_settings']['gpsmap_enableall'] = 'on';
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

// Tower markers carry the coverage radius grown to the furthest group member.
assert_true('region: coverage radius grows beyond zero', (bool) preg_match('/radius="[1-9][0-9]*(\.[0-9]+)?"/', $xml));

// enableAll off takes the WHERE-clause branch.
$GLOBALS['gpsmap_stub_settings']['gpsmap_enableall'] = '';
region('10.1.2.');
$deep = file_get_contents(gpsmap_xml_path('10.1.2.', 'xml'));
assert_contains('region: subnet pass writes its own file', '<markers>', $deep);

// Deepest level emits per-device graph links.
$GLOBALS['gpsmap_stub_settings']['gpsmap_enableall'] = 'on';
region('10.1.2.');
assert_contains('region: preempt 3 links to graphs', 'graph_view.php', file_get_contents($root . '/plugins/gpsmap/XML/10.1.2-top.html'));

// Beyond the deepest level every host is switched off.
region('10.1.2.3.4.');
assert_not_contains('region: past deepest level draws no markers', '<marker ', file_get_contents(gpsmap_xml_path('10.1.2.3.4.', 'xml')));

// Empty subnet is treated as 'all'.
region('');
assert_true('region: empty subnet writes the all- files', file_exists($root . '/plugins/gpsmap/XML/all-top.html'));

// callRegion wires the includes together.
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
$GLOBALS['gpsmap_stub_rows']['towers'] = [['templateID' => '10']];
$GLOBALS['gpsmap_stub_rows']['hosts']  = [
	gpsmap_test_row(['id' => '1', 'hostname' => '10.5.0.1', 'host_template_id' => '10', 'groupnum' => '1']),
	// different group, must not widen the tower
	gpsmap_test_row(['id' => '2', 'hostname' => '10.5.0.2', 'host_template_id' => '20', 'groupnum' => '7',
		'latitude'           => '-33.8688', 'longitude' => '151.2093']),
];
region('all');
assert_equal('coverageXML: other groups do not widen the radius', 0.0, gpsmap_test_tower_radius(file_get_contents(gpsmap_xml_path('all', 'xml'))));

$GLOBALS['gpsmap_stub_rows']['hosts'] = [
	gpsmap_test_row(['id' => '1', 'hostname' => '10.5.0.1', 'host_template_id' => '10', 'groupnum' => '1']),
	// furthest member listed first, nearest last: the radius must keep the max
	gpsmap_test_row(['id' => '2', 'hostname' => '10.5.0.2', 'host_template_id' => '20', 'groupnum' => '1',
		'latitude'           => '-33.8688', 'longitude' => '151.2093']),
	gpsmap_test_row(['id' => '3', 'hostname' => '10.5.0.3', 'host_template_id' => '20', 'groupnum' => '1',
		'latitude'           => '51.5080', 'longitude' => '-0.1280']),
];
region('all');
assert_true('coverageXML: radius keeps the furthest member, not the last', gpsmap_test_tower_radius(file_get_contents(gpsmap_xml_path('all', 'xml'))) > 1000.0);

// A device with coverage switched off is ignored by the overlay.
$GLOBALS['gpsmap_stub_rows']['hosts'] = [
	gpsmap_test_row(['id' => '1', 'hostname' => '10.5.0.1', 'host_template_id' => '10', 'groupnum' => '1']),
	gpsmap_test_row(['id' => '2', 'hostname' => '10.5.0.2', 'host_template_id' => '20', 'groupnum' => '1',
		'GPScoverage'        => '', 'latitude' => '-33.8688', 'longitude' => '151.2093']),
];
region('all');
assert_equal('coverageXML: coverage-off devices are ignored', 0.0, gpsmap_test_tower_radius(file_get_contents(gpsmap_xml_path('all', 'xml'))));

// ------------------------------------------------------------------
// Single-pass loading: one query and one DNS pass for the whole cycle
// ------------------------------------------------------------------

$GLOBALS['gpsmap_stub_rows']['towers'] = [['templateID' => '10']];
$GLOBALS['gpsmap_stub_rows']['hosts']  = [
	gpsmap_test_row(['id' => '1', 'hostname' => '10.1.2.3', 'host_template_id' => '10']),
	gpsmap_test_row(['id' => '2', 'hostname' => '10.1.9.4', 'host_template_id' => '20']),
	gpsmap_test_row(['id' => '3', 'hostname' => '192.168.1.5', 'host_template_id' => '20']),
];

// No mapped devices at all is a normal state on a fresh install.
$savedHosts                           = $GLOBALS['gpsmap_stub_rows']['hosts'];
$GLOBALS['gpsmap_stub_rows']['hosts'] = [];
assert_equal('load: empty result set yields empty groups', [[], []], gpsmap_load_devices(true));
$GLOBALS['gpsmap_stub_rows']['hosts'] = $savedHosts;

$loaded = gpsmap_load_devices(true);
assert_equal('load: towers separated', 1, cacti_sizeof($loaded[0]));
assert_equal('load: devices separated', 2, cacti_sizeof($loaded[1]));
assert_equal('load: iprange holds the resolved address', '10.1.2.3', $loaded[0][0]->iprange);

$prefixes = gpsmap_subnet_prefixes($loaded);
assert_equal('prefixes: every depth, de-duplicated', ['10.', '10.1.', '10.1.2.', '10.1.9.', '192.', '192.168.', '192.168.1.'], $prefixes);
assert_equal('prefixes: empty set yields none', [], gpsmap_subnet_prefixes([[], []]));

/* IPv6 uses filename-safe reversible tokens while remaining in the same
 * three-level navigation model. */
$GLOBALS['gpsmap_stub_rows']['hosts'] = [
	gpsmap_test_row(['id' => '21', 'hostname' => '2001:db8:1234::5', 'host_template_id' => '20']),
];
$v6loaded = gpsmap_load_devices(true);
assert_equal('ipv6: literal address is mapped', 1, cacti_sizeof($v6loaded[1]));
assert_equal('ipv6: safe prefixes', ['v6-16-2001', 'v6-32-20010db8', 'v6-48-20010db81234'], gpsmap_subnet_prefixes($v6loaded));
assert_equal('ipv6: token has a readable label', '2001:db8::/32', gpsmap_ipv6_prefix_label('v6-32-20010db8'));
assert_true('ipv6: prefix membership', gpsmap_ipv6_prefix_contains('v6-32-20010db8', '2001:db8:ffff::1'));
assert_false('ipv6: rejects another prefix', gpsmap_ipv6_prefix_contains('v6-32-20010db8', '2001:db9::1'));
assert_equal('ipv6: invalid token has no label', null, gpsmap_ipv6_prefix_label('v6-31-nope'));
assert_equal('ipv6: wrong token length has no label', null, gpsmap_ipv6_prefix_label('v6-32-2001'));
assert_equal('ipv6: invalid address has no token', null, gpsmap_ipv6_prefix_token('nope', 32));
assert_equal('ipv6: unsupported prefix length has no token', null, gpsmap_ipv6_prefix_token('2001:db8::1', 64));
assert_false('ipv6: malformed prefix never matches', gpsmap_ipv6_prefix_contains('not-a-prefix', '2001:db8::1'));
assert_false('ipv6: short prefix token never matches', gpsmap_ipv6_prefix_contains('v6-32-2001', '2001:db8::1'));
assert_equal('artefact: invalid name falls back safely', 'all', gpsmap_artifact_stem('../escape'));

gpsmap_render_region($v6loaded, 'v6-32-20010db8');
assert_true('ipv6: safe artefact filename is written', file_exists(gpsmap_xml_path('v6-32-20010db8', 'xml')));
assert_contains('ipv6: marker survives drilldown', 'id="21"', file_get_contents(gpsmap_xml_path('v6-32-20010db8', 'xml')));

gpsmap_render_region($v6loaded, 'v6-48-20010db81234');
assert_contains('ipv6: deepest level links to Device graphs', 'host_id=21',
	file_get_contents($root . '/plugins/gpsmap/XML/v6-48-20010db81234-top.html'));

$GLOBALS['gpsmap_stub_rows']['hosts'] = [
	gpsmap_test_row(['id' => '24', 'hostname' => '10.20.30.40', 'host_template_id' => '20']),
	gpsmap_test_row(['id' => '25', 'hostname' => '2001:db8:abcd::1', 'description' => 'IPv6 only', 'host_template_id' => '20']),
];
$mixed = gpsmap_load_devices(true);
gpsmap_render_region($mixed, 'all');
assert_contains('dual stack: all view includes IPv4', 'id="24"', file_get_contents(gpsmap_xml_path('all', 'xml')));
assert_contains('dual stack: all view includes IPv6', 'id="25"', file_get_contents(gpsmap_xml_path('all', 'xml')));
gpsmap_render_region($mixed, '10.20');
assert_not_contains('dual stack: IPv4 drilldown hides IPv6', 'id="25"', file_get_contents(gpsmap_xml_path('10.20', 'xml')));
assert_not_contains('dual stack: IPv4 KML hides IPv6', '<name>IPv6 only</name>',
	file_get_contents(gpsmap_xml_path('10.20', 'kml')));
gpsmap_render_region($mixed, 'v6-32-20010db9');
assert_not_contains('dual stack: nonmatching IPv6 prefix is hidden', 'id="25"',
	file_get_contents(gpsmap_xml_path('v6-32-20010db9', 'xml')));

// A configured hostname consumes only a cached value during the poll.
$GLOBALS['gpsmap_stub_rows']['hosts'] = [
	gpsmap_test_row(['id' => '22', 'hostname' => 'router.example', 'cached_address' => '2001:db8::22', 'host_template_id' => '20']),
];
assert_equal('dns cache: cached hostname is mapped without a lookup', 1, cacti_sizeof(gpsmap_load_devices(true)[1]));

/* thold stays optional; when enabled an alerting, otherwise-up Device receives
 * the alert marker state while disabled/down precedence stays intact. */
$GLOBALS['gpsmap_stub_plugins']       = ['thold'];
$GLOBALS['gpsmap_stub_rows']['hosts'] = [
	gpsmap_test_row(['id' => '23', 'hostname' => '10.2.3.4', 'thold_alarm' => '1']),
];
assert_equal('thold: alert overrides healthy status', 'alert', gpsmap_load_devices(true)[0][0]->status);
gpsmap_render_region(gpsmap_load_devices(true), 'all');
assert_contains('thold: alert reaches XML', 'status="alert"', file_get_contents(gpsmap_xml_path('all', 'xml')));
$GLOBALS['gpsmap_stub_missing_tables'] = ['plugin_gpsmap_dns_cache', 'thold_data'];
assert_equal('soft dependencies: missing optional tables keep literal Devices', 1, cacti_sizeof(gpsmap_load_devices(true)[0]));
$GLOBALS['gpsmap_stub_missing_tables'] = [];
$GLOBALS['gpsmap_stub_plugins']        = [];
$GLOBALS['gpsmap_stub_rows']['hosts']  = $savedHosts;
$GLOBALS['gpsmap_stub_rows']['towers'] = [['templateID' => '10']];

// Rendering mutates the shared set, so it must be restored between subnets.
gpsmap_render_region($loaded, '10.1.2.');
$hidden = 0;

foreach ($loaded as $g) {
	foreach ($g as $h) {
		if ($h->showMap == 0) {
			$hidden++;
		}
	}
}
assert_true('render: a narrow subnet hides the others', $hidden > 0);

gpsmap_render_region($loaded, 'all');
$hidden = 0;

foreach ($loaded as $g) {
	foreach ($g as $h) {
		if ($h->showMap == 0) {
			$hidden++;
		}
	}
}
assert_equal('render: state is reset before each subnet', 0, $hidden);

// Reusing the set must give the same bytes as rendering it fresh.
gpsmap_render_region($loaded, '10.1.2.');
$reused = file_get_contents(gpsmap_xml_path('10.1.2.', 'xml'));
region('10.1.2.');
assert_equal('render: reused set matches a fresh load', $reused, file_get_contents(gpsmap_xml_path('10.1.2.', 'xml')));

// ------------------------------------------------------------------
// Atomic writes
// ------------------------------------------------------------------

$xmldir = $root . '/plugins/gpsmap/XML';
assert_equal('write: leaves no temp files behind', [], preg_grep('/\.tmp$/', scandir($xmldir)));

/* Prefix de-duplication must stay correct now that it is keyed rather than
 * searched: many Devices in one subnet still yield one prefix per depth. */
$dupHosts = [];

for ($i = 1; $i <= 5; $i++) {
	$dupHosts[] = gpsmap_test_row(['id' => (string) (60 + $i), 'hostname' => '10.3.3.' . $i]);
}

$GLOBALS['gpsmap_stub_rows']['towers'] = [];
$GLOBALS['gpsmap_stub_rows']['hosts']  = $dupHosts;

$dupPrefixes = gpsmap_subnet_prefixes(gpsmap_load_devices(true));

assert_equal('prefixes: repeated addresses collapse', ['10.', '10.3.', '10.3.3.'], $dupPrefixes);
assert_equal('prefixes: no duplicates survive', count($dupPrefixes), count(array_unique($dupPrefixes)));

/* rename() into an occupied directory name fails, exercising the staged-write
 * rollback: the temp file is removed and the failure is logged. */
$blocked = $xmldir . '/occupied';
@mkdir($blocked, 0700, true);
file_put_contents($blocked . '/child', 'x');

$GLOBALS['gpsmap_stub_log'] = [];
assert_false('write: failed rename returns false', gpsmap_write_file($blocked, 'body'));
assert_true('write: failed rename is logged', str_contains($GLOBALS['gpsmap_stub_log'][0] ?? '', 'Unable to write to'));
assert_equal('write: failed rename leaves no temp file', [], preg_grep('/occupied\..*\.tmp$/', scandir($xmldir)));

/* A short write must not be renamed into place: the previous document has to
 * survive and the failure has to be logged. */
$target = $xmldir . '/shortwrite.xml';
file_put_contents($target, 'original');

$GLOBALS['gpsmap_stub_log'] = [];
$full                       = str_repeat('x', 64);

assert_false('write: short write returns false', gpsmap_write_file('gpsmapshort://target', $full));
assert_true('write: short write is logged', str_contains($GLOBALS['gpsmap_stub_log'][0] ?? '', 'Unable to write to'));

assert_equal('write: previous document survives a failure', 'original', file_get_contents($target));

/* Two Devices resolving to one address must yield one graph link, not two.
 * The de-duplication guard used to test a different string than it stored. */
$GLOBALS['gpsmap_stub_rows']['towers'] = [['templateID' => '10']];
$GLOBALS['gpsmap_stub_rows']['hosts']  = [
	gpsmap_test_row(['id' => '11', 'hostname' => '10.4.4.4', 'host_template_id' => '20']),
	gpsmap_test_row(['id' => '12', 'hostname' => '10.4.4.4', 'host_template_id' => '20']),
];
region('10.4.4.');
$deepest = file_get_contents($root . '/plugins/gpsmap/XML/10.4.4-top.html');
assert_equal('region: one link per address at the deepest level', 1, substr_count($deepest, 'graph_view.php'));

/* Overwriting an existing artefact is the normal case: the poller rewrites the
 * same names every cycle. */
$overwrite = gpsmap_xml_path('overwrite-probe', 'xml');
assert_true('write: first write creates the file', gpsmap_write_file($overwrite, 'first'));
assert_true('write: second write replaces it', gpsmap_write_file($overwrite, 'second'));
assert_equal('write: contents are the newer document', 'second', file_get_contents($overwrite));
assert_equal('write: no temp files survive', [],
	preg_grep('/overwrite-probe\..*\.tmp$/', scandir(dirname($overwrite))));

// Pruning is age based and restricted to names gpsmap owns.
$staleMap = gpsmap_xml_path('10.99', 'xml');
$freshMap = gpsmap_xml_path('10.98', 'xml');
$foreign  = dirname($staleMap) . '/operator-note.txt';
file_put_contents($staleMap, 'stale');
file_put_contents($freshMap, 'fresh');
file_put_contents($foreign, 'keep');
touch($staleMap, time() - 1000);
assert_equal('prune: removes one stale generated artefact', 1, gpsmap_prune_artifacts(time() - 500));
assert_false('prune: stale generated artefact is gone', file_exists($staleMap));
assert_true('prune: fresh generated artefact remains', file_exists($freshMap));
assert_true('prune: foreign file remains', file_exists($foreign));

/* A failed rename must leave the previous document in place rather than
 * deleting it and hoping the retry works. */
$xd   = $root . '/plugins/gpsmap/XML';
$live = $xd . '/rename-guard.xml';
file_put_contents($live, 'previous');
@mkdir($xd . '/rename-guard-dir', 0700, true);
file_put_contents($xd . '/rename-guard-dir/child', 'x');

$GLOBALS['gpsmap_stub_log'] = [];
assert_false('write: a failed rename reports failure', gpsmap_write_file($xd . '/rename-guard-dir', 'body'));
assert_equal('write: the previous document is untouched', 'previous', file_get_contents($live));

// Disk pressure must not publish a truncated document while reporting success.
$GLOBALS['gpsmap_stub_log'] = [];
assert_false('write: a short write fails', gpsmap_write_file('gpsmapshort://target', str_repeat('x', 64)));
assert_true('write: a short write is logged', str_contains($GLOBALS['gpsmap_stub_log'][0] ?? '', 'Unable to write to'));

// A failed rename must roll back rather than leave a stray temp file.
$blocked = gpsmap_test_tmpdir() . '/plugins/gpsmap/XML/occupied';
@mkdir($blocked, 0700, true);
file_put_contents($blocked . '/child', 'x');
$GLOBALS['gpsmap_stub_log'] = [];
assert_false('write: a failed rename fails', gpsmap_write_file($blocked, 'body'));
assert_true('write: a failed rename is logged', str_contains($GLOBALS['gpsmap_stub_log'][0] ?? '', 'Unable to write to'));
assert_equal('write: a failed rename leaves no temp file', [],
	preg_grep('/occupied\..*\.tmp$/', scandir(gpsmap_test_tmpdir() . '/plugins/gpsmap/XML')));

/* The Display Disabled Devices setting has to reach the query.  It never did:
 * pollinginitial.php assigns $enableAll inside callRegion(), so the global
 * region() read was always null and the setting was inert. */
$GLOBALS['gpsmap_stub_rows']['hosts'] = [gpsmap_test_row(['id' => '31', 'hostname' => '10.7.7.1'])];

$GLOBALS['gpsmap_stub_settings']['gpsmap_enableall'] = '';
region('all');
assert_contains('enableAll off: query filters disabled Devices', 'h.disabled = ?', $GLOBALS['gpsmap_stub_host_sql']);

$GLOBALS['gpsmap_stub_settings']['gpsmap_enableall'] = 'on';
region('all');
assert_not_contains('enableAll on: query does not filter disabled Devices', 'h.disabled = ?', $GLOBALS['gpsmap_stub_host_sql']);

// And it must not depend on a global the caller happens to have set.
$GLOBALS['enableAll']                                = '';
$GLOBALS['gpsmap_stub_settings']['gpsmap_enableall'] = 'on';
region('all');
assert_not_contains('enableAll: a stale global does not override the setting', 'h.disabled = ?', $GLOBALS['gpsmap_stub_host_sql']);
unset($GLOBALS['enableAll']);

/* A failed Device query and an estate with no mapped Devices both arrive as an
 * empty set, but only the first is a reason to withhold publication.  Treating
 * them alike meant a fresh install never got an all.xml at all. */
$GLOBALS['gpsmap_stub_rows']['hosts'] = false;
gpsmap_load_devices(true);
assert_true('load: a failed query is reported', $GLOBALS['gpsmap_load_failed']);

$GLOBALS['gpsmap_stub_rows']['hosts'] = [];
gpsmap_load_devices(true);
assert_false('load: an empty estate is not a failure', $GLOBALS['gpsmap_load_failed']);

// An empty estate still publishes, so the map reflects reality.
$emptyXml = gpsmap_xml_path('empty-estate', 'xml');
gpsmap_render_region([[], []], 'empty-estate');
assert_true('render: an empty estate still writes its documents', file_exists($emptyXml));
assert_contains('render: the empty document is well formed', '<markers>', file_get_contents($emptyXml));

// The staged write keeps the destination's mode, which the web server relies on.
$modeTarget = gpsmap_xml_path('mode-probe', 'xml');
gpsmap_write_file($modeTarget, 'first');
chmod($modeTarget, 0644);
$before = fileperms($modeTarget) & 0777;
gpsmap_write_file($modeTarget, 'second');
assert_equal('write: the destination mode survives the rename', $before, fileperms($modeTarget) & 0777);

/* gpsmap_poller_bottom() is the refactor's only production entry point, so it
 * is exercised directly rather than only through region(). */
require_once __DIR__ . '/../includes/polling.php';

$GLOBALS['gpsmap_stub_rows']['towers'] = [['templateID' => '10']];
$GLOBALS['gpsmap_stub_rows']['hosts']  = [
	gpsmap_test_row(['id' => '41', 'hostname' => '10.8.1.1', 'host_template_id' => '10']),
	gpsmap_test_row(['id' => '42', 'hostname' => '10.8.2.2', 'host_template_id' => '20']),
];
$GLOBALS['gpsmap_stub_settings']['gpsmap_enableall'] = 'on';
$GLOBALS['gpsmap_stub_settings']['path_php_binary']  = '/usr/bin/php';
$GLOBALS['gpsmap_stub_log']                          = [];
$GLOBALS['gpsmap_stub_background']                   = [];

gpsmap_poller_bottom();

assert_true('poller: publishes the top level', file_exists(gpsmap_xml_path('all', 'xml')));
assert_true('poller: publishes a subnet', file_exists(gpsmap_xml_path('10.8.1', 'xml')));
assert_true('poller: logs a stats line',
	(bool) preg_grep('/GPSMAP STATS: Mapped:2 /', $GLOBALS['gpsmap_stub_log']));
assert_true('poller: schedules DNS refresh outside the poller',
	(bool) preg_grep('/gpsmap_dns\.php$/', array_column($GLOBALS['gpsmap_stub_background'], 1)));

// Resolver/cache helpers are deterministic under an injected lookup.
assert_equal('dns: literal bypasses lookup', '192.0.2.1', gpsmap_resolve_hostname('192.0.2.1'));
assert_equal('dns: accepts AAAA answers', '2001:db8::9', gpsmap_resolve_hostname('router.example',
	static fn (string $name): array => [['ipv6' => '2001:db8::9']]));
assert_equal('dns: failed lookup is empty', '', gpsmap_resolve_hostname('missing.example',
	static fn (string $name): bool => false));
assert_equal('dns: invalid answers are empty', '', gpsmap_resolve_hostname('bad.example',
	static fn (string $name): array => [['ip' => 'not-an-ip']]));

$GLOBALS['gpsmap_stub_rows']['dns'] = [
	['hostname' => '192.0.2.2'],
	['hostname' => 'localhost.invalid'],
];
$GLOBALS['gpsmap_stub_execute'] = [];
assert_equal('dns: refresh skips literal rows', 1, gpsmap_refresh_dns_cache(
	static fn (string $name): string => '2001:db8::10'));
assert_equal('dns: refresh writes one cache row', 1, count($GLOBALS['gpsmap_stub_execute']));
assert_contains('dns: a failed refresh preserves the last-known address',
	'IF(VALUES(address) = "", address, VALUES(address))', $GLOBALS['gpsmap_stub_execute'][0][0]);

// A failed Device query must leave the published map alone.
file_put_contents(gpsmap_xml_path('all', 'xml'), '<markers><marker id="99"/></markers>');
$GLOBALS['gpsmap_stub_rows']['hosts'] = false;
$GLOBALS['gpsmap_stub_log']           = [];

gpsmap_poller_bottom();

assert_contains('poller: a failed query keeps the previous map', 'id="99"',
	file_get_contents(gpsmap_xml_path('all', 'xml')));
assert_true('poller: the failure is logged',
	(bool) preg_grep('/could not read the Device list/', $GLOBALS['gpsmap_stub_log']));

/* An estate with no mapped Devices still publishes, so a new install is not
 * left fetching a 404 forever. */
$GLOBALS['gpsmap_stub_rows']['hosts'] = [];
$GLOBALS['gpsmap_stub_log']           = [];

gpsmap_poller_bottom();

assert_not_contains('poller: an empty estate republishes', 'id="99"',
	file_get_contents(gpsmap_xml_path('all', 'xml')));
assert_true('poller: the empty estate is explained',
	(bool) preg_grep('/no Devices to map/', $GLOBALS['gpsmap_stub_log']));

// calcMeters is the retained deprecated alias.
assert_equal('calcMeters: delegates to calcKm', calcKm(1.0, 2.0, 3.0, 4.0), calcMeters(1.0, 2.0, 3.0, 4.0));

if (!defined('GPSMAP_TEST_SUITE')) {
	exit(gpsmap_test_summary());
}
