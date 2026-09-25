<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
*/

require_once __DIR__ . '/../gpsmap_security.php';

/**
 * Resolves a hostname to an IP address for map plotting: returns IP
 * literals unchanged, otherwise tries DNS A/AAAA records first and falls
 * back to the operating system's resolver (covering /etc/hosts, NIS, and
 * mDNS names that dns_get_record() bypasses). Called from
 * gpsmap_refresh_dns_cache() for each hostname due for a cache refresh.
 *
 * dns_get_record() bypasses the operating system's NSS sources. Keep
 * this fallback in the background worker so /etc/hosts, NIS and mDNS
 * names work without putting a blocking lookup back on the poller path.
 *
 * @param string        $hostname     The hostname (or IP literal) to
 *                                    resolve.
 * @param callable|null $lookup       Override for the DNS record lookup
 *                                    (for testing); defaults to
 *                                    dns_get_record() for A/AAAA records.
 * @param callable|null $systemLookup Override for the OS-resolver
 *                                    fallback (for testing); defaults to
 *                                    gethostbyname().
 *
 * @return string The resolved IP address, or '' when resolution failed.
 */
function gpsmap_resolve_hostname(string $hostname, ?callable $lookup = null, ?callable $systemLookup = null): string {
	if (filter_var($hostname, FILTER_VALIDATE_IP)) {
		return $hostname;
	}

	$lookup ??= static fn (string $name): array|false => dns_get_record($name, DNS_A | DNS_AAAA);
	$systemLookup ??= static fn (string $name): string|false => gethostbyname($name);
	$records = @$lookup($hostname);

	if (is_array($records)) {
		// Preserve the resolver's ordering; both families are valid map addresses.
		foreach ($records as $record) {
			$address = $record['ip'] ?? $record['ipv6'] ?? '';

			if (filter_var($address, FILTER_VALIDATE_IP)) {
				return $address;
			}
		}
	}

	/* dns_get_record() bypasses the operating system's NSS sources. Keep this
	 * fallback in the background worker so /etc/hosts, NIS and mDNS names work
	 * without putting a blocking lookup back on the poller path. */
	$address = @$systemLookup($hostname);

	return is_string($address) && $address !== $hostname && filter_var($address, FILTER_VALIDATE_IP)
		? $address
		: '';
}

if (!defined('GPSMAP_DNS_REFRESH_BATCH_SIZE')) {
	define('GPSMAP_DNS_REFRESH_BATCH_SIZE', 100);
}

if (!defined('GPSMAP_DNS_WORKER_REGISTRATION_TIMEOUT')) {
	define('GPSMAP_DNS_WORKER_REGISTRATION_TIMEOUT', (int) GPSMAP_DNS_REFRESH_TIME_BUDGET + 30);
}

/**
 * Deletes plugin_gpsmap_dns_cache rows for hostnames that no longer
 * belong to any device using a Map Template, keeping the cache table
 * from growing unbounded as devices are removed/reassigned. Called from
 * gpsmap_refresh_dns_cache() after processing a batch of hostname
 * lookups.
 *
 * @return bool True when the cleanup query executed successfully.
 */
function gpsmap_reap_dns_cache(): bool {
	$reaped = db_execute_prepared('DELETE dc
		FROM plugin_gpsmap_dns_cache AS dc
		LEFT JOIN (
			SELECT DISTINCT UNHEX(SHA2(h.hostname, 256)) AS hostname_hash
			FROM host AS h
			INNER JOIN gpsmap_templates AS gt ON gt.templateID = h.host_template_id
		) AS live ON live.hostname_hash = dc.hostname_hash
		WHERE live.hostname_hash IS NULL', []);

	if (!$reaped) {
		cacti_log('ERROR: gpsmap DNS refresh could not remove cache rows for Devices that are no longer mapped', false, 'GPSMAP');
	}

	return $reaped;
}

/**
 * Refreshes the DNS cache for a bounded batch of mapped device hostnames
 * that are due for a lookup (staggered by an increasing backoff based on
 * each hostname's failure count), stopping early if the configured time
 * budget is exceeded so a slow batch can't stall the poller. Keeps the
 * last successful address and its refreshed_at timestamp on failure, but
 * records the failed attempt so the poller can eventually stop
 * preserving a permanently unresolved device after a bounded grace
 * period. Called from gpsmap_run_dns_refresh_worker() (i.e. from
 * gpsmap_dns.php, launched by gpsmap_schedule_dns_refresh()).
 *
 * @param callable|null $resolver Override for hostname resolution (for
 *                                testing); defaults to
 *                                gpsmap_resolve_hostname().
 * @param callable|null $clock    Override for the monotonic clock used
 *                                to enforce the time budget (for
 *                                testing); defaults to
 *                                hrtime(true)/1e9.
 *
 * @return int|false The number of hostnames successfully resolved and
 *                   cached this run, or false when a database error
 *                   prevented the refresh from completing.
 */
function gpsmap_refresh_dns_cache(?callable $resolver = null, ?callable $clock = null): int|false {
	$resolver ??= static fn (string $name): string => gpsmap_resolve_hostname($name);
	$clock ??= static fn (): float => hrtime(true) / 1e9;
	$started = $clock();
	$rows    = db_fetch_assoc_prepared('SELECT h.hostname, MIN(dc.attempted_at) AS attempted_at
		FROM host AS h
		INNER JOIN gpsmap_templates AS gt ON gt.templateID = h.host_template_id
		LEFT JOIN plugin_gpsmap_dns_cache AS dc
			ON dc.hostname_hash = UNHEX(SHA2(h.hostname, 256))
		WHERE INET6_ATON(h.hostname) IS NULL
			AND (dc.hostname_hash IS NULL
				OR dc.attempted_at IS NULL
				OR (dc.failure_count = 0 AND dc.attempted_at < NOW() - INTERVAL 1 HOUR)
				OR (dc.failure_count BETWEEN 1 AND 3 AND dc.attempted_at < NOW() - INTERVAL 4 HOUR)
				OR (dc.failure_count >= 4 AND dc.attempted_at < NOW() - INTERVAL 24 HOUR))
		GROUP BY h.hostname
		ORDER BY MIN(dc.attempted_at) IS NULL DESC, MIN(dc.attempted_at)
		LIMIT ' . GPSMAP_DNS_REFRESH_BATCH_SIZE, []);

	if ($rows === false) {
		cacti_log('ERROR: gpsmap DNS refresh could not read the hostname cache work queue', false, 'GPSMAP');

		return false;
	}

	$updated = 0;

	foreach (array_slice($rows, 0, GPSMAP_DNS_REFRESH_BATCH_SIZE) as $row) {
		if ($clock() - $started >= GPSMAP_DNS_REFRESH_TIME_BUDGET) {
			cacti_log('WARNING: gpsmap DNS refresh reached its time budget; remaining names are deferred to the next worker run', false, 'GPSMAP');

			break;
		}

		$hostname = (string) $row['hostname'];
		$address  = $resolver($hostname);

		/* Keep the last successful address and its refreshed_at timestamp, but
		 * record failed attempts. The poller uses that count to stop preserving a
		 * permanently unresolved Device after a bounded grace period. */
		if (!filter_var($address, FILTER_VALIDATE_IP)) {
			cacti_log('WARNING: gpsmap DNS refresh could not resolve a configured hostname; retaining any last-known-good address', false, 'GPSMAP');

			if (!db_execute_prepared('INSERT INTO plugin_gpsmap_dns_cache
				(hostname_hash, hostname, address, attempted_at, failure_count)
				VALUES (UNHEX(SHA2(?, 256)), ?, ?, NOW(), 1)
				ON DUPLICATE KEY UPDATE
					hostname = VALUES(hostname),
					attempted_at = NOW(),
					failure_count = LEAST(failure_count + 1, 65535)',
				[$hostname, $hostname, ''])) {
				cacti_log('ERROR: gpsmap DNS refresh could not record a failed hostname lookup', false, 'GPSMAP');

				return false;
			}

			continue;
		}

		if (!db_execute_prepared('INSERT INTO plugin_gpsmap_dns_cache
			(hostname_hash, hostname, address, refreshed_at, attempted_at, failure_count)
			VALUES (UNHEX(SHA2(?, 256)), ?, ?, NOW(), NOW(), 0)
			ON DUPLICATE KEY UPDATE
				hostname = VALUES(hostname),
				address = VALUES(address),
				refreshed_at = NOW(),
				attempted_at = NOW(),
				failure_count = 0',
			[$hostname, $hostname, $address])) {
			cacti_log('ERROR: gpsmap DNS refresh could not store a resolved hostname', false, 'GPSMAP');

			return false;
		}

		$updated++;
	}

	return gpsmap_reap_dns_cache() ? $updated : false;
}

/**
 * Runs the DNS cache refresh as a singleton background worker: claims a
 * process-registration lock (refusing to start if another worker already
 * owns it), guarantees the lock is released on completion or failure via
 * a shutdown handler, runs the refresh, and records the last successful
 * completion time on success. Called from gpsmap_dns_refresh_exit_code()
 * (i.e. from gpsmap_dns.php's main flow).
 *
 * @param callable|null $refresh           Override for the refresh
 *                                         operation (for testing);
 *                                         defaults to
 *                                         'gpsmap_refresh_dns_cache'.
 * @param callable|null $shutdownRegistrar Override for registering the
 *                                         lock-release callback (for
 *                                         testing); defaults to
 *                                         'register_shutdown_function'.
 *
 * @return bool True when the refresh completed successfully; false when
 *              another worker already owns the lock or the refresh
 *              itself failed.
 */
function gpsmap_run_dns_refresh_worker(?callable $refresh = null, ?callable $shutdownRegistrar = null): bool {
	if (!register_process_start('gpsmap', 'dns-refresh', 0, GPSMAP_DNS_WORKER_REGISTRATION_TIMEOUT)) {
		cacti_log('NOTICE: gpsmap DNS refresh did not start because another worker owns the process registration', false, 'GPSMAP');

		return false;
	}

	$refresh ??= 'gpsmap_refresh_dns_cache';
	$shutdownRegistrar ??= 'register_shutdown_function';
	$registered           = true;
	$release              = static function () use (&$registered): void {
		if (!$registered) {
			return;
		}

		unregister_process('gpsmap', 'dns-refresh', 0);
		$registered = false;
	};
	$shutdownRegistrar($release);

	try {
		$result = $refresh();
	} finally {
		$release();
	}

	if ($result === false) {
		return false;
	}

	set_config_option('plugin_gpsmap_dns_last_success', (string) time());

	return true;
}

/**
 * Runs the DNS cache refresh worker and translates its result to a
 * process exit code. Called from gpsmap_dns.php's main flow as the
 * script's final action.
 *
 * @return int 0 on success, 1 on failure.
 */
function gpsmap_dns_refresh_exit_code(): int {
	return gpsmap_run_dns_refresh_worker() ? 0 : 1;
}
