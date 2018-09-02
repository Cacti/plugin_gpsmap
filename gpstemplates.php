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

chdir('../../');
include_once('./include/auth.php');

$ds_actions = array(
	1 => __('Delete')
);

set_default_action();

if (isset_request_var('drp_action')) {
	if (get_filter_request_var('drp_action') == 1) {
		set_request_var('action', 'delete');
	}
}

switch (get_nfilter_request_var('action')) {
	case 'delete':
		template_delete();

		break;
	case 'edit':
		template_edit();

		break;
	case 'save':
		gpsmap_save_template();

		break;
	default:
		top_header();
		templates();
		bottom_footer();
		break;
}

//------------------------------------------------------------------------------
function templates() {
	global $config, $ds_actions;

	form_start('gpstemplates.php', 'chk');

	html_start_box(__('Map Templates', 'gpsmap'), '100%', false, '3', 'center', 'gpstemplates.php?action=edit');

	$display_text = array(
		__('Host Template', 'gpsmap'), 
		__('Up Image', 'gpsmap'), 
		__('Recovering Image', 'gpsmap'), 
		__('Down Image', 'gpsmap'), 
		__('Is AP', 'gpsmap')
	);

	html_header_checkbox($display_text);

	$template_list = db_fetch_assoc('SELECT * 
		FROM gpsmap_templates 
		ORDER BY templateID');

	if (sizeof($template_list)) {
		foreach ($template_list as $template) {
			if($template['AP']) {
				$isAP = __('True', 'gpsmap');
			} else {
				$isAP = __('False', 'gpsmap');
			}

			$url = $config['url_path'] . 'plugins/gpsmap/gpstemplates.php?action=edit&id=' . $template['templateID'];

			form_alternate_row('line' . $template['templateID'], true);
			form_selectable_cell("<a class='linkEditMain' href='$url'>" . $template['templateName'] . "</a>", $template['templateID']);
			form_selectable_cell("<img src='" . $config['url_path'] . 'plugins/gpsmap/images/icons/' . $template['upimage'] . "'>", $template['templateID']);
			form_selectable_cell("<img src='" . $config['url_path'] . 'plugins/gpsmap/images/icons/' . $template['recoverimage'] . "'>", $template['templateID']);
			form_selectable_cell("<img src='" . $config['url_path'] . 'plugins/gpsmap/images/icons/' . $template['downimage'] . "'>", $template['templateID']);
			form_selectable_cell($isAP, $template['templateID']);
			form_checkbox_cell($template['templateID'], $template['templateID']);
			form_end_row();
		}
	} else {
		print '<tr><td colspan="' . (sizeof($display_text) + 1) . '"><em>No Data Templates</em></td></tr>';
	}

	html_end_box(false);

	draw_actions_dropdown($ds_actions);

	form_end();
}

//------------------------------------------------------------------------------
function template_delete() {
	foreach($_POST as $t=>$v) {
		if (substr($t, 0,4) == 'chk_') {
			$id = substr($t, 4);
			db_execute_prepared('DELETE FROM gpsmap_templates WHERE templateID = ?', array($id));
		}
	}

	header('Location: gpstemplates.php?header=false');
	exit;
}

//------------------------------------------------------------------------------
function template_edit() {
	global $config;

	$template = db_fetch_row_prepared('SELECT * 
		FROM `gpsmap_templates` 
		WHERE templateID = ?', 
		array(get_filter_request_var('id')));

	//get all icons in the icon folder
	$iconArray = getIcons();

	$form_template = array(
		'templateID' => array(
			'method'        => 'drop_sql',
			'friendly_name' => __('Device Template', 'gpsmap'),
			'description'   => __('Choose the Device Template to base this Map Template type.', 'gpsmap'),
			'value'         => '|arg1:templateID|',
			'sql'           => 'SELECT id, name FROM host_template ORDER BY name ASC'
		),
		'upimage' => array(
			'method'        => 'drop_array',
			'friendly_name' => __('Up Image', 'gpsmap'),
			'description'   => __('Select the Image to Show for an Up Device', 'gpsmap'),
			'value'         => '|arg1:upimage|',
			'default'       => 'Green.png',
			'array'         => $iconArray
		),
		'recoverimage' => array(
			'method'        => 'drop_array',
			'friendly_name' => __('Recovering Image', 'gpsmap'),
			'description'   => __('Select the Image to Show for a Recovering Device', 'gpsmap'),
			'value'         => '|arg1:recoverimage|',
			'default'       => 'Orange.png',
			'array'         => $iconArray
		),
		'downimage' => array(
			'method'        => 'drop_array',
			'friendly_name' => __('Down Image', 'gpsmap'),
			'description'   => __('Select the Image to Show for a Down Device', 'gpsmap'),
			'value'         => '|arg1:downimage|',
			'default'       => 'Red.png',
			'array'         => $iconArray
		),
		'AP' => array(
			'method'        => 'drop_array',
			'friendly_name' => __('Access Point', 'gpsmap'),
			'description'   => __('Does this Device Template Represent an Access Point.', 'gpsmap'),
			'value'         => '|arg1:AP|',
			'array'         => array(
				0 => __('No', 'gpsmap'),
				1 => __('Yes', 'gpsmap')
			)
		)
	);

	top_header();

	form_start('gpstemplates.php', 'gpsform');

	html_start_box(__('Map Template Edit', 'gpsmap'), '100%', '', '3', 'center', '');

	draw_edit_form(
		array(
			'config' => array('no_form_tag' => true),
			'fields' => inject_form_variables($form_template, $template)
		)
	);

	?>
	<script type='text/javascript'>

	$.widget('custom.mapiconselectmenu', $.ui.selectmenu, {
		_renderItem: function(ul, item) {
			var li = $('<li>'); 
			//var wrapper = $('<div>', {title: item.label});
			var wrapper = $('<div>');
			if (item.disabled) {
				li.addClass('ui-state-disabled');
			}

			$('<img>', {
				src: urlPath+'plugins/gpsmap/images/icons/'+item.label,
				style: 'display:inline-block;width:12px;height:20px;'
			}).appendTo(wrapper);

			return li.append(wrapper).prependTo(ul);
		}
	});

	$(function() {
		$('#upimage').selectmenu('destroy').mapiconselectmenu().mapiconselectmenu('menuWidget').addClass('ui-menu-icons customicons');
		$('#recoverimage').selectmenu('destroy').mapiconselectmenu().mapiconselectmenu('menuWidget').addClass('ui-menu-icons customicons');
		$('#downimage').selectmenu('destroy').mapiconselectmenu().mapiconselectmenu('menuWidget').addClass('ui-menu-icons customicons');
	});
	</script>
	<?php

	html_end_box();

	form_save_button('gpstemplates.php?action=edit&id=' . get_request_var('id'));

	bottom_footer();
}


//------------------------------------------------------------------------------
function gpsmap_save_template() {
	global $config;

	$save                 = array();
	$save['templateID']   = get_filter_request_var('templateID');
	$save['templateName'] = db_fetch_cell_prepared('SELECT name FROM host_template WHERE id = ?', array($save['templateID']));
	$save['upimage']      = get_nfilter_request_var('upimage');
	$save['recoverimage'] = get_nfilter_request_var('recoverimage');
	$save['downimage']    = get_nfilter_request_var('downimage');
	$save['AP']           = get_filter_request_var('AP');

	$templateID = sql_save($save, 'gpsmap_templates', 'templateID');

	if ($templateID > 0) {
		raise_message(1);
	} else {
		raise_message(2);
	}

	header('Location: gpstemplates.php?header=false');
	exit;
}

//------------------------------------------------------------------------------
function getIcons() {
	$iconArray = array();
	$icons     = opendir('./plugins/gpsmap/images/icons');

	while (false !== ($icon = readdir($icons))) {
		if ($icon != '.' && $icon != '..') {
			$iconExplode    = explode('.', $icon);
			$iconExplode[1] = $iconExplode[1];

			switch ($iconExplode[1]) {
				case 'png':
				case 'jpg':
				case 'jpeg':
				case 'gif':
					$iconArray[$icon] = $icon;
					break;
				default:
					break;
			}
		}
	}

	closedir($icons);

	return $iconArray;
}

