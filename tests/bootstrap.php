<?php

declare(strict_types=1);

/*
 * Test bootstrap: stub Cacti framework functions so plugin code
 * can be loaded in isolation without the full Cacti application.
 */

// Track calls for spy/assertion purposes
$GLOBALS['__test_db_calls'] = [];

if (!function_exists('db_execute')) {
    function db_execute(string $sql): bool {
        $GLOBALS['__test_db_calls'][] = ['fn' => 'db_execute', 'sql' => $sql, 'params' => []];
        return true;
    }
}

if (!function_exists('db_execute_prepared')) {
    function db_execute_prepared(string $sql, array $params = []): bool {
        $GLOBALS['__test_db_calls'][] = ['fn' => 'db_execute_prepared', 'sql' => $sql, 'params' => $params];
        return true;
    }
}

if (!function_exists('db_fetch_assoc')) {
    function db_fetch_assoc(string $sql): array {
        return [];
    }
}

if (!function_exists('db_fetch_assoc_prepared')) {
    function db_fetch_assoc_prepared(string $sql, array $params = []): array {
        return [];
    }
}

if (!function_exists('db_fetch_row_prepared')) {
    function db_fetch_row_prepared(string $sql, array $params = []): array {
        return [];
    }
}

if (!function_exists('db_fetch_cell_prepared')) {
    function db_fetch_cell_prepared(string $sql, array $params = []): string {
        return '';
    }
}

if (!function_exists('db_index_exists')) {
    function db_index_exists(string $table, string $index): bool {
        return false;
    }
}

if (!function_exists('db_add_index')) {
    function db_add_index(string $table, string $type, string $name, array $columns): bool {
        return true;
    }
}

if (!function_exists('api_plugin_db_add_column')) {
    function api_plugin_db_add_column(string $plugin, string $table, array $data): bool {
        return true;
    }
}

if (!function_exists('api_plugin_db_table_create')) {
    function api_plugin_db_table_create(string $plugin, string $table, array $data): bool {
        return true;
    }
}

if (!function_exists('read_config_option')) {
    function read_config_option(string $name, bool $force = false): string {
        return '';
    }
}

if (!function_exists('plugin_gpsmap_version')) {
    function plugin_gpsmap_version(): array {
        return ['name' => 'gpsmap', 'version' => '2.5', 'longname' => 'GPS Map'];
    }
}

if (!function_exists('html_escape')) {
    function html_escape(string $string): string {
        return htmlspecialchars($string, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}

if (!function_exists('__')) {
    function __(string $text, string $domain = ''): string {
        return $text;
    }
}

if (!function_exists('__esc')) {
    function __esc(string $text, string $domain = ''): string {
        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
