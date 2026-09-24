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
/**
 * Loads a single subnet's device set and renders its map artifacts, for
 * ad-hoc single-subnet regeneration (as opposed to the poller's full
 * multi-subnet cycle). Currently a compatibility entry point with no
 * production call sites in this plugin (gpsmap.php/gpstemplates.php
 * read the generated map artifacts rather than calling this directly).
 *
 * @param string $subnet The subnet prefix (or 'all'/'v6-...' stem) to
 *                       render.
 *
 * @return void
 *
 * @global array $config Cacti global configuration array; used to load
 *                        pollinginitial.php and processregion.php.
 */
function callRegion(string $subnet): void {
	global $config;

	include_once($config['base_path'] . '/plugins/gpsmap/includes/polling/pollinginitial.php');
	include_once($config['base_path'] . '/plugins/gpsmap/includes/polling/processregion.php');

	region($subnet);
}

/**
 * Returns the list of host_template_id values flagged as Access Points
 * (AP=1) in gpsmap_templates, used to classify loaded devices as towers
 * vs. regular devices. Called from gpsmap_load_devices() while building
 * the tower/device arrays.
 *
 * 9999 is a sentinel that matches no host_template_id, so an empty
 * result set still produces a usable in_array() haystack.
 *
 * @return array The list of Access-Point host_template_id values, or
 *               [9999] (a sentinel matching no real template) when none
 *               are configured.
 */
function getTowerIds(): array {
	$results = db_fetch_assoc_prepared('SELECT `templateID`
		FROM `gpsmap_templates`
		WHERE `AP` = 1', []);

	/* 9999 is a sentinel that matches no host_template_id, so an empty
	 * result set still produces a usable in_array() haystack. */
	return cacti_sizeof($results) ? array_column($results, 'templateID') : [9999];
}

/**
 * Computes the great-circle distance between two lat/lon coordinate
 * pairs. Called from gpsmap_marker() and coverage-overlay generation
 * while sizing a tower's coverage radius/rendering distance-based map
 * elements.
 *
 * Returns the great-circle distance in kilometres (not metres, the
 * constant 6378.7 is Earth's mean radius in km). Renamed from calcMeters
 * to reflect the actual unit; callers treating the result as metres will
 * be off by 1000x. The gpsmap_templates.radius column is also stored in
 * km.
 *
 * @param float $Lat1 The first point's latitude.
 * @param float $Lon1 The first point's longitude.
 * @param float $Lat2 The second point's latitude.
 * @param float $Lon2 The second point's longitude.
 *
 * @return float The great-circle distance in kilometres.
 */
function calcKm(float $Lat1, float $Lon1, float $Lat2, float $Lon2): float {
	return 6378.7 * 3.1415926 * sqrt(
		($Lat2 - $Lat1) * ($Lat2 - $Lat1)
		+ cos($Lat2 / 57.29578) * cos($Lat1 / 57.29578) * ($Lon2 - $Lon1) * ($Lon2 - $Lon1)
	) / 180;
}

/**
 * Deprecated alias for calcKm(), kept for backward compatibility with any
 * external callers still using the old (misleadingly-named) function.
 *
 * @deprecated Use calcKm() instead.
 *
 * @param float $Lat1 The first point's latitude.
 * @param float $Lon1 The first point's longitude.
 * @param float $Lat2 The second point's latitude.
 * @param float $Lon2 The second point's longitude.
 *
 * @return float The great-circle distance in kilometres.
 */
function calcMeters(float $Lat1, float $Lon1, float $Lat2, float $Lon2): float {
	return calcKm($Lat1, $Lon1, $Lat2, $Lon2);
}

/**
 * Validates that a coordinate string looks like a signed decimal number,
 * used to guard against unparseable latitude/longitude values reaching
 * the map renderer. Called from gpsmap_load_devices() while building
 * each host object.
 *
 * @param string $coords The candidate coordinate string.
 *
 * @return string The trimmed coordinate string when valid, or '0.000'
 *                for anything unparseable (the user can correct the
 *                device).
 */
function coordCheck(string $coords): string {
	$coords = trim($coords);

	// return 0.000 for anything unparseable, the user can correct the device
	return preg_match('#^-?\d{1,3}\.\d+$#', $coords) ? $coords : '0.000';
}

/**
 * Writes generated artifact content (XML, KML, top HTML) to disk
 * atomically: writes to a temp file, preserves the destination's
 * existing file permissions, then renames the temp file into place, so
 * a concurrent web request never observes a partially-written or
 * truncated document. This is the single writer for every generated
 * artifact so the failure path and its log message stay identical across
 * all three. Called from createDoc()/xmlCreate()/kmlCreate()/coverage
 * generation whenever a map artifact file needs to be (re)written.
 *
 * Staged and renamed into place: the browser fetches these files while
 * the poller rewrites them, and rename() is atomic within a filesystem.
 * A short write is a failure, so disk pressure cannot publish a
 * truncated document while reporting success. The temp file is a new
 * inode, so it starts at 0666 & ~umask rather than inheriting the
 * destination's mode. These files are read by the web server, not the
 * poller, so losing the mode breaks the map silently. rename() already
 * replaces an existing destination on every supported platform, so a
 * failure here is a filesystem or permission problem. Unlinking first
 * would destroy the last-good document without any guarantee the retry
 * succeeds, turning a stale map into a missing one.
 *
 * @param string        $filename   The destination path to write.
 * @param string        $contents   The content to write.
 * @param callable|null $modeSetter Override for the permission-setting
 *                                  call (for testing); defaults to
 *                                  'chmod'.
 *
 * @return bool True once the file has been written and renamed into
 *              place; false on any write/rename failure (logged via
 *              cacti_log()).
 */
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

/**
 * Builds the on-disk path for a subnet's generated artifact file, given
 * its extension. Called throughout this file's artifact-generation
 * functions to resolve where to read/write a given subnet's file.
 *
 * @param string $preemptive The subnet prefix (or 'all'/'v6-...' stem).
 * @param string $extension  The file extension ('xml', 'kml', etc.),
 *                           without a leading dot.
 *
 * @return string The resolved absolute file path.
 *
 * @global array $config Cacti global configuration array; used to
 *                        resolve the plugin's XML directory.
 */
function gpsmap_xml_path(string $preemptive, string $extension): string {
	global $config;
	$stem = gpsmap_require_artifact_stem($preemptive);

	return $config['base_path'] . '/plugins/gpsmap/XML/' . $stem . '.' . $extension;
}

/**
 * Encodes an IPv6 address's prefix (of a given bit length) as a
 * reversible, filesystem-safe token for use in subnet artifact
 * filenames. Called from gpsmap_subnet_prefixes() while enumerating
 * IPv6 prefixes present in the loaded device set.
 *
 * IPv4 keeps its historical dotted tokens. IPv6 uses a reversible,
 * filename safe token: v6-<prefix length>-<significant hex>.
 *
 * @param string $address The IPv6 address to derive a prefix token from.
 * @param int    $length  The prefix length in bits; must be 16, 32, or
 *                        48.
 *
 * @return string|null The encoded prefix token, or null when $address
 *                      isn't a valid IPv6 address or $length isn't
 *                      supported.
 */
function gpsmap_ipv6_prefix_token(string $address, int $length): ?string {
	$packed = @inet_pton($address);

	if ($packed === false || strlen($packed) !== 16 || !in_array($length, [16, 32, 48], true)) {
		return null;
	}

	return 'v6-' . $length . '-' . substr(bin2hex($packed), 0, intdiv($length, 4));
}

/**
 * Decodes a gpsmap_ipv6_prefix_token()-encoded subnet token back into a
 * human-readable CIDR notation string, for display in the map's subnet
 * navigation UI.
 *
 * @param string $token The encoded IPv6 prefix token
 *                      ('v6-<length>-<hex>').
 *
 * @return string|null The 'address/length' CIDR string, or null when
 *                      $token is not a validly-formed prefix token.
 */
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

/**
 * Determines whether a given IPv6 address falls within a
 * gpsmap_ipv6_prefix_token()-encoded subnet token. Called from
 * gpsmap_render_region() while deciding which hosts belong to the
 * requested IPv6 subnet render.
 *
 * @param string $token   The encoded IPv6 prefix token
 *                        ('v6-<length>-<hex>').
 * @param string $address The IPv6 address to test.
 *
 * @return bool True when $address falls within the prefix encoded by
 *              $token.
 */
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

/**
 * Normalizes a requested subnet value to its canonical artifact filename
 * stem ('all' for an empty value, or the value with surrounding dots
 * trimmed), validating it against the allowed stem pattern. Called from
 * gpsmap_require_artifact_stem() and gpsmap_artifact_subnet_is_valid()
 * before trusting a subnet value used to build a filename.
 *
 * @param string $subnet The requested subnet value.
 *
 * @return string|null The normalized stem, or null when $subnet doesn't
 *                      match the allowed pattern.
 */
function gpsmap_artifact_stem(string $subnet): ?string {
	$subnet = $subnet === '' ? 'all' : trim($subnet, '.');

	return gpsmap_artifact_parameter_is_valid($subnet)
		? $subnet
		: null;
}

/**
 * Normalizes a requested subnet value to its canonical artifact filename
 * stem, throwing when the value is invalid rather than returning null.
 * Called throughout this plugin's artifact-generation functions
 * (gpsmap_xml_path(), gpsmap_render_region(), etc.) wherever an invalid
 * subnet must not silently produce a usable path.
 *
 * @param string $subnet The requested subnet value.
 *
 * @return string The normalized artifact stem.
 *
 * @throws InvalidArgumentException When $subnet does not match the
 *                                   allowed stem pattern.
 */
function gpsmap_require_artifact_stem(string $subnet): string {
	$stem = gpsmap_artifact_stem($subnet);

	if ($stem === null) {
		throw new InvalidArgumentException('Invalid gpsmap artifact subnet');
	}

	return $stem;
}

// ---------------------------------------------------------------
/**
 * Validates that a subnet value is either a valid artifact stem, or the
 * legacy dotted-prefix form ('10.', '10.20.') that IPv4 subnet
 * traversal has historically supplied, without accepting stray
 * punctuation around IPv6 tokens. Called from
 * gpsmap_render_region()/coveragexml.php before trusting a
 * caller-supplied subnet value.
 *
 * IPv4 traversal historically supplies dotted prefixes such as "10.20.".
 * Keep that internal contract without accepting punctuation around IPv6
 * tokens.
 *
 * @param string $subnet The candidate subnet value to validate.
 *
 * @return bool True when $subnet is a valid artifact stem or legacy
 *              dotted-prefix form.
 */
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

	/**
	 * Resets every tracked field back to its initial empty/false state, so
	 * a single GpsmapPollState instance can be reused across repeated
	 * calls to gpsmap_load_devices() within the same poller cycle. Called
	 * from gpsmap_load_devices() at the start of each device load.
	 *
	 * @return void
	 */
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

/**
 * Returns a process-wide singleton GpsmapPollState instance, created on
 * first use. Compatibility state for direct region() callers; the poller
 * passes its own state instance through gpsmap_load_devices()/
 * gpsmap_render_region() instead of relying on this singleton.
 *
 * Compatibility state for direct region() callers; the poller passes its
 * own.
 *
 * @return GpsmapPollState The shared poll-state instance.
 */
function gpsmap_poll_state(): GpsmapPollState {
	/** @var ?GpsmapPollState $state */
	static $state = null;

	return $state ??= new GpsmapPollState();
}

if (!defined('GPSMAP_PRESERVED_MARKER_TTL')) {
	define('GPSMAP_PRESERVED_MARKER_TTL', 86400);
}

/**
 * Computes the number of seconds after which the DNS refresh worker's
 * last successful completion should be considered stale, based on the
 * poller interval and the worker's own time budget. Called from
 * gpsmap_dns_worker_is_stale() to determine the staleness threshold.
 *
 * @param int $pollerInterval The configured poller interval in seconds
 *                            (clamped to a 60-second minimum).
 *
 * @return int The computed staleness threshold in seconds.
 */
function gpsmap_dns_worker_stale_after(int $pollerInterval): int {
	$pollerInterval = max(60, $pollerInterval);

	return max(3 * $pollerInterval, (int) ceil(GPSMAP_DNS_REFRESH_TIME_BUDGET) + 2 * $pollerInterval);
}

/**
 * Determines whether the DNS refresh worker's last successful run is too
 * old (or has never succeeded) to be trusted. Called from
 * gpsmap_load_devices()/gpsmap_poller_bottom() to decide whether to warn
 * about a stale/unavailable resolver.
 *
 * @param int      $lastSuccess    The Unix timestamp of the worker's last
 *                                 successful completion (0 or negative
 *                                 when it has never succeeded).
 * @param int      $pollerInterval The configured poller interval in
 *                                 seconds.
 * @param int|null $now            The reference "now" timestamp; defaults
 *                                 to the current time when null.
 *
 * @return bool True when the worker's last success is stale or missing.
 */
function gpsmap_dns_worker_is_stale(int $lastSuccess, int $pollerInterval, ?int $now = null): bool {
	$now ??= time();

	return $lastSuccess <= 0 || $lastSuccess < $now - gpsmap_dns_worker_stale_after($pollerInterval);
}

/**
 * Computes the cutoff timestamp before which a generated artifact/
 * temporary file is old enough to be considered abandoned and safe to
 * prune. Called from gpsmap_poller_bottom() before pruning stale
 * artifact files.
 *
 * @param int $cycleStartedAt The current poller cycle's start timestamp.
 * @param int $pollerInterval The configured poller interval in seconds.
 *
 * @return int The computed cutoff Unix timestamp.
 */
function gpsmap_artifact_prune_threshold(int $cycleStartedAt, int $pollerInterval): int {
	return $cycleStartedAt - (3 * max(60, $pollerInterval));
}

/**
 * Deletes generated artifact/temporary files (per
 * gpsmap_artifact_filename_is_valid()/
 * gpsmap_artifact_temporary_filename_is_valid()) in this plugin's XML
 * directory that are older than a given cutoff, cleaning up abandoned
 * subnet files (e.g. from since-removed devices) and orphaned temp files
 * from interrupted writes. Called from gpsmap_poller_bottom() once per
 * cycle after regenerating this cycle's artifacts.
 *
 * @param int $olderThan The cutoff Unix timestamp; files modified before
 *                        this are eligible for removal.
 *
 * @return int The number of files removed.
 *
 * @global array $config Cacti global configuration array; used to locate
 *                        the plugin's XML directory.
 */
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

/**
 * Generates both the XML and KML documents for a subnet. Called from
 * gpsmap_render_region() (via the coveragexml.php/kmlcreation.php
 * includes) as the combined entry point for a subnet's two primary map
 * artifacts.
 *
 * @param array                $hostArrays The [$towerArray, $hostArray]
 *                                         pair to render.
 * @param string               $preemptive The subnet prefix (or
 *                                         'all'/'v6-...' stem) being
 *                                         rendered.
 * @param GpsmapPollState|null $state      The current poll state, used
 *                                         for last-known-good
 *                                         preservation; created
 *                                         automatically when null.
 *
 * @return bool True when both the XML and KML documents were written
 *              successfully.
 */
function createDoc(array $hostArrays, string $preemptive, ?GpsmapPollState $state = null): bool {
	$state ??= gpsmap_poll_state();
	$xml_written = xmlCreate($hostArrays, $preemptive, $state);
	$kml_written = kmlCreate($hostArrays, $preemptive, $state);

	return $xml_written && $kml_written;
}

/**
 * Merges last-known-good marker entries from a subnet's previously
 * generated XML document into the newly generated one, for any devices
 * whose hostname currently can't be resolved (per the poll state's
 * unresolved device ids), so an unresolved device's marker doesn't
 * simply vanish from the map. Preserved markers are flagged 'undefined'
 * status and time-stamped so they eventually expire (unless the DNS
 * resolver itself is unavailable, in which case they're kept
 * indefinitely). Also carries forward a preserved tower's last-known
 * coverage radius rather than letting a newly collapsed circle publish
 * as measured truth. Called from xmlCreate() before writing a subnet's
 * XML document.
 *
 * @param string                $doc        The newly generated XML
 *                                          document string.
 * @param string                $preemptive The subnet prefix (or
 *                                          'all'/'v6-...' stem) being
 *                                          rendered.
 * @param GpsmapPollState|null  $state      The current poll state,
 *                                          including the list of
 *                                          unresolved device ids;
 *                                          created automatically when
 *                                          null.
 *
 * @return string The XML document, with any preserved markers merged in
 *                (or unchanged when there was nothing to preserve, or
 *                the previous document could not be parsed).
 */
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
/**
 * Escapes a string for safe embedding as XML character data/attribute
 * content. Called throughout this file's XML/KML generation functions
 * (gpsmap_marker(), gpsmap_preserved_kml_placemarks(), etc.) for every
 * value written into a generated document.
 *
 * @param mixed $htmlStr The value to escape.
 *
 * @return string The XML-escaped string.
 */
function parseToXML($htmlStr): string {
	return htmlspecialchars((string) $htmlStr, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/**
 * Generates and writes a subnet's `<markers>` XML document (device
 * markers plus the coverage-overlay markup), preserving any last-known-
 * good markers for currently-unresolved devices. Called from createDoc()
 * as one of the two primary artifacts generated per subnet.
 *
 * @param array                $hostArrays The [$towerArray, $hostArray]
 *                                         pair to render.
 * @param string               $preemptive The subnet prefix (or
 *                                         'all'/'v6-...' stem) being
 *                                         rendered.
 * @param GpsmapPollState|null $state      The current poll state, used
 *                                         for last-known-good
 *                                         preservation; created
 *                                         automatically when null.
 *
 * @return bool True once the XML document has been written successfully.
 */
function xmlCreate(array $hostArrays, string $preemptive, ?GpsmapPollState $state = null): bool {
	$doc = '<markers>'
		. createXMLNodes($hostArrays[1])
		. coverageXML($hostArrays)
		. '</markers>';
	$doc = gpsmap_preserve_unresolved_markers($doc, $preemptive, $state);

	return gpsmap_write_file(gpsmap_xml_path($preemptive, 'xml'), $doc);
}

/**
 * Renders the coverage-overlay markup (circles around each Access
 * Point's furthest associated device) by delegating to the
 * coveragexml.php include, which builds its output into the local $doc
 * variable. Called from xmlCreate() while assembling a subnet's markers
 * XML document.
 *
 * @param array $hostArrays The [$towerArray, $hostArray] pair to render
 *                          coverage for.
 *
 * @return string The generated coverage-overlay XML markup.
 *
 * @global array $config Cacti global configuration array; used to locate
 *                        coveragexml.php.
 */
function coverageXML(array $hostArrays): string {
	global $config;

	$doc = '';
	require($config['base_path'] . '/plugins/gpsmap/includes/polling/coveragexml.php');

	return $doc;
}

/**
 * Generates and writes a subnet's KML document by delegating to the
 * kmlcreation.php include, which builds its result into the local
 * $kml_written variable. Called from createDoc() as one of the two
 * primary artifacts generated per subnet.
 *
 * @param array                $hostArrays The [$towerArray, $hostArray]
 *                                         pair to render.
 * @param string               $preemptive The subnet prefix (or
 *                                         'all'/'v6-...' stem) being
 *                                         rendered.
 * @param GpsmapPollState|null $state      The current poll state, used
 *                                         for last-known-good
 *                                         preservation; created
 *                                         automatically when null.
 *
 * @return bool True once the KML document has been written successfully.
 *
 * @global array $config Cacti global configuration array; used to locate
 *                        kmlcreation.php.
 */
function kmlCreate(array $hostArrays, string $preemptive, ?GpsmapPollState $state = null): bool {
	global $config;
	gpsmap_require_artifact_stem($preemptive);
	$kml_written = false;

	require($config['base_path'] . '/plugins/gpsmap/includes/polling/kmlcreation.php');

	return $kml_written;
}

/**
 * Reads a subnet's already-written XML document and re-renders any
 * markers flagged as preserved (last-known-good, currently unresolved)
 * as KML `<Placemark>` elements, so the KML output stays consistent with
 * the preservation already applied to the XML document. Called from
 * kmlcreation.php while assembling a subnet's KML document.
 *
 * @param string $preemptive The subnet prefix (or 'all'/'v6-...' stem)
 *                           whose XML document should be scanned.
 *
 * @return string The generated `<Placemark>` KML markup for every
 *                preserved marker found, or '' when the XML document
 *                could not be read.
 */
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

/**
 * Returns a map of host_template.id to its display name, used to label
 * each marker with its device template type. Called from
 * createXMLNodes() before rendering markers.
 *
 * @return array Map of host_template.id to host_template.name.
 */
function createTypeArray(): array {
	return array_column(db_fetch_assoc_prepared('SELECT `id`, `name` FROM `host_template`', []), 'name', 'id');
}

/**
 * Renders a single `<marker/>` XML element for one host, including its
 * position, template type, availability/status/latency, and (when
 * requested) coverage radius and monitoring-window schedule. Device
 * markers carry no radius and no schedule; tower markers carry the
 * computed coverage radius and their active window. Called from
 * createXMLNodes() for each visible device, and from coveragexml.php for
 * each tower.
 *
 * @param host   $host         The host object to render.
 * @param array  $typeArray    Map of host_template.id to display name, as
 *                             returned by createTypeArray().
 * @param string $radius       The coverage radius to embed (device
 *                             markers pass '0').
 * @param bool   $withSchedule Whether to include the start/stop
 *                             monitoring-window attributes (true for
 *                             towers).
 *
 * @return string The rendered `<marker/>` XML element.
 */
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

/**
 * Renders `<marker/>` XML elements for every currently-visible (non-
 * tower) device in the given host array. Called from xmlCreate() while
 * assembling a subnet's markers XML document.
 *
 * @param array $hostArray The device host objects to render (as returned
 *                         by gpsmap_load_devices()'s second array
 *                         element).
 *
 * @return string The concatenated `<marker/>` XML elements for every
 *                visible device.
 */
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
