<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2009-2013 Andrew Aloia                                    |
 | Copyright (C) 2014 Wixiweb                                              |
 | Copyright (C) 2017-2018 The Cacti Group                                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

function plugin_gpsmap_install() {
	global $config;

	api_plugin_register_hook('gpsmap', 'top_header_tabs',       'gpsmap_show_tab',             'includes/setup/tabs.php');
	api_plugin_register_hook('gpsmap', 'top_graph_header_tabs', 'gpsmap_show_tab',             'includes/setup/tabs.php');
	api_plugin_register_hook('gpsmap', 'config_arrays',         'gpsmap_config_arrays',        'includes/setup/settings.php');
	api_plugin_register_hook('gpsmap', 'config_settings',       'gpsmap_config_settings',      'includes/setup/settings.php');
	api_plugin_register_hook('gpsmap', 'draw_navigation_text',  'gpsmap_draw_navigation_text', 'includes/setup/settings.php');
	api_plugin_register_hook('gpsmap', 'api_device_save',       'gpsmap_api_device_save',      'includes/setup/settings.php');
	api_plugin_register_hook('gpsmap', 'config_form',           'gpsmap_config_form',          'setup.php');
	api_plugin_register_hook('gpsmap', 'poller_bottom',         'gpsmap_poller_bottom',        'includes/polling.php');
	api_plugin_register_hook('gpsmap', 'page_head',             'gpsmap_page_head',            'setup.php');

	api_plugin_register_realm('gpsmap', 'gpstemplates.php,gpstemplates_add.php',__('Configure Maps', 'gpsmap'), 1);
	api_plugin_register_realm('gpsmap', 'gpsmap.php', __('View Maps', 'gpsmap'), 1);

	include_once($config['base_path'] . '/plugins/gpsmap/includes/setup/database.php');

	gpsmap_setup_database();
}


function plugin_gpsmap_uninstall() {
	//gpsmap_remove_database();
}

function plugin_gpsmap_check_config() {
	gpsmap_check_upgrade();
	return true;
}

function plugin_gpsmap_upgrade() {
	gpsmap_check_upgrade();
	return false;
}

function plugin_gpsmap_version() {
	return gpsmap_version();
}

function gpsmap_check_upgrade() {
	global $config;

	$files = array('gpsmap.php','gpstemplates.php','poller.php');

	if (isset($_SERVER['PHP_SELF']) && !in_array(basename($_SERVER['PHP_SELF']), $files)) {
		return;
	}

	$info = plugin_gpsmap_version();
	$current = $info['version'];
	$old = read_config_option('plugin_gpsmap_version', TRUE);
	if ($current != $old) {
		include_once($config['base_path'] . '/plugins/gpsmap/includes/database.php');
		gpsmap_upgrade_database();
	}
}

function gpsmap_page_head() {
	global $config;

	$apiKey = read_config_option('gpsmap_apikey');

	print "<script type='text/javascript' src='https://maps.googleapis.com/maps/api/js?" . (empty($apiKey) === false ? "key=" . $apiKey . '&' : '') . "libraries=geometry'></script>" . PHP_EOL;
	print "<script type='text/javascript' src='" . $config['url_path'] . "plugins/gpsmap/js/GPSMaps.js'></script>" . PHP_EOL;
	print "<script type='text/javascript' src='" . $config['url_path'] . "plugins/gpsmap/js/infobubble.js'></script>" . PHP_EOL;
}

function gpsmap_version() {
	global $config;
	$info = parse_ini_file($config['base_path'] . '/plugins/gpsmap/INFO', true);
	return $info['info'];
}

//defines latitude and longitude for devices
function gpsmap_config_form() {
	global $fields_host_edit, $url_path;

	$fields_host_edit2 = $fields_host_edit;
	$fields_host_edit3 = array();

	foreach ($fields_host_edit2 as $f => $a) {
		$fields_host_edit3[$f] = $a;
		if ($f == 'disabled') {
			$fields_host_edit3['gpsSpacer'] = array(
				'friendly_name' => __('Map Settings', 'gpsmap'),
				'method' => 'spacer',
			);

			$fields_host_edit3['GPScoverage'] = array(
				'friendly_name' => __('Overlay Inclusion', 'gpsmap'),
				'description' => __('Disable to plot host only, not included in coverage overlay.', 'gpsmap'),
				'method' => 'checkbox',
				'value' => '|arg1:GPScoverage|',
				'default' => 'on',
			);

			$fields_host_edit3['latitude'] = array(
				'friendly_name' => __('Latitude', 'gpsmap'),
				'description' => __('The devices latitude coordinates', 'gpsmap'),
				'method' => 'textbox',
				'max_length' => 13,
				'value' => '|arg1:latitude|',
				'default' => '',
			);

			$fields_host_edit3['longitude'] = array(
				'friendly_name' => __('Longitude', 'gpsmap'),
				'description' => __('The devices longitude coordinates', 'gpsmap'),
				'method' => 'textbox',
				'max_length' => 13,
				'value' => '|arg1:longitude|',
				'default' => '',
			);

			if (isset_request_var('id') && get_current_page() == $url_path . 'host.php') {
				$did = get_filter_request_var('id');

				$row = db_fetch_row_prepared('SELECT AP
					FROM `host`
					RIGHT JOIN gpsmap_templates
					ON host.host_template_id = gpsmap_templates.templateID
					WHERE id = ?',
					array($did));

				if (sizeof($row) && $row['AP'] == 1) {
					$fields_host_edit3['start'] = array(
						'friendly_name' => __('Starting Degree', 'gpsmap'),
						'description' => __('Starting degree for directional area between 0-360', 'gpsmap'),
						'method' => 'textbox',
						'max_length' => 4,
						'value' => '|arg1:start|',
						'default' => '0',
					);

					$fields_host_edit3['stop'] = array(
						'friendly_name' => __('Stopping Degree', 'gpsmap'),
						'description' => __('Stopping degree for directional area, must be greater than the Starting Degree', 'gpsmap'),
						'method' => 'textbox',
						'max_length' => 4,
						'value' => '|arg1:stop|',
						'default' => '360',
					);

					$fields_host_edit3['rdistance'] = array(
						'friendly_name' => __('Specify Radius', 'gpsmap'),
						'description' => __('Manually specify radius for Access Point. Set to 0 to determine radius based on grouped devices', 'gpsmap'),
						'method' => 'textbox',
						'max_length' => 10,
						'value' => '|arg1:rdistance|',
						'default' => '0',
					);
				}
			}

			$fields_host_edit3['groupnum'] = array(
				'friendly_name' => __('Group ID', 'gpsmap'),
				'description' => __('Groups define what devices are included in the coverage overlay. Will be checked against AP device group number. 0 to disable', 'gpsmap'),
				'method' => 'textbox',
				'max_length' => 3,
				'value' => '|arg1:groupnum|',
				'default' => '0',
			);

		}
	}

	$fields_host_edit = $fields_host_edit3;
}

