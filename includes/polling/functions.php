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

require_once(__DIR__ . '/../../gpsmap_security.php');
// ---------------------------------------------------------------
function callRegion(string $subnet): void {
	global $config;

	include_once($config['base_path'] . '/plugins/gpsmap/includes/polling/pollinginitial.php');
	include_once($config['base_path'] . '/plugins/gpsmap/includes/polling/processregion.php');

	region($subnet);
}

// ---------------------------------------------------------------
function getTowerIds(): array {
	$results = db_fetch_assoc_prepared('SELECT `templateID`
		FROM `gpsmap_templates`
		WHERE `AP` = 1', []);

	/* 9999 is a sentinel that matches no host_template_id, so an empty
	 * result set still produces a usable in_array() haystack. */
	return cacti_sizeof($results) ? array_column($results, 'templateID') : [9999];
}

// ---------------------------------------------------------------
/* Returns the great-circle distance in kilometres (not metres, the constant
 * 6378.7 is Earth's mean radius in km).  Renamed from calcMeters to reflect
 * the actual unit; callers treating the result as metres will be off by 1000x.
 * The gpsmap_templates.radius column is also stored in km. */
function calcKm(float $Lat1, float $Lon1, float $Lat2, float $Lon2): float {
	return 6378.7 * 3.1415926 * sqrt(
		($Lat2 - $Lat1) * ($Lat2 - $Lat1)
		+ cos($Lat2 / 57.29578) * cos($Lat1 / 57.29578) * ($Lon2 - $Lon1) * ($Lon2 - $Lon1)
	) / 180;
}

/** @deprecated Use calcKm() instead. */
function calcMeters(float $Lat1, float $Lon1, float $Lat2, float $Lon2): float {
	return calcKm($Lat1, $Lon1, $Lat2, $Lon2);
}

// ---------------------------------------------------------------
function coordCheck(string $coords): string {
	$coords = trim($coords);

	// return 0.000 for anything unparseable, the user can correct the device
	return preg_match('#^-?\d{1,3}\.\d+$#', $coords) ? $coords : '0.000';
}

// ---------------------------------------------------------------
/* Single writer for every generated artefact (XML, KML, top HTML) so the
 * failure path and its log message stay identical across all three. */
function gpsmap_write_file(string $filename, string $contents, ?callable $modeSetter = null): bool {
	$fail = function () use ($filename) {
		cacti_log('Unable to write to: ' . $filename . '.  Please verify that the Data Collector has write access to this location.', false, 'POLLER');

		return false;
	};

	/* Staged and renamed into place: the browser fetches these files while the
	 * poller rewrites them, and rename() is atomic within a filesystem.  A
	 * short write is a failure, so disk pressure cannot publish a truncated
	 * document while reporting success. */
	/* The temp file is a new inode, so it starts at 0666 & ~umask rather than
	 * inheriting the destination's mode.  These files are read by the web
	 * server, not the poller, so losing the mode breaks the map silently. */
	$mode = file_exists($filename) ? (fileperms($filename) & 0777) : null;
	$temp = $filename . '.' . getmypid() . '.tmp';
	$f    = @fopen($temp, 'w');
	$modeSetter ??= 'chmod';

	if ($f === false) {
		return $fail();
	}

	$written = fwrite($f, $contents);

	if (!fclose($f) || $written !== strlen($contents)) {
		@unlink($temp);

		return $fail();
	}

	if ($mode !== null && !@$modeSetter($temp, $mode)) {
		cacti_log('WARNING: gpsmap could not preserve the existing artifact permissions for ' . $filename, false, 'GPSMAP');
	}

	/* rename() already replaces an existing destination on every supported
	 * platform, so a failure here is a filesystem or permission problem.
	 * Unlinking first would destroy the last-good document without any
	 * guarantee the retry succeeds, turning a stale map into a missing one. */
	if (!@rename($temp, $filename)) {
		@unlink($temp);

		return $fail();
	}

	return true;
}

// ---------------------------------------------------------------
function gpsmap_xml_path(string $preemptive, string $extension): string {
	global $config;
	$stem = gpsmap_require_artifact_stem($preemptive);

	return $config['base_path'] . '/plugins/gpsmap/XML/' . $stem . '.' . $extension;
}

// ---------------------------------------------------------------
/* IPv4 keeps its historical dotted tokens.  IPv6 uses a reversible, filename
 * safe token: v6-<prefix length>-<significant hex>. */
function gpsmap_ipv6_prefix_token(string $address, int $length): ?string {
	$packed = @inet_pton($address);

	if ($packed === false || strlen($packed) !== 16 || !in_array($length, [16, 32, 48], true)) {
		return null;
	}

	return 'v6-' . $length . '-' . substr(bin2hex($packed), 0, intdiv($length, 4));
}

// ---------------------------------------------------------------
function gpsmap_ipv6_prefix_label(string $token): ?string {
	if (!preg_match('/^v6-(16|32|48)-([0-9a-f]+)$/', $token, $matches)) {
		return null;
	}

	$length = (int) $matches[1];

	if (strlen($matches[2]) !== intdiv($length, 4)) {
		return null;
	}

	$packed = hex2bin(str_pad($matches[2], 32, '0'));

	return inet_ntop($packed) . '/' . $length;
}

// ---------------------------------------------------------------
function gpsmap_ipv6_prefix_contains(string $token, string $address): bool {
	if (!preg_match('/^v6-(16|32|48)-([0-9a-f]+)$/', $token, $matches)) {
		return false;
	}

	if (strlen($matches[2]) !== intdiv((int) $matches[1], 4)) {
		return false;
	}

	$packed = @inet_pton($address);

	return $packed !== false && strlen($packed) === 16 && str_starts_with(bin2hex($packed), $matches[2]);
}

// ---------------------------------------------------------------
function gpsmap_artifact_stem(string $subnet): ?string {
	$subnet = $subnet === '' ? 'all' : trim($subnet, '.');

	return gpsmap_artifact_parameter_is_valid($subnet)
		? $subnet
		: null;
}

// ---------------------------------------------------------------
function gpsmap_require_artifact_stem(string $subnet): string {
	$stem = gpsmap_artifact_stem($subnet);

	if ($stem === null) {
		throw new InvalidArgumentException('Invalid gpsmap artifact subnet');
	}

	return $stem;
}

// ---------------------------------------------------------------
function gpsmap_artifact_subnet_is_valid(string $subnet): bool {
	if ($subnet === '' || gpsmap_artifact_parameter_is_valid($subnet)) {
		return true;
	}

	$normalized = trim($subnet, '.');

	// IPv4 traversal historically supplies dotted prefixes such as "10.20.".
	// Keep that internal contract without accepting punctuation around IPv6 tokens.
	return $normalized !== $subnet
		&& preg_match('/^[0-9]+(?:\.[0-9]+){0,2}$/D', $normalized) === 1;
}

final class GpsmapPollState {
	public bool $loadFailed             = false;
	public bool $dnsResolverUnavailable = false;
	/** @var list<string> */
	public array $unresolvedHostnames = [];
	/** @var list<string> */
	public array $unresolvedDeviceIds = [];
	/** @var list<string> */
	public array $expiredHostnames = [];
	/** @var list<string> */
	public array $expiredDeviceIds = [];
	/** @var list<string> */
	public array $staleHostnames = [];
	/** @var list<string> */
	public array $staleDeviceIds = [];
	/** @var array<string, bool> */
	public array $preservedArtifacts = [];

	public function reset(): void {
		$this->loadFailed             = false;
		$this->dnsResolverUnavailable = false;
		$this->unresolvedHostnames    = [];
		$this->unresolvedDeviceIds    = [];
		$this->expiredHostnames       = [];
		$this->expiredDeviceIds       = [];
		$this->staleHostnames         = [];
		$this->staleDeviceIds         = [];
		$this->preservedArtifacts     = [];
	}
}

// Compatibility state for direct region() callers; the poller passes its own.
function gpsmap_poll_state(): GpsmapPollState {
	/** @var ?GpsmapPollState $state */
	static $state = null;

	return $state ??= new GpsmapPollState();
}

if (!defined('GPSMAP_PRESERVED_MARKER_TTL')) {
	define('GPSMAP_PRESERVED_MARKER_TTL', 86400);
}

function gpsmap_dns_worker_stale_after(int $pollerInterval): int {
	$pollerInterval = max(60, $pollerInterval);

	return max(3 * $pollerInterval, (int) ceil(GPSMAP_DNS_REFRESH_TIME_BUDGET) + 2 * $pollerInterval);
}

function gpsmap_dns_worker_is_stale(int $lastSuccess, int $pollerInterval, ?int $now = null): bool {
	$now ??= time();

	return $lastSuccess <= 0 || $lastSuccess < $now - gpsmap_dns_worker_stale_after($pollerInterval);
}

function gpsmap_artifact_prune_threshold(int $cycleStartedAt, int $pollerInterval): int {
	return $cycleStartedAt - (3 * max(60, $pollerInterval));
}

// ---------------------------------------------------------------
function gpsmap_prune_artifacts(int $olderThan): int {
	global $config;

	$directory = $config['base_path'] . '/plugins/gpsmap/XML';
	$removed   = 0;

	foreach (glob($directory . '/*') ?: [] as $path) {
		$name     = basename($path);
		$modified = @filemtime($path);
		$owned    = gpsmap_artifact_filename_is_valid($name)
			|| gpsmap_artifact_temporary_filename_is_valid($name);

		if (!is_file($path) || $modified === false || $modified >= $olderThan || !$owned) {
			continue;
		}

		if (@unlink($path)) {
			$removed++;
		}
	}

	return $removed;
}

// ---------------------------------------------------------------
function createDoc(array $hostArrays, string $preemptive, ?GpsmapPollState $state = null): bool {
	$state ??= gpsmap_poll_state();
	$xml_written = xmlCreate($hostArrays, $preemptive, $state);
	$kml_written = kmlCreate($hostArrays, $preemptive, $state);

	return $xml_written && $kml_written;
}

// ---------------------------------------------------------------
function gpsmap_preserve_unresolved_markers(string $doc, string $preemptive, ?GpsmapPollState $state = null): string {
	$state ??= gpsmap_poll_state();
	$unresolved = array_fill_keys($state->unresolvedDeviceIds, true);

	if ($unresolved === []) {
		return $doc;
	}

	$stem     = gpsmap_require_artifact_stem($preemptive);
	$previous = @file_get_contents(gpsmap_xml_path($stem, 'xml'));

	if (!is_string($previous) || $previous === '') {
		return $doc;
	}

	$current_xml  = new DOMDocument();
	$previous_xml = new DOMDocument();

	if (!@$current_xml->loadXML($doc, LIBXML_NONET) || !@$previous_xml->loadXML($previous, LIBXML_NONET)) {
		return $doc;
	}

	$current_ids     = [];
	$current_markers = [];

	foreach ($current_xml->getElementsByTagName('marker') as $marker) {
		$id                   = $marker->getAttribute('id');
		$current_ids[$id]     = true;
		$current_markers[$id] = $marker;
	}

	$preserved        = false;
	$previous_markers = [];
	$preserved_groups = [];
	/** @var ?DOMElement $root */
	$root      = $current_xml->documentElement;

	foreach ($previous_xml->getElementsByTagName('marker') as $marker) {
		$id                    = $marker->getAttribute('id');
		$previous_markers[$id] = $marker;

		if (!isset($unresolved[$id]) || isset($current_ids[$id])) {
			continue;
		}

		$preserved_at = (int) $marker->getAttribute('gpsmapPreservedAt');

		if (!$state->dnsResolverUnavailable
			&& $preserved_at > 0
			&& $preserved_at < time() - GPSMAP_PRESERVED_MARKER_TTL) {
			continue;
		}

		$copy = $current_xml->importNode($marker, true);

		if ($copy instanceof DOMElement && $root instanceof DOMElement) {
			$copy->setAttribute('gpsmapPreservedAt', (string) ($preserved_at > 0 ? $preserved_at : time()));
			$copy->setAttribute('status', 'undefined');
			$root->appendChild($copy);
			$current_ids[$id]                                 = true;
			$preserved_groups[$marker->getAttribute('group')] = true;
			$preserved                                        = true;
		}
	}

	/* A preserved group member still contributes to the last-known coverage
	 * geometry. Carry the previous radius for its current tower rather than
	 * publishing a newly collapsed circle as if it were measured truth. */
	foreach ($current_markers as $id => $marker) {
		if (!$marker->hasAttribute('start') || !isset($preserved_groups[$marker->getAttribute('group')])) {
			continue;
		}

		$previous_marker = $previous_markers[$id] ?? null;

		if (!$previous_marker instanceof DOMElement) {
			continue;
		}

		$current_radius  = $marker->getAttribute('radius');
		$previous_radius = $previous_marker->getAttribute('radius');

		if (is_numeric($previous_radius)
			&& (!is_numeric($current_radius) || (float) $previous_radius > (float) $current_radius)) {
			$marker->setAttribute('radius', $previous_radius);
		}
	}

	if (!$preserved) {
		return $doc;
	}

	$state->preservedArtifacts[$stem] = true;
	$merged                           = $current_xml->saveXML($root);

	return is_string($merged) ? $merged : $doc;
}

// ---------------------------------------------------------------
/* The previous implementation processed '&' last, which re-encoded the '&'
 * already introduced by the earlier substitutions (e.g. '<' -> '&lt;' -> '&amp;lt;').
 * htmlspecialchars() with ENT_XML1 handles the correct order atomically. */
function parseToXML($htmlStr): string {
	return htmlspecialchars((string) $htmlStr, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

// ---------------------------------------------------------------
function xmlCreate(array $hostArrays, string $preemptive, ?GpsmapPollState $state = null): bool {
	$doc = '<markers>'
		. createXMLNodes($hostArrays[1])
		. coverageXML($hostArrays)
		. '</markers>';
	$doc = gpsmap_preserve_unresolved_markers($doc, $preemptive, $state);

	return gpsmap_write_file(gpsmap_xml_path($preemptive, 'xml'), $doc);
}

// ---------------------------------------------------------------
function coverageXML(array $hostArrays): string {
	global $config;

	$doc = '';
	require($config['base_path'] . '/plugins/gpsmap/includes/polling/coveragexml.php');

	return $doc;
}

// ---------------------------------------------------------------
function kmlCreate(array $hostArrays, string $preemptive, ?GpsmapPollState $state = null): bool {
	global $config;
	gpsmap_require_artifact_stem($preemptive);
	$kml_written = false;

	require($config['base_path'] . '/plugins/gpsmap/includes/polling/kmlcreation.php');

	return $kml_written;
}

// ---------------------------------------------------------------
function gpsmap_preserved_kml_placemarks(string $preemptive): string {
	$xml = new DOMDocument();

	if (!@$xml->load(gpsmap_xml_path($preemptive, 'xml'), LIBXML_NONET)) {
		return '';
	}

	$placemarks = '';

	foreach ($xml->getElementsByTagName('marker') as $marker) {
		if (!$marker->hasAttribute('gpsmapPreservedAt')) {
			continue;
		}

		$lat = $marker->getAttribute('lat');
		$lng = $marker->getAttribute('lng');

		if (!is_numeric($lat) || !is_numeric($lng)) {
			continue;
		}

		$name         = $marker->getAttribute('name');
		$address      = $marker->getAttribute('address');
		$availability = $marker->getAttribute('availability');
		$placemarks .= '<Placemark>'
			. '<name>' . parseToXML($name) . '</name>'
			. '<styleUrl>pushpin</styleUrl> '
			. '<description>' . parseToXML($name) . PHP_EOL
				. 'Availability: ' . parseToXML($availability) . PHP_EOL
				. 'Address: ' . parseToXML($address) . '</description>'
			. '<Point><coordinates>' . parseToXML($lng) . ',' . parseToXML($lat) . '</coordinates></Point>'
			. '</Placemark>' . PHP_EOL;
	}

	return $placemarks;
}

// ---------------------------------------------------------------
function createTypeArray(): array {
	return array_column(db_fetch_assoc_prepared('SELECT `id`, `name` FROM `host_template`', []), 'name', 'id');
}

// ---------------------------------------------------------------
/* Renders one <marker/>.  Device markers carry no radius and no schedule;
 * tower markers carry the computed coverage radius and their active window. */
function gpsmap_marker(host $host, array $typeArray, string $radius, bool $withSchedule): string {
	$doc = '<marker '
		. 'id="' . parseToXML($host->id) . '" '
		. 'name="' . parseToXML($host->description) . '" '
		. 'address="' . parseToXML($host->hostname) . '" '
		. 'lat="' . parseToXML($host->lat) . '" '
		. 'lng="' . parseToXML($host->long) . '" '
		. 'type="' . parseToXML($typeArray[$host->type] ?? 'Unknown') . '" '
		. 'templateId="' . parseToXML($host->type) . '" '
		. 'availability="' . $host->avail . '" '
		. 'radius="' . $radius . '" '
		. 'status="' . parseToXML($host->status) . '" '
		. 'latency="' . parseToXML($host->latency) . '" ';

	if ($withSchedule) {
		$doc .= 'start="' . parseToXML($host->start) . '" '
			. 'stop="' . parseToXML($host->stop) . '" ';
	}

	return $doc . 'group="' . parseToXML($host->group) . '" />' . "\n";
}

// ---------------------------------------------------------------
function createXMLNodes(array $hostArray): string {
	$typeArray = createTypeArray();
	$doc       = '';

	foreach ($hostArray as $host) {
		if ($host->showMap == 1) {
			$doc .= gpsmap_marker($host, $typeArray, '0', false);
		}
	}

	return $doc;
}
