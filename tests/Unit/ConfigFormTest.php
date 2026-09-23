<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
*/

/*
 * Unit coverage for gpsmap_config_form() in setup.php - injects the map
 * fields into the host-edit form field list.
 */

beforeAll(function () {
	require_once __DIR__ . '/../../setup.php';
});

beforeEach(function () {
	$GLOBALS['fields_host_edit'] = array(
		'name'     => array('friendly_name' => 'Description'),
		'disabled' => array('friendly_name' => 'Disabled'),
		'notes'    => array('friendly_name' => 'Notes'),
	);
});

it('inserts the map fields immediately after disabled, preserving the rest', function () {
	gpsmap_config_form();

	global $fields_host_edit;

	$keys = array_keys($fields_host_edit);

	expect($keys)->toContain('name');
	expect($keys)->toContain('disabled');
	expect($keys)->toContain('gpsSpacer');
	expect($keys)->toContain('GPScoverage');
	expect($keys)->toContain('latitude');
	expect($keys)->toContain('longitude');
	expect($keys)->toContain('groupnum');
	expect($keys)->toContain('notes');

	expect(array_search('gpsSpacer', $keys))->toBeGreaterThan(array_search('disabled', $keys));
	expect($fields_host_edit['latitude']['method'])->toBe('textbox');
});
