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

?><html>
<?php

if ($_POST){
	global $config, $database_default;
	include_once($config['library_path'] . '/database.php');
}else{
	global $config, $database_default;
	include_once($config['library_path'] . '/database.php');

	$results = db_fetch_assoc('SELECT name,id FROM host_template');

	//Begin form
	$body .= '<form action=\'towerSelect.php\' method=\'post\'>';

	//Printout template types
	if (sizeof($results)) {
		foreach($results as $row) {
			$body .= $row['name'] + ': <input type=\'text\' name=\''+ $row['id'] + '\' />';
		}
	}

	$body .= '<input type=\'submit\' />';

	$body .= '</form>';

	print($body);
}

?>
</html>
