<?php

declare(strict_types=1);

describe('prepared helper consistency across migrated gpsmap files', function () {
	it('keeps migrated files on prepared DB helpers', function () {
		$targetFiles = [
			'gpstemplates.php',
			'includes/customicons.php',
			'includes/polling.php',
			'includes/polling/functions.php',
			'includes/setup/database.php',
			'includes/towerSelect.php',
		];

		$rawPattern = '/\bdb_(?:execute|fetch_row|fetch_assoc|fetch_cell)\s*\(/';
		$preparedPattern = '/\bdb_(?:execute|fetch_row|fetch_assoc|fetch_cell)_prepared\s*\(/';
		$totalPreparedCalls = 0;

		foreach ($targetFiles as $relativeFile) {
			$path = realpath(__DIR__ . '/../../' . $relativeFile);
			expect($path)->not->toBeFalse();

			$contents = file_get_contents($path);
			expect($contents)->not->toBeFalse();

			if ($contents === false) {
				continue;
			}

			expect(preg_match($rawPattern, $contents))->toBe(0);

			$preparedCalls = preg_match_all($preparedPattern, $contents);
			if ($preparedCalls === false) {
				$preparedCalls = 0;
			}

			expect($preparedCalls)->toBeGreaterThan(0);
			$totalPreparedCalls += $preparedCalls;
		}

		expect($totalPreparedCalls)->toBeGreaterThanOrEqual(10);
	});
});
