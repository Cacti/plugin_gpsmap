<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Tests for the host value object.                                        |
 +-------------------------------------------------------------------------+
*/

/* Never reachable over HTTP.  Cacti deploys plugins inside the web root, so
 * plugins/gpsmap/tests/ would otherwise be a public endpoint that resolves DNS
 * and writes to the filesystem. */
if (PHP_SAPI !== 'cli') {
	exit;
}

require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/../class/hosts_class.php';

function gpsmap_test_make_host(string $coverage = 'on', int $radius = 0): host {
	return new host(
		'7', '3', '51.5074', '-0.1278', '10.0.0.7', 'London PoP', 'pop.example',
		$radius, '99.5', 'up', '4.2', $coverage,
		'Green.png', 'Red.png', 'Yellow.png', '0', '360', '2'
	);
}

$h = gpsmap_test_make_host();

assert_equal('host: id promoted',          '7',           $h->id);
assert_equal('host: type promoted',        '3',           $h->type);
assert_equal('host: lat promoted',         '51.5074',     $h->lat);
assert_equal('host: long promoted',        '-0.1278',     $h->long);
assert_equal('host: iprange promoted',     '10.0.0.7',    $h->iprange);
assert_equal('host: description promoted', 'London PoP',  $h->description);
assert_equal('host: hostname promoted',    'pop.example', $h->hostname);
assert_equal('host: avail promoted',       '99.5',        $h->avail);
assert_equal('host: status promoted',      'up',          $h->status);
assert_equal('host: latency promoted',     '4.2',         $h->latency);
assert_equal('host: upimage promoted',     'Green.png',   $h->upimage);
assert_equal('host: downimage promoted',   'Red.png',     $h->downimage);
assert_equal('host: recoverimage promoted','Yellow.png',  $h->recoverimage);
assert_equal('host: start promoted',       '0',           $h->start);
assert_equal('host: stop promoted',        '360',         $h->stop);
assert_equal('host: group promoted',       '2',           $h->group);

// radius arrives as int and is stored as the string the XML writer emits.
assert_equal('host: radius int cast to string', '0', $h->radius);
assert_equal('host: radius non-zero', '15', gpsmap_test_make_host('on', 15)->radius);
assert_equal('host: configured radius retains its reset baseline', '15',
	gpsmap_test_make_host('on', 15)->configuredRadius);

// Cacti checkbox contract: 'on' means ticked, anything else means unticked.
assert_equal('host: coverage on',    1, gpsmap_test_make_host('on')->coverage);
assert_equal('host: coverage empty', 0, gpsmap_test_make_host('')->coverage);
assert_equal('host: coverage off',   0, gpsmap_test_make_host('off')->coverage);

assert_equal('host: showMap defaults visible', 1, $h->showMap);

/* coveragexml.php widens the radius by assigning a float onto the string
 * property; the class has to tolerate that. */
$h->radius = (string) 12.75;
assert_equal('host: radius accepts widened value', '12.75', $h->radius);

$h->showMap = 0;
assert_equal('host: showMap is mutable', 0, $h->showMap);

if (!defined('GPSMAP_TEST_SUITE')) {
	exit(gpsmap_test_summary());
}
