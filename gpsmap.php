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
		$body = __('Please make sure to properly configure Maps first under Settings > Maps', 'gpsmap');
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

if ($show != 'setup') { ?>
	<script type='text/javascript'>
		var initialLat      = <?php echo $initialLat; ?>;
		var initialLng      = <?php echo $initialLong; ?>;
		var initialZoom     = <?php echo $initialzoom; ?>;
	</script>
<?php	if (get_request_var('provider') == 'osm') {
		echo "\n<h1>Using OpenLayers</h1>\n"; ?>
	<script src="https://cdn.polyfill.io/v2/polyfill.min.js?features=requestAnimationFrame,Element.prototype.classList,URL"></script>
	<script src="https://cdn.rawgit.com/openlayers/openlayers.github.io/master/en/v5.3.0/build/ol.js"></script>
	<link rel="stylesheet" href="https://cdn.rawgit.com/openlayers/openlayers.github.io/master/en/v5.3.0/css/ol.css">
	<link rel="stylesheet" href="gpsmaps.css">
	<script type='text/javascript'>
		$(function() {
			data = [
				{
					name: 'Test 1',
					lat: 53.0,
					lng: -2.0,
					icon: 'images/icons/Green.png'
				},{
					name: 'Test 2',
					lat: 50.0,
					lng: -1.0,
					icon: 'images/icons/Red.png'
				}
			];

			var iconStyles = new Array();
			var iconFeatures = new Array();
			data.forEach(function(item) {
				var iconFeature = new ol.Feature({
					geometry: new ol.geom.Point(ol.proj.fromLonLat([item.lng, item.lat])),
					labelPoint: new ol.geom.Point(ol.proj.fromLonLat([item.lng, item.lat])),
					name: item.name
				});

				var iconStyle = null;
				for (var i = 0; i < iconStyles.length; i++) {
					if (iconStyles[i].src == item.icon) {
						iconStyle = iconStyles[i];
						break;
					}
				}

				if (iconStyle == null) {
					iconStyle = new ol.style.Style({
						image: new ol.style.Icon(/** @type {module:ol/style/Icon~Options} */ {
/*							anchor: [0.5, 46],
							anchorXUnits: 'fraction',
							anchorYUnits: 'pixels',
*/							src: item.icon
						}),
					});

					iconStyle.src = item.icon;
					iconStyles.push(iconStyle);
				}

				iconFeature.setStyle(iconStyle);
				iconFeatures.push(iconFeature);
			});

			var vectorLayer = new ol.layer.Vector({
				source: new ol.source.Vector({
					features: iconFeatures
				})
			});

			var rasterLayer = new ol.layer.Tile({
				source: new ol.source.TileJSON({
					url: 'https://api.tiles.mapbox.com/v3/mapbox.geography-class.json?secure',
					crossOrigin: ''
				})
			});
/*
			var rasterLayer = new ol.layer.Tile({
				source: new ol.source.OSM()
			});
*/

			var mousePositionControl = new ol.control.MousePosition({
				coordinateFormat: ol.coordinate.createStringXY(4),
				projection: 'EPSG:4326',
				// comment the following two lines to have the mouse position
				// be placed within the map.
				className: 'custom-mouse-position',
				target: document.getElementById('mouse-position'),
				undefinedHTML: '&nbsp;'
			});

			var map = new ol.Map({
				controls: ol.control.defaults().extend([
					mousePositionControl,
					new ol.control.OverviewMap()
				]),
				target: 'map',
				layers: [
					rasterLayer,
					vectorLayer
				],
				view: new ol.View({
					center: ol.proj.fromLonLat([initialLng, initialLat]),
					zoom: initialZoom
				})
			});

			hoverInteraction = new ol.interaction.Select({
				condition: ol.events.condition.pointerMove,
				layers:[vectorLayer]  //Setting layers to be hovered
			});
			map.addInteraction(hoverInteraction);

/*
			//Add a selector control to the vectorLayer with popup functions
			var controls = {
				selector: new ol.control.SelectFeature(vectorLayer, {
					onSelect: createPopup,
					onUnselect: destroyPopup
				})
			};

			function createPopup(feature) {
				feature.popup = new ol.Popup.FramedCloud("pop",
					feature.geometry.getBounds().getCenterLonLat(),
					null,
					'<div class="markerContent">'+feature.attributes.description+'</div>',
					null,
					true,
					function() {
						controls['selector'].unselectAll();
					}
				);
				//feature.popup.closeOnMove = true;
				olMap.addPopup(feature.popup);
			}

			function destroyPopup(feature) {
				feature.popup.destroy();
				feature.popup = null;
			}

			map.addControl(controls['selector']);
			controls['selector'].activate();
*/
		});

	</script>
<?php	} else {
		echo "\n<h1>Using GoogleMaps</h1><br/>\n";?>
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
}

$body .= '
<div id="mouse-position"></div>
<div id="map"></div>
';
echo $body;

bottom_footer();

