<?php

declare(strict_types=1);

describe('auth guard presence', function () {
    it('includes auth.php in print.php before any output', function () {
        $source = file_get_contents(realpath(__DIR__ . '/../../print.php'));

        // auth.php must be required/included
        expect($source)->toMatch('/require_once\s*\(\s*[\'"]\.\/include\/auth\.php[\'"]\s*\)/');
    });

    it('includes auth.php in towerSelect.php before any output', function () {
        $source = file_get_contents(realpath(__DIR__ . '/../../includes/towerSelect.php'));

        // auth.php must be required/included
        expect($source)->toMatch('/require_once\s*\(\s*[\'"]\.\/include\/auth\.php[\'"]\s*\)/');
    });

    it('places auth.php include before HTML output in print.php', function () {
        $source = file_get_contents(realpath(__DIR__ . '/../../print.php'));
        $lines = explode("\n", $source);

        $authLine = null;
        $firstOutputLine = null;

        foreach ($lines as $i => $line) {
            if ($authLine === null && preg_match('/require_once.*auth\.php/', $line)) {
                $authLine = $i;
            }
            if ($firstOutputLine === null && (str_contains($line, '<script') || str_contains($line, 'echo') || str_contains($line, 'print'))) {
                $firstOutputLine = $i;
            }
        }

        expect($authLine)->not->toBeNull();
        expect($firstOutputLine)->not->toBeNull();
        expect($authLine)->toBeLessThan($firstOutputLine);
    });

    it('places auth.php include before HTML output in towerSelect.php', function () {
        $source = file_get_contents(realpath(__DIR__ . '/../../includes/towerSelect.php'));
        $lines = explode("\n", $source);

        $authLine = null;
        $firstOutputLine = null;

        foreach ($lines as $i => $line) {
            if ($authLine === null && preg_match('/require_once.*auth\.php/', $line)) {
                $authLine = $i;
            }
            if ($firstOutputLine === null && (str_contains($line, '<html') || str_contains($line, 'print') || str_contains($line, 'echo'))) {
                $firstOutputLine = $i;
            }
        }

        expect($authLine)->not->toBeNull();
        expect($firstOutputLine)->not->toBeNull();
        expect($authLine)->toBeLessThan($firstOutputLine);
    });
});
