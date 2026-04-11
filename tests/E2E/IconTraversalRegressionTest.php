<?php

describe('gpsmap icon traversal regression wiring', function () {
    it('does not save raw posted icon filenames directly', function () {
        $source = file_get_contents(realpath(__DIR__ . '/../../gpstemplates.php'));

        expect($source)->not->toContain("\$save['upimage']      = get_nfilter_request_var('upimage');");
        expect($source)->not->toContain("\$save['recoverimage'] = get_nfilter_request_var('recoverimage');");
        expect($source)->not->toContain("\$save['downimage']    = get_nfilter_request_var('downimage');");
    });
});
