<?php
/**
 * Submission Geo Resolver
 *
 * Resolves and caches geo snapshots for submission IPs.
 */

defined('ABSPATH') || exit;

class Aether_Submission_Geo_Resolver
{
    const CACHE_PREFIX = 'aether_geo_v2_';
    const SOURCE = 'ipapi.co';
    const SOURCE_CLOUDFLARE = 'cloudflare';
    const SOURCE_CLIENT_HINT = 'client_hint';

    /**
     * Cloudflare special values that are not real countries.
     *
     * @var string[]
     */
    private static $invalid_edge_country_codes = [
        'T1',
        'XX',
    ];

    /**
     * Resolve geo for a live submission request.
     *
     * Order:
     * 1. Trusted Cloudflare country header
     * 2. Client-side geo hint
     * 3. Server-side IP lookup fallback
     *
     * @param string $ip
     * @param array $request_data
     * @param array|null $server
     * @return array
     */
    public static function resolve_submission_geo($ip, $request_data = [], $server = null)
    {
        $geo = self::resolve_from_cloudflare_headers($server);
        $hint_geo = self::resolve_from_client_hint($request_data);

        if (!empty($geo['country'])) {
            return self::merge_snapshots($geo, $hint_geo);
        }

        if (!empty($hint_geo['country'])) {
            return $hint_geo;
        }

        return self::resolve_by_ip($ip);
    }

    /**
     * Resolve a geo snapshot for an IP.
     *
     * @param string $ip
     * @return array
     */
    public static function resolve_by_ip($ip)
    {
        $result = self::lookup_by_ip($ip, true);
        return is_array($result['geo'] ?? null) ? $result['geo'] : [];
    }

    /**
     * Resolve from positive cache only.
     *
     * @param string $ip
     * @return array
     */
    public static function resolve_cached_by_ip($ip)
    {
        $result = self::lookup_by_ip($ip, false);
        return is_array($result['geo'] ?? null) ? $result['geo'] : [];
    }

    /**
     * Resolve and return detailed provider result.
     *
     * @param string $ip
     * @param bool $allow_live_lookup
     * @return array{geo: array, error_code: int, retry_after: int, source: string}
     */
    public static function lookup_by_ip($ip, $allow_live_lookup = true)
    {
        $result = [
            'geo'         => [],
            'error_code'  => 0,
            'retry_after' => 0,
            'source'      => self::SOURCE,
        ];

        $ip = is_string($ip) ? trim($ip) : '';
        if (!$ip || !Aether_Submissions_Utils::is_valid_public_ip($ip)) {
            return $result;
        }

        $cached_geo = self::get_cached_geo($ip);
        if (!empty($cached_geo['country'])) {
            $result['geo'] = $cached_geo;
            return $result;
        }

        if (!$allow_live_lookup) {
            return $result;
        }

        $url = 'https://ipapi.co/' . rawurlencode($ip) . '/json/';
        $resp = wp_remote_get($url, [
            'timeout' => 3,
            'headers' => [
                'Accept' => 'application/json',
            ],
        ]);

        if (is_wp_error($resp)) {
            error_log(sprintf(
                'Aether submission geo lookup failed ip=%s source=%s error=%s',
                $ip,
                self::SOURCE,
                $resp->get_error_message()
            ));
            return $result;
        }

        $code = (int) wp_remote_retrieve_response_code($resp);
        if ($code === 429) {
            $retry_after = (int) wp_remote_retrieve_header($resp, 'retry-after');
            $result['error_code'] = 429;
            $result['retry_after'] = max(0, $retry_after);
            error_log(sprintf(
                'Aether submission geo lookup rate-limited ip=%s source=%s retry_after=%d',
                $ip,
                self::SOURCE,
                $result['retry_after']
            ));
            return $result;
        }

        if ($code < 200 || $code >= 300) {
            $result['error_code'] = $code;
            error_log(sprintf(
                'Aether submission geo lookup failed ip=%s source=%s code=%d',
                $ip,
                self::SOURCE,
                $code
            ));
            return $result;
        }

        $body = trim((string) wp_remote_retrieve_body($resp));
        if ($body === '' || stripos($body, '<') !== false) {
            return $result;
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return $result;
        }

        $geo = self::build_snapshot_from_ipapi_payload($decoded, self::SOURCE);
        if (empty($geo['country'])) {
            return $result;
        }

        self::cache_geo($ip, $geo);
        $result['geo'] = $geo;

        return $result;
    }

    /**
     * Build a normalized geo snapshot.
     *
     * @param array $snapshot
     * @return array
     */
    public static function build_snapshot($snapshot)
    {
        if (!is_array($snapshot)) {
            return [];
        }

        $source = self::normalize_text($snapshot['source'] ?? self::SOURCE);
        $country_code = Aether_Country_Registry::normalize_country_code($snapshot['country_code'] ?? '');
        $country = self::normalize_text($snapshot['country'] ?? '');
        $region = self::normalize_text($snapshot['region'] ?? '');
        $region_code = self::normalize_region_code($snapshot['region_code'] ?? '');
        $city = self::normalize_text($snapshot['city'] ?? '');
        $resolved_at = self::normalize_text($snapshot['resolved_at'] ?? '');

        if ($country === '' && $country_code !== '') {
            $country = Aether_Country_Registry::get_country_name($country_code);
        }

        if ($country === '') {
            return [];
        }

        return [
            'country'      => $country,
            'country_code' => $country_code,
            'region'       => $region,
            'region_code'  => $region_code,
            'city'         => $city,
            'source'       => $source !== '' ? $source : self::SOURCE,
            'resolved_at'  => $resolved_at !== '' ? $resolved_at : current_time('mysql'),
        ];
    }

    /**
     * Build a normalized geo snapshot from ISO country code.
     *
     * @param mixed $country_code
     * @param string $source
     * @return array
     */
    public static function build_snapshot_from_country_code($country_code, $source)
    {
        $country_code = Aether_Country_Registry::normalize_country_code($country_code);
        if ($country_code === '') {
            return [];
        }

        return self::build_snapshot([
            'country_code' => $country_code,
            'source'       => $source,
        ]);
    }

    /**
     * Read a positive cache entry.
     *
     * @param string $ip
     * @return array
     */
    public static function get_cached_geo($ip)
    {
        $ip = is_string($ip) ? trim($ip) : '';
        if (!$ip || !Aether_Submissions_Utils::is_valid_public_ip($ip)) {
            return [];
        }

        $cached = get_transient(self::cache_key($ip));
        if ($cached === '') {
            delete_transient(self::cache_key($ip));
            return [];
        }

        return is_array($cached) ? self::build_snapshot($cached) : [];
    }

    /**
     * Cache a positive country result.
     *
     * @param string $ip
     * @param array $geo
     * @return void
     */
    public static function cache_geo($ip, $geo)
    {
        $ip = is_string($ip) ? trim($ip) : '';
        $geo = self::normalize_cached_geo($geo);

        if ($ip === '' || empty($geo['country']) || !Aether_Submissions_Utils::is_valid_public_ip($ip)) {
            return;
        }

        set_transient(self::cache_key($ip), $geo, DAY_IN_SECONDS);
    }

    /**
     * @param string $ip
     * @return string
     */
    private static function cache_key($ip)
    {
        return self::CACHE_PREFIX . md5($ip);
    }

    /**
     * @param array|null $server
     * @return array
     */
    private static function resolve_from_cloudflare_headers($server = null)
    {
        $country_code = self::extract_cloudflare_country_code($server);
        if ($country_code === '') {
            return [];
        }

        return self::build_snapshot_from_country_code($country_code, self::SOURCE_CLOUDFLARE);
    }

    /**
     * @param array $request_data
     * @return array
     */
    private static function resolve_from_client_hint($request_data)
    {
        if (!is_array($request_data)) {
            return [];
        }

        return self::build_snapshot([
            'country_code' => $request_data['_aether_geo_country_code'] ?? '',
            'region'       => $request_data['_aether_geo_region'] ?? '',
            'region_code'  => $request_data['_aether_geo_region_code'] ?? '',
            'city'         => $request_data['_aether_geo_city'] ?? '',
            'source'       => self::SOURCE_CLIENT_HINT,
        ]);
    }

    /**
     * @param array|null $server
     * @return string
     */
    private static function extract_cloudflare_country_code($server = null)
    {
        if (!Aether_Submissions_Utils::is_trusted_cloudflare_request($server)) {
            return '';
        }

        $server = is_array($server) ? $server : $_SERVER;
        $country_code = isset($server['HTTP_CF_IPCOUNTRY']) ? (string) $server['HTTP_CF_IPCOUNTRY'] : '';
        $country_code = Aether_Country_Registry::normalize_country_code($country_code);

        if ($country_code === '' || in_array($country_code, self::$invalid_edge_country_codes, true)) {
            return '';
        }

        return Aether_Country_Registry::get_country_name($country_code) !== '' ? $country_code : '';
    }

    /**
     * @param array $payload
     * @param string $source
     * @return array
     */
    private static function build_snapshot_from_ipapi_payload($payload, $source)
    {
        $country_code = Aether_Country_Registry::normalize_country_code($payload['country_code'] ?? $payload['country'] ?? '');
        $country = self::normalize_text($payload['country_name'] ?? '');
        if ($country === '' && $country_code === '') {
            $country = self::normalize_text($payload['country'] ?? '');
        }

        return self::build_snapshot([
            'country'      => $country,
            'country_code' => $country_code,
            'region'       => $payload['region'] ?? '',
            'region_code'  => $payload['region_code'] ?? '',
            'city'         => $payload['city'] ?? '',
            'source'       => $source,
        ]);
    }

    /**
     * Prefer a richer same-country snapshot atomically.
     * Never blend different providers into one mixed-source snapshot.
     *
     * @param array $base
     * @param array $overlay
     * @return array
     */
    private static function merge_snapshots($base, $overlay)
    {
        $base = self::normalize_cached_geo($base);
        $overlay = self::normalize_cached_geo($overlay);

        if (empty($base['country']) || empty($overlay['country'])) {
            return $base;
        }

        if (
            (($base['country_code'] ?? '') !== '' && ($overlay['country_code'] ?? '') !== '' && $base['country_code'] !== $overlay['country_code']) ||
            (($base['country'] ?? '') !== '' && ($overlay['country'] ?? '') !== '' && $base['country'] !== $overlay['country'])
        ) {
            return $base;
        }

        $base_richness = self::snapshot_richness($base);
        $overlay_richness = self::snapshot_richness($overlay);

        return $overlay_richness > $base_richness ? self::build_snapshot($overlay) : self::build_snapshot($base);
    }

    /**
     * @param mixed $geo
     * @return array
     */
    private static function normalize_cached_geo($geo)
    {
        if (!is_array($geo)) {
            return [];
        }

        return [
            'country'      => self::normalize_text($geo['country'] ?? ''),
            'country_code' => Aether_Country_Registry::normalize_country_code($geo['country_code'] ?? ''),
            'region'       => self::normalize_text($geo['region'] ?? ''),
            'region_code'  => self::normalize_region_code($geo['region_code'] ?? ''),
            'city'         => self::normalize_text($geo['city'] ?? ''),
            'source'       => self::normalize_text($geo['source'] ?? self::SOURCE),
            'resolved_at'  => self::normalize_text($geo['resolved_at'] ?? ''),
        ];
    }

    /**
     * @param mixed $value
     * @return string
     */
    private static function normalize_text($value)
    {
        return is_string($value) ? trim($value) : '';
    }

    /**
     * @param mixed $value
     * @return string
     */
    private static function normalize_region_code($value)
    {
        $value = self::normalize_text($value);
        return $value !== '' ? strtoupper($value) : '';
    }

    /**
     * @param array $snapshot
     * @return int
     */
    private static function snapshot_richness($snapshot)
    {
        $score = 0;

        if (!empty($snapshot['region'])) {
            $score++;
        }

        if (!empty($snapshot['region_code'])) {
            $score++;
        }

        if (!empty($snapshot['city'])) {
            $score++;
        }

        return $score;
    }
}
