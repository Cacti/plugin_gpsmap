<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2009-2013 Andrew Aloia                                    |
 | Copyright (C) 2014 Wixiweb                                              |
 | Copyright (C) 2004-2026 The Cacti Group                                 |
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
/* auth.php halts execution (exit/redirect) for unauthenticated users, so the
 * markup below is only reached after successful authentication. */
require_once('./include/auth.php');

/* print.php is a direct endpoint and does not fire the hooks that lazily load
 * this plugin's setup.php, so ensure the CSP nonce helper is defined here. */
if (!function_exists('plugin_gpsmap_csp_nonce')) {
	require_once(__DIR__ . '/setup.php');
}
?>
<script language='javascript' <?php print plugin_gpsmap_csp_nonce(); ?>>
var mapEl = window.opener.document.getElementById('map');
if (mapEl) {
	var clone = mapEl.cloneNode(true);
	clone.querySelectorAll('script').forEach(function(s) { s.remove(); });
	clone.querySelectorAll('*').forEach(function(el) {
		Array.from(el.attributes).forEach(function(attr) {
			if (attr.name.toLowerCase().indexOf('on') === 0) { el.removeAttribute(attr.name); }
		});
	});
	document.body.appendChild(clone);
}
window.print();
</script>
