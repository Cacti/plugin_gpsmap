<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2009-2013 Andrew Aloia                                    |
 | Copyright (C) 2014 Wixiweb                                              |
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

function gpsmap_normalize_icon_name($value, $icon_array, $default = 'Green.png') {
	if (!is_string($value) || $value === '') {
		return $default;
	}

	if (!isset($icon_array[$value])) {
		return $default;
	}

	return $value;
}
