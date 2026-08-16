<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2009-2013 Andrew Aloia                                    |
 | Copyright (C) 2014 Wixiweb                                              |
 | Copyright (C) 2004-2026 The Cacti Group                                 |
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

//set headers to NOT cache a page
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

//decide what needs to be shown
switch ($show) {
	//selected nodes
	case 'setup':
		$body = __('Please make sure to properly configure Maps first under Settings > Maps', 'gpsmap');
		break;
	default:
		if (!$parameter) {
			$parameter = 'all';
		}

		/* Reject any parameter that contains directory traversal sequences or
		 * characters outside the safe set.  basename() alone does not strip
		 * embedded ../ so we validate the whole value first. */
		if (!preg_match('/^[a-zA-Z0-9_-]+(\.[a-zA-Z0-9_-]+)*$/', $parameter)) {
			$parameter = 'all';
		}

		$fileLocation = './plugins/gpsmap/XML/' . $parameter . '-top.html';

		if (file_exists($fileLocation)) {
			echo file_get_contents($fileLocation);
		}

		break;
}

//---------------------------------------------------------------

if ($show != 'setup') { ?>
	<script type='text/javascript'>
		var initialLat      = <?php echo is_finite((float) $initialLat) ? json_encode((float) $initialLat) : '0'; ?>;
		var initialLng      = <?php echo is_finite((float) $initialLong) ? json_encode((float) $initialLong) : '0'; ?>;
		var initialZoom     = <?php echo json_encode((int) $initialzoom); ?>;
	</script>
	<script type='text/javascript'>
		gpsmap.refreshMap      = <?php echo json_encode((string) $refreshMap, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_UNESCAPED_SLASHES); ?>;
		gpsmap.initialLat      = <?php echo is_finite((float) $initialLat) ? json_encode((float) $initialLat) : '0'; ?>;
		gpsmap.initialLng      = <?php echo is_finite((float) $initialLong) ? json_encode((float) $initialLong) : '0'; ?>;
		gpsmap.initialZoom     = <?php echo json_encode((int) $initialzoom); ?>;
		gpsmap.initialized     = false;
		gpsmap.liColor         = <?php echo json_encode((string) $liColor, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_UNESCAPED_SLASHES); ?>;
		gpsmap.liWidth         = <?php echo json_encode((string) $liWidth, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_UNESCAPED_SLASHES); ?>;
		gpsmap.liOpa           = <?php echo json_encode((string) $liOpa, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_UNESCAPED_SLASHES); ?>;
		gpsmap.fillColor       = <?php echo json_encode((string) $fillColor, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_UNESCAPED_SLASHES); ?>;
		gpsmap.fillOpa         = <?php echo json_encode((string) $fillOpa, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_UNESCAPED_SLASHES); ?>;
		gpsmap.circleQuality   = <?php echo json_encode((string) $circleQuality, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_UNESCAPED_SLASHES); ?>;
		gpsmap.enableWeather   = <?php echo json_encode((string) $enableWeather, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_UNESCAPED_SLASHES); ?>;
		gpsmap.coverageOverlay = <?php echo json_encode((string) $coverageMap, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_UNESCAPED_SLASHES); ?>;
		gpsmap.markerArray     = [];
		gpsmap.downloadURL     = <?php echo json_encode($config['url_path'] . 'plugins/gpsmap/XML/' . $parameter . '.xml', JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_UNESCAPED_SLASHES); ?>;
		gpsmap.t_error         = <?php echo is_finite((float) $terror) ? json_encode((float) $terror) : '0'; ?>;

		<?php include_once('plugins/gpsmap/includes/icons.php'); ?>
		<?php include_once('plugins/gpsmap/includes/customicons.php'); ?>

		window.onresize = gpsmap.resize;

		$(function() {
			gpsmap.loader();
		});
	</script>
<?php
}

$body .= '
<div id="mouse-position"></div>
<div id="map"></div>
';
echo $body;

bottom_footer();

