<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2009-2013 Andrew Aloia                                    |
 | Copyright (C) 2014 Wixiweb                                              |
 | Copyright (C) 2017-2018 The Cacti Group                                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

chdir('../../');
include('./include/auth.php');
include_once('./plugins/gpsmap/includes/setup/show.php');
include_once('./plugins/gpsmap/includes/setup/gpsmapinitial.php');
$body = '';

general_header();

//decide what needs to be shown
switch ($show) {
    //selected nodes
    case 'setup':
        $body = __('Please make sure to properly configure GPS Map first under settings -> gpsmap', 'gpsmap');
        break;
    default:
        if (!$parameter) {
            $parameter = 'all';
        }

        $fileLocation = './plugins/gpsmap/XML/' . $parameter . '-top.html';

		if (file_exists($fileLocation)) {
	        echo (file_get_contents($fileLocation));
		}

        break;
}

//---------------------------------------------------------------

if ($show != 'setup') {?>
	<script type='text/javascript'>
		gpsmap.refreshMap      = '<?php echo $refreshMap; ?>';
		gpsmap.initialLat      = <?php echo $initialLat; ?>;
		gpsmap.initialLng      = <?php echo $initialLong; ?>;
		gpsmap.initialZoom     = <?php echo $initialzoom; ?>;
		gpsmap.initialized     = false;
		gpsmap.liColor         = '<?php echo $liColor; ?>';
		gpsmap.liWidth         = '<?php echo $liWidth; ?>';
		gpsmap.liOpa           = '<?php echo $liOpa; ?>';
		gpsmap.fillColor       = '<?php echo $fillColor; ?>';
		gpsmap.fillOpa         = '<?php echo $fillOpa; ?>';
		gpsmap.circleQuality   = '<?php echo $circleQuality; ?>';
		gpsmap.enableWeather   = '<?php echo $enableWeather; ?>';
		gpsmap.coverageOverlay = '<?php echo $coverageMap; ?>';
		gpsmap.markerArray     = [];
		gpsmap.downloadURL     = '<?php print $config['url_path'] . 'plugins/gpsmap/XML/' . trim($parameter, '.') . '.xml'; ?>';
		gpsmap.t_error         = parseFloat('<?php echo $terror; ?>');

		<?php include_once('plugins/gpsmap/includes/icons.php'); ?>
		<?php include_once('plugins/gpsmap/includes/customicons.php'); ?>

		window.onresize = gpsmap.resize;

		$(function() {
			gpsmap.loader();
		});
	</script>
	<?php
}

$body .= '<div id="map" style="height:10px;"></div>';
echo $body;

bottom_footer();

