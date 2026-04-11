<?php

declare(strict_types=1);

describe('gpstemplates.php template output escaping', function () {
    it('escapes templateName containing HTML/script tags in form_selectable_cell output', function () {
        $malicious = '<script>alert("xss")</script>';
        $escaped = html_escape($malicious);

        expect($escaped)->not->toContain('<script>');
        expect($escaped)->toContain('&lt;script&gt;');
    });

    it('escapes single and double quotes in template names', function () {
        $malicious = "test' onmouseover=\"alert(1)\"";
        $escaped = html_escape($malicious);

        expect($escaped)->not->toContain("'");
        expect($escaped)->not->toContain('"');
        // PHP ENT_QUOTES|ENT_HTML5 produces &apos;; ENT_QUOTES alone produces &#039;
        expect($escaped)->toMatch('/&apos;|&#039;/');
        expect($escaped)->toContain('&quot;');
    });

    it('normalizes image filenames against the allowed icon list before saving', function () {
        require_once __DIR__ . '/../../gpsmap_security.php';

        $icons = array(
            'Green.png' => 'Green.png',
            'Orange.png' => 'Orange.png',
            'Red.png' => 'Red.png',
        );

        expect(gpsmap_normalize_icon_name('Green.png', $icons, 'Green.png'))->toBe('Green.png');
        expect(gpsmap_normalize_icon_name('../../etc/passwd', $icons, 'Green.png'))->toBe('Green.png');
        expect(gpsmap_normalize_icon_name("x.png' onerror='alert(1)", $icons, 'Green.png'))->toBe('Green.png');
    });

    it('verifies gpstemplates.php templates() uses html_escape on output fields', function () {
        $source = file_get_contents(realpath(__DIR__ . '/../../gpstemplates.php'));

        // Line 90: templateName must be escaped
        expect($source)->toContain("html_escape(\$template['templateName'])");

        // Lines 91-93: image fields must be escaped
        expect($source)->toContain("html_escape(\$template['upimage'])");
        expect($source)->toContain("html_escape(\$template['recoverimage'])");
        expect($source)->toContain("html_escape(\$template['downimage'])");

        expect($source)->toContain("gpsmap_normalize_icon_name(get_nfilter_request_var('upimage'), \$iconArray, 'Green.png')");
        expect($source)->toContain("gpsmap_normalize_icon_name(get_nfilter_request_var('recoverimage'), \$iconArray, 'Orange.png')");
        expect($source)->toContain("gpsmap_normalize_icon_name(get_nfilter_request_var('downimage'), \$iconArray, 'Red.png')");
    });
});
