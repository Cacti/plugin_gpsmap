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

    it('escapes image filenames containing path traversal and XSS payloads', function () {
        $payloads = [
            "../../etc/passwd",
            "x.png' onerror='alert(1)",
            '<img src=x onerror=alert(1)>.png',
        ];

        foreach ($payloads as $payload) {
            $escaped = html_escape($payload);
            expect($escaped)->not->toContain("'");
            expect($escaped)->not->toContain('<img');
        }
    });

    it('verifies gpstemplates.php templates() uses html_escape on output fields', function () {
        $source = file_get_contents(realpath(__DIR__ . '/../../gpstemplates.php'));

        // Line 90: templateName must be escaped
        expect($source)->toContain("html_escape(\$template['templateName'])");

        // Lines 91-93: image fields must be escaped
        expect($source)->toContain("html_escape(\$template['upimage'])");
        expect($source)->toContain("html_escape(\$template['recoverimage'])");
        expect($source)->toContain("html_escape(\$template['downimage'])");
    });
});
