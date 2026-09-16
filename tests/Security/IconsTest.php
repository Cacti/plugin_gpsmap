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

require_once __DIR__ . '/../../setup.php';
require_once __DIR__ . '/../../includes/polling/iconskml.php';

beforeEach(function () {
	gpsmap_test_use_tmp_root();
});

describe('gpsmap_icon_identifier', function () {
	it('accepts or rejects a filename', function (string $filename, ?string $expected) {
		expect(gpsmap_icon_identifier($filename))->toBe($expected);
	})->with([
		'simple name'      => ['Green.png', 'Green'],
		'underscore start' => ['_x.png', '_x'],
		'digits allowed'   => ['Node2.gif', 'Node2'],
		'no extension'     => ['Plain', 'Plain'],
		'dotted name'      => ['ap.v2.png', null],
		'hyphenated name'  => ['my-icon.png', null],
		'leading digit'    => ['2fast.png', null],
		'empty'            => ['', null],
		'quote injection'  => ["x';alert(1);//.png", null],
		'space'            => ['two words.png', null],
	]);
});

describe('includes/icons.php - JavaScript emitted into an inline <script>', function () {
	beforeEach(function () {
		gpsmap_test_icons(array('Green.png', 'GoogleBlue.PNG', 'ap.v2.png', 'my-icon.png', 'notes.txt', 'noext'));

		$cwd = getcwd();
		chdir(gpsmap_test_tmpdir());
		ob_start();
		include __DIR__ . '/../../includes/icons.php';
		$this->js = ob_get_clean();
		chdir($cwd);
	});

	it('emits a safe name', function () {
		expect($this->js)->toContain('gpsmap.Green = {');
	});

	it('keeps on-disk casing', function () {
		expect($this->js)->toContain('GoogleBlue.PNG');
	});

	it('drops a dotted name', function () {
		expect($this->js)->not->toContain('gpsmap.ap.v2');
	});

	it('drops a hyphenated name', function () {
		expect($this->js)->not->toContain('my-icon');
	});

	it('ignores non-images', function () {
		expect($this->js)->not->toContain('notes');
	});

	it('ignores extensionless files', function () {
		expect($this->js)->not->toContain('gpsmap.noext');
	});

	it('json encodes the url', function () {
		expect($this->js)->toContain('"/cacti/plugins/gpsmap/images/icons/Green.png"');
	});

	it('only emits bare identifiers as assignment targets', function () {
		preg_match_all('/gpsmap\.([^ ]+) = \{/', $this->js, $m);

		foreach ($m[1] as $name) {
			expect((bool) preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name))->toBeTrue();
		}
	});

	it('emits nothing for an unreadable icon directory', function () {
		$cwd = getcwd();
		chdir(sys_get_temp_dir());
		ob_start();
		@include __DIR__ . '/../../includes/icons.php';
		$empty = ob_get_clean();
		chdir($cwd);

		expect($empty)->not->toContain('gpsmap.');
	});
});

describe('iconskml() - KML Style ids', function () {
	beforeEach(function () {
		gpsmap_test_icons(array('Green.png', 'Red.jpg', 'Amber.jpeg', 'Node.gif', 'ap.v2.png', 'notes.txt'));

		$this->kml = iconskml();
	});

	it('emits a style for a png', function () {
		expect($this->kml)->toContain('<Style id="Green">');
	});

	it('emits a style for a jpg', function () {
		expect($this->kml)->toContain('<Style id="Red">');
	});

	it('emits a style for a jpeg', function () {
		expect($this->kml)->toContain('<Style id="Amber">');
	});

	it('emits a style for a gif', function () {
		expect($this->kml)->toContain('<Style id="Node">');
	});

	it('drops a dotted name', function () {
		expect($this->kml)->not->toContain('ap.v2');
	});

	it('ignores non-images', function () {
		expect($this->kml)->not->toContain('notes');
	});

	it('emits an absolute href', function () {
		expect($this->kml)->toContain('https://cacti.example//cacti/plugins/gpsmap/images/icons/Green.png');
	});

	it('logs a missing icon directory instead of failing', function () {
		$GLOBALS['gpsmap_stub_log'] = array();
		$saved                      = $GLOBALS['config']['base_path'];
		$GLOBALS['config']['base_path'] = sys_get_temp_dir() . '/gpsmap-no-such-root';

		expect(@iconskml())->toBe('');
		expect($GLOBALS['gpsmap_stub_log'][0] ?? '')->toContain('could not open icon directory');

		$GLOBALS['config']['base_path'] = $saved;
	});
});

describe('customicons.php - property-access position, degrades to undefined', function () {
	beforeEach(function () {
		$GLOBALS['gpsmap_stub_rows']['icons'] = array(
			array('templateID' => '10', 'upimage' => 'Green.png', 'downimage' => 'Red.png',    'recoverimage' => 'Yellow.png'),
			array('templateID' => '11', 'upimage' => 'ap.v2.png', 'downimage' => 'my-icon.png', 'recoverimage' => ''),
		);

		ob_start();
		include __DIR__ . '/../../includes/customicons.php';
		$this->custom = ob_get_clean();
	});

	it('maps a good icon', function () {
		expect($this->custom)->toContain('["10up"] = gpsmap.Green;');
	});

	it('degrades an unsafe icon to undefined', function () {
		expect($this->custom)->toContain('["11up"] = gpsmap.undefined;');
	});

	it('keeps the builtin up icon', function () {
		expect($this->custom)->toContain("gpsmap.customIcons['up'] = gpsmap.Green;");
	});

	it('keeps the builtin disabled icon', function () {
		expect($this->custom)->toContain("gpsmap.customIcons['disabled'] = gpsmap.Black;");
	});

	it('maps a good name via gpsmap_safe_icon_base', function () {
		expect(gpsmap_safe_icon_base('Green.png'))->toBe('Green');
	});

	it('degrades a bad name via gpsmap_safe_icon_base', function () {
		expect(gpsmap_safe_icon_base('ap.v2.png'))->toBe('undefined');
	});
});
