<?php

namespace App\Support\Http;

/**
 * SSRF protection: only http(s) URLs whose host resolves exclusively to public IP addresses.
 * Used before the server requests any user-provided URL (link checks, remote images).
 */
class SafeUrl
{
    public static function isPublic(string $url): bool
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || empty($parts['host'])) {
            return false;
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $host = trim($parts['host'], '[]');

        $ips = filter_var($host, FILTER_VALIDATE_IP)
            ? [$host]
            : array_merge(
                gethostbynamel($host) ?: [],
                array_column(@dns_get_record($host, DNS_AAAA) ?: [], 'ipv6'),
            );

        if ($ips === []) {
            return false;
        }

        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }

        return true;
    }
}
