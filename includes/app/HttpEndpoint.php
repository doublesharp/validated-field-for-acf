<?php
namespace ACF_VF\App;
defined('ABSPATH') || exit;
/** Shared configuration safety, without external delivery. */
final class HttpEndpoint {
    public static function validate_endpoint(string $url) {
        $parts = wp_parse_url($url);
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || (isset($parts['port']) && (int) $parts['port'] !== 443) || !wp_http_validate_url($url)) { return new \WP_Error('webhook_endpoint', 'Webhook requires a public HTTPS URL on port 443.'); }
        $host = strtolower($parts['host'] ?? '');
        if ($host === 'localhost' || substr($host, -6) === '.local' || substr($host, -9) === '.internal' || (filter_var($host, FILTER_VALIDATE_IP) && !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE))) { return new \WP_Error('webhook_endpoint', 'Private webhook addresses are prohibited.'); }
        $addresses = self::addresses($host);
        if (!$addresses) { return new \WP_Error('webhook_endpoint', 'Webhook hostname has no public address.'); }
        return $url;
    }

    public static function addresses(string $host): array {
        if (filter_var($host, FILTER_VALIDATE_IP)) { $addresses = [$host]; }
        else {
            $addresses = gethostbynamel($host) ?: [];
            if (function_exists('dns_get_record')) {
                foreach ((array) @dns_get_record($host, DNS_AAAA) as $record) { if (!empty($record['ipv6'])) { $addresses[] = $record['ipv6']; } }
            }
        }
        $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
        if (defined('FILTER_FLAG_GLOBAL_RANGE')) { $flags |= FILTER_FLAG_GLOBAL_RANGE; }
        foreach ($addresses as $address) {
            if (!filter_var($address, FILTER_VALIDATE_IP, $flags)) { return []; }
        }
        return array_values(array_unique($addresses));
    }

}
