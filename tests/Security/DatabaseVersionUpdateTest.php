<?php

declare(strict_types=1);

describe('database.php version update query', function () {
    beforeEach(function () {
        $GLOBALS['__test_db_calls'] = [];
        $GLOBALS['config'] = [
            'library_path' => '/dev/null',
            'base_path'    => '/dev/null',
        ];
    });

    it('uses db_execute_prepared with a bound parameter for the version value', function () {
        // Load the file under test (defines gpsmap_setup_database)
        require_once realpath(__DIR__ . '/../../includes/setup/database.php');

        gpsmap_setup_database();

        // Find the UPDATE plugin_config call
        $updateCalls = array_filter(
            $GLOBALS['__test_db_calls'],
            fn (array $call) => str_contains($call['sql'], 'UPDATE plugin_config')
        );

        expect($updateCalls)->not->toBeEmpty();

        $call = array_values($updateCalls)[0];

        // Must use prepared statement, not string interpolation
        expect($call['fn'])->toBe('db_execute_prepared');
        expect($call['sql'])->toContain('?');
        expect($call['params'])->toHaveCount(1);
    });

    it('does not interpolate the version value into the SQL string', function () {
        require_once realpath(__DIR__ . '/../../includes/setup/database.php');

        gpsmap_setup_database();

        $updateCalls = array_filter(
            $GLOBALS['__test_db_calls'],
            fn (array $call) => str_contains($call['sql'], 'UPDATE plugin_config')
        );

        $call = array_values($updateCalls)[0];
        $version = plugin_gpsmap_version()['version'];

        // The literal version string must NOT appear in the SQL
        expect($call['sql'])->not->toContain('"' . $version . '"');
    });
});
