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

function gpsmap_draw_navigation_text($nav) {
   $nav['gpsmap.php:'] = array(
		'title' => __('Maps', 'gpsmap'),
		'mapping' => '',
		'url' => 'gpsmap.php',
		'level' => '0'
	);

   $nav['gpstemplates.php:'] = array(
		'title' => __('Map Templates', 'gpsmap'),
		'mapping' => 'index.php:',
		'url' => 'gpstemplates.php',
		'level' => '1'
	);

   $nav['gpstemplates.php:save'] = array(
		'title' => __('Map Templates', 'gpsmap'),
		'mapping' => 'index.php:',
		'url' => 'gpstemplates.php',
		'level' => '1'
	);

   $nav['gpstemplates.php:add'] = array(
		'title' => __('Map Templates', 'gpsmap'),
		'mapping' => 'index.php:',
		'url' => 'gpstemplates.php',
		'level' => '1'
	);

   $nav['gpstemplates.php:actions'] = array(
		'title' => __('Map Templates', 'gpsmap'),
		'mapping' => 'index.php:',
		'url' => 'gpstemplates.php',
		'level' => '1'
	);

   $nav['gpstemplates_add.php:'] = array(
		'title' => __('Create New Template', 'gpsmap'),
		'mapping' => 'index.php:',
		'url' => 'gpstemplates_add.php',
		'level' => '1'
	);

   return $nav;
}

function gpsmap_config_arrays() {
   global $menu;
   $menu[__('Templates')]['plugins/gpsmap/gpstemplates.php'] = __('Map', 'gpsmap');
}

function gpsmap_api_device_save($save) {
	if (isset_request_var('GPScoverage')) {
		$save['GPScoverage'] = 'on';
	} else {
		$save['GPScoverage'] = 'off';
	}

    if (isset_request_var('latitude')) {
        $save['latitude'] = form_input_validate(get_nfilter_request_var('latitude'), 'latitude', '', true, 3);
	} else {
        $save['latitude'] = form_input_validate('', 'latitude', '', true, 3);
	}

    if (isset_request_var('longitude')) {
        $save['longitude'] = form_input_validate(get_nfilter_request_var('longitude'), 'longitude', '', true, 3);
	} else {
        $save['longitude'] = form_input_validate('', 'longitude', '', true, 3);
	}

    if (isset_request_var('start')) {
        $save['start'] = form_input_validate(get_nfilter_request_var('start'), 'start', '', true, 3);
	} else {
        $save['start'] = form_input_validate('', 'start', '', true, 3);
	}

    if (isset_request_var('stop')) {
        $save['stop'] = form_input_validate(get_nfilter_request_var('stop'), 'stop', '', true, 3);
	} else {
        $save['stop'] = form_input_validate('', 'stop', '', true, 3);
	}

    if (isset_request_var('rdistance')) {
        $save['rdistance'] = form_input_validate(get_nfilter_request_var('rdistance'), 'distance', '', true, 3);
	} else {
        $save['rdistance'] = form_input_validate('', 'rdistance', '', true, 3);
	}

    if (isset_request_var('groupnum')) {
        $save['groupnum'] = form_input_validate(get_nfilter_request_var('groupnum'), 'groupnum', '', true, 3);
	} else {
        $save['groupnum'] = form_input_validate('', 'groupnum', '', true, 3);
	}

    return $save;
}

function gpsmap_config_settings() {
	global $tabs, $settings;

	if (basename(get_current_page()) !== 'settings.php') {
		return;
	}

	$tabs['gpsmap'] = __('Maps', 'gpsmap');

	$settings['gpsmap'] = array(
		'gpsmap_header' => array(
			'friendly_name' => __('Maps', 'gpsmap'),
			'method'        => 'spacer',
		),
		'gpsmap_apikey' => array(
			'friendly_name' => __('Google API Key', 'gpsmap'),
			'description'   => __('The Google Maps API Key is not required but highly recommended, get more info at <a href=\'https://developers.google.com/maps/documentation/javascript/tutorial#api_key\'>Google Documentation</a>.', 'gpsmap'),
			'method'        => 'textbox',
			'max_length'    => 100,
			'size'          => 50
		),
		'gpsmap_geoloc' => array(
			'friendly_name' => __('Geolocation URL', 'gpsmap'),
			'description' => __('Enter the URL of the IP to Geolocation lookup service.  Every devices IP address will be tested against this location service if not set to determine the devices Geolocation.  The devices IP address will be appended to the end of this URL.', 'gpsmap'),
			'method'        => 'textbox',
			'default'       => 'http://ipinfo.io/',
			'max_length'    => 80,
			'size'          => 40 
		),
		'gpsmap_latutude' => array(
			'friendly_name' => __('Initial Latitude', 'gpsmap'),
			'description'   => __('Defines the centering of the map', 'gpsmap'),
			'method'        => 'textbox',
			'max_length'    => 12,
			'default'       => 33.8734,
			'size'          => 10 
        ),
        'gpsmap_longitude' => array(
			'friendly_name' => __('Initial Longitude', 'gpsmap'),
			'description'   => __('Defines the centering of the map', 'gpsmap'),
			'method'        => 'textbox',
			'max_length'    => 12,
			'default'       => -115.901,
			'size'          => 10 
		),
		'gpsmap_zoom' => array(
			'friendly_name' => __('Initial Elevation', 'gpsmap'),
			'description'   => __('Defines the elevation of the map from 0 - 12', 'gpsmap'),
			'method'        => 'textbox',
			'default'       => 9,
			'max_length'    => 2,
			'size'          => 4 
		),
		'gpsmap_hostspacer' => array(
			'friendly_name' => __('Display Settings', 'gpsmap'),
			'method'        => 'spacer',
		),
		'gpsmap_enableall' => array(
			'friendly_name' => __('Display Disabled Devices', 'gpsmap'),
			'description'   => __('Allow the display of disabled Devices on the Map', 'gpsmap'),
			'method'        => 'checkbox',
			'default'       => '',
		),
		'gpsmap_coveragemap' => array(
			'friendly_name' => __('Coverage Overlay', 'gpsmap'),
			'description'   => __('Draws a transparent circle around an AP with a radius equal to the furthest node in the same subnet', 'gpsmap'),
			'method'        => 'checkbox',
			'default'       => ''
		),
		'gpsmap_refreshMap' => array(
			'friendly_name' => __('Map Refresh', 'gpsmap'),
			'description'   => __('Refreshes map after set minutes. Reccomend set to poller interval, 0 to disable.', 'gpsmap'),
			'method'        => 'textbox',
			'default'       => '5',
			'max_length'    => 2,
			'size'          => 4 
        ),
		'gpsmap_overlayspacer' => array(
			'friendly_name' => __('Overlay Settings', 'gpsmap'),
			'method'        => 'spacer',
		),
		'gpsmap_terror' => array(
			'friendly_name' => __('Tab Radius (Required)', 'gpsmap'),
			'description'   => __('Defines radius to combine points into one. (default: 0.0003)', 'gpsmap'),
			'method'        => 'textbox',
			'default'       => '.0003',
			'max_length'    => 7,
			'size'          => 4 
		),
		'gpsmap_fillcolor' => array(
			'friendly_name' => __('Fill Color', 'gpsmap'),
			'description'   => __('The overlay circle fill color as FFFFFF', 'gpsmap'),
			'method'        => 'drop_color',
			'default'       => '005D57',
		),
		'gpsmap_licolor' => array(
			'friendly_name' => __('Ring Color', 'gpsmap'),
			'description'   => __('Defines the outer rim color as FFFFFF', 'gpsmap'),
			'method'        => 'drop_color',
			'default'       => '005D57',
		),
		'gpsmap_liwidth' => array(
			'friendly_name' => __('Ring Width', 'gpsmap'),
			'description'   => __('Outter Ring width', 'gpsmap'),
			'method'        => 'textbox',
			'default'       => '2',
			'max_length'    => 7,
			'size'          => 4 
		),
		'gpsmap_fillopa' => array(
			'friendly_name' => __('Fill Opacity', 'gpsmap'),
			'description'   => __('Defines the fill opacity between 0 and 1', 'gpsmap'),
			'method'        => 'textbox',
			'default'       => '.2',
			'max_length'    => 2,
			'size'          => 4 
		),
		'gpsmap_liopa' => array(
			'friendly_name' => __('Ring Opacity', 'gpsmap'),
			'description'   => __('Defines the ring opacity between 0 and 1', 'gpsmap'),
			'method'        => 'textbox',
			'default'       => '.8',
			'max_length'    => 2,
			'size'          => 4 
		),
		'gpsmap_circlequality' => array(
			'friendly_name' => __('Quality', 'gpsmap'),
			'description'   => __('Number of divisions in circle (preferably > 15) greater numbers can slow down browser performace.', 'gpsmap'),
			'method'        => 'textbox',
			'default'       => '15',
			'max_length'    => 3,
			'size'          => 4 
		)
    );
}

