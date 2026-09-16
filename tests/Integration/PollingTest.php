<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Tests for the poller path: region(), the XML/KML writers and the        |
 | coverage overlay.                                                       |
 +-------------------------------------------------------------------------+
*/

require_once __DIR__ . '/../../setup.php';
require_once __DIR__ . '/../../class/hosts_class.php';
require_once __DIR__ . '/../../includes/polling/functions.php';
require_once __DIR__ . '/../../includes/polling/processregion.php';

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

function gpsmap_test_marker_host(string $type = '10'): host {
	return new host('5', $type, '1.0', '2.0', '10.0.0.5', 'A & B', 'h.example',
		0, '99', 'up', '1', 'on', 'Green.png', 'Red.png', 'Yellow.png', '0', '360', '1');
}

function gpsmap_test_tower_radius(string $xml): ?float {
	if (preg_match('/<marker [^>]*radius="([0-9.]+)"[^>]*start=/', $xml, $m)) {
		return (float) $m[1];
	}

	return null;
}

beforeEach(function () {
	gpsmap_test_use_tmp_root();
	gpsmap_test_icons(array('Green.png', 'Red.png', 'Yellow.png', 'GoogleBlue.png', 'ap.v2.png', 'readme.txt'));

	$this->root = gpsmap_test_tmpdir();

	// Order-independent: every test starts from a clean fixture set.
	$GLOBALS['gpsmap_stub_rows']     = array();
	$GLOBALS['gpsmap_stub_settings'] = array('base_url' => 'https://cacti.example/');
	$GLOBALS['gpsmap_stub_log']      = array();
});

describe('gpsmap_xml_path / gpsmap_write_file', function () {
	it('strips surrounding dots from the subnet name', function () {
		expect(gpsmap_xml_path('.10.1.2.', 'xml'))->toBe($this->root . '/plugins/gpsmap/XML/10.1.2.xml');
	});

	it('appends the requested extension', function () {
		expect(gpsmap_xml_path('all', 'kml'))->toBe($this->root . '/plugins/gpsmap/XML/all.kml');
	});

	it('writes the file contents', function () {
		expect(gpsmap_write_file(gpsmap_xml_path('probe', 'xml'), 'body'))->toBeTrue();
		expect(file_get_contents(gpsmap_xml_path('probe', 'xml')))->toBe('body');
	});

	it('returns false and logs when the path is unwritable', function () {
		expect(gpsmap_write_file($this->root . '/no/such/dir/x.xml', 'body'))->toBeFalse();
		expect($GLOBALS['gpsmap_stub_log'][0] ?? '')->toContain('Unable to write to');
	});

	it('returns false and logs on a short write', function () {
		expect(gpsmap_write_file('gpsmapshort://target', str_repeat('x', 64)))->toBeFalse();
		expect($GLOBALS['gpsmap_stub_log'][0] ?? '')->toContain('Unable to write to');
	});

	it('rolls back a failed rename rather than leaving a stray temp file', function () {
		$blocked = $this->root . '/plugins/gpsmap/XML/occupied';
		@mkdir($blocked, 0700, true);
		file_put_contents($blocked . '/child', 'x');

		expect(gpsmap_write_file($blocked, 'body'))->toBeFalse();
		expect($GLOBALS['gpsmap_stub_log'][0] ?? '')->toContain('Unable to write to');
		expect(preg_grep('/occupied\..*\.tmp$/', scandir($this->root . '/plugins/gpsmap/XML')))->toBe(array());
	});
});

describe('getTowerIds / createTypeArray', function () {
	it('returns the sentinel when there are no towers', function () {
		$GLOBALS['gpsmap_stub_rows']['towers'] = array();

		expect(getTowerIds())->toBe(array(9999));
	});

	it('returns the tower template ids', function () {
		$GLOBALS['gpsmap_stub_rows']['towers'] = array(array('templateID' => '10'), array('templateID' => '11'));

		expect(getTowerIds())->toBe(array('10', '11'));
	});

	it('keys template names by id', function () {
		$GLOBALS['gpsmap_stub_rows']['templates'] = array(
			array('id' => '10', 'name' => 'Tower'),
			array('id' => '20', 'name' => 'Switch'),
		);

		expect(createTypeArray())->toBe(array('10' => 'Tower', '20' => 'Switch'));
	});
});

describe('gpsmap_marker', function () {
	it('renders a device marker without a schedule', function () {
		$marker = gpsmap_marker(gpsmap_test_marker_host(), array('10' => 'Tower'), '0', false);

		expect($marker)->toContain('id="5"');
		expect($marker)->toContain('name="A &amp; B"');
		expect($marker)->toContain('type="Tower"');
		expect($marker)->toContain('radius="0"');
		expect($marker)->not->toContain('start=');
	});

	it('renders a tower marker with its coverage schedule', function () {
		$towerMarker = gpsmap_marker(gpsmap_test_marker_host(), array('10' => 'Tower'), '42.5', true);

		expect($towerMarker)->toContain('radius="42.5"');
		expect($towerMarker)->toContain('start="0"');
		expect($towerMarker)->toContain('stop="360"');
	});

	it('falls back to Unknown for an unrecognized template', function () {
		$unknown = gpsmap_marker(gpsmap_test_marker_host('99'), array('10' => 'Tower'), '0', false);

		expect($unknown)->toContain('type="Unknown"');
	});
});

it('createXMLNodes skips hosts the traversal switched off', function () {
	$visible = gpsmap_test_marker_host();
	$hidden  = gpsmap_test_marker_host();
	$hidden->showMap = 0;

	$nodes = createXMLNodes(array($visible, $hidden));

	expect(substr_count($nodes, '<marker '))->toBe(1);
	expect(createXMLNodes(array()))->toBe('');
});

it('runs a full poller pass: writes markers, statuses, KML styles and navigation', function () {
	$GLOBALS['gpsmap_stub_rows']['hosts'] = array(
		gpsmap_test_row(array('id' => '1', 'hostname' => '10.1.2.3', 'host_template_id' => '10', 'groupnum' => '1')),
		gpsmap_test_row(array('id' => '2', 'hostname' => '10.1.2.4', 'host_template_id' => '20', 'groupnum' => '1', 'status' => '1',
			// same group as the tower but 250km away, so the coverage radius has to grow
			'latitude' => '53.4808', 'longitude' => '-2.2426')),
		gpsmap_test_row(array('id' => '3', 'hostname' => '10.9.9.9', 'host_template_id' => '20', 'groupnum' => '2', 'status' => '2')),
		// filtered: no coordinates
		gpsmap_test_row(array('id' => '4', 'latitude' => '0.000', 'longitude' => '0.000')),
		gpsmap_test_row(array('id' => '5', 'longitude' => '0.000')),
		// filtered: name does not resolve to a dotted quad
		gpsmap_test_row(array('id' => '6', 'hostname' => 'not-an-ip')),
		// disabled device keeps its own status label
		gpsmap_test_row(array('id' => '7', 'hostname' => '10.1.2.7', 'disabled' => 'on', 'status' => '3')),
		// status outside 1/2/3 falls through to 'undefined'
		gpsmap_test_row(array('id' => '8', 'hostname' => '10.1.2.8', 'status' => '9')),
		// GoogleXxx icons map onto Google's own KML styles
		gpsmap_test_row(array('id' => '9', 'hostname' => '10.1.2.9', 'upimage' => 'GoogleBlue.png')),
	);
	$GLOBALS['gpsmap_stub_rows']['towers'] = array(array('templateID' => '10'));
	$GLOBALS['gpsmap_stub_settings']['gpsmap_enableall'] = 'on';

	region('all');

	$xml = file_get_contents(gpsmap_xml_path('all', 'xml'));
	$kml = file_get_contents(gpsmap_xml_path('all', 'kml'));
	$top = file_get_contents($this->root . '/plugins/gpsmap/XML/all-top.html');

	expect($xml)->toContain('<markers>');
	expect($xml)->toContain('status="up"');
	expect($xml)->toContain('status="down"');
	expect($xml)->toContain('status="recovering"');
	expect($xml)->toContain('status="disabled"');
	expect($xml)->toContain('status="undefined"');
	expect($xml)->not->toContain('id="4"');
	expect($xml)->not->toContain('id="6"');
	expect($kml)->toContain('<kml');
	expect($kml)->toContain('<Placemark>');
	expect($kml)->toContain('<styleUrl>Green</styleUrl>');
	expect($kml)->toContain('<styleUrl>blue</styleUrl>');
	expect($top)->toContain('gpstopmenu');
	expect($top)->toContain('subnet=10');

	// Tower markers carry the coverage radius grown to the furthest group member.
	expect((bool) preg_match('/radius="[1-9][0-9]*(\.[0-9]+)?"/', $xml))->toBeTrue();
});

it('takes the WHERE-clause branch and writes its own file when enableAll is off', function () {
	$GLOBALS['gpsmap_stub_rows']['hosts'] = array(
		gpsmap_test_row(array('id' => '1', 'hostname' => '10.1.2.3', 'host_template_id' => '10', 'groupnum' => '1')),
	);
	$GLOBALS['gpsmap_stub_rows']['towers'] = array(array('templateID' => '10'));
	$GLOBALS['gpsmap_stub_settings']['gpsmap_enableall'] = '';

	region('10.1.2.');

	expect(file_get_contents(gpsmap_xml_path('10.1.2.', 'xml')))->toContain('<markers>');
});

it('emits per-device graph links at the deepest level', function () {
	$GLOBALS['gpsmap_stub_rows']['hosts'] = array(
		gpsmap_test_row(array('id' => '1', 'hostname' => '10.1.2.3', 'host_template_id' => '10', 'groupnum' => '1')),
	);
	$GLOBALS['gpsmap_stub_rows']['towers'] = array(array('templateID' => '10'));
	$GLOBALS['gpsmap_stub_settings']['gpsmap_enableall'] = 'on';

	region('10.1.2.');

	expect(file_get_contents($this->root . '/plugins/gpsmap/XML/10.1.2-top.html'))->toContain('graph_view.php');
});

it('draws no markers past the deepest level', function () {
	$GLOBALS['gpsmap_stub_rows']['hosts'] = array(
		gpsmap_test_row(array('id' => '1', 'hostname' => '10.1.2.3', 'host_template_id' => '10', 'groupnum' => '1')),
	);
	$GLOBALS['gpsmap_stub_rows']['towers'] = array(array('templateID' => '10'));
	$GLOBALS['gpsmap_stub_settings']['gpsmap_enableall'] = 'on';

	region('10.1.2.3.4.');

	expect(file_get_contents(gpsmap_xml_path('10.1.2.3.4.', 'xml')))->not->toContain('<marker ');
});

it('treats an empty subnet as all', function () {
	$GLOBALS['gpsmap_stub_rows']['hosts'] = array(gpsmap_test_row());
	$GLOBALS['gpsmap_stub_rows']['towers'] = array(array('templateID' => '10'));
	$GLOBALS['gpsmap_stub_settings']['gpsmap_enableall'] = 'on';

	region('');

	expect(file_exists($this->root . '/plugins/gpsmap/XML/all-top.html'))->toBeTrue();
});

it('callRegion wires the includes together', function () {
	$GLOBALS['gpsmap_stub_rows']['hosts'] = array(gpsmap_test_row());
	$GLOBALS['gpsmap_stub_rows']['towers'] = array(array('templateID' => '10'));
	$GLOBALS['gpsmap_stub_settings']['gpsmap_enableall'] = 'on';

	callRegion('all');

	expect(file_get_contents($this->root . '/plugins/gpsmap/XML/all-top.html'))->toContain('gpstopmenu');
});

it('does not let navigation accumulate across repeated region() calls in one process', function () {
	// Regression: the poller calls region() many times in one process. Each
	// -top.html must contain only its own navigation. This is what a global
	// $body silently broke.
	$GLOBALS['gpsmap_stub_rows']['hosts'] = array(
		gpsmap_test_row(array('id' => '1', 'hostname' => '10.1.2.3', 'host_template_id' => '10', 'groupnum' => '1')),
		gpsmap_test_row(array('id' => '2', 'hostname' => '10.9.9.9', 'host_template_id' => '10', 'groupnum' => '1')),
	);
	$GLOBALS['gpsmap_stub_rows']['towers'] = array(array('templateID' => '10'));
	$GLOBALS['gpsmap_stub_settings']['gpsmap_enableall'] = 'on';

	region('10.1.2.');
	$first = file_get_contents($this->root . '/plugins/gpsmap/XML/10.1.2-top.html');
	region('10.9.9.');
	$second = file_get_contents($this->root . '/plugins/gpsmap/XML/10.9.9-top.html');

	expect(substr_count($second, 'gpstopmenu'))->toBe(1);
	expect($second)->not->toContain('host_id=1"');

	region('10.1.2.');
	expect(file_get_contents($this->root . '/plugins/gpsmap/XML/10.1.2-top.html'))->toBe($first);
});

it('does not widen the tower radius for a device in a different group', function () {
	$GLOBALS['gpsmap_stub_rows']['towers'] = array(array('templateID' => '10'));
	$GLOBALS['gpsmap_stub_rows']['hosts']  = array(
		gpsmap_test_row(array('id' => '1', 'hostname' => '10.5.0.1', 'host_template_id' => '10', 'groupnum' => '1')),
		// different group, must not widen the tower
		gpsmap_test_row(array('id' => '2', 'hostname' => '10.5.0.2', 'host_template_id' => '20', 'groupnum' => '7',
			'latitude' => '-33.8688', 'longitude' => '151.2093')),
	);
	$GLOBALS['gpsmap_stub_settings']['gpsmap_enableall'] = 'on';

	region('all');

	expect(gpsmap_test_tower_radius(file_get_contents(gpsmap_xml_path('all', 'xml'))))->toBe(0.0);
});

it('keeps the furthest group member for the coverage radius, not the last one seen', function () {
	$GLOBALS['gpsmap_stub_rows']['towers'] = array(array('templateID' => '10'));
	$GLOBALS['gpsmap_stub_rows']['hosts']  = array(
		gpsmap_test_row(array('id' => '1', 'hostname' => '10.5.0.1', 'host_template_id' => '10', 'groupnum' => '1')),
		// furthest member listed first, nearest last: the radius must keep the max
		gpsmap_test_row(array('id' => '2', 'hostname' => '10.5.0.2', 'host_template_id' => '20', 'groupnum' => '1',
			'latitude' => '-33.8688', 'longitude' => '151.2093')),
		gpsmap_test_row(array('id' => '3', 'hostname' => '10.5.0.3', 'host_template_id' => '20', 'groupnum' => '1',
			'latitude' => '51.5080', 'longitude' => '-0.1280')),
	);
	$GLOBALS['gpsmap_stub_settings']['gpsmap_enableall'] = 'on';

	region('all');

	expect(gpsmap_test_tower_radius(file_get_contents(gpsmap_xml_path('all', 'xml'))))->toBeGreaterThan(1000.0);
});

it('ignores a device with coverage switched off', function () {
	$GLOBALS['gpsmap_stub_rows']['towers'] = array(array('templateID' => '10'));
	$GLOBALS['gpsmap_stub_rows']['hosts']  = array(
		gpsmap_test_row(array('id' => '1', 'hostname' => '10.5.0.1', 'host_template_id' => '10', 'groupnum' => '1')),
		gpsmap_test_row(array('id' => '2', 'hostname' => '10.5.0.2', 'host_template_id' => '20', 'groupnum' => '1',
			'GPScoverage' => '', 'latitude' => '-33.8688', 'longitude' => '151.2093')),
	);
	$GLOBALS['gpsmap_stub_settings']['gpsmap_enableall'] = 'on';

	region('all');

	expect(gpsmap_test_tower_radius(file_get_contents(gpsmap_xml_path('all', 'xml'))))->toBe(0.0);
});

it('yields one graph link when two devices resolve to the same address', function () {
	// The de-duplication guard used to test a different string than it stored.
	$GLOBALS['gpsmap_stub_rows']['towers'] = array(array('templateID' => '10'));
	$GLOBALS['gpsmap_stub_rows']['hosts']  = array(
		gpsmap_test_row(array('id' => '11', 'hostname' => '10.4.4.4', 'host_template_id' => '20')),
		gpsmap_test_row(array('id' => '12', 'hostname' => '10.4.4.4', 'host_template_id' => '20')),
	);
	$GLOBALS['gpsmap_stub_settings']['gpsmap_enableall'] = 'on';

	region('10.4.4.');

	expect(substr_count(file_get_contents($this->root . '/plugins/gpsmap/XML/10.4.4-top.html'), 'graph_view.php'))->toBe(1);
});

it('reaches the query for the Display Disabled Devices setting, ignoring any stale global', function () {
	// pollinginitial.php assigns $enableAll inside callRegion(), so a global
	// region() read was always null and the setting was inert.
	$GLOBALS['gpsmap_stub_rows']['hosts'] = array(gpsmap_test_row(array('id' => '31', 'hostname' => '10.7.7.1')));
	$GLOBALS['gpsmap_stub_rows']['towers'] = array(array('templateID' => '10'));

	$GLOBALS['gpsmap_stub_settings']['gpsmap_enableall'] = '';
	region('all');
	expect($GLOBALS['gpsmap_stub_host_sql'])->toContain('h.disabled = ?');

	$GLOBALS['gpsmap_stub_settings']['gpsmap_enableall'] = 'on';
	region('all');
	expect($GLOBALS['gpsmap_stub_host_sql'])->not->toContain('h.disabled = ?');

	$GLOBALS['enableAll'] = '';
	$GLOBALS['gpsmap_stub_settings']['gpsmap_enableall'] = 'on';
	region('all');
	expect($GLOBALS['gpsmap_stub_host_sql'])->not->toContain('h.disabled = ?');
	unset($GLOBALS['enableAll']);
});

it('retains calcMeters as a delegating deprecated alias', function () {
	expect(calcMeters(1.0, 2.0, 3.0, 4.0))->toBe(calcKm(1.0, 2.0, 3.0, 4.0));
});
