<?php

describe('gpsmap icon filename normalization', function () {
    it('accepts known icon filenames and rejects tampered values', function () {
        require_once __DIR__ . '/../../gpsmap_security.php';

        $icons = array(
            'Green.png' => 'Green.png',
            'Orange.png' => 'Orange.png',
            'Red.png' => 'Red.png',
        );

        expect(gpsmap_normalize_icon_name('Orange.png', $icons, 'Green.png'))->toBe('Orange.png');
        expect(gpsmap_normalize_icon_name('../../secret.txt', $icons, 'Green.png'))->toBe('Green.png');
        expect(gpsmap_normalize_icon_name('', $icons, 'Green.png'))->toBe('Green.png');
    });
});
