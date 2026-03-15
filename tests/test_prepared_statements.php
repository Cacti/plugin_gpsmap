<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 +-------------------------------------------------------------------------+
 | Regression checks for prepared DB helper usage in core plugin files     |
 |                                                                         |
 | Run: php tests/test_prepared_statements.php                             |
 +-------------------------------------------------------------------------+
 */

$pass = 0;
$fail = 0;

function assert_true($label, $value) {
	global $pass, $fail;

	if ($value) {
		echo "PASS  $label\n";
		$pass++;
	} else {
		echo "FAIL  $label\n";
		$fail++;
	}
}

$target_files = [
	__DIR__ . '/../gpstemplates.php',
	__DIR__ . '/../includes/customicons.php',
	__DIR__ . '/../includes/polling.php',
	__DIR__ . '/../includes/polling/functions.php',
	__DIR__ . '/../includes/setup/database.php',
	__DIR__ . '/../includes/towerSelect.php',
];

$raw_patterns = [
	'db_execute'     => '/\bdb_execute\s*\(/',
	'db_fetch_row'   => '/\bdb_fetch_row\s*\(/',
	'db_fetch_assoc' => '/\bdb_fetch_assoc\s*\(/',
	'db_fetch_cell'  => '/\bdb_fetch_cell\s*\(/',
];

$prepared_pattern = '/\bdb_(?:execute|fetch_row|fetch_assoc|fetch_cell)_prepared\s*\(/';
$total_prepared_calls = 0;

foreach ($target_files as $file) {
	$contents = file_get_contents($file);
	$basename = basename($file);

	foreach ($raw_patterns as $name => $pattern) {
		assert_true("$basename has no raw $name calls", preg_match($pattern, $contents) === 0);
	}

	$prepared_calls = preg_match_all($prepared_pattern, $contents);
	$total_prepared_calls += $prepared_calls;
	assert_true("$basename contains prepared DB helper usage", $prepared_calls > 0);
}

assert_true('total prepared helper call count is at least 11', $total_prepared_calls >= 11);

echo "\n";
echo "Results: $pass passed, $fail failed\n";

exit($fail > 0 ? 1 : 0);
