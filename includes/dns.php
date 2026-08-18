<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
*/

function gpsmap_resolve_hostname(string $hostname, ?callable $lookup = null): string {
	if (filter_var($hostname, FILTER_VALIDATE_IP)) {
		return $hostname;
	}

	$lookup ??= static fn (string $name): array|false => dns_get_record($name, DNS_A | DNS_AAAA);
	$records = @$lookup($hostname);

	if (!is_array($records)) {
		return '';
	}

	// Preserve the resolver's ordering; both families are valid map addresses.
	foreach ($records as $record) {
		$address = $record['ip'] ?? $record['ipv6'] ?? '';

		if (filter_var($address, FILTER_VALIDATE_IP)) {
			return $address;
		}
	}

	return '';
}

function gpsmap_refresh_dns_cache(?callable $resolver = null): int {
	$resolver ??= static fn (string $name): string => gpsmap_resolve_hostname($name);
	$rows = db_fetch_assoc('SELECT DISTINCT h.hostname
		FROM host AS h
		INNER JOIN gpsmap_templates AS gt ON gt.templateID = h.host_template_id
		LEFT JOIN plugin_gpsmap_dns_cache AS dc ON dc.hostname = h.hostname
		WHERE dc.hostname IS NULL OR dc.refreshed_at < NOW() - INTERVAL 1 HOUR');

	$updated = 0;

	foreach ($rows ?: [] as $row) {
		$hostname = (string) $row['hostname'];

		if (filter_var($hostname, FILTER_VALIDATE_IP)) {
			continue;
		}

		db_execute_prepared('INSERT INTO plugin_gpsmap_dns_cache
			(hostname, address, refreshed_at) VALUES (?, ?, NOW())
			ON DUPLICATE KEY UPDATE
				address = IF(VALUES(address) = "", address, VALUES(address)),
				refreshed_at = NOW()',
			[$hostname, $resolver($hostname)]);
		$updated++;
	}

	return $updated;
}
