<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Tests for the host value object.                                        |
 +-------------------------------------------------------------------------+
*/

require_once __DIR__ . '/../../class/hosts_class.php';

function gpsmap_test_make_host(string $coverage = 'on', int $radius = 0): host {
	return new host(
		'7', '3', '51.5074', '-0.1278', '10.0.0.7', 'London PoP', 'pop.example',
		$radius, '99.5', 'up', '4.2', $coverage,
		'Green.png', 'Red.png', 'Yellow.png', '0', '360', '2'
	);
}

beforeEach(function () {
	$this->host = gpsmap_test_make_host();
});

it('promotes constructor arguments onto public properties', function () {
	expect($this->host->id)->toBe('7');
	expect($this->host->type)->toBe('3');
	expect($this->host->lat)->toBe('51.5074');
	expect($this->host->long)->toBe('-0.1278');
	expect($this->host->iprange)->toBe('10.0.0.7');
	expect($this->host->description)->toBe('London PoP');
	expect($this->host->hostname)->toBe('pop.example');
	expect($this->host->avail)->toBe('99.5');
	expect($this->host->status)->toBe('up');
	expect($this->host->latency)->toBe('4.2');
	expect($this->host->upimage)->toBe('Green.png');
	expect($this->host->downimage)->toBe('Red.png');
	expect($this->host->recoverimage)->toBe('Yellow.png');
	expect($this->host->start)->toBe('0');
	expect($this->host->stop)->toBe('360');
	expect($this->host->group)->toBe('2');
});

it('casts the radius int to the string the XML writer emits', function () {
	expect($this->host->radius)->toBe('0');
	expect(gpsmap_test_make_host('on', 15)->radius)->toBe('15');
});

it('retains the configured radius as a reset baseline', function () {
	expect(gpsmap_test_make_host('on', 15)->configuredRadius)->toBe('15');
});

it('follows the Cacti checkbox contract for coverage: on means ticked', function () {
	expect(gpsmap_test_make_host('on')->coverage)->toBe(1);
	expect(gpsmap_test_make_host('')->coverage)->toBe(0);
	expect(gpsmap_test_make_host('off')->coverage)->toBe(0);
});

it('defaults showMap to visible', function () {
	expect($this->host->showMap)->toBe(1);
});

it('accepts a radius widened to a float by coveragexml.php', function () {
	// coveragexml.php widens the radius by assigning a float onto the string
	// property; the class has to tolerate that.
	$this->host->radius = (string) 12.75;

	expect($this->host->radius)->toBe('12.75');
});

it('allows showMap to be mutated', function () {
	$this->host->showMap = 0;

	expect($this->host->showMap)->toBe(0);
});
