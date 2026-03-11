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
*/

/* Stub Cacti globals so functions.php loads without a full installation. */
if (!function_exists('db_fetch_assoc')) {
    function db_fetch_assoc(string $sql): array { return []; }
}
if (!function_exists('cacti_sizeof')) {
    function cacti_sizeof(mixed $v): int { return is_array($v) ? count($v) : 0; }
}

require_once __DIR__ . '/../../includes/polling/functions.php';

describe('parseToXML', function () {
    describe('entity encoding', function () {
        it('encodes an ampersand to &amp;', function () {
            expect(parseToXML('&'))->toBe('&amp;');
        });

        it('encodes less-than to &lt;', function () {
            expect(parseToXML('<'))->toBe('&lt;');
        });

        it('encodes greater-than to &gt;', function () {
            expect(parseToXML('>'))->toBe('&gt;');
        });

        it('encodes double-quote to &quot;', function () {
            expect(parseToXML('"'))->toBe('&quot;');
        });

        it('encodes single-quote to &apos; (ENT_XML1)', function () {
            expect(parseToXML("'"))->toBe('&apos;');
        });
    });

    describe('double-encoding prevention', function () {
        it('does not double-encode an ampersand introduced by &lt; substitution', function () {
            /* The old buggy implementation produced &amp;lt;br&amp;gt; */
            expect(parseToXML('<br>'))->toBe('&lt;br&gt;');
        });

        it('encodes an existing entity reference (& is just another ampersand)', function () {
            expect(parseToXML('&amp;'))->toBe('&amp;amp;');
        });

        it('handles a mixed string of & and < without double-encoding', function () {
            expect(parseToXML('A & B < C'))->toBe('A &amp; B &lt; C');
        });
    });

    describe('edge cases', function () {
        it('returns an empty string for empty input', function () {
            expect(parseToXML(''))->toBe('');
        });

        it('casts integers to string correctly', function () {
            expect(parseToXML(42))->toBe('42');
        });

        it('casts null to empty string', function () {
            expect(parseToXML(null))->toBe('');
        });

        it('passes plain text through unchanged', function () {
            expect(parseToXML('hello world'))->toBe('hello world');
        });

        it('passes multibyte UTF-8 through unchanged', function () {
            expect(parseToXML('日本語'))->toBe('日本語');
        });

        it('encodes entities around multibyte characters', function () {
            expect(parseToXML('<日本語>'))->toBe('&lt;日本語&gt;');
        });
    });
});
