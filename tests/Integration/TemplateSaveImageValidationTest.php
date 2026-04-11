<?php

describe('gpsmap template save image validation', function () {
    it('loads the icon normalization helper before saving template images', function () {
        $source = file_get_contents(realpath(__DIR__ . '/../../gpstemplates.php'));

        expect($source)->toContain("include_once('./plugins/gpsmap/gpsmap_security.php');");
        expect($source)->toContain('$iconArray            = getIcons();');
        expect($source)->toContain("gpsmap_normalize_icon_name(get_nfilter_request_var('upimage'), \$iconArray, 'Green.png')");
        expect($source)->toContain("gpsmap_normalize_icon_name(get_nfilter_request_var('recoverimage'), \$iconArray, 'Orange.png')");
        expect($source)->toContain("gpsmap_normalize_icon_name(get_nfilter_request_var('downimage'), \$iconArray, 'Red.png')");
    });
});
