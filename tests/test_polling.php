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

		return $GLOBALS['gpsmap_stub_execute_result'] ?? true;
	}
}

if (!function_exists('register_process_start')) {
	function register_process_start($process, $task, $id = 0, $timeout = 0) {
		$GLOBALS['gpsmap_stub_process_timeout'] = $timeout;

		return $GLOBALS['gpsmap_stub_process_registration'] ?? true;
	}
}

if (!function_exists('unregister_process')) {
	function unregister_process($process, $task, $id = 0) {
		$GLOBALS['gpsmap_stub_process_unregisters'] = ($GLOBALS['gpsmap_stub_process_unregisters'] ?? 0) + 1;
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
		'cache_is_fresh'   => '1',
		'cache_failures'   => '0',
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

$writeProbe = gpsmap_xml_path('192.0.2', 'xml');
assert_true('gpsmap_write_file: writes', gpsmap_write_file($writeProbe, 'body'));
assert_equal('gpsmap_write_file: contents', 'body', file_get_contents($writeProbe));

$newFileProbe      = gpsmap_xml_path('192.0.3', 'xml');
$modeSetterInvoked = false;
$previousUmask     = umask(0027);

try {
	assert_true('gpsmap_write_file: creates a new destination', gpsmap_write_file(
		$newFileProbe,
		'new',
		static function () use (&$modeSetterInvoked): bool {
			$modeSetterInvoked = true;

			return true;
		}
	));
} finally {
	umask($previousUmask);
}

assert_false('gpsmap_write_file: does not chmod a new destination', $modeSetterInvoked);
assert_equal('gpsmap_write_file: a new destination keeps the umask-derived mode', 0640,
	fileperms($newFileProbe) & 0777);

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

$alertHost         = gpsmap_test_marker_host();
$alertHost->status = 'alert';
assert_true('kml: alerting device renders successfully', kmlCreate([[$alertHost]], '203.0.0'));
assert_contains('kml: alerting device uses its down icon', '<styleUrl>Red</styleUrl>',
	file_get_contents(gpsmap_xml_path('203.0.0', 'kml')));

$missingPreservedKmlState                            = new GpsmapPollState();
$missingPreservedKmlState->preservedArtifacts['all'] = true;
unlink(gpsmap_xml_path('all', 'kml'));
assert_true('kml: a missing preserved file falls through to rendering',
	kmlCreate(gpsmap_load_devices(true), 'all', $missingPreservedKmlState));
assert_true('kml: the missing preserved file is recreated', is_file(gpsmap_xml_path('all', 'kml')));
assert_equal('kml: a missing XML snapshot has no preserved placemarks', '',
	gpsmap_preserved_kml_placemarks('203.0.1'));
gpsmap_write_file(gpsmap_xml_path('203.0.1', 'xml'),
	'<markers><marker gpsmapPreservedAt="1" lat="invalid" lng="invalid" /></markers>');
assert_equal('kml: invalid preserved coordinates are ignored', '',
	gpsmap_preserved_kml_placemarks('203.0.1'));
gpsmap_write_file(gpsmap_xml_path('203.0.1', 'xml'),
	'<markers><marker gpsmapPreservedAt="1" name="" address="" lat="1" lng="2" /></markers>');
$emptyLabelPlacemark = gpsmap_preserved_kml_placemarks('203.0.1');
assert_contains('kml: preserved marker with empty labels is retained', '<Placemark>', $emptyLabelPlacemark);
assert_contains('kml: preserved marker with empty labels keeps its coordinates',
	'<coordinates>2,1</coordinates>', $emptyLabelPlacemark);

$skipKmlState = new GpsmapPollState();
gpsmap_write_file(gpsmap_xml_path('203.0.2', 'xml'),
	'<markers><marker gpsmapPreservedAt="1" name="Skip parse" lat="1" lng="2" /></markers>');
assert_true('kml: render without preservation succeeds', kmlCreate([], '203.0.2', $skipKmlState));
assert_not_contains('kml: empty preservation state skips loading old XML', 'Skip parse',
	file_get_contents(gpsmap_xml_path('203.0.2', 'kml')));
$skipKmlState->preservedArtifacts['203.0.2'] = true;
assert_true('kml: render with preservation succeeds', kmlCreate([], '203.0.2', $skipKmlState));
assert_contains('kml: flagged preservation loads old XML markers', 'Skip parse',
	file_get_contents(gpsmap_xml_path('203.0.2', 'kml')));

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

// Invalid render targets must not fall through to and overwrite all.xml.
$allBeforeInvalid = file_get_contents(gpsmap_xml_path('all', 'xml'));
assert_false('region: past deepest level is rejected', gpsmap_render_region(gpsmap_load_devices(true), '10.1.2.3.4.'));
assert_equal('region: an invalid target leaves all.xml untouched', $allBeforeInvalid,
	file_get_contents(gpsmap_xml_path('all', 'xml')));

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

// A non-zero Specify Radius value is authoritative even with distant members.
$GLOBALS['gpsmap_stub_rows']['hosts'] = [
	gpsmap_test_row(['id' => '1', 'hostname' => '10.5.0.1', 'host_template_id' => '10',
		'groupnum'           => '1', 'rdistance' => '5']),
	gpsmap_test_row(['id' => '2', 'hostname' => '10.5.0.2', 'host_template_id' => '20',
		'groupnum'           => '1', 'latitude' => '-33.8688', 'longitude' => '151.2093']),
];
region('all');
assert_equal('coverageXML: configured radius overrides automatic group geometry', 5.0,
	gpsmap_test_tower_radius(file_get_contents(gpsmap_xml_path('all', 'xml'))));

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
assert_equal('prefixes: every depth, normalized and de-duplicated', ['10', '10.1', '10.1.2', '10.1.9', '192', '192.168', '192.168.1'], $prefixes);
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

foreach ([16, 32, 48] as $prefixLength) {
	$token = gpsmap_ipv6_prefix_token('2001:db8:1234::5', $prefixLength);
	assert_true("ipv6: $prefixLength-bit token round-trips through label", $token !== null && gpsmap_ipv6_prefix_label($token) !== null);
	assert_true("ipv6: $prefixLength-bit token contains its source", $token !== null && gpsmap_ipv6_prefix_contains($token, '2001:db8:1234::5'));
}

$v6PreserveState                      = new GpsmapPollState();
$v6PreserveState->unresolvedDeviceIds = ['21'];
gpsmap_write_file(gpsmap_xml_path('v6-32-20010db8', 'xml'),
	'<markers><marker id="21" name="IPv6 previous" lat="51.5" lng="-0.1" /></markers>');
assert_contains('ipv6: an unresolved Device is preserved in a v6 artifact', 'id="21"',
	gpsmap_preserve_unresolved_markers('<markers></markers>', 'v6-32-20010db8', $v6PreserveState));

assert_equal('artefact: invalid name has no stem', null, gpsmap_artifact_stem('../escape'));
$invalidPathRejected = false;

try {
	gpsmap_xml_path('../escape', 'xml');
} catch (InvalidArgumentException) {
	$invalidPathRejected = true;
}

assert_true('artefact: invalid name cannot alias all.xml', $invalidPathRejected);

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
assert_contains('dual stack: dotless IPv4 drilldown includes its IPv4 Device', 'id="24"',
	file_get_contents(gpsmap_xml_path('10.20', 'xml')));
assert_not_contains('dual stack: IPv4 drilldown hides IPv6', 'id="25"', file_get_contents(gpsmap_xml_path('10.20', 'xml')));
assert_contains('dual stack: IPv4 KML includes its IPv4 Device', '<name>Device &lt;one&gt;</name>',
	file_get_contents(gpsmap_xml_path('10.20', 'kml')));
assert_not_contains('dual stack: IPv4 KML hides IPv6', '<name>IPv6 only</name>',
	file_get_contents(gpsmap_xml_path('10.20', 'kml')));

$dotlessTwoLevel = file_get_contents(gpsmap_xml_path('10.20', 'xml'));
gpsmap_render_region($mixed, '10.20.');
assert_equal('subnet: dotted and dotless two-level forms select the same Devices', $dotlessTwoLevel,
	file_get_contents(gpsmap_xml_path('10.20', 'xml')));
gpsmap_render_region($mixed, '10');
assert_contains('subnet: dotless one-level form includes an in-prefix Device', 'id="24"',
	file_get_contents(gpsmap_xml_path('10', 'xml')));
assert_not_contains('subnet: dotless one-level form excludes IPv6', 'id="25"',
	file_get_contents(gpsmap_xml_path('10', 'xml')));
gpsmap_render_region($mixed, 'v6-32-20010db9');
assert_not_contains('dual stack: nonmatching IPv6 prefix is hidden', 'id="25"',
	file_get_contents(gpsmap_xml_path('v6-32-20010db9', 'xml')));

// A configured hostname consumes only a cached value during the poll.
$GLOBALS['gpsmap_stub_rows']['hosts'] = [
	gpsmap_test_row(['id' => '22', 'hostname' => 'router.example', 'cached_address' => '2001:db8::22', 'host_template_id' => '20']),
];
assert_equal('dns cache: cached hostname is mapped without a lookup', 1, cacti_sizeof(gpsmap_load_devices(true)[1]));

$GLOBALS['gpsmap_stub_rows']['hosts'] = [
	gpsmap_test_row([
		'id'             => '22', 'hostname' => 'router.example', 'cached_address' => '2001:db8::22',
		'cache_is_fresh' => '0', 'host_template_id' => '20',
	]),
];
$staleState = new GpsmapPollState();
assert_equal('dns cache: an expired cached address remains mapped as degraded', 1,
	cacti_sizeof(gpsmap_load_devices(true, $staleState)[1]));
assert_equal('dns cache: an expired cached address is explicitly tracked', ['22'], $staleState->staleDeviceIds);

/* thold stays optional; when enabled an alerting, otherwise-up Device receives
 * the alert marker state while disabled/down precedence stays intact. */
$GLOBALS['gpsmap_stub_plugins']       = ['thold'];
$GLOBALS['gpsmap_stub_rows']['hosts'] = [
	gpsmap_test_row(['id' => '23', 'hostname' => '10.2.3.4', 'thold_alarm' => '1']),
	gpsmap_test_row(['id' => '24', 'hostname' => '10.2.3.5', 'disabled' => 'on', 'thold_alarm' => '1']),
	gpsmap_test_row(['id' => '25', 'hostname' => '10.2.3.6', 'status' => '1', 'thold_alarm' => '1']),
	gpsmap_test_row(['id' => '26', 'hostname' => '10.2.3.7', 'status' => '2', 'thold_alarm' => '1']),
];
$tholdDevices = gpsmap_load_devices(true)[0];
assert_equal('thold: alert overrides healthy status', 'alert', $tholdDevices[0]->status);
assert_equal('thold: disabled status takes precedence over alert', 'disabled', $tholdDevices[1]->status);
assert_equal('thold: down status takes precedence over alert', 'down', $tholdDevices[2]->status);
assert_equal('thold: recovering status takes precedence over alert', 'recovering', $tholdDevices[3]->status);
assert_contains('thold: enabled state uses a placeholder', 'WHERE thold_enabled = ?', $GLOBALS['gpsmap_stub_host_sql']);
assert_not_contains('thold: query is safe under ANSI_QUOTES', 'thold_enabled = "on"', $GLOBALS['gpsmap_stub_host_sql']);
gpsmap_load_devices(false);
assert_equal('thold: disabled-Device filter parameters follow SQL placeholder order', ['on', ''],
	$GLOBALS['gpsmap_stub_last_params']);
gpsmap_render_region(gpsmap_load_devices(true), 'all');
assert_contains('thold: alert reaches XML', 'status="alert"', file_get_contents(gpsmap_xml_path('all', 'xml')));
$GLOBALS['gpsmap_stub_missing_tables'] = ['plugin_gpsmap_dns_cache', 'thold_data'];
$GLOBALS['gpsmap_stub_log']            = [];
$GLOBALS['gpsmap_stub_rows']['hosts']  = [
	gpsmap_test_row(['id' => '23', 'hostname' => '10.2.3.4', 'thold_alarm' => '1']),
];
assert_equal('soft dependencies: missing optional tables keep literal Devices', 1, cacti_sizeof(gpsmap_load_devices(true)[0]));
assert_true('soft dependencies: nonblocking DNS deferral is logged with its upgrade remedy',
	(bool) preg_grep('/named Devices are deferred without blocking the poller until the plugin upgrade succeeds/', $GLOBALS['gpsmap_stub_log']));
$GLOBALS['gpsmap_stub_rows']['hosts'] = [
	gpsmap_test_row(['id' => '26', 'hostname' => 'legacy-name.example', 'cached_address' => null]),
];
$missingDnsState = new GpsmapPollState();
assert_equal('soft dependencies: a missing DNS table never resolves on the poller path', 0,
	cacti_sizeof(gpsmap_load_devices(true, $missingDnsState)[0]));
assert_equal('soft dependencies: a deferred name remains explicitly unresolved', ['legacy-name.example'],
	$missingDnsState->unresolvedHostnames);
assert_true('soft dependencies: missing DNS schema suspends marker expiry',
	$missingDnsState->dnsResolverUnavailable);
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

assert_equal('prefixes: repeated addresses collapse', ['10', '10.3', '10.3.3'], $dupPrefixes);
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
$overwrite = gpsmap_xml_path('192.0.10', 'xml');
assert_true('write: first write creates the file', gpsmap_write_file($overwrite, 'first'));
assert_true('write: second write replaces it', gpsmap_write_file($overwrite, 'second'));
assert_equal('write: contents are the newer document', 'second', file_get_contents($overwrite));
assert_equal('write: no temp files survive', [],
	preg_grep('/192\.0\.10\.xml\..*\.tmp$/', scandir(dirname($overwrite))));

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

$newlineForeign = dirname($staleMap) . "/all\n.xml";
file_put_contents($newlineForeign, 'operator file');
touch($newlineForeign, time() - 1000);
assert_equal('prune: newline-bearing foreign file is not owned', 0,
	gpsmap_prune_artifacts(time() - 500));
assert_true('prune: newline-bearing foreign file remains', file_exists($newlineForeign));

$interruptedWrite = dirname($staleMap) . '/10.97.xml.4242.tmp';
file_put_contents($interruptedWrite, 'partial');
touch($interruptedWrite, time() - 1000);
assert_equal('prune: removes a stale interrupted-writer staging file', 1,
	gpsmap_prune_artifacts(time() - 500));
assert_false('prune: interrupted-writer staging file is gone', file_exists($interruptedWrite));

$topLevelArtifacts = [
	gpsmap_xml_path('all', 'xml'),
	gpsmap_xml_path('all', 'kml'),
	dirname($staleMap) . '/all-top.html',
];

foreach ($topLevelArtifacts as $artifact) {
	file_put_contents($artifact, 'stale top level');
	touch($artifact, time() - 1000);
}

assert_equal('prune: stale top-level XML, KML and navigation are all generated artifacts', 3,
	gpsmap_prune_artifacts(time() - 500));

foreach ($topLevelArtifacts as $artifact) {
	assert_false('prune: stale top-level artifact is removed - ' . basename($artifact), file_exists($artifact));
}

$cycleStartedAt = time() - 1000;
$slowCycleMap   = gpsmap_xml_path('all', 'xml');
file_put_contents($slowCycleMap, 'published during a slow cycle');
touch($slowCycleMap, $cycleStartedAt + 1);
assert_equal('prune: threshold is anchored to cycle start', $cycleStartedAt - 180,
	gpsmap_artifact_prune_threshold($cycleStartedAt, 60));
assert_equal('prune: a slow cycle keeps an artifact it published after cycle start', 0,
	gpsmap_prune_artifacts(gpsmap_artifact_prune_threshold($cycleStartedAt, 60)));
assert_true('prune: slow-cycle artifact remains published', file_exists($slowCycleMap));
$savedBasePath                  = $GLOBALS['config']['base_path'];
$GLOBALS['config']['base_path'] = $root . '/missing-prune-root';
assert_equal('prune: a missing artifact directory is a safe no-op', 0, gpsmap_prune_artifacts(time()));
$GLOBALS['config']['base_path'] = $savedBasePath;

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

$modeWarningTarget = gpsmap_xml_path('10.96', 'xml');
file_put_contents($modeWarningTarget, 'old');
$GLOBALS['gpsmap_stub_log'] = [];
assert_true('write: a chmod failure does not discard a valid document',
	gpsmap_write_file($modeWarningTarget, 'new', static fn (string $path, int $mode): bool => false));
assert_equal('write: chmod failure still publishes the complete document', 'new',
	file_get_contents($modeWarningTarget));
assert_true('write: chmod failure is logged',
	(bool) preg_grep('/could not preserve the existing artifact permissions/', $GLOBALS['gpsmap_stub_log']));

$zeroModeTarget = gpsmap_xml_path('10.95', 'xml');
file_put_contents($zeroModeTarget, 'old');
chmod($zeroModeTarget, 0000);
assert_true('write: a zero-mode destination can be atomically replaced',
	gpsmap_write_file($zeroModeTarget, 'new'));
assert_equal('write: an intentional zero mode is preserved', 0, fileperms($zeroModeTarget) & 0777);
chmod($zeroModeTarget, 0600);
assert_equal('write: zero-mode replacement contains the complete document', 'new',
	file_get_contents($zeroModeTarget));

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
$loadState                            = new GpsmapPollState();
gpsmap_load_devices(true, $loadState);
assert_true('load: a failed query is reported', $loadState->loadFailed);

$GLOBALS['gpsmap_stub_rows']['hosts'] = [];
gpsmap_load_devices(true, $loadState);
assert_false('load: an empty estate is not a failure', $loadState->loadFailed);

$failedState                          = new GpsmapPollState();
$emptyState                           = new GpsmapPollState();
$GLOBALS['gpsmap_stub_rows']['hosts'] = false;
gpsmap_load_devices(true, $failedState);
$GLOBALS['gpsmap_stub_rows']['hosts'] = [];
gpsmap_load_devices(true, $emptyState);
assert_true('load: explicit poll state does not leak between cycles',
	$failedState->loadFailed && !$emptyState->loadFailed);

// An empty estate still publishes, so the map reflects reality.
$emptyXml = gpsmap_xml_path('198.51.100', 'xml');
assert_true('render: an empty estate reports successful publication',
	gpsmap_render_region([[], []], '198.51.100'));
assert_true('render: an empty estate still writes its documents', file_exists($emptyXml));
assert_contains('render: the empty document is well formed', '<markers>', file_get_contents($emptyXml));

// The staged write keeps the destination's mode, which the web server relies on.
$modeTarget = gpsmap_xml_path('203.0.113', 'xml');
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
	(bool) preg_grep('/gpsmap_dns\.php/', array_column($GLOBALS['gpsmap_stub_background'], 1)));

$savedBasePath                               = $GLOBALS['config']['base_path'];
$GLOBALS['config']['base_path']              = '/opt/network tools/cacti';
$GLOBALS['gpsmap_stub_background']           = [];
gpsmap_schedule_dns_refresh();
assert_equal('dns schedule: a path containing spaces is shell-safe',
	cacti_escapeshellarg('/opt/network tools/cacti/plugins/gpsmap/gpsmap_dns.php'),
	$GLOBALS['gpsmap_stub_background'][0][1]);
$GLOBALS['config']['base_path'] = $savedBasePath;

/* If the worker is unavailable long enough for the cache to become stale, a
 * previously known Device remains visible but is never presented as fresh. */
$GLOBALS['gpsmap_stub_rows']['hosts'] = [
	gpsmap_test_row([
		'id'             => '46', 'hostname' => 'stale.example', 'cached_address' => '192.0.2.46',
		'cache_is_fresh' => '0', 'cache_failures' => '8',
	]),
];
$GLOBALS['gpsmap_stub_log'] = [];
gpsmap_poller_bottom();
assert_contains('poller: a stale last-known-good Device remains visible', 'id="46"',
	file_get_contents(gpsmap_xml_path('all', 'xml')));
assert_true('poller: stale last-known-good rendering is explicit in the log',
	(bool) preg_grep('/explicitly stale last-known-good/', $GLOBALS['gpsmap_stub_log']));

// Resolver/cache helpers are deterministic under an injected lookup.
assert_equal('dns: literal bypasses lookup', '192.0.2.1', gpsmap_resolve_hostname('192.0.2.1'));
assert_equal('dns: accepts A answers', '192.0.2.9', gpsmap_resolve_hostname('router-v4.example',
	static fn (string $name): array => [['ip' => '192.0.2.9']]));
assert_equal('dns: accepts AAAA answers', '2001:db8::9', gpsmap_resolve_hostname('router.example',
	static fn (string $name): array => [['ipv6' => '2001:db8::9']]));
assert_equal('dns: a record carrying both families preserves A-before-AAAA precedence', '192.0.2.10',
	gpsmap_resolve_hostname('dual-stack.example',
		static fn (string $name): array => [['ip' => '192.0.2.10', 'ipv6' => '2001:db8::10']]));
assert_equal('dns: failed lookup is empty', '', gpsmap_resolve_hostname('missing.example',
	static fn (string $name): bool => false,
	static fn (string $name): string => $name));
assert_equal('dns: invalid answers are empty', '', gpsmap_resolve_hostname('bad.example',
	static fn (string $name): array => [['ip' => 'not-an-ip']],
	static fn (string $name): string => $name));
assert_equal('dns: NSS fallback accepts an address', '127.0.0.1', gpsmap_resolve_hostname('local.test',
	static fn (string $name): bool => false,
	static fn (string $name): string => '127.0.0.1'));

$GLOBALS['gpsmap_stub_rows']['dns'] = [
	['hostname' => '192.0.2.2'],
	['hostname' => 'localhost.invalid'],
];
$GLOBALS['gpsmap_stub_execute'] = [];
assert_equal('dns: refresh skips literal rows', 1, gpsmap_refresh_dns_cache(
	static fn (string $name): string => '2001:db8::10'));
assert_equal('dns: refresh writes one cache row and runs the cache reaper', 2,
	count($GLOBALS['gpsmap_stub_execute']));
assert_contains('dns: refresh removes cache rows for Devices no longer mapped',
	'DELETE dc', $GLOBALS['gpsmap_stub_execute'][1][0]);
assert_contains('dns: cache reaper is scoped to mapped Device templates',
	'INNER JOIN gpsmap_templates', $GLOBALS['gpsmap_stub_execute'][1][0]);
assert_contains('dns: cache reaper hashes each mapped hostname once',
	'SELECT DISTINCT UNHEX(SHA2(h.hostname, 256))', $GLOBALS['gpsmap_stub_execute'][1][0]);
assert_contains('dns: cache reaper probes the materialized live hashes',
	'live.hostname_hash = dc.hostname_hash', $GLOBALS['gpsmap_stub_execute'][1][0]);
assert_not_contains('dns: cache reaper is not correlated per cache row',
	'NOT EXISTS', $GLOBALS['gpsmap_stub_execute'][1][0]);
assert_contains('dns: the work queue excludes literal addresses before LIMIT', 'INET6_ATON(h.hostname) IS NULL',
	$GLOBALS['gpsmap_stub_last_sql']);

$GLOBALS['gpsmap_stub_execute_result'] = false;
$GLOBALS['gpsmap_stub_log']            = [];
assert_false('dns: a failed cache reaper fails closed', gpsmap_reap_dns_cache());
assert_true('dns: a failed cache reaper is logged',
	(bool) preg_grep('/could not remove cache rows/', $GLOBALS['gpsmap_stub_log']));
unset($GLOBALS['gpsmap_stub_execute_result']);

$GLOBALS['gpsmap_stub_rows']['dns'] = [];

for ($i = 0; $i < GPSMAP_DNS_REFRESH_BATCH_SIZE + 3; $i++) {
	$GLOBALS['gpsmap_stub_rows']['dns'][] = ['hostname' => '192.0.2.' . ($i % 255)];
}

$GLOBALS['gpsmap_stub_rows']['dns'][] = ['hostname' => 'not-starved.example'];
$resolvedNames                        = [];
gpsmap_refresh_dns_cache(static function (string $name) use (&$resolvedNames): string {
	$resolvedNames[] = $name;

	return '192.0.2.61';
});
assert_equal('dns: literal-heavy estates do not starve hostname work', ['not-starved.example'], $resolvedNames);

$GLOBALS['gpsmap_stub_rows']['dns'] = [['hostname' => 'missing.example']];
$GLOBALS['gpsmap_stub_execute']     = [];
$GLOBALS['gpsmap_stub_log']         = [];
assert_equal('dns: a failed refresh reports no update', 0, gpsmap_refresh_dns_cache(
	static fn (string $name): string => ''));
assert_equal('dns: a failed refresh records one bounded failure and runs the cache reaper', 2,
	count($GLOBALS['gpsmap_stub_execute']));
assert_contains('dns: cache identity hashes rather than truncates the hostname', 'UNHEX(SHA2(?, 256))',
	$GLOBALS['gpsmap_stub_execute'][0][0]);
assert_contains('dns: a failed refresh increments the failure counter', 'failure_count = LEAST',
	$GLOBALS['gpsmap_stub_execute'][0][0]);
assert_true('dns: a failed refresh is logged',
	(bool) preg_grep('/could not resolve a configured hostname/', $GLOBALS['gpsmap_stub_log']));

$GLOBALS['gpsmap_stub_rows']['dns'] = [];

for ($i = 0; $i < GPSMAP_DNS_REFRESH_BATCH_SIZE + 3; $i++) {
	$GLOBALS['gpsmap_stub_rows']['dns'][] = ['hostname' => 'batch-' . $i . '.example'];
}

$GLOBALS['gpsmap_stub_execute'] = [];
$batchLookups                   = 0;
gpsmap_refresh_dns_cache(static function (string $name) use (&$batchLookups): string {
	$batchLookups++;

	return '192.0.2.50';
});
assert_equal('dns: one worker run is batch bounded', GPSMAP_DNS_REFRESH_BATCH_SIZE, $batchLookups);
assert_contains('dns: the database query carries the same batch limit',
	'LIMIT ' . GPSMAP_DNS_REFRESH_BATCH_SIZE, $GLOBALS['gpsmap_stub_last_sql']);
assert_contains('dns: repeated failures use a wider retry window', 'failure_count >= 4',
	$GLOBALS['gpsmap_stub_last_sql']);
assert_contains('dns: the work queue is compatible with ONLY_FULL_GROUP_BY', 'GROUP BY h.hostname',
	$GLOBALS['gpsmap_stub_last_sql']);
assert_contains('dns: ordering uses an aggregate compatible with ONLY_FULL_GROUP_BY', 'ORDER BY MIN(dc.attempted_at) IS NULL',
	$GLOBALS['gpsmap_stub_last_sql']);

$GLOBALS['gpsmap_stub_rows']['dns'] = [['hostname' => 'deferred.example']];
$GLOBALS['gpsmap_stub_execute']     = [];
$GLOBALS['gpsmap_stub_log']         = [];
$clockReads                         = 0;
$timedLookups                       = 0;
assert_equal('dns: an exhausted time budget reports no updates', 0, gpsmap_refresh_dns_cache(
	static function (string $name) use (&$timedLookups): string {
		$timedLookups++;

		return '192.0.2.51';
	},
	static function () use (&$clockReads): float {
		return $clockReads++ === 0 ? 0.0 : GPSMAP_DNS_REFRESH_TIME_BUDGET;
	}
));
assert_equal('dns: an exhausted time budget defers lookup work', 0, $timedLookups);
assert_true('dns: an exhausted time budget is logged',
	(bool) preg_grep('/reached its time budget/', $GLOBALS['gpsmap_stub_log']));

$shutdownCleanup                                  = null;
$GLOBALS['gpsmap_stub_process_registration']      = true;
$GLOBALS['gpsmap_stub_process_unregisters']       = 0;
$GLOBALS['gpsmap_stub_rows']['dns']               = [];
assert_true('dns worker: shutdown cleanup completes the worker', gpsmap_run_dns_refresh_worker(
	static function () use (&$shutdownCleanup): void {
		assert(is_callable($shutdownCleanup));
		$shutdownCleanup();
	},
	static function (callable $cleanup) use (&$shutdownCleanup): void {
		$shutdownCleanup = $cleanup;
	}
));
assert_equal('dns worker: shutdown cleanup releases registration exactly once', 1,
	$GLOBALS['gpsmap_stub_process_unregisters']);
assert_equal('dns worker: registration expires shortly after its own time budget',
	GPSMAP_DNS_WORKER_REGISTRATION_TIMEOUT, $GLOBALS['gpsmap_stub_process_timeout']);

$GLOBALS['gpsmap_stub_process_registration'] = true;
$GLOBALS['gpsmap_stub_process_unregisters']  = 0;
$refreshThrew                                = false;

try {
	gpsmap_run_dns_refresh_worker(
		static function (): void {
			throw new RuntimeException('refresh failed');
		},
		static function (callable $cleanup): void {
		}
	);
} catch (RuntimeException) {
	$refreshThrew = true;
}

assert_true('dns worker: refresh exceptions propagate to the CLI boundary', $refreshThrew);
assert_equal('dns worker: refresh exceptions still release registration', 1,
	$GLOBALS['gpsmap_stub_process_unregisters']);

$GLOBALS['gpsmap_stub_rows']['dns']                                  = false;
$GLOBALS['gpsmap_stub_log']                                          = [];
$GLOBALS['gpsmap_stub_settings']['plugin_gpsmap_dns_last_success']   = '';
assert_equal('dns worker: a failed work-queue query returns failure status', 1, gpsmap_dns_refresh_exit_code());
assert_equal('dns worker: a failed work-queue query does not claim success', '',
	$GLOBALS['gpsmap_stub_settings']['plugin_gpsmap_dns_last_success']);
assert_true('dns worker: a failed work-queue query is logged',
	(bool) preg_grep('/could not read the hostname cache work queue/', $GLOBALS['gpsmap_stub_log']));

$GLOBALS['gpsmap_stub_rows']['dns']      = [['hostname' => 'missing-write.example']];
$GLOBALS['gpsmap_stub_execute_result']   = false;
$GLOBALS['gpsmap_stub_log']              = [];
assert_false('dns: a failed lookup-counter write fails the cycle', gpsmap_refresh_dns_cache(
	static fn (string $name): string => ''));
assert_true('dns: a failed lookup-counter write is logged',
	(bool) preg_grep('/could not record a failed hostname lookup/', $GLOBALS['gpsmap_stub_log']));

$GLOBALS['gpsmap_stub_rows']['dns'] = [['hostname' => 'resolved-write.example']];
$GLOBALS['gpsmap_stub_log']         = [];
assert_false('dns: a failed resolved-address write fails the cycle', gpsmap_refresh_dns_cache(
	static fn (string $name): string => '192.0.2.60'));
assert_true('dns: a failed resolved-address write is logged',
	(bool) preg_grep('/could not store a resolved hostname/', $GLOBALS['gpsmap_stub_log']));
unset($GLOBALS['gpsmap_stub_execute_result']);

$GLOBALS['gpsmap_stub_process_registration']                       = false;
$GLOBALS['gpsmap_stub_process_unregisters']                        = 0;
$GLOBALS['gpsmap_stub_log']                                        = [];
$GLOBALS['gpsmap_stub_settings']['plugin_gpsmap_dns_last_success'] = '';
assert_equal('dns worker: a registration conflict returns failure status', 1, gpsmap_dns_refresh_exit_code());
assert_equal('dns worker: a registration conflict does not unregister another worker', 0,
	$GLOBALS['gpsmap_stub_process_unregisters']);
assert_equal('dns worker: a registration conflict does not claim success', '',
	$GLOBALS['gpsmap_stub_settings']['plugin_gpsmap_dns_last_success']);
assert_true('dns worker: a registration conflict is logged',
	(bool) preg_grep('/another worker owns the process registration/', $GLOBALS['gpsmap_stub_log']));
assert_true('dns worker: expected registration contention is a notice',
	(bool) preg_grep('/^NOTICE: gpsmap DNS refresh did not start/', $GLOBALS['gpsmap_stub_log']));

$GLOBALS['gpsmap_stub_process_registration'] = true;
$GLOBALS['gpsmap_stub_rows']['dns']          = [];
assert_equal('dns worker: a registered worker returns success status', 0, gpsmap_dns_refresh_exit_code());
assert_equal('dns worker: a registered worker always unregisters', 1,
	$GLOBALS['gpsmap_stub_process_unregisters']);
assert_true('dns worker: a completed worker records liveness',
	(int) $GLOBALS['gpsmap_stub_settings']['plugin_gpsmap_dns_last_success'] > 0);
assert_false('dns health: a recent worker success is healthy',
	gpsmap_dns_worker_is_stale(940, 60, 1000));
assert_equal('dns health: stale window includes worker budget and scheduling slack', 420,
	gpsmap_dns_worker_stale_after(60));
assert_false('dns health: exact worker runtime window remains healthy',
	gpsmap_dns_worker_is_stale(580, 60, 1000));
assert_true('dns health: beyond the worker runtime window is stale',
	gpsmap_dns_worker_is_stale(579, 60, 1000));
assert_true('dns health: a worker that never succeeded is stale',
	gpsmap_dns_worker_is_stale(0, 60, 1000));

$GLOBALS['gpsmap_stub_rows']['hosts']                              = [gpsmap_test_row(['id' => '47'])];
$GLOBALS['gpsmap_stub_settings']['poller_interval']                = '60';
$GLOBALS['gpsmap_stub_settings']['plugin_gpsmap_dns_last_success'] = '';
$GLOBALS['gpsmap_stub_log']                                        = [];
gpsmap_poller_bottom();
assert_true('dns health: a worker that never succeeded is surfaced on the poller path',
	(bool) preg_grep('/has never reported successful completion/', $GLOBALS['gpsmap_stub_log']));

$GLOBALS['gpsmap_stub_rows']['hosts']                              = [gpsmap_test_row(['id' => '48'])];
$GLOBALS['gpsmap_stub_settings']['poller_interval']                = '60';
$GLOBALS['gpsmap_stub_settings']['plugin_gpsmap_dns_last_success'] = (string) (time() - 421);
$GLOBALS['gpsmap_stub_log']                                        = [];
gpsmap_poller_bottom();
assert_true('dns health: a stalled worker is surfaced on the poller path',
	(bool) preg_grep('/has not completed successfully within its runtime and scheduling window/',
		$GLOBALS['gpsmap_stub_log']));
$GLOBALS['gpsmap_stub_settings']['plugin_gpsmap_dns_last_success'] = (string) time();

$GLOBALS['gpsmap_stub_rows']['hosts'] = [gpsmap_test_row([
	'id'        => '49',
	'rdistance' => '42.5',
])];
$manualRadiusHosts = gpsmap_load_devices(true);
assert_equal('radius: configured tower radius reaches the value object', '42.5',
	$manualRadiusHosts[0][0]->configuredRadius);
$manualRadiusHosts[0][0]->radius = '99';
gpsmap_reset_devices($manualRadiusHosts);
assert_equal('radius: render reset restores the configured tower radius', '42.5',
	$manualRadiusHosts[0][0]->radius);
gpsmap_render_region($manualRadiusHosts, 'all');
assert_contains('radius: configured tower radius reaches emitted XML', 'radius="42.5"',
	file_get_contents(gpsmap_xml_path('all', 'xml')));

// A failed Device query must leave the published map alone.
file_put_contents(gpsmap_xml_path('all', 'xml'), '<markers><marker id="99"/></markers>');
$GLOBALS['gpsmap_stub_rows']['hosts'] = false;
$GLOBALS['gpsmap_stub_log']           = [];

gpsmap_poller_bottom();

assert_contains('poller: a failed query keeps the previous map', 'id="99"',
	file_get_contents(gpsmap_xml_path('all', 'xml')));
assert_true('poller: the failure is logged',
	(bool) preg_grep('/could not read the Device list/', $GLOBALS['gpsmap_stub_log']));

/* A cold DNS cache does not stop healthy Devices from publishing, but pruning
 * remains deferred so the unresolved Device's last-known subnet survives. */
$coldPrefix = gpsmap_xml_path('10.77', 'xml');
file_put_contents($coldPrefix, '<markers><marker id="43" name="Last known subnet" address="cold-cache.example" '
	. 'lat="51.5" lng="-0.1" availability="99" status="up" /></markers>');
touch($coldPrefix, time() - 10000);
$coldDeepPrefix = gpsmap_xml_path('10.77.1', 'xml');
file_put_contents($coldDeepPrefix, '<markers><marker id="43" name="Last known deep subnet" address="cold-cache.example" '
	. 'lat="51.5" lng="-0.1" availability="99" status="up" /></markers>');
touch($coldDeepPrefix, time() - 10000);
file_put_contents(gpsmap_xml_path('all', 'xml'), '<markers>'
	. '<marker id="44" name="Tower" address="10.88.1.1" lat="51.5" lng="-0.1" '
	. 'availability="100" radius="123" status="up" start="0" stop="360" group="1" />'
	. '<marker id="43" name="Last known" address="cold-cache.example" lat="52.5" lng="-1.1" '
	. 'availability="99" radius="0" status="up" group="1" /></markers>');
file_put_contents(gpsmap_xml_path('all', 'kml'), 'last-known kml');
file_put_contents($root . '/plugins/gpsmap/XML/all-top.html', 'last-known menu');
$GLOBALS['gpsmap_stub_rows']['hosts'] = [
	gpsmap_test_row(['id' => '43', 'hostname' => 'cold-cache.example', 'cached_address' => null, 'host_template_id' => '20']),
	gpsmap_test_row(['id' => '44', 'hostname' => '10.88.1.1']),
];
$GLOBALS['gpsmap_stub_log']        = [];
$GLOBALS['gpsmap_stub_background'] = [];

gpsmap_poller_bottom();

assert_contains('poller: unresolved hostname does not block healthy Devices', 'id="44"',
	file_get_contents(gpsmap_xml_path('all', 'xml')));
assert_contains('poller: unresolved hostname retains its last-known marker', 'id="43"',
	file_get_contents(gpsmap_xml_path('all', 'xml')));
assert_contains('poller: a preserved marker is visibly downgraded', 'status="undefined"',
	file_get_contents(gpsmap_xml_path('all', 'xml')));
assert_contains('poller: preserved group geometry keeps the last-known tower radius', 'radius="123"',
	file_get_contents(gpsmap_xml_path('all', 'xml')));
assert_contains('poller: unresolved hostname retains a degraded KML placemark', 'Last known',
	file_get_contents(gpsmap_xml_path('all', 'kml')));
assert_contains('poller: one unresolved hostname does not freeze healthy KML', 'Device &lt;one&gt;',
	file_get_contents(gpsmap_xml_path('all', 'kml')));
assert_not_contains('poller: one unresolved hostname does not freeze navigation', 'last-known menu',
	file_get_contents($root . '/plugins/gpsmap/XML/all-top.html'));
assert_contains('poller: healthy subnet navigation remains current', 'subnet=10',
	file_get_contents($root . '/plugins/gpsmap/XML/all-top.html'));
assert_true('poller: unresolved hostname cannot trigger pruning', file_exists($coldPrefix));
assert_contains('poller: unresolved hostname downgrades its previous subnet marker', 'status="undefined"',
	file_get_contents($coldPrefix));
assert_contains('poller: unresolved hostname downgrades its deepest previous subnet marker', 'status="undefined"',
	file_get_contents($coldDeepPrefix));
assert_true('poller: unresolved hostname is logged',
	(bool) preg_grep('/Device IDs: 43/', $GLOBALS['gpsmap_stub_log']));
assert_true('poller: normalized live and preserved prefixes are counted once',
	(bool) preg_grep('/Subnets:5/', $GLOBALS['gpsmap_stub_log']));
assert_true('poller: cold cache schedules the background resolver',
	(bool) preg_grep('/gpsmap_dns\.php/', array_column($GLOBALS['gpsmap_stub_background'], 1)));
$syncedPrefixState                      = new GpsmapPollState();
$syncedPrefixState->unresolvedDeviceIds = ['43'];
assert_equal('poller: already-downgraded subnet artifacts need no repeat rewrite', [],
	gpsmap_preserved_subnet_prefixes($syncedPrefixState));
$v6PreservedPath = gpsmap_xml_path('v6-32-20010db8', 'xml');
gpsmap_write_file($v6PreservedPath,
	'<markers><marker id="55" status="up" lat="1" lng="2" /></markers>');
$v6PrefixState                      = new GpsmapPollState();
$v6PrefixState->unresolvedDeviceIds = ['55'];
assert_equal('poller: preserved IPv6 artifacts participate in one-time synchronization',
	['v6-32-20010db8'], gpsmap_preserved_subnet_prefixes($v6PrefixState));
unlink($v6PreservedPath);

$missingPreviousTowerState                      = new GpsmapPollState();
$missingPreviousTowerState->unresolvedDeviceIds = ['43'];
gpsmap_write_file(gpsmap_xml_path('10.78', 'xml'),
	'<markers><marker id="43" group="1" status="up" lat="1" lng="2" /></markers>');
$missingPreviousTower = gpsmap_preserve_unresolved_markers(
	'<markers><marker id="44" group="1" radius="0" start="0" stop="360" /></markers>',
	'10.78',
	$missingPreviousTowerState
);
assert_contains('poller: preservation tolerates a tower absent from the prior snapshot', 'id="43"',
	$missingPreviousTower);
assert_contains('poller: a tower absent from the prior snapshot keeps its current radius', 'radius="0"',
	$missingPreviousTower);

$malformedPreviousState                      = new GpsmapPollState();
$malformedPreviousState->unresolvedDeviceIds = ['43'];
$freshDocument                               = '<markers><marker id="44" /></markers>';
gpsmap_write_file(gpsmap_xml_path('10.79', 'xml'), '<markers><broken></markers>');
assert_equal('poller: malformed previous XML leaves the fresh snapshot unchanged', $freshDocument,
	gpsmap_preserve_unresolved_markers($freshDocument, '10.79', $malformedPreviousState));

$unavailableResolverState                         = new GpsmapPollState();
$unavailableResolverState->dnsResolverUnavailable = true;
$unavailableResolverState->unresolvedDeviceIds    = ['43'];
gpsmap_write_file(gpsmap_xml_path('10.80', 'xml'),
	'<markers><marker id="43" name="Last known" gpsmapPreservedAt="'
	. (time() - GPSMAP_PRESERVED_MARKER_TTL - 1) . '" /></markers>');
assert_contains('poller: resolver outage suspends last-known marker expiry', 'id="43"',
	gpsmap_preserve_unresolved_markers('<markers />', '10.80', $unavailableResolverState));

$unrelatedPreservationState                               = new GpsmapPollState();
$unrelatedPreservationState->preservedArtifacts['10.77']  = true;
gpsmap_write_file($root . '/plugins/gpsmap/XML/10.88-top.html', 'stale unrelated menu');
assert_true('poller: preservation in one subnet does not suppress another subnet',
	gpsmap_render_region(gpsmap_load_devices(true), '10.88', $unrelatedPreservationState));
assert_not_contains('poller: unrelated subnet navigation is regenerated', 'stale unrelated menu',
	file_get_contents($root . '/plugins/gpsmap/XML/10.88-top.html'));

preg_match('/gpsmapPreservedAt="([0-9]+)"/', file_get_contents(gpsmap_xml_path('all', 'xml')), $firstPreservedAt);
gpsmap_poller_bottom();
preg_match('/gpsmapPreservedAt="([0-9]+)"/', file_get_contents(gpsmap_xml_path('all', 'xml')), $secondPreservedAt);
assert_true('poller: a preserved marker receives an absolute expiry timestamp',
	(int) ($firstPreservedAt[1] ?? 0) > 0);
assert_equal('poller: republication does not refresh the preservation timestamp',
	$firstPreservedAt[1] ?? null, $secondPreservedAt[1] ?? null);

/* The XML timestamp is independent of the DNS failure counter, so a failed
 * counter update cannot make a republished marker immortal. */
$expiredAt = time() - GPSMAP_PRESERVED_MARKER_TTL - 1;
file_put_contents(gpsmap_xml_path('all', 'xml'),
	'<markers><marker id="43" name="Expired" gpsmapPreservedAt="' . $expiredAt . '" /></markers>');
file_put_contents($coldPrefix,
	'<markers><marker id="43" name="Expired subnet" gpsmapPreservedAt="' . $expiredAt . '" /></markers>');
file_put_contents($coldDeepPrefix,
	'<markers><marker id="43" name="Expired deep subnet" gpsmapPreservedAt="' . $expiredAt . '" /></markers>');
file_put_contents(gpsmap_xml_path('all', 'kml'), 'expired kml');
file_put_contents($root . '/plugins/gpsmap/XML/all-top.html', 'expired menu');
touch($coldPrefix, time() - 10000);
touch($coldDeepPrefix, time() - 10000);
$GLOBALS['gpsmap_stub_log'] = [];

gpsmap_poller_bottom();

assert_not_contains('poller: preservation TTL expires a stale marker independently', 'id="43"',
	file_get_contents(gpsmap_xml_path('all', 'xml')));
assert_not_contains('poller: preservation TTL releases KML publication', 'expired kml',
	file_get_contents(gpsmap_xml_path('all', 'kml')));
assert_not_contains('poller: preservation TTL releases navigation publication', 'expired menu',
	file_get_contents($root . '/plugins/gpsmap/XML/all-top.html'));
assert_false('poller: preservation TTL releases artifact pruning', file_exists($coldPrefix));

/* Repeated permanent failures must eventually stop freezing KML, navigation,
 * and pruning. The last-known marker is a grace mechanism, not an immortal
 * record for a Device that can no longer resolve. */
touch($coldPrefix, time() - 10000);
$GLOBALS['gpsmap_stub_rows']['hosts'] = [
	gpsmap_test_row([
		'id'             => '43', 'hostname' => 'cold-cache.example', 'cached_address' => null,
		'cache_is_fresh' => '0', 'cache_failures' => '4',
	]),
	gpsmap_test_row(['id' => '44', 'hostname' => '10.88.1.1']),
];
$GLOBALS['gpsmap_stub_log'] = [];

gpsmap_poller_bottom();

assert_not_contains('poller: repeated DNS failures expire the old marker', 'id="43"',
	file_get_contents(gpsmap_xml_path('all', 'xml')));
assert_not_contains('poller: repeated DNS failures refresh KML', 'last-known kml',
	file_get_contents(gpsmap_xml_path('all', 'kml')));
assert_not_contains('poller: repeated DNS failures refresh navigation', 'last-known menu',
	file_get_contents($root . '/plugins/gpsmap/XML/all-top.html'));
assert_false('poller: repeated DNS failures allow stale artifact pruning', file_exists($coldPrefix));
assert_true('poller: an expired DNS entry is logged',
	(bool) preg_grep('/repeated DNS failures/', $GLOBALS['gpsmap_stub_log']));

// Missing schema and configuration fail visibly without launching junk work.
$GLOBALS['gpsmap_stub_missing_tables'] = ['plugin_gpsmap_dns_cache'];
$GLOBALS['gpsmap_stub_log']            = [];
$GLOBALS['gpsmap_stub_background']     = [];
gpsmap_schedule_dns_refresh();
assert_equal('dns schedule: missing cache table launches nothing', [], $GLOBALS['gpsmap_stub_background']);
assert_true('dns schedule: missing cache table is logged',
	(bool) preg_grep('/DNS cache table is unavailable/', $GLOBALS['gpsmap_stub_log']));
$GLOBALS['gpsmap_stub_missing_tables'] = [];

$GLOBALS['gpsmap_stub_settings']['path_php_binary']  = '';
$GLOBALS['gpsmap_stub_log']                          = [];
gpsmap_schedule_dns_refresh();
assert_true('dns schedule: missing PHP path is logged',
	(bool) preg_grep('/PHP binary path is not configured/', $GLOBALS['gpsmap_stub_log']));
$GLOBALS['gpsmap_stub_settings']['path_php_binary'] = '/usr/bin/php';

/* A failed snapshot write must suppress pruning. This models a full or
 * read-only artifact filesystem by making all.xml an unwritable directory. */
$writeGuard = gpsmap_xml_path('10.76', 'xml');
file_put_contents($writeGuard, 'last good subnet');
touch($writeGuard, time() - 10000);
$allXml = gpsmap_xml_path('all', 'xml');
unlink($allXml);
mkdir($allXml);
$GLOBALS['gpsmap_stub_rows']['hosts'] = [gpsmap_test_row(['id' => '45', 'hostname' => '10.89.1.1'])];
$GLOBALS['gpsmap_stub_log']           = [];
assert_false('render: a failed snapshot write reports failure',
	gpsmap_render_region(gpsmap_load_devices(true), 'all', new GpsmapPollState()));
gpsmap_poller_bottom();
assert_true('poller: a failed snapshot write suppresses pruning', file_exists($writeGuard));
assert_true('poller: a failed snapshot write is logged',
	(bool) preg_grep('/did not prune artifacts/', $GLOBALS['gpsmap_stub_log']));
rmdir($allXml);
file_put_contents($allXml, '<markers><marker id="99"/></markers>');

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
