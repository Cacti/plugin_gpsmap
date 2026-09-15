<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Tests for gpsmap_normalize_icon_name(), which gates the icon names the  |
 | template admin form is allowed to store.                                |
 +-------------------------------------------------------------------------+
*/

require_once __DIR__ . '/../../gpsmap_security.php';
require_once __DIR__ . '/../../includes/polling/functions.php';

describe('gpsmap_normalize_icon_name', function () {
	beforeEach(function () {
		// getIcons() returns a name-keyed map, so membership is an isset() check.
		$this->icons = array('Green.png' => 'Green.png', 'Red.png' => 'Red.png', 'Orange.png' => 'Orange.png');
	});

	it('passes a known name through unchanged', function () {
		expect(gpsmap_normalize_icon_name('Red.png', $this->icons))->toBe('Red.png');
	});

	it('falls back for an unknown name, empty string, null, array or integer', function ($value) {
		expect(gpsmap_normalize_icon_name($value, $this->icons))->toBe('Green.png');
	})->with([
		'Evil.png',
		'',
		null,
		[['Red.png']],
		1,
	]);

	it('falls back when the icon set is empty', function () {
		expect(gpsmap_normalize_icon_name('Red.png', array()))->toBe('Green.png');
	});

	it('rejects path traversal attempts', function () {
		expect(gpsmap_normalize_icon_name('../../../etc/passwd', $this->icons))->toBe('Green.png');
	});

	it('rejects null byte injection attempts', function () {
		expect(gpsmap_normalize_icon_name("Red.png\0.txt", $this->icons))->toBe('Green.png');
	});

	it('uses the caller-supplied default for the recover icon', function () {
		expect(gpsmap_normalize_icon_name('nope', $this->icons, 'Orange.png'))->toBe('Orange.png');
	});

	it('uses the caller-supplied default for the down icon', function () {
		expect(gpsmap_normalize_icon_name('nope', $this->icons, 'Red.png'))->toBe('Red.png');
	});
});

describe('coordCheck boundaries against the anchored pattern', function () {
	it('rejects four integer digits', function () {
		expect(coordCheck('1234.5'))->toBe('0.000');
	});

	it('rejects a value with no decimal point', function () {
		expect(coordCheck('51'))->toBe('0.000');
	});

	it('rejects a trailing decimal point', function () {
		expect(coordCheck('51.'))->toBe('0.000');
	});

	it('trims surrounding space', function () {
		expect(coordCheck(' 51.5 '))->toBe('51.5');
	});

	it('rejects a double negative', function () {
		expect(coordCheck('--5.0'))->toBe('0.000');
	});

	it('rejects exponent notation', function () {
		expect(coordCheck('1.0e2'))->toBe('0.000');
	});

	it('allows three integer digits', function () {
		expect(coordCheck('180.0'))->toBe('180.0');
	});
});

describe('calcKm degenerate and extreme inputs', function () {
	it('returns zero for identical coordinates', function () {
		expect(calcKm(51.5, -0.12, 51.5, -0.12))->toBe(0.0);
	});

	it('is finite for an antimeridian pair', function () {
		expect(is_finite(calcKm(0.0, 179.9, 0.0, -179.9)))->toBeTrue();
	});
});
