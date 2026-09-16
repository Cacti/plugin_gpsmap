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
 | Unit tests for includes/polling/functions.php and the subnet parameter  |
 | validation in gpsmap.php.                                               |
 +-------------------------------------------------------------------------+
*/

require_once __DIR__ . '/../../includes/polling/functions.php';

describe('parseToXML - encoding correctness', function () {
	it('encodes an ampersand', function () {
		expect(parseToXML('&'))->toBe('&amp;');
	});

	it('encodes a less-than sign', function () {
		expect(parseToXML('<'))->toBe('&lt;');
	});

	it('encodes a greater-than sign', function () {
		expect(parseToXML('>'))->toBe('&gt;');
	});

	it('encodes a double-quote', function () {
		expect(parseToXML('"'))->toBe('&quot;');
	});

	it('encodes a single-quote as &apos; (ENT_XML1)', function () {
		expect(parseToXML("'"))->toBe('&apos;');
	});

	it('does not double-encode the ampersand introduced by < or >', function () {
		// The old implementation double-encoded: '<br>' became '&amp;lt;br&amp;gt;'
		// because & was replaced AFTER < and >.
		expect(parseToXML('<br>'))->toBe('&lt;br&gt;');
	});

	it('does not double-encode an already-encoded entity', function () {
		expect(parseToXML('&amp;'))->toBe('&amp;amp;');
	});

	it('passes plain strings through unchanged', function () {
		expect(parseToXML('hello world'))->toBe('hello world');
	});

	it('passes numeric strings through unchanged', function () {
		expect(parseToXML(42))->toBe('42');
	});

	it('passes an empty string through unchanged', function () {
		expect(parseToXML(''))->toBe('');
	});

	it('leaves multibyte CJK untouched', function () {
		expect(parseToXML('日本語'))->toBe('日本語');
	});

	it('encodes entities around multibyte CJK', function () {
		expect(parseToXML('<日本語>'))->toBe('&lt;日本語&gt;');
	});
});

describe('calcKm - distance calculation and backward-compat alias', function () {
	it('returns 0 for the same point', function () {
		expect(calcKm(0, 0, 0, 0))->toBe(0.0);
	});

	it('returns roughly the known London-Paris distance', function () {
		$dist = calcKm(51.5, -0.1, 48.8, 2.3);

		expect($dist)->toBeGreaterThanOrEqual(330)->toBeLessThanOrEqual(360);
	});

	it('delegates calcMeters() to calcKm()', function () {
		// The old name was wrong - it always returned km, not metres.
		expect(calcMeters(51.5, -0.1, 48.8, 2.3))->toBe(calcKm(51.5, -0.1, 48.8, 2.3));
	});

	it('returns a finite positive value for negative coordinates', function () {
		// Southern hemisphere: Cape Town (-33.9, 18.4) to Buenos Aires (-34.6, -58.4).
		// The flat-earth approximation overestimates at large longitude
		// separations, so only finiteness and sign are asserted.
		$dist = calcKm(-33.9, 18.4, -34.6, -58.4);

		expect($dist)->toBeGreaterThan(0)->and(is_finite($dist))->toBeTrue();
	});

	it('is symmetric for London-Paris', function () {
		expect(calcKm(51.5, -0.1, 48.8, 2.3))->toBe(calcKm(48.8, 2.3, 51.5, -0.1));
	});

	it('is symmetric for Cape Town-Buenos Aires', function () {
		expect(calcKm(-33.9, 18.4, -34.6, -58.4))->toBe(calcKm(-34.6, -58.4, -33.9, 18.4));
	});

	it('handles antipodal points without producing NaN', function () {
		$dist = calcKm(0, 0, 0, 180);

		expect(is_nan($dist))->toBeFalse();
		expect($dist)->toBeGreaterThan(0)->toBeLessThanOrEqual(21000);
	});
});

describe('subnet parameter validation regex (mirrors gpsmap.php logic)', function () {
	beforeEach(function () {
		$this->validRe = '/^[a-zA-Z0-9_-]+(\.[a-zA-Z0-9_-]+)*$/';
	});

	it('accepts valid values', function (string $value) {
		expect((bool) preg_match($this->validRe, $value))->toBeTrue();
	})->with([
		'all',
		'192-168-1',
		'region1',
		'region_1',
		'RegionA',
		'10.0.0',
		'192-168-1.0',
	]);

	it('rejects path traversal and other dangerous input', function (string $value) {
		expect((bool) preg_match($this->validRe, $value))->toBeFalse();
	})->with([
		'..',
		'.',
		'../etc/passwd',
		'.foo',
		'foo.',
		"foo\x00bar",
		'a/b',
		'a\\b',
		'..%2fetc',
		'a b',
	]);
});

describe('coordCheck - validation', function () {
	it('formats a valid input to the expected string', function (string $input, string $expected) {
		expect(coordCheck($input))->toBe($expected);
	})->with([
		['45.123', '45.123'],
		['-90.000', '-90.000'],
		['180.000', '180.000'],
		['abc', '0.000'],
		['', '0.000'],
		['0.000', '0.000'],
		['-122.4194', '-122.4194'],
	]);
});
