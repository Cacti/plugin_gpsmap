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

chdir('../../../');
include('./include/auth.php');

$results = db_fetch_assoc_prepared('SELECT name, id FROM host_template');

$body = '<ul>';

if (cacti_sizeof($results)) {
	foreach ($results as $row) {
		$body .= '<li>' . html_escape($row['name']) . ' (ID: ' . html_escape($row['id']) . ')</li>';
	}
}

$body .= '</ul>';

print('<html>');
print($body);
print('</html>');
