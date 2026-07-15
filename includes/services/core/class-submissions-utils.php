<?php
/**
 * Submissions utilities
 *
 * Helper methods used across submission-related features.
 */

defined('ABSPATH') || exit;

class Aether_Submissions_Utils
{
    const DEDUPE_OPTION_PREFIX = 'aether_submission_dedupe_';

    /**
     * Get client IP address with proxy support and forgery protection
     *
     * Security: Only trust proxy headers when actually behind a trusted proxy,
     * otherwise attackers can forge IPs to bypass rate limiting.
     *
     * @return string Sanitized IP address or empty string
     */
    public static function get_client_ip()
    {
        // Get direct connection IP (always trustworthy)
        $remote_addr = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';

        // Check if we're behind a trusted proxy
        $trusted_proxies = apply_filters('aether_trusted_proxies', [
            '127.0.0.1',     // localhost
            '::1',           // localhost IPv6
        ]);

        // Ensure filter returns array (prevent type error in in_array)
        if (!is_array($trusted_proxies)) {
            $trusted_proxies = ['127.0.0.1', '::1'];
        }

        $use_proxy_headers = false;

        // Method 1: Check if Cloudflare (CF-Connecting-IP is signed, hard to forge)
        if (self::is_trusted_cloudflare_request()) {
            // CF-RAY header presence indicates legitimate Cloudflare request
            $use_proxy_headers = true;
            $ip = sanitize_text_field(wp_unslash($_SERVER['HTTP_CF_CONNECTING_IP']));
            if (self::is_valid_public_ip($ip)) {
                return $ip;
            }
        }

        // Method 2: Check if REMOTE_ADDR is in trusted proxy list
        if (in_array($remote_addr, $trusted_proxies, true)) {
            $use_proxy_headers = true;
        }

        // Method 3: Allow admin override (use with caution!)
        if (apply_filters('aether_force_proxy_headers', false)) {
            $use_proxy_headers = true;
        }

        // If behind trusted proxy, try proxy headers
        if ($use_proxy_headers) {
            $proxy_headers = [
                'HTTP_X_REAL_IP',         // Nginx proxy_set_header X-Real-IP
                'HTTP_X_FORWARDED_FOR',   // Standard proxy header
            ];

            foreach ($proxy_headers as $header) {
                if (!empty($_SERVER[$header])) {
                    $ip = sanitize_text_field(wp_unslash($_SERVER[$header]));

                    // X-Forwarded-For may contain chain: client, proxy1, proxy2
                    // Take leftmost (original client IP)
                    if (strpos($ip, ',') !== false) {
                        $ips = array_map('trim', explode(',', $ip));
                        $ip = $ips[0];
                    }

                    if (self::is_valid_public_ip($ip)) {
                        return $ip;
                    }
                }
            }
        }

        // Fallback: use REMOTE_ADDR (direct connection or untrusted proxy)
        if (self::is_valid_ip($remote_addr)) {
            return $remote_addr;
        }

        return '';
    }

    /**
     * Check if IP is valid and public (not private/reserved)
     *
     * @param string $ip IP address to check
     * @return bool
     */
    public static function is_valid_public_ip($ip)
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /**
     * Cloudflare edge headers are only trusted when CF-RAY is present and
     * the connecting client IP itself is a valid public address.
     *
     * @param array|null $server
     * @return bool
     */
    public static function is_trusted_cloudflare_request($server = null)
    {
        $server = is_array($server) ? $server : $_SERVER;

        $cf_ray = isset($server['HTTP_CF_RAY']) ? trim((string) $server['HTTP_CF_RAY']) : '';
        $cf_connecting_ip = isset($server['HTTP_CF_CONNECTING_IP']) ? trim((string) $server['HTTP_CF_CONNECTING_IP']) : '';

        return $cf_ray !== '' && self::is_valid_public_ip($cf_connecting_ip);
    }

    /**
     * Check if IP is valid (including private IPs for localhost testing)
     *
     * @param string $ip IP address to check
     * @return bool
     */
    private static function is_valid_ip($ip)
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    public static function resolve_source_url($context)
    {
        if (!is_array($context)) return '';
        if (!empty($context['hx_current'])) return (string) $context['hx_current'];
        if (!empty($context['referer'])) return (string) $context['referer'];
        return '';
    }

    public static function get_geo_from_context($context)
    {
        if (!is_array($context)) {
            return [];
        }

        $geo = $context['geo'] ?? null;
        if (is_array($geo) && !empty($geo)) {
            return $geo;
        }

        if (!empty($context['country']) && is_string($context['country'])) {
            return [
                'country' => trim((string) $context['country']),
            ];
        }

        return is_array($geo) ? $geo : [];
    }

    public static function get_country_from_context($context)
    {
        $geo = self::get_geo_from_context($context);
        $country = $geo['country'] ?? '';
        return is_string($country) ? trim($country) : '';
    }

    public static function get_region_from_context($context)
    {
        $geo = self::get_geo_from_context($context);
        $region = $geo['region'] ?? '';
        return is_string($region) ? trim($region) : '';
    }

    public static function get_city_from_context($context)
    {
        $geo = self::get_geo_from_context($context);
        $city = $geo['city'] ?? '';
        return is_string($city) ? trim($city) : '';
    }

    public static function get_location_from_context($context)
    {
        $parts = [];

        $city = self::get_city_from_context($context);
        if ($city !== '') {
            $parts[] = $city;
        }

        $region = self::get_region_from_context($context);
        if ($region !== '') {
            $parts[] = $region;
        }

        $country = self::get_country_from_context($context);
        if ($country !== '') {
            $parts[] = $country;
        }

        return implode(', ', $parts);
    }

    public static function sanitize_payload($data)
    {
        if (!is_array($data)) return [];

        $out = [];
        foreach ($data as $key => $value) {
            $k = is_string($key) ? sanitize_key($key) : $key;

            if (is_array($value)) {
                $out[$k] = self::sanitize_payload($value);
                continue;
            }

            if (is_numeric($value)) {
                $out[$k] = 0 + $value;
                continue;
            }

            if (!is_string($value)) {
                $out[$k] = $value;
                continue;
            }

            $lower_k = strtolower((string) $k);
            if (strpos($lower_k, 'email') !== false) {
                $out[$k] = sanitize_email($value);
            } elseif (strpos($lower_k, 'url') !== false) {
                $out[$k] = esc_url_raw($value);
            } elseif (strpos($lower_k, 'message') !== false || strpos($lower_k, 'comment') !== false || strpos($lower_k, 'content') !== false) {
                $out[$k] = sanitize_textarea_field($value);
            } else {
                $out[$k] = sanitize_text_field($value);
            }
        }
        return $out;
    }

    public static function prune_private_fields($data)
    {
        if (!is_array($data)) return [];

        $defaults = [
            'keys' => ['_aether_hp', '__csrf', '__ts'],
            'prefixes' => ['__', '_aether_'],
        ];
        $rules = apply_filters('aether_submission_private_field_rules', $defaults, $data);
        $keys = is_array($rules['keys'] ?? null) ? $rules['keys'] : $defaults['keys'];
        $prefixes = is_array($rules['prefixes'] ?? null) ? $rules['prefixes'] : $defaults['prefixes'];

        $out = [];
        foreach ($data as $k => $v) {
            $key = is_string($k) ? $k : (string) $k;
            $drop = in_array($key, $keys, true);
            if (!$drop) {
                foreach ($prefixes as $pfx) {
                    if ($pfx !== '' && strncmp($key, $pfx, strlen($pfx)) === 0) {
                        $drop = true;
                        break;
                    }
                }
            }
            if ($drop) {
                continue;
            }
            $out[$key] = is_array($v) ? self::prune_private_fields($v) : $v;
        }
        return $out;
    }

    public static function get_submission_dedupe_window()
    {
        return max(1, (int) apply_filters('aether_submission_dedupe_window', 10));
    }

    public static function normalize_submission_source_url($hx_current, $referer)
    {
        foreach ([$hx_current, $referer] as $candidate) {
            $normalized = self::canonicalize_submission_url($candidate);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return '';
    }

    public static function normalize_submission_file_entries($files, $allowed_fields = ['attachment', 'attachments'])
    {
        if (!is_array($files)) {
            return [];
        }

        $allowed = array_map('strval', is_array($allowed_fields) ? $allowed_fields : []);
        $file_entries = [];
        foreach ($files as $field => $entry) {
            $field_name = is_string($field) ? $field : (string) $field;
            if (!empty($allowed) && !in_array($field_name, $allowed, true)) {
                continue;
            }
            if (!is_array($entry) || !isset($entry['name'])) {
                continue;
            }

            if (is_array($entry['name'])) {
                $count = count($entry['name']);
                for ($i = 0; $i < $count; $i++) {
                    $file_entries[] = [
                        'field' => $field_name,
                        'name' => isset($entry['name'][$i]) ? (string) $entry['name'][$i] : '',
                        'type' => isset($entry['type'][$i]) ? (string) $entry['type'][$i] : '',
                        'tmp_name' => isset($entry['tmp_name'][$i]) ? (string) $entry['tmp_name'][$i] : '',
                        'error' => isset($entry['error'][$i]) ? (int) $entry['error'][$i] : UPLOAD_ERR_NO_FILE,
                        'size' => isset($entry['size'][$i]) ? (int) $entry['size'][$i] : 0,
                    ];
                }
                continue;
            }

            $file_entries[] = [
                'field' => $field_name,
                'name' => isset($entry['name']) ? (string) $entry['name'] : '',
                'type' => isset($entry['type']) ? (string) $entry['type'] : '',
                'tmp_name' => isset($entry['tmp_name']) ? (string) $entry['tmp_name'] : '',
                'error' => isset($entry['error']) ? (int) $entry['error'] : UPLOAD_ERR_NO_FILE,
                'size' => isset($entry['size']) ? (int) $entry['size'] : 0,
            ];
        }

        return array_values(array_filter($file_entries, function ($entry) {
            return !empty($entry['name']) && ($entry['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        }));
    }

    public static function normalize_submission_attachment_signature($files, $allowed_fields = ['attachment', 'attachments'])
    {
        $entries = self::normalize_submission_file_entries($files, $allowed_fields);
        $normalized = [];

        foreach ($entries as $entry) {
            $content_hash = self::hash_submission_tmp_file($entry['tmp_name'] ?? '');
            if ($content_hash === '') {
                return null;
            }
            $normalized[] = [
                'field' => sanitize_key((string) ($entry['field'] ?? '')),
                'name' => sanitize_file_name((string) ($entry['name'] ?? '')),
                'size' => (int) ($entry['size'] ?? 0),
                'type' => sanitize_mime_type((string) ($entry['type'] ?? '')),
                'error' => (int) ($entry['error'] ?? UPLOAD_ERR_NO_FILE),
                'sha256' => $content_hash,
            ];
        }

        return $normalized;
    }

    public static function build_submission_fingerprint($client_ip, $source_url, $public_payload, $files)
    {
        $ip = trim((string) $client_ip);
        $source = trim((string) $source_url);
        if ($ip === '' || $source === '' || !is_array($public_payload)) {
            return '';
        }

        $attachments = self::normalize_submission_attachment_signature($files);
        if ($attachments === null) {
            return '';
        }

        $payload = [
            'ip' => $ip,
            'source' => $source,
            'payload' => self::normalize_submission_value($public_payload),
            'attachments' => $attachments,
        ];

        $encoded = wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded) || $encoded === '') {
            return '';
        }

        return hash('sha256', $encoded);
    }

    public static function claim_submission_fingerprint($fingerprint, $window, $context = [])
    {
        $fingerprint = trim((string) $fingerprint);
        if ($fingerprint === '') {
            return ['state' => 'unavailable'];
        }

        $window = max(1, (int) $window);
        $option_name = self::get_submission_dedupe_option_name($fingerprint);
        $now = time();
        $recovered = false;

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $pending = self::build_submission_dedupe_state('pending', $fingerprint, 0, $window, $context);
            if (add_option($option_name, $pending, '', 'no')) {
                $pending['state'] = 'acquired';
                $pending['recovered'] = $recovered;
                return $pending;
            }

            $existing = get_option($option_name, null);
            if (!is_array($existing)) {
                delete_option($option_name);
                continue;
            }

            $state = isset($existing['state']) ? (string) $existing['state'] : '';
            $expires_at = isset($existing['expires_at']) ? (int) $existing['expires_at'] : 0;
            $is_expired = $expires_at > 0 && $expires_at <= $now;

            if ($state === 'complete' && !$is_expired && !empty($existing['post_id'])) {
                $existing['state'] = 'complete';
                return $existing;
            }

            if ($is_expired || ($state === 'complete' && empty($existing['post_id']))) {
                delete_option($option_name);
                $recovered = true;
                continue;
            }

            $existing['state'] = 'pending';
            return $existing;
        }

        return ['state' => 'pending'];
    }

    public static function wait_for_submission_result($fingerprint, $timeout_ms = 1500, $poll_ms = 100)
    {
        $fingerprint = trim((string) $fingerprint);
        if ($fingerprint === '') {
            return null;
        }

        $deadline = microtime(true) + (max(0, (int) $timeout_ms) / 1000);
        $sleep_us = max(10000, (int) $poll_ms * 1000);
        $option_name = self::get_submission_dedupe_option_name($fingerprint);

        do {
            $current = get_option($option_name, null);
            if (!is_array($current)) {
                return null;
            }

            if (($current['state'] ?? '') === 'complete' && !empty($current['post_id'])) {
                return $current;
            }

            if (($current['state'] ?? '') !== 'pending') {
                return null;
            }

            if (microtime(true) >= $deadline) {
                break;
            }

            usleep($sleep_us);
        } while (microtime(true) < $deadline);

        return null;
    }

    public static function store_submission_result($fingerprint, $post_id, $window, $context = [])
    {
        $fingerprint = trim((string) $fingerprint);
        $post_id = (int) $post_id;
        if ($fingerprint === '' || $post_id <= 0) {
            return null;
        }

        $window = max(1, (int) $window);
        $option_name = self::get_submission_dedupe_option_name($fingerprint);
        $existing = get_option($option_name, null);
        $state = self::build_submission_dedupe_state('complete', $fingerprint, $post_id, $window, $context);
        if (is_array($existing) && !empty($existing['created_at'])) {
            $state['created_at'] = (int) $existing['created_at'];
        }

        if (!update_option($option_name, $state, false)) {
            add_option($option_name, $state, '', 'no');
        }

        return $state;
    }

    public static function release_submission_fingerprint($fingerprint)
    {
        $fingerprint = trim((string) $fingerprint);
        if ($fingerprint === '') {
            return;
        }

        delete_option(self::get_submission_dedupe_option_name($fingerprint));
    }

    public static function find_recent_submission_by_fingerprint($fingerprint, $window)
    {
        $fingerprint = trim((string) $fingerprint);
        if ($fingerprint === '') {
            return 0;
        }

        $window = max(1, (int) $window);
        $after = date('Y-m-d H:i:s', current_time('timestamp') - max($window + 5, 30));
        $query = new WP_Query([
            'post_type' => 'aether_submission',
            'post_status' => 'publish',
            'posts_per_page' => 25,
            'fields' => 'ids',
            'orderby' => 'date',
            'order' => 'DESC',
            'date_query' => [
                [
                    'after' => $after,
                    'inclusive' => true,
                ],
            ],
        ]);

        foreach ($query->posts as $post_id) {
            $content = get_post_field('post_content', $post_id);
            $decoded = json_decode((string) $content, true);
            $stored = is_array($decoded)
                ? (string) (($decoded['context']['dedupe']['fingerprint'] ?? ''))
                : '';
            if ($stored === $fingerprint) {
                return (int) $post_id;
            }
        }

        return 0;
    }

    private static function canonicalize_submission_url($url)
    {
        $url = trim((string) $url);
        if ($url === '') {
            return '';
        }

        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return '';
        }

        $scheme = isset($parts['scheme']) ? strtolower((string) $parts['scheme']) : 'https';
        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $path = isset($parts['path']) ? '/' . ltrim((string) $parts['path'], '/') : '/';
        $path = $path === '' ? '/' : $path;

        $query = '';
        if (!empty($parts['query'])) {
            $query_params = [];
            parse_str((string) $parts['query'], $query_params);
            if (!empty($query_params)) {
                $normalized_query = self::normalize_submission_value($query_params);
                $query = self::build_query_string($normalized_query);
            }
        }

        $normalized = $scheme . '://' . $host . $port . $path;
        if ($query !== '') {
            $normalized .= '?' . $query;
        }

        return $normalized;
    }

    private static function normalize_submission_value($value)
    {
        if (!is_array($value)) {
            return $value;
        }

        $normalized = [];
        foreach ($value as $key => $item) {
            $normalized[$key] = self::normalize_submission_value($item);
        }

        if (self::is_assoc_array($normalized)) {
            ksort($normalized);
        }

        return $normalized;
    }

    private static function is_assoc_array($value)
    {
        if (!is_array($value)) {
            return false;
        }

        return array_keys($value) !== range(0, count($value) - 1);
    }

    private static function build_query_string($value, $prefix = '')
    {
        if (!is_array($value)) {
            return rawurlencode((string) $prefix) . '=' . rawurlencode((string) $value);
        }

        $parts = [];
        foreach ($value as $key => $item) {
            $next_prefix = $prefix === ''
                ? (string) $key
                : (self::is_assoc_array($value) ? $prefix . '[' . $key . ']' : $prefix . '[]');
            if (is_array($item)) {
                $nested = self::build_query_string($item, $next_prefix);
                if ($nested !== '') {
                    $parts[] = $nested;
                }
                continue;
            }

            $parts[] = rawurlencode((string) $next_prefix) . '=' . rawurlencode((string) $item);
        }

        return implode('&', array_filter($parts, 'strlen'));
    }

    private static function build_submission_dedupe_state($state, $fingerprint, $post_id, $window, $context)
    {
        $now = time();

        return [
            'state' => (string) $state,
            'fingerprint' => (string) $fingerprint,
            'post_id' => max(0, (int) $post_id),
            'created_at' => $now,
            'updated_at' => $now,
            'expires_at' => $now + max(1, (int) $window),
            'source' => isset($context['source']) ? (string) $context['source'] : '',
            'ip_hash' => isset($context['client_ip']) && $context['client_ip'] !== ''
                ? substr(md5((string) $context['client_ip']), 0, 12)
                : '',
        ];
    }

    private static function get_submission_dedupe_option_name($fingerprint)
    {
        return self::DEDUPE_OPTION_PREFIX . $fingerprint;
    }

    private static function hash_submission_tmp_file($tmp_name)
    {
        $tmp_name = is_string($tmp_name) ? trim($tmp_name) : '';
        if ($tmp_name === '' || !is_readable($tmp_name)) {
            return '';
        }

        $hash = @hash_file('sha256', $tmp_name);
        return is_string($hash) ? $hash : '';
    }

    public static function check_rate_limit($ip)
    {
        if (!$ip) return true;
        $window = 60;
        $max = 30;
        $key = 'aether_submit_rate_' . md5($ip);
        $data = get_transient($key);
        if (!is_array($data)) {
            $data = ['count' => 0, 'start' => time()];
        }
        if ((time() - $data['start']) > $window) {
            $data = ['count' => 0, 'start' => time()];
        }
        $data['count']++;
        set_transient($key, $data, $window);
        return $data['count'] <= $max;
    }

    public static function is_same_origin($referer, $hx_current, $origin = '')
    {
        $site = parse_url(home_url('/'));
        $site_host = isset($site['host']) ? $site['host'] : '';
        if (!$site_host) {
            // Cannot determine site host - reject for security
            error_log('Aether Security: Cannot parse site host from home_url: ' . home_url('/'));
            return false;
        }

        $hosts = [];

        // Priority 1: Check origin header (most reliable for CORS requests)
        if ($origin) {
            $p = @parse_url($origin);
            if (!empty($p['host'])) $hosts[] = $p['host'];
        }

        // Priority 2: Check HTMX current URL and referer
        foreach ([$hx_current, $referer] as $u) {
            if (!$u) continue;
            $p = @parse_url($u);
            if (!empty($p['host'])) $hosts[] = $p['host'];
        }

        // Require at least one origin header to prevent bot submissions
        if (empty($hosts)) {
            // Allow override via filter for debugging or special scenarios
            $allow = apply_filters('aether_submission_allow_no_referer', false);
            if (!$allow) {
                error_log('Aether Security: Submission rejected - no origin headers (Referer, HX-Current-URL, Origin)');
            }
            return $allow;
        }

        // Validate that at least one host matches site host
        foreach ($hosts as $h) {
            if ($h === $site_host) return true;
        }

        // No matching host found
        error_log('Aether Security: Submission rejected - origin mismatch. Expected: ' . $site_host . ', Got: ' . implode(', ', $hosts));
        return false;
    }
}
